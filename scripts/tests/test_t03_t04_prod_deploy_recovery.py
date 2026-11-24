import argparse
import contextlib
import hashlib
import http.server
import importlib.util
import io
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tarfile
import tempfile
import threading
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

SCRIPTS = Path(__file__).resolve().parents[1]
REPO_ROOT = SCRIPTS.parent
sys.path.insert(0, str(SCRIPTS))

spec = importlib.util.spec_from_file_location("hw_deploy", SCRIPTS / "hw-deploy.py")
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)

build_spec = importlib.util.spec_from_file_location("build_release", SCRIPTS / "build-release.py")
build_release = importlib.util.module_from_spec(build_spec)
build_spec.loader.exec_module(build_release)


def has_php_runtime() -> bool:
    if deploy.has_native_php():
        return True
    if os.name == "nt" and shutil.which("wsl.exe"):
        p = subprocess.run(
            ["wsl.exe", "-e", "bash", "-c", 'export PATH="$HOME/.local/bin:$PATH"; command -v php >/dev/null 2>&1'],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        return p.returncode == 0
    return False


class _MockNginxHandler(http.server.BaseHTTPRequestHandler):
    server_header_value = "nginx/1.26.2"
    ping_code = 1
    info_code = 401
    admin_status = 200

    def log_message(self, format, *args):
        return

    def send_version_header(self):
        pass

    def _reply(self, status: int, body: bytes, content_type: str = "application/json"):
        self.send_response(status)
        self.send_header("Server", self.server_header_value)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path.startswith("/api/index/ping"):
            self._reply(200, json.dumps({"code": self.ping_code, "msg": "pong"}).encode("utf-8"))
        elif self.path.startswith("/adminapi/index/info"):
            self._reply(401 if self.info_code == 401 else 200, json.dumps({"code": self.info_code}).encode("utf-8"))
        elif self.path.startswith("/admin/"):
            self._reply(self.admin_status, b"<html><body>Admin</body></html>", "text/html")
        else:
            self._reply(404, b"not found", "text/plain")


@contextlib.contextmanager
def start_mock_http_server(server_header: str = "nginx/1.26.2", ping_code: int = 1, info_code: int = 401):
    class CustomHandler(_MockNginxHandler):
        server_header_value = server_header
        pass

    CustomHandler.ping_code = ping_code
    CustomHandler.info_code = info_code
    httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), CustomHandler)
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, daemon=True)
    thread.start()
    try:
        yield port
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=2)


