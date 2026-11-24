import contextlib
import hashlib
import http.server
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tarfile
import tempfile
import threading
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SCRIPTS))

spec = importlib.util.spec_from_file_location("hw_runtime", SCRIPTS / "hw-runtime.py")
hw_runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(hw_runtime)


def _make_tar_gz(dest: Path, files: dict[str, bytes], symlinks: dict[str, str] | None = None) -> str:
    dest.parent.mkdir(parents=True, exist_ok=True)
    with tarfile.open(dest, "w:gz") as tf:
        for rel, content in files.items():
            info = tarfile.TarInfo(rel)
            info.size = len(content)
            info.mode = 0o755 if "/bin/" in f"/{rel}" or rel.startswith("bin/") else 0o644
            tf.addfile(info, io.BytesIO(content))
        for rel, target in (symlinks or {}).items():
            info = tarfile.TarInfo(rel)
            info.type = tarfile.SYMTYPE
            info.linkname = target
            tf.addfile(info)
    return hashlib.sha256(dest.read_bytes()).hexdigest().lower()


def _make_zip(dest: Path, files: dict[str, bytes]) -> str:
    dest.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(dest, "w") as zf:
        for rel, content in files.items():
            zf.writestr(rel, content)
    return hashlib.sha256(dest.read_bytes()).hexdigest().lower()


class T01RuntimeMatrixAndPrepareTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="hwadmin-t01-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "server/config").mkdir(parents=True)
        (self.root / "server/runtime").mkdir(parents=True)
        (self.root / "server/public/uploads").mkdir(parents=True)

    def test_platform_support_matrix_supported_and_unsupported(self):
        for plat_id in (
            "windows-x64-wsl2",
            "linux-ubuntu-22.04-x86_64",
            "linux-ubuntu-22.04-arm64",
            "linux-ubuntu-24.04-x86_64",
            "linux-ubuntu-24.04-arm64",
            "linux-debian-12-x86_64",
            "linux-debian-12-arm64",
        ):
            with self.subTest(plat_id=plat_id):
                res = hw_runtime.detect_current_platform(platform_override=plat_id)
                self.assertEqual("supported", res["status"])

        for bad_args in (
            {"system_name": "Darwin", "release_str": "23.5.0", "machine_arch": "arm64"},
            {"system_name": "Darwin", "release_str": "23.5.0", "machine_arch": "x86_64"},
            {"system_name": "Windows", "release_str": "11", "machine_arch": "ARM64"},
            {"system_name": "Linux", "release_str": "5.15.0", "machine_arch": "x86_64", "os_release_text": 'ID=centos\nVERSION_ID="7"\n'},
            {"system_name": "Linux", "release_str": "6.1.0", "machine_arch": "x86_64", "os_release_text": 'ID=alpine\nVERSION_ID="3.20"\n'},
        ):
            with self.subTest(bad_args=bad_args):
                res = hw_runtime.detect_current_platform(**bad_args)
                self.assertEqual("unsupported", res["status"])

    def test_prepare_rejects_unsupported_platform_nonzero(self):
        code, data = hw_runtime.prepare_runtimes(self.root, platform_override="darwin-arm64")
        self.assertNotEqual(0, code)
        self.assertEqual("unsupported_platform", data["error_code"])

    def test_detect_runtimes_missing_and_version_boundaries(self):
        # 1. 空 PATH 冷机环境 -> 缺项清单明确
        cold = hw_runtime.detect_runtimes(self.root, mode="sqlite", platform_override="linux-ubuntu-24.04", path_override="")
        self.assertFalse(cold["ready"])
        missing_names = {m["component"] for m in cold["missing"]}
        self.assertTrue({"php", "composer", "node", "pnpm", "python"}.issubset(missing_names))
        self.assertFalse(cold["runtimes"]["php"]["reusable"])

        # 2. 构造桩目录并模拟不同版本与扩展缺失
        fake_bin = self.root / "fake-bin"
        fake_bin.mkdir()
        for tool in ("php", "composer", "node", "pnpm", "python3", "docker"):
            p = fake_bin / (tool + (".exe" if os.name == "nt" else ""))
            p.write_text("#!/bin/sh\n", encoding="utf-8")
            p.chmod(0o755)

        def mock_runner_low_php(argv, env=None, timeout=8):
            exe = Path(argv[0]).stem.lower()
            if exe == "php" and "-r" in argv:
                return 0, "8.0.30", ""
            if exe == "php" and "-m" in argv:
                return 0, "pdo\nmbstring\njson\nopenssl\ncurl\nfileinfo\nctype\npdo_sqlite\n", ""
            if exe == "node":
                return 0, "v18.20.0", ""
            if exe == "pnpm":
                return 0, "8.15.0", ""
            if exe == "composer":
                return 0, "Composer version 2.7.1", ""
            if exe == "python3":
                return 0, "Python 3.12.3", ""
            return 1, "", "unsupported"

        res_low = hw_runtime.detect_runtimes(
            self.root,
            mode="sqlite",
            platform_override="linux-ubuntu-24.04",
            path_override=str(fake_bin),
            runner=mock_runner_low_php,
        )
        self.assertFalse(res_low["ready"])
        self.assertFalse(res_low["runtimes"]["php"]["meets_minimum"])
        self.assertFalse(res_low["runtimes"]["node"]["reusable"])
        self.assertFalse(res_low["runtimes"]["pnpm"]["reusable"])

        # 3. Match actual Composer/package engines; PostgreSQL still requires pdo_pgsql.
        def mock_runner_min_ok(argv, env=None, timeout=8):
            exe = Path(argv[0]).stem.lower()
            if exe == "php" and "-r" in argv:
                return 0, "8.2.29", ""
            if exe == "php" and "-m" in argv:
                return 0, "pdo\nmbstring\njson\nopenssl\ncurl\nfileinfo\nctype\npdo_sqlite\n", ""
            if exe == "node":
                return 0, "v22.18.0", ""
            if exe == "pnpm":
                return 0, "10.33.0", ""
            if exe == "composer":
                return 0, "Composer version 2.7.9", ""
            if exe == "python3":
                return 0, "Python 3.12.3", ""
            return 1, "", ""

        res_sqlite = hw_runtime.detect_runtimes(
            self.root,
            mode="sqlite",
            platform_override="linux-ubuntu-24.04",
            path_override=str(fake_bin),
            runner=mock_runner_min_ok,
        )
        self.assertTrue(res_sqlite["ready"])
        self.assertTrue(res_sqlite["runtimes"]["php"]["meets_minimum"])
        self.assertTrue(res_sqlite["runtimes"]["node"]["meets_minimum"])

        res_pgsql = hw_runtime.detect_runtimes(
            self.root,
            mode="pgsql",
            platform_override="linux-ubuntu-24.04",
            path_override=str(fake_bin),
            runner=mock_runner_min_ok,
        )
        self.assertFalse(res_pgsql["ready"])
        self.assertIn("pdo_pgsql", res_pgsql["runtimes"]["php"]["extensions"]["missing_required"])

    def test_windows_reboot_prompt_when_wsl_missing(self):
        def mock_no_wsl(argv, env=None, timeout=8):
            return 1, "", "wsl not installed"

        with mock.patch.object(hw_runtime.shutil, "which", return_value=None):
            res = hw_runtime.detect_runtimes(
                self.root,
                mode="sqlite",
                platform_override="windows-x64-wsl2",
                path_override="",
                runner=mock_no_wsl,
            )
        self.assertTrue(res["reboot_required"])
        self.assertIn("wsl --install", res["reboot_reason"])

    @unittest.skipUnless(os.name != "nt" and shutil.which("bash"), "requires POSIX bash")
    def test_prepare_from_cache_and_repeat_preserves_data(self):
        cache_src = self.root / "offline-cache"
        archive_path = cache_src / "php-bundle.tar.gz"
        sha = _make_tar_gz(
            archive_path,
            {
                "php-bundle/bin/php": b"#!/bin/sh\nif [ \"$1\" = \"-r\" ]; then echo 8.3.14; elif [ \"$1\" = \"-m\" ]; then printf 'pdo\\nmbstring\\njson\\nopenssl\\ncurl\\nfileinfo\\nctype\\npdo_sqlite\\n'; fi\n",
                "php-bundle/bin/composer": b"#!/bin/sh\necho 'Composer version 2.8.1'\n",
                "php-bundle/bin/node": b"#!/bin/sh\necho 'v22.18.0'\n",
                "php-bundle/bin/pnpm": b"#!/bin/sh\necho '10.33.0'\n",
                "php-bundle/bin/python3": b"#!/bin/sh\necho 'Python 3.12.3'\n",
            },
        )
        manifest = {
            "version": "1",
            "artifacts": [
                {
                    "name": "toolchain",
                    "version": "1.0.0",
                    "platform": "linux-ubuntu-24.04",
                    "arch": "x86_64",
                    "filename": "php-bundle.tar.gz",
                    "sha256": sha,
                    "bin_relpaths": ["bin/php", "bin/composer", "bin/node", "bin/pnpm", "bin/python3"],
                }
            ],
        }
        (cache_src / "runtime-manifest.json").write_text(json.dumps(manifest), encoding="utf-8")

        # 预设站点数据与管理员账号标记，验证重复执行 prepare 绝不破坏既有数据
        db_sentinel = self.root / "server/runtime/dev.sqlite"
        db_sentinel.write_text("admin-account-preserved", encoding="utf-8")
        lock_sentinel = self.root / "server/config/install.lock"
        lock_sentinel.write_text("installed", encoding="utf-8")

        code, res = hw_runtime.prepare_runtimes(
            self.root,
            mode="sqlite",
            from_cache=str(cache_src),
            platform_override="linux-ubuntu-24.04",
            path_override="",
        )
        self.assertEqual(0, code, res)
        self.assertEqual("pass", res["status"])
        self.assertTrue((self.root / ".hw-state/runtime/bin/php").exists())
        self.assertEqual("admin-account-preserved", db_sentinel.read_text(encoding="utf-8"))
        self.assertEqual("installed", lock_sentinel.read_text(encoding="utf-8"))

        # 重复执行幂等保留数据
        code2, res2 = hw_runtime.prepare_runtimes(
            self.root,
            mode="sqlite",
            from_cache=str(cache_src),
            platform_override="linux-ubuntu-24.04",
            path_override="",
        )
        self.assertEqual(0, code2, res2)
        self.assertEqual("admin-account-preserved", db_sentinel.read_text(encoding="utf-8"))

    def test_prepare_rejects_sha256_mismatch_corrupt_and_traversal_archives(self):
        cache_src = self.root / "bad-cache"
        archive_path = cache_src / "bad.tar.gz"
        real_sha = _make_tar_gz(archive_path, {"bin/php": b"#!/bin/sh\n"})

        # 1. 哈希不匹配必须立即拒绝
        manifest_bad_hash = {
            "artifacts": [{"name": "php", "filename": "bad.tar.gz", "sha256": "0" * 64, "bin_relpaths": ["bin/php"]}]
        }
        (cache_src / "runtime-manifest.json").write_text(json.dumps(manifest_bad_hash), encoding="utf-8")
        code, res = hw_runtime.prepare_runtimes(
            self.root, from_cache=str(cache_src), platform_override="linux-ubuntu-24.04", path_override=""
        )
        self.assertNotEqual(0, code)
        self.assertEqual("sha256_mismatch", res["error_code"])
        self.assertFalse((self.root / ".hw-state/runtime/bin/php").exists())

        # 2. 损坏归档（即便哈希一致）必须拒绝
        broken_path = cache_src / "broken.tar.gz"
        broken_path.write_bytes(b"not a valid gzip tar archive")
        broken_sha = hashlib.sha256(broken_path.read_bytes()).hexdigest()
        manifest_broken = {
            "artifacts": [{"name": "php", "filename": "broken.tar.gz", "sha256": broken_sha, "bin_relpaths": ["bin/php"]}]
        }
        (cache_src / "runtime-manifest.json").write_text(json.dumps(manifest_broken), encoding="utf-8")
        code2, res2 = hw_runtime.prepare_runtimes(
            self.root, from_cache=str(cache_src), platform_override="linux-ubuntu-24.04", path_override=""
        )
        self.assertNotEqual(0, code2)
        self.assertEqual("corrupt_archive", res2["error_code"])

        # 3. 路径穿越归档必须拒绝并不得写出目标目录外
        traversal_path = cache_src / "evil.tar.gz"
        evil_sha = _make_tar_gz(traversal_path, {"../escaped-payload": b"pwned"})
        manifest_evil = {
            "artifacts": [{"name": "php", "filename": "evil.tar.gz", "sha256": evil_sha}]
        }
        (cache_src / "runtime-manifest.json").write_text(json.dumps(manifest_evil), encoding="utf-8")
        code3, res3 = hw_runtime.prepare_runtimes(
            self.root, from_cache=str(cache_src), platform_override="linux-ubuntu-24.04", path_override=""
        )
        self.assertNotEqual(0, code3)
        self.assertEqual("corrupt_archive", res3["error_code"])
        self.assertFalse((self.root / ".hw-state/escaped-payload").exists())

        # 4. 非受信任远程 URL / 缺少 SHA-256 必须拒绝
        with self.assertRaises(ValueError):
            hw_runtime.validate_source_url("http://evil.example.com/php.tar.gz")
        with self.assertRaises(ValueError):
            hw_runtime.validate_source_url("https://untrusted.example.org/php.tar.gz")
        code4, res4 = hw_runtime.prepare_runtimes(
            self.root,
            url="https://dl.static-php.dev/static-php-cli/bulk/php.tar.gz",
            sha256=None,
            platform_override="linux-ubuntu-24.04",
        )
        self.assertNotEqual(0, code4)
        self.assertEqual("missing_sha256", res4["error_code"])
        self.assertEqual(len(real_sha), 64)

        # 5. 失败后修复清单并重试恢复成功
        if os.name != "nt":
            good_path = cache_src / "good.tar.gz"
            good_sha = _make_tar_gz(
                good_path,
                {
                    "bin/php": b"#!/bin/sh\nif [ \"$1\" = \"-r\" ]; then echo 8.3.14; elif [ \"$1\" = \"-m\" ]; then printf 'pdo\\nmbstring\\njson\\nopenssl\\ncurl\\nfileinfo\\nctype\\npdo_sqlite\\n'; fi\n",
                    "bin/composer": b"#!/bin/sh\necho 'Composer version 2.8.1'\n",
                    "bin/python3": b"#!/bin/sh\necho 'Python 3.12.3'\n",
                },
            )
            (cache_src / "runtime-manifest.json").write_text(
                json.dumps(
                    {
                        "artifacts": [
                            {
                                "name": "toolchain",
                                "filename": "good.tar.gz",
                                "sha256": good_sha,
                                "bin_relpaths": ["bin/php", "bin/composer", "bin/python3"],
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            code5, res5 = hw_runtime.prepare_runtimes(
                self.root,
                mode="sqlite",
                from_cache=str(cache_src),
                platform_override="linux-ubuntu-24.04",
                path_override="",
            )
            self.assertEqual(0, code5, res5)
            self.assertEqual("pass", res5["status"])

    @unittest.skipUnless(os.name != "nt" and hasattr(os, "geteuid") and os.geteuid() != 0, "requires non-root POSIX")
    def test_prepare_permission_denied_fails_nonzero(self):
        ro_root = self.root / "readonly-proj"
        ro_root.mkdir()
        ro_root.chmod(0o555)
        self.addCleanup(lambda: ro_root.chmod(0o755))
        code, res = hw_runtime.prepare_runtimes(ro_root, platform_override="linux-ubuntu-24.04")
        self.assertNotEqual(0, code)
        self.assertEqual("permission_denied", res["error_code"])

    @unittest.skipUnless(os.name != "nt" and shutil.which("bash"), "requires POSIX bash")
    def test_prepare_http_download_retry_recovery_and_proxy_unmodified(self):
        # 构建一个合法的受管工具链归档，并通过本地 HTTP 服务模拟前 2 次 503 失败，第 3 次成功
        src_archive = self.root / "upstream/toolchain.tar.gz"
        sha = _make_tar_gz(
            src_archive,
            {
                "bin/php": b"#!/bin/sh\nif [ \"$1\" = \"-r\" ]; then echo 8.3.14; elif [ \"$1\" = \"-m\" ]; then printf 'pdo\\nmbstring\\njson\\nopenssl\\ncurl\\nfileinfo\\nctype\\npdo_sqlite\\n'; fi\n",
                "bin/composer": b"#!/bin/sh\necho 'Composer version 2.8.1'\n",
                "bin/node": b"#!/bin/sh\necho 'v22.18.0'\n",
                "bin/pnpm": b"#!/bin/sh\necho '10.33.0'\n",
                "bin/python3": b"#!/bin/sh\necho 'Python 3.12.3'\n",
            },
        )
        payload = src_archive.read_bytes()
        counter = {"hits": 0}

        class FlakyHandler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                counter["hits"] += 1
                if counter["hits"] <= 2:
                    self.send_response(503)
                    self.end_headers()
                    self.wfile.write(b"transient upstream error")
                    return
                self.send_response(200)
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def log_message(self, fmt, *args):
                pass

        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), FlakyHandler)
        port = server.server_address[1]
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.shutdown)
        self.addCleanup(server.server_close)

        manifest_file = self.root / "remote-manifest.json"
        manifest_file.write_text(
            json.dumps(
                {
                    "artifacts": [
                        {
                            "name": "toolchain",
                            "version": "1.0.0",
                            "url": f"http://127.0.0.1:{port}/toolchain.tar.gz",
                            "filename": "toolchain.tar.gz",
                            "sha256": sha,
                            "bin_relpaths": ["bin/php", "bin/composer", "bin/node", "bin/pnpm", "bin/python3"],
                        }
                    ]
                }
            ),
            encoding="utf-8",
        )

        proxy_before = os.environ.get("HTTPS_PROXY")
        code, res = hw_runtime.prepare_runtimes(
            self.root,
            mode="sqlite",
            manifest_arg=str(manifest_file),
            retries=3,
            retry_delay=0.05,
            platform_override="linux-ubuntu-24.04",
            path_override="",
        )
        self.assertEqual(0, code, res)
        self.assertEqual(3, counter["hits"])
        self.assertEqual(3, res["prepared"][0]["attempts"])
        self.assertTrue((self.root / ".hw-state/runtime-cache/toolchain.tar.gz").is_file())
        self.assertEqual(proxy_before, os.environ.get("HTTPS_PROXY"))

    @unittest.skipUnless(os.name != "nt" and shutil.which("bash"), "requires POSIX bash")
    def test_hw_doctor_embeds_platform_support_matrix_and_chinese_space_path_coexistence(self):
        # 1. 验证仓库根 ./hw doctor --json 嵌入 platform_support_matrix
        doc = subprocess.run(
            ["bash", str(ROOT / "hw"), "doctor", "--json"],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        doc_data = json.loads(doc.stdout)
        self.assertIn("platform_support_matrix", doc_data)
        self.assertIn("supported", doc_data["platform_support_matrix"])
        self.assertIn("current_platform", doc_data["platform_support_matrix"])

        # 2. 验证 ./hw runtime matrix --json 可用
        mat = subprocess.run(
            ["bash", str(ROOT / "hw"), "runtime", "matrix", "--json"],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(0, mat.returncode, mat.stderr)
        self.assertEqual("supported", json.loads(mat.stdout)["current_platform"]["status"])

        # 3. 两个中文+空格路径隔离项目并存 + 端口冲突检测 + 隔离端口同时启动
        proj_a = self.root / "项目 甲 one"
        proj_b = self.root / "项目 乙 two"
        for proj in (proj_a, proj_b):
            (proj / "server/public").mkdir(parents=True)
            (proj / "server/runtime").mkdir(parents=True)
            (proj / "server/config").mkdir(parents=True)
            (proj / "scripts").mkdir(parents=True)
            shutil.copy2(ROOT / "hw", proj / "hw")
            shutil.copy2(ROOT / "scripts/wsl-dev.sh", proj / "scripts/wsl-dev.sh")
            shutil.copy2(ROOT / "scripts/dev.sh", proj / "scripts/dev.sh")
            shutil.copy2(ROOT / "scripts/hw-runtime.py", proj / "scripts/hw-runtime.py")
            (proj / "server/think").write_text("<?php\n", encoding="utf-8")
            (proj / "server/php.ini").write_text("", encoding="utf-8")
            (proj / "server/.env").write_text("APP_DEBUG=true\nDB_DRIVER=sqlite\n", encoding="utf-8")
            (proj / "server/public/router.php").write_text(
                '<?php header("Content-Type: application/json"); echo json_encode(["code"=>1,"proj"=>basename(dirname(__DIR__, 2))]);',
                encoding="utf-8",
            )

        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s1, socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s2:
            s1.bind(("127.0.0.1", 0))
            s2.bind(("127.0.0.1", 0))
            port_a = s1.getsockname()[1]
            port_b = s2.getsockname()[1]

        env_a = os.environ.copy()
        env_a["PHP_PORT"] = str(port_a)
        proc_a = subprocess.Popen(
            ["bash", str(proj_a / "scripts/dev.sh"), "--backend-only"],
            cwd=proj_a,
            env=env_a,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            # 等待 A 健康响应
            import urllib.request
            ready_a = False
            for _ in range(30):
                try:
                    with urllib.request.urlopen(f"http://127.0.0.1:{port_a}/api/index/ping", timeout=1) as r:
                        if r.status == 200:
                            ready_a = True
                            break
                except Exception:
                    import time
                    time.sleep(0.2)
            self.assertTrue(ready_a, f"Project A failed to start on port {port_a}")

            # 项目 B 故意使用相同 port_a -> 必须检测到端口冲突并失败退出非零，且不杀死 A
            env_b_conflict = os.environ.copy()
            env_b_conflict["PHP_PORT"] = str(port_a)
            conflict_res = subprocess.run(
                ["bash", str(proj_b / "scripts/dev.sh"), "--backend-only"],
                cwd=proj_b,
                env=env_b_conflict,
                capture_output=True,
                text=True,
                timeout=10,
            )
            self.assertNotEqual(0, conflict_res.returncode)
            self.assertIn("占用", conflict_res.stderr)
            self.assertIsNone(proc_a.poll(), "Project B conflict must not kill Project A")

            # 项目 B 使用独立端口 port_b -> 两个项目同时并存成功
            env_b_ok = os.environ.copy()
            env_b_ok["PHP_PORT"] = str(port_b)
            proc_b = subprocess.Popen(
                ["bash", str(proj_b / "scripts/dev.sh"), "--backend-only"],
                cwd=proj_b,
                env=env_b_ok,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            try:
                ready_b = False
                for _ in range(30):
                    try:
                        with urllib.request.urlopen(f"http://127.0.0.1:{port_b}/api/index/ping", timeout=1) as r:
                            if r.status == 200:
                                ready_b = True
                                break
                    except Exception:
                        import time
                        time.sleep(0.2)
                self.assertTrue(ready_b, f"Project B failed to coexist on port {port_b}")
            finally:
                proc_b.terminate()
                proc_b.wait(timeout=5)
                subprocess.run(["bash", str(proj_b / "scripts/wsl-dev.sh"), "stop"], cwd=proj_b, capture_output=True)
        finally:
            proc_a.terminate()
            proc_a.wait(timeout=5)
            subprocess.run(["bash", str(proj_a / "scripts/wsl-dev.sh"), "stop"], cwd=proj_a, capture_output=True)


class T02DockerDualDatabaseTest(unittest.TestCase):
    def setUp(self):
        self.compose_text = (ROOT / "docker-compose.yml").read_text(encoding="utf-8")
        self.dockerfile_text = (ROOT / "docker/php/Dockerfile").read_text(encoding="utf-8")
        self.nginx_text = (ROOT / "docker/nginx/default.conf").read_text(encoding="utf-8")
        self.env_example_text = (ROOT / ".env.docker.example").read_text(encoding="utf-8")

    def test_dockerfile_has_both_mysql_and_pgsql_extensions(self):
        self.assertIn("pdo_mysql", self.dockerfile_text)
        self.assertIn("pdo_pgsql", self.dockerfile_text)
        self.assertIn("pdo_sqlite", self.dockerfile_text)
        self.assertIn("redis", self.dockerfile_text)
        self.assertIn("postgresql-dev", self.dockerfile_text)

    def test_nginx_routes_php_entry_and_blocks_uploads_scripts(self):
        self.assertIn("fastcgi_pass hwadmin-php:9000;", self.nginx_text)
        self.assertIn("location ~* ^/(uploads|plugins)/.*\\.(php", self.nginx_text)

    def test_compose_structure_decouples_mysql_and_pg_init_profiles(self):
        # 确保旧耦合服务 hwadmin-init 已被拆分为 hwadmin-init-mysql 与 hwadmin-init-pg
        self.assertNotIn("\n  hwadmin-init:\n", self.compose_text)
        self.assertIn("hwadmin-init-mysql:", self.compose_text)
        self.assertIn("hwadmin-init-pg:", self.compose_text)
        self.assertIn("hwadmin-pg:", self.compose_text)
        self.assertIn('DB_TYPE: "pgsql"', self.compose_text)
        self.assertIn('DB_HOST: "hwadmin-pg"', self.compose_text)
        self.assertIn('DB_PORT: "5432"', self.compose_text)
        self.assertIn("COMPOSE_PROFILES=mysql", self.env_example_text)

    def _docker_compose_cmd(self, args: list[str], env_file: Path | None = None) -> subprocess.CompletedProcess:
        docker_bin = shutil.which("docker")
        if not docker_bin:
            self.skipTest("docker CLI not found in current PATH")
        cmd = [docker_bin, "compose", "-f", str(ROOT / "docker-compose.yml")]
        if env_file is not None:
            cmd.extend(["--env-file", str(env_file)])
        cmd.extend(args)
        proc = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, encoding="utf-8", errors="replace")
        if "could not be found in this WSL 2 distro" in (proc.stdout + proc.stderr):
            self.skipTest("Docker Desktop WSL integration is disabled in this WSL distro")
        return proc

    def test_docker_compose_real_profile_isolation_and_missing_strong_passwords(self):
        # 1. --profile pgsql 仅包含 PG + Redis + PHP + Nginx，零 MySQL 隐性依赖
        pg_services = self._docker_compose_cmd(
            ["--profile", "pgsql", "config", "--services"],
            env_file=ROOT / ".env.docker.example",
        )
        self.assertEqual(0, pg_services.returncode, pg_services.stderr)
        svc_set = set(pg_services.stdout.strip().splitlines())
        self.assertEqual({"hwadmin-pg", "hwadmin-redis", "hwadmin-init-pg", "hwadmin-php", "hwadmin-nginx"}, svc_set)
        self.assertNotIn("hwadmin-mysql", svc_set)
        self.assertNotIn("hwadmin-init-mysql", svc_set)

        # 2. --profile mysql 仅包含 MySQL + Redis + PHP + Nginx，零 PG
        my_services = self._docker_compose_cmd(
            ["--profile", "mysql", "config", "--services"],
            env_file=ROOT / ".env.docker.example",
        )
        self.assertEqual(0, my_services.returncode, my_services.stderr)
        my_set = set(my_services.stdout.strip().splitlines())
        self.assertEqual({"hwadmin-mysql", "hwadmin-redis", "hwadmin-init-mysql", "hwadmin-php", "hwadmin-nginx"}, my_set)
        self.assertNotIn("hwadmin-pg", my_set)
        self.assertNotIn("hwadmin-init-pg", my_set)

        # 3. 验证 hwadmin-init-pg 注入参数与 depends_on (hwadmin-pg healthy)
        pg_cfg = self._docker_compose_cmd(
            ["--profile", "pgsql", "config", "--format", "json"],
            env_file=ROOT / ".env.docker.example",
        )
        self.assertEqual(0, pg_cfg.returncode, pg_cfg.stderr)
        cfg_json = json.loads(pg_cfg.stdout)
        init_pg = cfg_json["services"]["hwadmin-init-pg"]
        self.assertEqual("pgsql", init_pg["environment"]["DB_TYPE"])
        self.assertEqual("pgsql", init_pg["environment"]["DB_DRIVER"])
        self.assertEqual("hwadmin-pg", init_pg["environment"]["DB_HOST"])
        self.assertEqual("5432", str(init_pg["environment"]["DB_PORT"]))
        self.assertEqual("service_healthy", init_pg["depends_on"]["hwadmin-pg"]["condition"])
        self.assertEqual("service_healthy", init_pg["depends_on"]["hwadmin-redis"]["condition"])

        # 4. 反例：缺少强口令（分别缺失 DB_PASS 或 REDIS_PASSWORD）必须被 docker compose 明确阻断
        with tempfile.TemporaryDirectory() as td:
            missing_db_pass = Path(td) / ".env.no-db-pass"
            missing_db_pass.write_text("COMPOSE_PROFILES=pgsql\nDB_PASS=\nREDIS_PASSWORD=StrongRedis123!\n", encoding="utf-8")
            bad_db = self._docker_compose_cmd(["--profile", "pgsql", "config"], env_file=missing_db_pass)
            self.assertNotEqual(0, bad_db.returncode)
            self.assertIn("DB_PASS", bad_db.stderr + bad_db.stdout)

            missing_redis_pass = Path(td) / ".env.no-redis-pass"
            missing_redis_pass.write_text("COMPOSE_PROFILES=pgsql\nDB_PASS=StrongDbPass123!\nREDIS_PASSWORD=\n", encoding="utf-8")
            bad_redis = self._docker_compose_cmd(["--profile", "pgsql", "config"], env_file=missing_redis_pass)
            self.assertNotEqual(0, bad_redis.returncode)
            self.assertIn("REDIS_PASSWORD", bad_redis.stderr + bad_redis.stdout)

    def test_port_conflict_and_db_unreachable_and_migration_failure_blocking(self):
        # 1. 端口冲突反例：模拟占用 HTTP_PORT 时，端口绑定检测失败
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as holder:
            holder.bind(("127.0.0.1", 0))
            holder.listen(1)
            busy_port = holder.getsockname()[1]
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as contender:
                with self.assertRaises(OSError):
                    contender.bind(("127.0.0.1", busy_port))

        # 2. 数据库未就绪与迁移失败注入（真实运行 server 安装/迁移命令，验证失败退出非零且不写入 install.lock）
        php_bin = shutil.which("php") or str(Path.home() / ".local/bin/php")
        if not Path(php_bin).is_file() and not shutil.which("php"):
            self.skipTest("PHP CLI required for real migration failure injection")
        if not (ROOT / "server/vendor/autoload.php").is_file():
            self.skipTest("server/vendor required for real migration failure injection")

        with tempfile.TemporaryDirectory(prefix="hwadmin-t02-inj-") as td:
            iso_server = Path(td) / "server"
            shutil.copytree(
                ROOT / "server",
                iso_server,
                ignore=shutil.ignore_patterns(".env", ".env.*", "runtime", "public/admin", "public/uploads", "public/plugins", "tests", "install.lock*", "install.token*"),
            )
            (iso_server / "runtime").mkdir(parents=True, exist_ok=True)
            (iso_server / "config").mkdir(parents=True, exist_ok=True)
            (iso_server / "public/uploads").mkdir(parents=True, exist_ok=True)
            (iso_server / "public/plugins").mkdir(parents=True, exist_ok=True)

            # 反例 A：数据库未就绪（不可达端口 127.0.0.1:59999 的 pgsql）执行 init 链必须失败且不生成 install.lock
            env_bad_db = os.environ.copy()
            env_bad_db.update(
                {
                    "DB_DRIVER": "pgsql",
                    "DB_TYPE": "pgsql",
                    "DB_HOST": "127.0.0.1",
                    "DB_PORT": "59999",
                    "DB_NAME": "hwadmin_missing",
                    "DB_USER": "hwadmin_app",
                    "DB_PASS": "StrongPass123!",
                    "DB_PREFIX": "hw_",
                }
            )
            proc_db = subprocess.run(
                [php_bin, "think", "hw:install", "--username=admin", "--password=StrongAdmin123!", "--skip-if-installed"],
                cwd=iso_server,
                env=env_bad_db,
                capture_output=True,
                text=True,
            )
            self.assertNotEqual(0, proc_db.returncode)
            self.assertFalse((iso_server / "config/install.lock").exists())

            # 反例 B：注入破坏性迁移文件后，init 链（hw:install && migrate:run）必须阻断失败
            bad_mig = iso_server / "database/migrations/20991231235959_inject_broken_migration.php"
            bad_mig.write_text(
                "<?php\nuse think\\migration\\Migrator;\nclass InjectBrokenMigration extends Migrator {\n"
                "  public function up(): void { throw new \\RuntimeException('injected migration failure'); }\n}\n",
                encoding="utf-8",
            )
            env_sqlite = os.environ.copy()
            env_sqlite.update(
                {
                    "DB_DRIVER": "sqlite",
                    "DB_TYPE": "sqlite",
                    "DB_NAME": str(iso_server / "runtime/test.sqlite"),
                    "DB_PREFIX": "hw_",
                }
            )
            proc_mig = subprocess.run(
                [php_bin, "think", "hw:install", "--username=admin", "--password=StrongAdmin123!", "--skip-if-installed"],
                cwd=iso_server,
                env=env_sqlite,
                capture_output=True,
                text=True,
            )
            self.assertNotEqual(0, proc_mig.returncode)
            self.assertFalse((iso_server / "config/install.lock").exists())


if __name__ == "__main__":
    unittest.main()