class T03T04ProdDeployRecoveryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="hwadmin-t03-t04-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "release").mkdir(parents=True, exist_ok=True)
        (self.root / "docker/php").mkdir(parents=True, exist_ok=True)
        (self.root / "docker/php/Dockerfile").write_text(deploy.FALLBACK_PHP_DOCKERFILE, encoding="utf-8")

    def build_signed_deploy_zip(self, filename: str = "hwadmin-release-v1.4.2.zip", extra_members: dict = None) -> Path:
        zip_path = self.root / "release" / filename
        members = {
            "think": b"<?php echo 'think';",
            "version.json": b'{"version":"1.4.2"}',
            "public/index.php": b"<?php echo 'ok';",
            "public/admin/index.html": b"<!doctype html><html></html>",
            "app/index/view/portal/index.html": b"<html>portal v1</html>",
            "database/migrations/20261026000001_create_core_tables.php": b"<?php class CreateCoreTables { public function up(): void {} }",
        }
        if extra_members:
            members.update(extra_members)
        manifest = {
            "artifact_type": "deploy",
            "framework_version": "1.4.2",
            "required_files": ["think", "public/index.php", "public/admin/index.html"],
            "files": {name: hashlib.sha256(data).hexdigest() for name, data in members.items()},
        }
        with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
            for name, data in members.items():
                zf.writestr(name, data)
            zf.writestr("artifact-manifest.json", json.dumps(manifest))
        Path(str(zip_path) + ".sha256").write_text(
            hashlib.sha256(zip_path.read_bytes()).hexdigest() + f" *{zip_path.name}\n",
            encoding="utf-8",
        )
        return zip_path

    def make_tls_pair(self):
        cert = self.root / "certs/fullchain.pem"
        key = self.root / "certs/privkey.pem"
        cert.parent.mkdir(parents=True, exist_ok=True)
        cert.write_text("-----BEGIN CERTIFICATE-----\nMIIB_TEST_CERT\n-----END CERTIFICATE-----\n", encoding="utf-8")
        key.write_text("-----BEGIN PRIVATE KEY-----\nMIIE_TEST_KEY\n-----END PRIVATE KEY-----\n", encoding="utf-8")
        return cert, key

    def test_baota_nginx_apply_explicitly_rejected_with_manual_steps(self):
        self.build_signed_deploy_zip()
        args = argparse.Namespace(target="baota-nginx", dry_run=False, json=True, artifact=None)
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_apply(args, self.root)
        self.assertNotEqual(0, rc)
        payload = json.loads(buf.getvalue())
        self.assertEqual("fail", payload["status"])
        self.assertEqual("adapter", payload["step"])
        self.assertGreaterEqual(len(payload.get("manual_steps", [])), 4)

    def test_container_database_passwords_never_enter_process_arguments(self):
        ctx = {'compose_cmd': ['docker', 'compose'], 'compose_file': self.root / 'compose.yml',
               'env_file': None, 'cwd': self.root}
        for driver, host, key in (('mysql', 'hwadmin-mysql', 'MYSQL_PWD'),
                                  ('pgsql', 'hwadmin-postgres', 'PGPASSWORD')):
            config = {'driver': driver, 'host': host, 'port': '3306' if driver == 'mysql' else '5432',
                      'user': 'app', 'name': 'app', 'pass': 'never-print-this-password'}
            for builder in (deploy.build_db_dump_command, deploy.build_db_restore_command):
                command, environment = builder(config, ctx)
                self.assertNotIn(config['pass'], ' '.join(command))
                self.assertEqual(config['pass'], environment[key])
                self.assertIn(key, command)

    def test_running_but_unhealthy_container_blocks_production_probe(self):
        ctx = {'compose_cmd': ['docker', 'compose'], 'compose_file': self.root / 'compose.yml',
               'env_file': None, 'cwd': self.root}
        states = [{'Service': name, 'State': 'running', 'Health': 'unhealthy' if name == 'hwadmin-mysql' else ''}
                  for name in ('hwadmin-nginx', 'hwadmin-php', 'hwadmin-redis', 'hwadmin-mysql')]
        with patch.object(deploy, 'run_subprocess', return_value=(0, json.dumps(states).encode(), b'')):
            ok, _ = deploy.run_production_health_probe(ctx, 'mysql', '8080')
        self.assertFalse(ok)

    def test_root_cli_success_cannot_hide_fpm_worker_permission_failure(self):
        ctx = {'compose_cmd': ['docker', 'compose'], 'compose_file': self.root / 'compose.yml',
               'env_file': None, 'cwd': self.root}
        states = [{'Service': name, 'State': 'running', 'Health': ''}
                  for name in ('hwadmin-nginx', 'hwadmin-php', 'hwadmin-redis', 'hwadmin-mysql')]
        def run(command, **kwargs):
            if '--format' in command:
                return 0, json.dumps(states).encode(), b''
            if '--user' in command:
                return 1, b'', b'permission denied'
            return 0, b'php-fpm: master process', b''
        with patch.object(deploy, 'run_subprocess', side_effect=run):
            ok, error = deploy.run_production_health_probe(ctx, 'mysql', '8080')
        self.assertFalse(ok)
        self.assertIn('www-data', error)

    def test_docker_apply_first_deploy_mysql_with_tls_and_production_probe(self):
        zip_path = self.build_signed_deploy_zip()
        cert, key = self.make_tls_pair()
        target_dir = self.root / "prod-mysql"
        backup_dir = self.root / "bak-mysql-1"
        recorded_cmds = []

        with start_mock_http_server(server_header="nginx/1.26.2") as http_port:
            def fake_run(cmd, cwd=None, env=None, stdin_bytes=None, timeout=300):
                recorded_cmds.append(list(cmd))
                joined = " ".join(str(x) for x in cmd)
                if cmd[:2] == ["docker", "info"]:
                    return 0, b"Server Version: 26.0\n", b""
                if cmd[:3] == ["docker", "compose", "version"]:
                    return 0, b"Docker Compose version v2.29.0\n", b""
                if " ps " in f" {joined} " and "--format" in cmd:
                    ps_rows = [
                        {"Service": "hwadmin-nginx", "State": "running", "Health": ""},
                        {"Service": "hwadmin-php", "State": "running", "Health": ""},
                        {"Service": "hwadmin-redis", "State": "running", "Health": "healthy"},
                        {"Service": "hwadmin-mysql", "State": "running", "Health": "healthy"},
                    ]
                    return 0, json.dumps(ps_rows).encode("utf-8"), b""
                if " exec " in f" {joined} " and "ps" in cmd and "-eo" in cmd:
                    return 0, b"COMMAND\nphp-fpm: master process (/usr/local/etc/php-fpm.conf)\n", b""
                if "hw:install" in joined:
                    (target_dir / "config").mkdir(parents=True, exist_ok=True)
                    (target_dir / "config/install.lock").write_text("installed", encoding="utf-8")
                return 0, b"ok\n", b""

            args = argparse.Namespace(
                target="docker",
                dry_run=False,
                json=True,
                artifact=str(zip_path),
                target_dir=str(target_dir),
                backup_dir=str(backup_dir),
                db_driver="mysql",
                domain="app.example.com",
                https_cert=str(cert),
                https_key=str(key),
                http_port=str(http_port),
                https_port=str(http_port),
                env_file=None,
            )
            with patch.object(shutil, "which", return_value="/usr/bin/docker"), \
                 patch.object(deploy, "run_subprocess", side_effect=fake_run), \
                 patch.object(deploy, "http_get_response", side_effect=lambda url, **kw: (
                     200 if "/api/index/ping" in url else (401 if "/adminapi/" in url else 200),
                     {"server": "nginx/1.26.2"},
                     b'{"code":1}' if "/api/index/ping" in url else (b'{"code":401}' if "/adminapi/" in url else b"<html></html>"),
                 )):
                buf = io.StringIO()
                with contextlib.redirect_stdout(buf):
                    rc = deploy.cmd_apply(args, self.root)

        self.assertEqual(0, rc, buf.getvalue())
        res = json.loads(buf.getvalue())
        self.assertEqual("success", res["status"])
        self.assertEqual("mysql", res["db_driver"])
        self.assertTrue(res["production_runtime"]["builtin_dev_server_rejected"])
        self.assertFalse(res["g07_public_verified"])
        self.assertTrue(res["external_pending_steps"])

        # 验证生成的 .env / .env.docker / initial-admin.txt 与 Compose / Nginx 配置
        env_text = (target_dir / ".env").read_text(encoding="utf-8")
        self.assertIn("APP_DEBUG = false", env_text)
        self.assertIn("DB_DRIVER = mysql", env_text)
        self.assertNotIn("CHANGE_ME", env_text)
        self.assertTrue((target_dir / "runtime/initial-admin.txt").is_file())
        if os.name != "nt":
            self.assertEqual(0o600, (target_dir / ".env").stat().st_mode & 0o777)
            self.assertEqual(0o600, (target_dir / ".env.docker").stat().st_mode & 0o777)

        compose_text = (target_dir / "docker-compose.prod.yml").read_text(encoding="utf-8")
        self.assertIn("hwadmin-mysql:", compose_text)
        self.assertNotIn("hwadmin-postgres:", compose_text)
        self.assertIn("443", compose_text)

        nginx_text = (target_dir / ".hw-deploy/nginx/default.conf").read_text(encoding="utf-8")
        self.assertIn("server_name app.example.com;", nginx_text)
        self.assertIn("listen 443 ssl;", nginx_text)
        self.assertIn("fastcgi_pass hwadmin-php:9000;", nginx_text)

        flat_cmds = [" ".join(c) for c in recorded_cmds]
        self.assertTrue(any("config -q" in c for c in flat_cmds))
        self.assertTrue(any("up -d --build" in c for c in flat_cmds))
        self.assertTrue(any("think migrate:run" in c for c in flat_cmds))
        self.assertTrue(any("think hw:menu:sync" in c for c in flat_cmds))

    def test_docker_apply_pgsql_zero_mysql_and_upgrade_preserves_env_and_uploads(self):
        zip_v1 = self.build_signed_deploy_zip("hwadmin-release-v1.4.2.zip")
        zip_v2 = self.build_signed_deploy_zip(
            "hwadmin-release-v1.4.3.zip",
            extra_members={"app/index/view/portal/index.html": b"<html>portal v2</html>"},
        )
        target_dir = self.root / "prod-pgsql"
        bak1 = self.root / "bak-pg-1"
        bak2 = self.root / "bak-pg-2"
        pg_dump_calls = []

        with start_mock_http_server(server_header="nginx/1.26.2") as http_port:
            def fake_run(cmd, cwd=None, env=None, stdin_bytes=None, timeout=300):
                joined = " ".join(str(x) for x in cmd)
                if cmd[:2] == ["docker", "info"]:
                    return 0, b"ok\n", b""
                if cmd[:3] == ["docker", "compose", "version"]:
                    return 0, b"v2\n", b""
                if "pg_dump" in joined:
                    pg_dump_calls.append((list(cmd), dict(env or {})))
                    return 0, b"-- PostgreSQL dump\nCREATE TABLE hw_test(id INT);\n", b""
                if " ps " in f" {joined} " and "--format" in cmd:
                    ps_rows = [
                        {"Service": "hwadmin-nginx", "State": "running"},
                        {"Service": "hwadmin-php", "State": "running"},
                        {"Service": "hwadmin-redis", "State": "running"},
                        {"Service": "hwadmin-postgres", "State": "running"},
                    ]
                    return 0, json.dumps(ps_rows).encode("utf-8"), b""
                if " exec " in f" {joined} " and "-eo" in cmd:
                    return 0, b"php-fpm: master process\n", b""
                if "hw:install" in joined:
                    (target_dir / "config").mkdir(parents=True, exist_ok=True)
                    (target_dir / "config/install.lock").write_text("installed", encoding="utf-8")
                return 0, b"ok\n", b""

            args1 = argparse.Namespace(
                target="docker", dry_run=False, json=True,
                artifact=str(zip_v1), target_dir=str(target_dir), backup_dir=str(bak1),
                db_driver="pgsql", domain=None, https_cert=None, https_key=None,
                http_port=str(http_port), https_port=None, env_file=None,
            )
            with patch.object(shutil, "which", return_value="/usr/bin/docker"), \
                 patch.object(deploy, "run_subprocess", side_effect=fake_run):
                buf1 = io.StringIO()
                with contextlib.redirect_stdout(buf1):
                    rc1 = deploy.cmd_apply(args1, self.root)

            self.assertEqual(0, rc1, buf1.getvalue())
            res1 = json.loads(buf1.getvalue())
            self.assertEqual("pgsql", res1["db_driver"])
            self.assertFalse(res1["g07_public_verified"])
            self.assertGreaterEqual(len(res1["external_pending_steps"]), 2)

            compose_text = (target_dir / "docker-compose.prod.yml").read_text(encoding="utf-8")
            self.assertIn("hwadmin-postgres:", compose_text)
            self.assertNotIn("hwadmin-mysql:", compose_text)

            # 写入业务自定义标记与附件
            original_env = (target_dir / ".env").read_text(encoding="utf-8") + "CUSTOM_FLAG = keep_me\n"
            (target_dir / ".env").write_text(original_env, encoding="utf-8")
            upload_file = target_dir / "public/uploads/contracts/2026/a.pdf"
            upload_file.parent.mkdir(parents=True, exist_ok=True)
            upload_file.write_bytes(b"%PDF-1.4-CONTRACT")

            args2 = argparse.Namespace(
                target="docker", dry_run=False, json=True,
                artifact=str(zip_v2), target_dir=str(target_dir), backup_dir=str(bak2),
                db_driver="pgsql", domain=None, https_cert=None, https_key=None,
                http_port=str(http_port), https_port=None, env_file=None,
            )
            with patch.object(shutil, "which", return_value="/usr/bin/docker"), \
                 patch.object(deploy, "run_subprocess", side_effect=fake_run):
                buf2 = io.StringIO()
                with contextlib.redirect_stdout(buf2):
                    rc2 = deploy.cmd_apply(args2, self.root)

        self.assertEqual(0, rc2, buf2.getvalue())
        res2 = json.loads(buf2.getvalue())
        self.assertTrue(res2["is_upgrade"])
        self.assertIn("CUSTOM_FLAG = keep_me", (target_dir / ".env").read_text(encoding="utf-8"))
        self.assertEqual(b"%PDF-1.4-CONTRACT", upload_file.read_bytes())
        self.assertEqual(1, len(pg_dump_calls))
        # 验证备份清单中包含 pg dump 与 uploads tar.gz + uploads-manifest.json
        b_manifest = json.loads((bak2 / "backup-manifest.json").read_text(encoding="utf-8"))
        self.assertEqual("pgsql", b_manifest["db_driver"])
        self.assertEqual("data/database.sql", b_manifest["data_backups"]["database_dump"])
        self.assertEqual("data/public_uploads.tar.gz", b_manifest["data_backups"]["uploads_archive"])
        self.assertEqual("data/uploads-manifest.json", b_manifest["data_backups"]["uploads_manifest"])
        self.assertTrue((bak2 / "data/public_uploads.tar.gz").is_file())

    def test_production_probe_explicitly_rejects_php_builtin_dev_server(self):
        compose_ctx = {
            "compose_cmd": ["docker", "compose"],
            "compose_file": self.root / "docker-compose.prod.yml",
            "env_file": None,
            "cwd": self.root,
        }
        # 场景 A: 目标端口响应头为 PHP Development Server
        with start_mock_http_server(server_header="PHP/8.4.23 Development Server") as port:
            def fake_run_ok(cmd, cwd=None, env=None, stdin_bytes=None, timeout=300):
                if "--format" in cmd and "ps" in cmd:
                    return 0, json.dumps([
                        {"Service": "hwadmin-nginx", "State": "running"},
                        {"Service": "hwadmin-php", "State": "running"},
                        {"Service": "hwadmin-redis", "State": "running"},
                        {"Service": "hwadmin-mysql", "State": "running"},
                    ]).encode("utf-8"), b""
                if "-eo" in cmd:
                    return 0, b"php-fpm: master process\n", b""
                return 0, b"", b""

            with patch.object(deploy, "run_subprocess", side_effect=fake_run_ok):
                ok, msg = deploy.run_production_health_probe(compose_ctx, "mysql", str(port))
            self.assertFalse(ok)
            self.assertIn("内置开发服务器", str(msg))

        # 场景 B: 容器内进程检测出 php -S
        with start_mock_http_server(server_header="nginx/1.26.2") as port:
            def fake_run_php_s(cmd, cwd=None, env=None, stdin_bytes=None, timeout=300):
                if "--format" in cmd and "ps" in cmd:
                    return 0, json.dumps([
                        {"Service": "hwadmin-nginx", "State": "running"},
                        {"Service": "hwadmin-php", "State": "running"},
                        {"Service": "hwadmin-redis", "State": "running"},
                        {"Service": "hwadmin-mysql", "State": "running"},
                    ]).encode("utf-8"), b""
                if "-eo" in cmd:
                    return 0, b"php -S 0.0.0.0:8080 -t public public/router.php\n", b""
                return 0, b"", b""

            with patch.object(deploy, "run_subprocess", side_effect=fake_run_php_s):
                ok, msg = deploy.run_production_health_probe(compose_ctx, "mysql", str(port))
            self.assertFalse(ok)
            self.assertIn("php -S", str(msg))

    def test_fault_injections_migration_fail_backup_fail_corrupt_archive_missing_uploads(self):
        zip_path = self.build_signed_deploy_zip()
        target_dir = self.root / "fault-target"
        target_dir.mkdir(parents=True, exist_ok=True)
        (target_dir / ".env").write_text("APP_DEBUG=false\nDB_DRIVER=mysql\nDB_HOST=hwadmin-mysql\nDB_PORT=3306\nDB_NAME=hwadmin\nDB_USER=hwadmin_app\nDB_PASS=OldSecretPass\n", encoding="utf-8")
        (target_dir / "config").mkdir(parents=True, exist_ok=True)
        (target_dir / "config/install.lock").write_text("locked", encoding="utf-8")
        (target_dir / "public/uploads").mkdir(parents=True, exist_ok=True)
        (target_dir / "public/uploads/live.txt").write_text("LIVE_UPLOAD", encoding="utf-8")
        (target_dir / "sentinel.txt").write_text("LIVE_CODE", encoding="utf-8")

        # 1) 备份失败（mysqldump 返回非零）-> apply 中止，绝不篡改目标
        def fake_dump_fail(cmd, cwd=None, env=None, stdin_bytes=None, timeout=300):
            joined = " ".join(str(x) for x in cmd)
            if cmd[:2] == ["docker", "info"]:
                return 0, b"ok\n", b""
            if cmd[:3] == ["docker", "compose", "version"]:
                return 0, b"v2\n", b""
            if "mysqldump" in joined:
                return 2, b"", b"mysqldump connection refused"
            return 0, b"ok\n", b""

        bak_fail = self.root / "bak-fail"
        args_dump_fail = argparse.Namespace(
            target="docker", dry_run=False, json=True,
            artifact=str(zip_path), target_dir=str(target_dir), backup_dir=str(bak_fail),
            db_driver="mysql", domain=None, https_cert=None, https_key=None,
            http_port="18080", https_port=None, env_file=None,
        )
        with patch.object(shutil, "which", return_value="/usr/bin/docker"), \
             patch.object(deploy, "run_subprocess", side_effect=fake_dump_fail):
            buf = io.StringIO()
            with contextlib.redirect_stdout(buf):
                rc = deploy.cmd_apply(args_dump_fail, self.root)
        self.assertNotEqual(0, rc)
        self.assertEqual("backup", json.loads(buf.getvalue())["step"])
        self.assertEqual("LIVE_CODE", (target_dir / "sentinel.txt").read_text(encoding="utf-8"))
        self.assertEqual("LIVE_UPLOAD", (target_dir / "public/uploads/live.txt").read_text(encoding="utf-8"))

        # 2) 迁移失败 -> apply 返回非零且提供 recovery_point，不删有效备份
        def fake_migrate_fail(cmd, cwd=None, env=None, stdin_bytes=None, timeout=300):
            joined = " ".join(str(x) for x in cmd)
            if cmd[:2] == ["docker", "info"]:
                return 0, b"ok\n", b""
            if cmd[:3] == ["docker", "compose", "version"]:
                return 0, b"v2\n", b""
            if "mysqldump" in joined:
                return 0, b"CREATE TABLE hw_x(id INT);\n", b""
            if "migrate:run" in joined:
                return 1, b"", b"SQLSTATE[42S01]: Base table already exists"
            return 0, b"ok\n", b""

        bak_mig = self.root / "bak-mig-fail"
        args_mig_fail = argparse.Namespace(
            target="docker", dry_run=False, json=True,
            artifact=str(zip_path), target_dir=str(target_dir), backup_dir=str(bak_mig),
            db_driver="mysql", domain=None, https_cert=None, https_key=None,
            http_port="18080", https_port=None, env_file=None,
        )
        with patch.object(shutil, "which", return_value="/usr/bin/docker"), \
             patch.object(deploy, "run_subprocess", side_effect=fake_migrate_fail):
            buf = io.StringIO()
            with contextlib.redirect_stdout(buf):
                rc = deploy.cmd_apply(args_mig_fail, self.root)
        self.assertNotEqual(0, rc)
        mig_fail_res = json.loads(buf.getvalue())
        self.assertEqual("migrate", mig_fail_res["step"])
        self.assertEqual(str(bak_mig.resolve()), mig_fail_res["recovery_point"])
        self.assertTrue((bak_mig / "backup-manifest.json").is_file())

        # 3) 重复执行到已存在非空 backup_dir -> 拒绝覆盖恢复点
        args_dup = argparse.Namespace(
            target="docker", dry_run=False, json=True,
            artifact=str(zip_path), target_dir=str(target_dir), backup_dir=str(bak_mig),
            db_driver="mysql", domain=None, https_cert=None, https_key=None,
            http_port="18080", https_port=None, env_file=None,
        )
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_apply(args_dup, self.root)
        self.assertNotEqual(0, rc)
        self.assertEqual("preflight", json.loads(buf.getvalue())["step"])

        # 4) 损坏 SQL 转储哈希 -> rollback --mode=full 拦截且不修改当前目标
        bad_sql_bak = self.root / "bak-bad-sql"
        shutil.copytree(bak_mig, bad_sql_bak)
        (bad_sql_bak / "data/database.sql").write_bytes(b"TAMPERED SQL")
        (target_dir / "public/uploads/live.txt").write_text("CURRENT_KEEP", encoding="utf-8")
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_rollback(
                argparse.Namespace(backup_dir=str(bad_sql_bak), target_dir=str(target_dir), target="docker", mode="full", json=True),
                self.root,
            )
        self.assertNotEqual(0, rc)
        self.assertEqual("CURRENT_KEEP", (target_dir / "public/uploads/live.txt").read_text(encoding="utf-8"))

        # 5) 缺附件备份（清单声明有附件但删掉 public_uploads.tar.gz 并重签 manifest）-> rollback 拦截
        missing_up_bak = self.root / "bak-missing-uploads"
        shutil.copytree(bak_mig, missing_up_bak)
        (missing_up_bak / "data/public_uploads.tar.gz").unlink()
        m_obj = json.loads((missing_up_bak / "backup-manifest.json").read_text(encoding="utf-8"))
        m_obj["data_backups"]["uploads_archive"] = None
        m_obj["checksums"].pop("data/public_uploads.tar.gz", None)
        (missing_up_bak / "backup-manifest.json").write_text(json.dumps(m_obj), encoding="utf-8")
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_rollback(
                argparse.Namespace(backup_dir=str(missing_up_bak), target_dir=str(target_dir), target="docker", mode="full", json=True),
                self.root,
            )
        self.assertNotEqual(0, rc)
        self.assertEqual("CURRENT_KEEP", (target_dir / "public/uploads/live.txt").read_text(encoding="utf-8"))

        # 6) 正常 full 回滚 MySQL（验证恢复耗时、恢复点变化及数据还原，且不泄露密码到 CLI 参数）
        restore_cmds = []

        def fake_mysql_restore(cmd, cwd=None, env=None, stdin_bytes=None, timeout=300):
            restore_cmds.append((list(cmd), dict(env or {}), stdin_bytes))
            return 0, b"", b""

        def fake_which(bin_name):
            if bin_name in {"mysql", "mysqldump", "psql", "pg_dump"}:
                return f"/usr/bin/{bin_name}"
            return None

        with patch.object(shutil, "which", side_effect=fake_which), \
             patch.object(deploy, "run_subprocess", side_effect=fake_mysql_restore):
            buf = io.StringIO()
            with contextlib.redirect_stdout(buf):
                rc = deploy.cmd_rollback(
                    argparse.Namespace(backup_dir=str(bak_mig), target_dir=str(target_dir), target="docker", mode="full", json=True),
                    self.root,
                )
        self.assertEqual(0, rc, buf.getvalue())
        rb_res = json.loads(buf.getvalue())
        self.assertEqual("success", rb_res["status"])
        self.assertIn("recovery_duration_seconds", rb_res)
        self.assertEqual(bak_mig.name, rb_res["recovery_point_change"]["restored_backup_id"])
        self.assertEqual("LIVE_UPLOAD", (target_dir / "public/uploads/live.txt").read_text(encoding="utf-8"))
        self.assertEqual(1, len(restore_cmds))
        self.assertNotIn("OldSecretPass", " ".join(restore_cmds[0][0]))
        self.assertEqual("OldSecretPass", restore_cmds[0][1]["MYSQL_PWD"])
        self.assertIn(b"CREATE TABLE hw_x", restore_cmds[0][2])

    @unittest.skipUnless(has_php_runtime(), "PHP runtime required for end-to-end Phinx migration & business verification")
    def test_destructive_migration_blocks_code_only_and_full_rollback_restores_business_state(self):
        # 构造包含真实 server/ 内核（复用宿主 vendor）的 deploy ZIP v1 与 v2/v3
        server_src = REPO_ROOT / "server"
        if not (server_src / "vendor/autoload.php").is_file():
            self.skipTest("server/vendor not installed")

        # 让 self.root/server/vendor 指向宿主已安装的 vendor（WSL 下优先通过软链接/复制隔离）
        (self.root / "server").mkdir(parents=True, exist_ok=True)
        vendor_link = self.root / "server/vendor"
        if not vendor_link.exists():
            try:
                os.symlink((server_src / "vendor").resolve(), vendor_link, target_is_directory=True)
            except OSError:
                shutil.copytree(server_src / "vendor", vendor_link)

        def pack_server_zip(zip_name: str, extra_files: dict = None) -> Path:
            zp = self.root / "release" / zip_name
            files_map = {}
            with zipfile.ZipFile(zp, "w", compression=zipfile.ZIP_DEFLATED) as zf:
                for sub in ("app", "core", "config", "database", "public", "route"):
                    sub_dir = server_src / sub
                    if not sub_dir.is_dir():
                        continue
                    for p in sub_dir.rglob("*"):
                        if not p.is_file() or p.is_symlink():
                            continue
                        rel = p.relative_to(server_src).as_posix()
                        if rel in {"config/install.lock", "config/install.token"} or rel.startswith("public/uploads/") or rel.startswith("public/storage/"):
                            continue
                        if extra_files and rel in extra_files:
                            continue
                        data = p.read_bytes()
                        zf.writestr(rel, data)
                        files_map[rel] = hashlib.sha256(data).hexdigest()
                for special in ("think", "composer.json", ".example.env"):
                    sp = server_src / special
                    if sp.is_file():
                        data = sp.read_bytes()
                        zf.writestr(special, data)
                        files_map[special] = hashlib.sha256(data).hexdigest()
                for rel, data in (extra_files or {}).items():
                    zf.writestr(rel, data)
                    files_map[rel] = hashlib.sha256(data).hexdigest()
                manifest = {
                    "artifact_type": "deploy",
                    "framework_version": "1.4.2",
                    "required_files": ["think", "public/index.php", "app/adminapi/controller/Index.php"],
                    "files": files_map,
                }
                zf.writestr("artifact-manifest.json", json.dumps(manifest))
            Path(str(zp) + ".sha256").write_text(
                hashlib.sha256(zp.read_bytes()).hexdigest() + f" *{zp.name}\n",
                encoding="utf-8",
            )
            return zp

        v1_zip = pack_server_zip("hwadmin-release-v1.4.2.zip")
        target_dir = self.root / "e2e-target"
        bak_init = self.root / "e2e-bak-init"

        # 1. 部署 v1（真实执行 hw:install, migrate:run, hw:menu:sync, HTTP 探针）
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_apply(
                argparse.Namespace(
                    target="local", dry_run=False, json=True,
                    artifact=str(v1_zip), target_dir=str(target_dir), backup_dir=str(bak_init), env_file=None,
                ),
                self.root,
            )
        self.assertEqual(0, rc, buf.getvalue())

        # 2. 写入真实业务记录、RBAC 受限管理员、附件并验证初始凭据文件存在
        cred_text = (target_dir / "runtime/initial-admin.txt").read_text(encoding="utf-8")
        init_pw = [ln.split("=", 1)[1] for ln in cred_text.splitlines() if ln.startswith("password=")][0]
        db_file = target_dir / "runtime/dev.sqlite"
        with contextlib.closing(sqlite3.connect(str(db_file))) as conn:
            conn.execute("UPDATE hw_admin SET nickname='v1_business_admin' WHERE id=1")
            conn.execute("INSERT INTO hw_config (name, title, value, create_time, update_time) VALUES ('t04_biz_key', 'T04业务键', 'v1_value', 1700000000, 1700000000)")
            conn.execute("INSERT INTO hw_auth_group (id, pid, name, rules, status, create_time, update_time) VALUES (2, 0, 'T04审计员', '1', 1, 1700000000, 1700000000)")
            conn.commit()
        up_file = target_dir / "public/uploads/docs/v1_proof.txt"
        up_file.parent.mkdir(parents=True, exist_ok=True)
        up_file.write_text("V1_UPLOAD_PROOF", encoding="utf-8")

        # 3. 升级 v2（仅包含新增列的非破坏性迁移，down() 才含 removeColumn）-> 允许 code-only 回滚
        additive_mig = b"""<?php
declare(strict_types=1);
use think\\migration\\Migrator;
class AddT04SafeNote extends Migrator {
    public function up(): void {
        $this->table('config')->addColumn('t04_safe_note', 'string', ['limit' => 64, 'default' => ''])->update();
    }
    public function down(): void {
        $t = $this->table('config');
        if ($t->hasColumn('t04_safe_note')) { $t->removeColumn('t04_safe_note')->update(); }
    }
}
"""
        v2_zip = pack_server_zip(
            "hwadmin-release-v1.4.3.zip",
            extra_files={
                "database/migrations/20261115000088_add_t04_safe_note.php": additive_mig,
                "app/index/view/portal/index.html": b"<html>portal v2</html>",
            },
        )
        bak_v1 = self.root / "e2e-bak-v1"
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_apply(
                argparse.Namespace(
                    target="local", dry_run=False, json=True,
                    artifact=str(v2_zip), target_dir=str(target_dir), backup_dir=str(bak_v1), env_file=None,
                ),
                self.root,
            )
        self.assertEqual(0, rc, buf.getvalue())

        # 验证非破坏性迁移下 code-only 被放行并还原门户文件
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_rollback(
                argparse.Namespace(backup_dir=str(bak_v1), target_dir=str(target_dir), target="local", mode="code-only", json=True),
                self.root,
            )
        self.assertEqual(0, rc, buf.getvalue())
        self.assertNotIn("portal v2", (target_dir / "app/index/view/portal/index.html").read_text(encoding="utf-8"))

        # 4. 升级 v3（包含破坏性 removeColumn 迁移且已执行 migrate:run，并覆盖附件与篡改业务值）
        destructive_mig = b"""<?php
declare(strict_types=1);
use think\\migration\\Migrator;
class DropT04ColumnDestructive extends Migrator {
    public function up(): void {
        $t = $this->table('config');
        if ($t->hasColumn('t04_safe_note')) {
            $t->removeColumn('t04_safe_note')->update();
        }
    }
}
"""
        # 先写入 v2 的迁移与 v3 的破坏性迁移，使 v3 执行后 config 表实际发生破坏性结构变更
        v3_zip = pack_server_zip(
            "hwadmin-release-v1.4.4.zip",
            extra_files={
                "database/migrations/20261115000088_add_t04_safe_note.php": additive_mig,
                "database/migrations/20261115000099_drop_t04_column_destructive.php": destructive_mig,
                "app/index/view/portal/index.html": b"<html>portal v3 destructive</html>",
            },
        )
        bak_v2 = self.root / "e2e-bak-v2"
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_apply(
                argparse.Namespace(
                    target="local", dry_run=False, json=True,
                    artifact=str(v3_zip), target_dir=str(target_dir), backup_dir=str(bak_v2), env_file=None,
                ),
                self.root,
            )
        self.assertEqual(0, rc, buf.getvalue())

        # 模拟 v3 期间数据与附件变化
        with contextlib.closing(sqlite3.connect(str(db_file))) as conn:
            conn.execute("UPDATE hw_config SET value='v3_corrupted_value' WHERE name='t04_biz_key'")
            conn.commit()
        up_file.write_text("V3_OVERWRITTEN", encoding="utf-8")
        extra_up = target_dir / "public/uploads/docs/v3_extra.txt"
        extra_up.write_text("SHOULD_BE_REMOVED_ON_FULL", encoding="utf-8")

        # 5. 尝试对 bak_v1 执行 code-only 回滚 -> 必须因破坏性迁移被拒绝，且代码/数据不变
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_rollback(
                argparse.Namespace(backup_dir=str(bak_v1), target_dir=str(target_dir), target="local", mode="code-only", json=True),
                self.root,
            )
        self.assertNotEqual(0, rc)
        block_res = json.loads(buf.getvalue())
        self.assertEqual("schema_compatibility", block_res["step"])
        self.assertIn("removeColumn", block_res["error"])
        self.assertIn("portal v3 destructive", (target_dir / "app/index/view/portal/index.html").read_text(encoding="utf-8"))

        # 6. 执行 --mode=full 回滚到 bak_v1 -> 恢复代码、SQLite 快照、权限与附件
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = deploy.cmd_rollback(
                argparse.Namespace(backup_dir=str(bak_v1), target_dir=str(target_dir), target="local", mode="full", json=True),
                self.root,
            )
        self.assertEqual(0, rc, buf.getvalue())
        full_res = json.loads(buf.getvalue())
        self.assertEqual("success", full_res["status"])
        self.assertIn("recovery_duration_seconds", full_res)
        self.assertEqual(bak_v1.name, full_res["recovery_point_change"]["restored_backup_id"])
        self.assertNotIn("portal v3 destructive", (target_dir / "app/index/view/portal/index.html").read_text(encoding="utf-8"))
        self.assertEqual("V1_UPLOAD_PROOF", up_file.read_text(encoding="utf-8"))
        self.assertFalse(extra_up.exists())

        # 7. 在恢复后用真实 PHP 运行时校验：登录密码校验、业务记录、RBAC 角色权限与后续写入
        php_check = (
            "require 'vendor/autoload.php'; "
            "$app = new think\\App(__DIR__); $app->initialize(); "
            "$admin = think\\facade\\Db::name('admin')->where('id', 1)->find(); "
            "if (!$admin || $admin['nickname'] !== 'v1_business_admin') { fwrite(STDERR, 'admin mismatch'); exit(2); } "
            "if (!core\\auth\\PasswordHasher::verify($argv[1], $admin['password'])) { fwrite(STDERR, 'login password verify failed'); exit(3); } "
            "$cfg = think\\facade\\Db::name('config')->where('name', 't04_biz_key')->find(); "
            "if (!$cfg || $cfg['value'] !== 'v1_value') { fwrite(STDERR, 'biz record mismatch'); exit(4); } "
            "$grp = think\\facade\\Db::name('auth_group')->where('id', 2)->find(); "
            "if (!$grp || $grp['name'] !== 'T04审计员') { fwrite(STDERR, 'rbac group missing'); exit(5); } "
            "think\\facade\\Db::name('config')->where('name', 't04_biz_key')->update(['value' => 'post_restore_write_ok']); "
            "$after = think\\facade\\Db::name('config')->where('name', 't04_biz_key')->value('value'); "
            "if ($after !== 'post_restore_write_ok') { fwrite(STDERR, 'post restore write failed'); exit(6); } "
            "echo 'ALL_POST_RESTORE_CHECKS_OK';"
        )
        rc_php, out_php, err_php = deploy.run_php_cmd(target_dir, ["-r", php_check, "--", init_pw])
        self.assertEqual(0, rc_php, f"PHP post-restore check failed: {out_php} {err_php}")
        self.assertIn("ALL_POST_RESTORE_CHECKS_OK", out_php)

    def test_build_release_includes_t03_t04_and_scripts_tests_in_dev_whitelist(self):
        src_text = (SCRIPTS / "build-release.py").read_text(encoding="utf-8")
        for expected in (
            "scripts/hw-runtime.py",
            "scripts/verify-t06-projects.sh",
            "scripts/tests/test_t03_t04_prod_deploy_recovery.py",
            "scripts/tests/test_deploy_integrity.py",
        ):
            self.assertIn(expected, src_text)


if __name__ == "__main__":
    unittest.main()
