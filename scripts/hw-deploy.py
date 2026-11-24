#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
HwAdmin 声明式生产部署与运维工具 (hw-deploy)
命令支持:
  python3 scripts/hw-deploy.py plan --target=docker|baota-nginx|local [--db-driver=mysql|pgsql] [--domain=...] [--https-cert=...] [--https-key=...] [--env-file=...] [--artifact=...] [--target-dir=...] [--json]
  python3 scripts/hw-deploy.py apply --target=docker|local [--db-driver=mysql|pgsql] [--domain=...] [--https-cert=...] [--https-key=...] [--http-port=...] [--https-port=...] [--env-file=...] [--artifact=...] [--target-dir=...] [--backup-dir=...] [--dry-run] [--json]
  python3 scripts/hw-deploy.py rollback --backup-dir=... [--target-dir=...] [--target=docker|local] [--mode=full|code-only] [--json]
  python3 scripts/hw-deploy.py report [--format=json|markdown] [--json]
"""

import os
import sys
import io
import json
import re
import ssl
import time
import socket
import shutil
import zipfile
import tarfile
import hashlib
import secrets
import shlex
import sqlite3
import stat
import tempfile
import argparse
import subprocess
import urllib.request
import urllib.error
from contextlib import closing
from datetime import datetime, timezone
from pathlib import Path

from verification_evidence import source_fingerprint

# 控制台 UTF-8 支持
if sys.stdout.encoding and sys.stdout.encoding.lower() != 'utf-8':
    try:
        sys.stdout.reconfigure(encoding='utf-8')
    except Exception:
        pass


FALLBACK_PHP_DOCKERFILE = """FROM php:8.3-fpm-alpine
RUN apk add --no-cache bash curl git unzip libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev postgresql-dev sqlite-dev icu-dev libxml2-dev linux-headers $PHPIZE_DEPS \\
    && docker-php-ext-configure gd --with-freetype --with-jpeg \\
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql pdo_pgsql pdo_sqlite gd zip bcmath intl opcache pcntl \\
    && pecl install redis && docker-php-ext-enable redis \\
    && apk del $PHPIZE_DEPS && rm -rf /tmp/pear /var/cache/apk/*
WORKDIR /var/www/hwadmin/server
EXPOSE 9000
CMD ["php-fpm"]
"""

DESTRUCTIVE_MIGRATION_PATTERNS = [
    (re.compile(r'->\s*dropTable\s*\(', re.I), 'dropTable'),
    (re.compile(r'->\s*drop\s*\(', re.I), 'drop'),
    (re.compile(r'->\s*removeColumn\s*\(', re.I), 'removeColumn'),
    (re.compile(r'->\s*dropColumn\s*\(', re.I), 'dropColumn'),
    (re.compile(r'->\s*changeColumn\s*\(', re.I), 'changeColumn'),
    (re.compile(r'->\s*renameColumn\s*\(', re.I), 'renameColumn'),
    (re.compile(r'->\s*rename\s*\(', re.I), 'rename'),
    (re.compile(r'\bDROP\s+TABLE\b', re.I), 'SQL DROP TABLE'),
    (re.compile(r'\bDROP\s+COLUMN\b', re.I), 'SQL DROP COLUMN'),
    (re.compile(r'\bALTER\s+TABLE\b[^;]*\b(?:DROP|MODIFY|CHANGE|RENAME)\b', re.I), 'SQL ALTER TABLE'),
    (re.compile(r'\bTRUNCATE\s+TABLE\b', re.I), 'SQL TRUNCATE TABLE'),
]


def calculate_sha256(file_path: Path) -> str:
    sha256 = hashlib.sha256()
    with open(file_path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            sha256.update(chunk)
    return sha256.hexdigest()


def calculate_bytes_sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def mask_sensitive(val: str) -> str:
    if not val:
        return ""
    if len(val) <= 4:
        return "******"
    return val[:2] + "******" + val[-2:]


def safe_chmod(path: Path, mode: int):
    try:
        os.chmod(path, mode)
    except OSError:
        pass


def get_git_commit(project_root: Path) -> str:
    try:
        p = subprocess.run(
            ["git", "rev-parse", "HEAD"],
            cwd=project_root,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        if p.returncode == 0 and p.stdout.strip():
            return p.stdout.strip()
    except Exception:
        pass
    return "unknown"


def detect_framework_version(project_root: Path) -> str:
    for rel in ("server/version.json", "version.json"):
        vf = project_root / rel
        if vf.is_file():
            try:
                data = json.loads(vf.read_text(encoding="utf-8"))
                if data.get("version"):
                    return str(data["version"]).lstrip("v")
            except Exception:
                pass
    for rel in ("server/config/hwadmin.php", "config/hwadmin.php"):
        cf = project_root / rel
        if cf.is_file():
            m = re.search(r"'version'\s*=>\s*'(\d+\.\d+\.\d+)'", cf.read_text(encoding="utf-8", errors="ignore"))
            if m:
                return m.group(1)
    return "unknown"


def to_wsl_path(win_path: Path) -> str:
    p_str = str(win_path.resolve())
    m = re.match(r"^([A-Za-z]):\\(.*)$", p_str)
    if m:
        drive = m.group(1).lower()
        sub = m.group(2).replace("\\", "/")
        return f"/mnt/{drive}/{sub}"
    return p_str.replace("\\", "/")


def has_native_php() -> str:
    php_bin = shutil.which("php")
    if php_bin:
        return php_bin
    local_php = Path.home() / ".local/bin/php"
    if local_php.is_file():
        return str(local_php)
    return ""


def run_subprocess(cmd: list, cwd: Path = None, env: dict = None, stdin_bytes: bytes = None, timeout: int = 300) -> tuple:
    """Unified subprocess executor (injectable in tests)."""
    proc_env = os.environ.copy()
    if env:
        proc_env.update(env)
    p = subprocess.run(
        cmd,
        cwd=str(cwd) if cwd else None,
        env=proc_env,
        input=stdin_bytes,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout,
    )
    return p.returncode, p.stdout, p.stderr


def http_get_response(url: str, headers: dict = None, timeout: float = 3.0, verify_tls: bool = True) -> tuple:
    """Execute HTTP/HTTPS GET and return (status_code, lower_headers, body_bytes)."""
    req = urllib.request.Request(url, headers=headers or {})
    ctx = None
    if url.lower().startswith("https://") and not verify_tls:
        ctx = ssl._create_unverified_context()
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            hdrs = {k.lower(): v for k, v in resp.headers.items()}
            return resp.status, hdrs, resp.read()
    except urllib.error.HTTPError as he:
        hdrs = {k.lower(): v for k, v in he.headers.items()} if he.headers else {}
        return he.code, hdrs, he.read()


def run_php_cmd(work_dir: Path, args_list: list) -> tuple:
    php_bin = has_native_php()
    if php_bin:
        env = os.environ.copy()
        env["PATH"] = f"{Path.home() / '.local/bin'}:{env.get('PATH', '')}"
        p = subprocess.run(
            [php_bin] + args_list,
            cwd=work_dir,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
        )
        return p.returncode, p.stdout.decode("utf-8", errors="replace"), p.stderr.decode("utf-8", errors="replace")
    if os.name == "nt" and shutil.which("wsl.exe"):
        wsl_dir = to_wsl_path(work_dir)
        quoted_args = " ".join(shlex.quote(str(a)) for a in args_list)
        bash_cmd = f'export PATH="$HOME/.local/bin:$PATH"; cd {shlex.quote(wsl_dir)} && php {quoted_args}'
        p = subprocess.run(
            ["wsl.exe", "-e", "bash", "-c", bash_cmd],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        return p.returncode, p.stdout.decode("utf-8", errors="replace"), p.stderr.decode("utf-8", errors="replace")
    return 127, "", "未找到可用的 PHP 运行时"


ADMIN_ENTRY_PATTERN = re.compile(r"^[a-zA-Z0-9_-]{3,32}$")
ADMIN_ENTRY_RESERVED = {
    "api", "adminapi", "install", "static", "storage", "plugins",
    "index", "core", "config", "runtime", "view",
}


def is_valid_admin_entry(entry: str) -> bool:
    s = str(entry or "").strip()
    return bool(ADMIN_ENTRY_PATTERN.fullmatch(s)) and s.lower() not in ADMIN_ENTRY_RESERVED


def resolve_admin_entry(
    base_url: str,
    headers: dict = None,
    server_roots: list = None,
    verify_tls: bool = True,
) -> str:
    """Resolve dynamic admin_entry from /adminapi/index/config, then runtime files/SQLite, falling back to 'admin'."""
    try:
        status, _, body_bytes = http_get_response(
            f"{base_url}/adminapi/index/config",
            headers=headers,
            timeout=3.0,
            verify_tls=verify_tls,
        )
        if status == 200:
            payload = json.loads(body_bytes.decode("utf-8", errors="ignore"))
            if isinstance(payload, dict) and payload.get("code") == 1:
                data = payload.get("data") or {}
                if isinstance(data, dict):
                    entry = str(data.get("admin_entry") or "").strip()
                    if is_valid_admin_entry(entry):
                        return entry
    except Exception:
        pass

    for root in (server_roots or []):
        if not root:
            continue
        root_path = Path(root)
        for rel in ("runtime/admin_entry.txt", "runtime/adminapi/admin_entry.txt", "server/runtime/admin_entry.txt"):
            rf = root_path / rel
            if rf.is_file():
                try:
                    entry = rf.read_text(encoding="utf-8", errors="ignore").strip()
                    if is_valid_admin_entry(entry):
                        return entry
                except Exception:
                    pass
        for rel_db in ("runtime/hwadmin.sqlite", "runtime/dev.sqlite", "server/runtime/hwadmin.sqlite", "server/runtime/dev.sqlite"):
            db_file = root_path / rel_db
            if db_file.is_file():
                try:
                    with closing(sqlite3.connect(str(db_file))) as conn:
                        row = conn.execute("SELECT value FROM hw_config WHERE name='admin_entry' LIMIT 1").fetchone()
                        if row and is_valid_admin_entry(str(row[0] or "")):
                            return str(row[0]).strip()
                except Exception:
                    pass

    return "admin"


def run_http_health_probe(server_dir: Path) -> tuple:
    """在隔离目标目录拉起临时内置 HTTP 服务并执行真实业务健康探针（仅 --target=local 使用）"""
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]

    php_bin = has_native_php()
    proc = None
    try:
        if php_bin:
            env = os.environ.copy()
            env["PATH"] = f"{Path.home() / '.local/bin'}:{env.get('PATH', '')}"
            cmd = [php_bin, "-S", f"127.0.0.1:{port}", "-t", "public", "public/router.php"]
            proc = subprocess.Popen(
                cmd,
                cwd=server_dir,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                env=env,
            )
        elif os.name == "nt" and shutil.which("wsl.exe"):
            wsl_dir = to_wsl_path(server_dir)
            bash_cmd = f'export PATH="$HOME/.local/bin:$PATH"; cd "{wsl_dir}" && exec php -S 127.0.0.1:{port} -t public public/router.php'
            proc = subprocess.Popen(
                ["wsl.exe", "-e", "bash", "-c", bash_cmd],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
        else:
            return False, "未找到 PHP 运行时以执行 HTTP 健康探针"

        base_url = f"http://127.0.0.1:{port}"
        ping_ok = False
        ping_payload = {}
        last_err = ""
        for _ in range(25):
            if proc.poll() is not None:
                return False, f"探针服务进程异常退出 (exit {proc.returncode})"
            try:
                with urllib.request.urlopen(f"{base_url}/api/index/ping", timeout=1.5) as resp:
                    body = resp.read().decode("utf-8", errors="ignore")
                    data = json.loads(body)
                    if resp.status == 200 and data.get("code") == 1:
                        ping_ok = True
                        ping_payload = data
                        break
            except Exception as e:
                last_err = str(e)
                time.sleep(0.2)

        if not ping_ok:
            return False, f"健康探针 /api/index/ping 失败: {last_err}"

        # 二次探针：校验管理端鉴权拦截 /adminapi/index/info 返回 code=401
        try:
            req = urllib.request.Request(f"{base_url}/adminapi/index/info")
            try:
                with urllib.request.urlopen(req, timeout=2.0) as resp:
                    info_body = resp.read().decode("utf-8", errors="ignore")
            except urllib.error.HTTPError as he:
                info_body = he.read().decode("utf-8", errors="ignore")
            info_data = json.loads(info_body)
            if info_data.get("code") != 401:
                return False, f"鉴权探针 /adminapi/index/info 预期返回 401，实际: {info_body[:120]}"
        except Exception as e:
            return False, f"鉴权探针 /adminapi/index/info 请求异常: {e}"

        admin_entry = resolve_admin_entry(base_url, server_roots=[server_dir])
        admin_entry_status = None
        if (server_dir / "public/admin/index.html").is_file():
            try:
                admin_status, _, _ = http_get_response(f"{base_url}/{admin_entry}/", timeout=2.0)
                if admin_status not in (200, 304):
                    return False, f"管理端入口 /{admin_entry}/ 返回异常状态码: {admin_status}"
                admin_entry_status = admin_status
            except Exception as e:
                return False, f"管理端入口 /{admin_entry}/ 请求异常: {e}"

            if admin_entry != "admin":
                try:
                    legacy_status, _, _ = http_get_response(f"{base_url}/admin/", timeout=2.0)
                    if legacy_status == 200:
                        return False, f"安全拦截：已配置自定义入口 /{admin_entry}/，但默认 /admin/ 仍返回 200"
                except Exception:
                    pass

        return True, {
            "probe_mode": "local_php_cli",
            "ping_url": f"{base_url}/api/index/ping",
            "ping_code": ping_payload.get("code"),
            "auth_guard_code": 401,
            "admin_entry": admin_entry,
            "admin_entry_status": admin_entry_status,
        }
    finally:
        if proc and proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=2)
            except Exception:
                proc.kill()


def detect_docker_compose_cmd() -> list:
    if shutil.which("docker"):
        try:
            rc, _, _ = run_subprocess(["docker", "compose", "version"], timeout=10)
            if rc == 0:
                return ["docker", "compose"]
        except Exception:
            pass
    if shutil.which("docker-compose"):
        try:
            rc, _, _ = run_subprocess(["docker-compose", "version"], timeout=10)
            if rc == 0:
                return ["docker-compose"]
        except Exception:
            pass
    return []


def check_docker_runtime() -> tuple:
    """Verify Docker CLI, Docker Daemon, and Compose plugin are actually reachable."""
    if not shutil.which("docker"):
        return False, "未检测到 docker 命令，请在目标宿主机安装 Docker Engine", []
    compose_cmd = detect_docker_compose_cmd()
    if not compose_cmd:
        return False, "未检测到可用的 docker compose 编排插件", []
    try:
        rc, out, err = run_subprocess(["docker", "info"], timeout=15)
        if rc != 0:
            detail = (err or out or b"").decode("utf-8", errors="ignore").strip()
            return False, f"Docker Daemon 不可用或未启动: {detail[:200]}", []
    except Exception as e:
        return False, f"检测 Docker Daemon 状态失败: {e}", []
    return True, "ok", compose_cmd


def detect_latest_artifact(project_root: Path, preferred_type: str = "deploy", explicit_artifact: str = None) -> dict:
    if explicit_artifact:
        target_file = Path(explicit_artifact).resolve()
        if not target_file.is_file():
            return None
    else:
        release_dir = project_root / "release"
        if not release_dir.is_dir():
            return None
        candidates = []
        for f in release_dir.glob("hwadmin-*.zip"):
            fname = f.name
            is_unified = bool(re.fullmatch(r"hwadmin-v\d+\.\d+\.\d+.*\.zip", fname))
            is_deploy = "release" in fname
            is_dev = "dev" in fname
            mtime = f.stat().st_mtime
            candidates.append((mtime, f, is_deploy, is_dev, is_unified))
        if not candidates:
            return None
        candidates.sort(key=lambda x: x[0], reverse=True)
        target_file = candidates[0][1]
        for c in candidates:
            if preferred_type == "unified" and c[4]:
                target_file = c[1]
                break
            if preferred_type == "deploy" and (c[2] or c[4]):
                target_file = c[1]
                break
            if preferred_type == "dev" and (c[3] or c[4]):
                target_file = c[1]
                break

    sha = calculate_sha256(target_file)
    return {
        "filename": target_file.name,
        "path": str(target_file.resolve()),
        "size_mb": round(target_file.stat().st_size / (1024 * 1024), 2),
        "sha256": sha,
    }


def validate_archive_members(archive):
    """Reject ambiguous or escaping paths before any target files are touched."""
    seen = set()
    for member in archive.infolist():
        name = member.filename.rstrip('/') if member.is_dir() else member.filename
        parts = name.split('/')
        if (not name or '\\' in name or ':' in name or name.startswith('/')
                or any(part in {'', '.', '..'} for part in parts)
                or any(part.endswith(('.', ' ')) for part in parts)
                or any(re.search(r'[<>"|?*\x00-\x1f]', part) for part in parts)
                or any(re.fullmatch(r'(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?', part, re.I) for part in parts)
                or stat.S_ISLNK(member.external_attr >> 16)):
            raise ValueError(f'归档路径非法或为符号链接: {member.filename}')
        key = name.casefold()
        if key in seen:
            raise ValueError(f'归档路径重复（含大小写冲突）: {name}')
        seen.add(key)


def safe_tar_members(archive: tarfile.TarFile, prefix: str = "uploads") -> list:
    """Validate tar archive members against traversal, symlinks, special files, and case collisions."""
    seen = set()
    members = []
    for member in archive.getmembers():
        raw_name = member.name.removeprefix("./")
        name = raw_name.rstrip("/") if member.isdir() else raw_name
        if not name or name == ".":
            continue
        if member.issym() or member.islnk() or not (member.isfile() or member.isdir()):
            raise ValueError(f"附件归档包含符号链接或特殊文件: {member.name}")
        if name.startswith(("/", "\\")) or "\\" in name or ":" in name:
            raise ValueError(f"附件归档包含非法路径: {member.name}")
        parts = name.split("/")
        if any(part in {"", ".", ".."} for part in parts):
            raise ValueError(f"附件归档包含路径穿越: {member.name}")
        if any(part.endswith((".", " ")) for part in parts):
            raise ValueError(f"附件归档路径段非法: {member.name}")
        if prefix and parts[0] != prefix:
            raise ValueError(f"附件归档路径前缀不匹配 ({prefix}): {member.name}")
        key = name.casefold()
        if key in seen:
            raise ValueError(f"附件归档存在重复路径（含大小写冲突）: {name}")
        seen.add(key)
        member.name = name
        members.append(member)
    return members


def confined_path(root, relative):
    root = Path(root).resolve()
    target = (root / relative).resolve()
    if target == root or not target.is_relative_to(root):
        raise ValueError(f'路径超出目标目录: {relative}')
    return target


def backup_sqlite(source, destination):
    """Include committed WAL pages and restore through SQLite's locking protocol."""
    with closing(sqlite3.connect(str(source))) as source_db, closing(sqlite3.connect(str(destination))) as destination_db:
        source_db.backup(destination_db)


def fail_operation(args, message, step='preflight', extra: dict = None):
    if getattr(args, 'json', False):
        payload = {'status': 'fail', 'step': step, 'error': message}
        if extra:
            payload.update(extra)
        print(json.dumps(payload, ensure_ascii=False))
    else:
        print(message)
        if extra and extra.get("manual_steps"):
            print("建议手动/半自动迁移步骤:")
            for idx, s in enumerate(extra["manual_steps"], start=1):
                print(f"  {idx}. {s}")
    return 1


def verify_artifact_strictly(zip_path: Path) -> tuple:
    """
    严格验证制品包：
    1. 必须存在且为合法 ZIP 归档 (zipfile.is_zipfile)
    2. 必须存在同名 .sha256 签名文件且严格比对 SHA-256 一致
    3. 包内必须包含 artifact-manifest.json (或 manifest.json)，且逐文件校验 SHA-256 与 required_files
    """
    if not zip_path or not zip_path.is_file():
        return False, f"制品文件不存在: {zip_path}", {}

    actual_zip_sha = calculate_sha256(zip_path).lower()

    sha_candidates = [
        Path(str(zip_path) + ".sha256"),
        zip_path.with_suffix(zip_path.suffix + ".sha256"),
    ]
    external_sha_verified = False
    for sha_file in sha_candidates:
        if sha_file.is_file():
            raw_text = sha_file.read_text(encoding="utf-8", errors="ignore").strip()
            parts = raw_text.split()
            expected_sha = parts[0].lower() if parts else ""
            if not re.fullmatch(r"[0-9a-f]{64}", expected_sha):
                return False, f"外部签名文件格式非法: {sha_file}", {}
            if expected_sha != actual_zip_sha:
                return (
                    False,
                    f"外部 SHA-256 签名校验不匹配！预期: {expected_sha}, 实际: {actual_zip_sha}",
                    {},
                )
            external_sha_verified = True
            break

    if not external_sha_verified:
        return False, '制品缺少外部 SHA-256 校验文件', {}

    if not zipfile.is_zipfile(zip_path):
        return False, f"制品不是合法的 ZIP 归档文件: {zip_path.name}", {}

    try:
        with zipfile.ZipFile(zip_path, mode="r") as zf:
            validate_archive_members(zf)
            bad_member = zf.testzip()
            if bad_member is not None:
                return False, f"ZIP 归档 CRC 校验损坏: {bad_member}", {}

            names = set(zf.namelist())
            manifest_name = None
            if "artifact-manifest.json" in names:
                manifest_name = "artifact-manifest.json"
            elif "manifest.json" in names:
                manifest_name = "manifest.json"

            if not manifest_name:
                return False, "ZIP 制品缺少可信清单文件 artifact-manifest.json", {}

            manifest = json.loads(zf.read(manifest_name).decode("utf-8"))
            files_map = manifest.get("files")
            if not isinstance(files_map, dict) or len(files_map) == 0:
                return False, "制品清单 files 映射为空或格式非法", {}

            payload_names = {info.filename for info in zf.infolist() if not info.is_dir()}
            if set(files_map) != payload_names - {manifest_name}:
                return False, '归档实际文件与清单不一致（存在未登记或额外文件）', {}

            required_files = manifest.get("required_files", [])
            missing_req = [rf for rf in required_files if rf not in names]
            if missing_req:
                return False, f"制品缺失必需核心文件: {missing_req}", {}

            for rel_path, expected_f_sha in files_map.items():
                if rel_path not in names:
                    return False, f"制品清单声明的文件在归档中缺失: {rel_path}", {}
                actual_f_sha = hashlib.sha256(zf.read(rel_path)).hexdigest().lower()
                if actual_f_sha != str(expected_f_sha).lower():
                    return (
                        False,
                        f"制品内部文件哈希被篡改或不匹配: {rel_path} (预期 {expected_f_sha}, 实际 {actual_f_sha})",
                        {},
                    )
    except Exception as e:
        return False, f"解析校验 ZIP 制品异常: {e}", {}

    manifest["_external_sha_verified"] = external_sha_verified
    manifest["_zip_sha256"] = actual_zip_sha
    return True, "制品签名与逐文件完整性核验通过", manifest


def parse_env_file(env_path: Path) -> dict:
    data = {}
    if not env_path or not env_path.is_file():
        return data
    for line in env_path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or line.startswith("["):
            continue
        if "=" in line:
            k, v = line.split("=", 1)
            k = k.strip().upper()
            v = v.strip()
            if len(v) >= 2 and ((v[0] == '"' and v[-1] == '"') or (v[0] == "'" and v[-1] == "'")):
                v = v[1:-1]
            data[k] = v
    return data


def format_env_value(val: str) -> str:
    s = str(val).replace("\r", "").replace("\n", "")
    if re.search(r'[\s#"\'=]', s):
        escaped = s.replace("\\", "\\\\").replace('"', '\\"')
        return f'"{escaped}"'
    return s


def write_secret_file(path: Path, content: str):
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.is_symlink():
        raise ValueError(f"安全拒绝：凭据或配置文件不得为符号链接: {path}")
    tmp = path.with_name(f".{path.name}.tmp.{os.getpid()}")
    fd = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(content)
        safe_chmod(tmp, 0o600)
        tmp.replace(path)
        safe_chmod(path, 0o600)
    finally:
        if tmp.exists():
            try:
                tmp.unlink()
            except OSError:
                pass


def append_deploy_history(project_root: Path, record: dict, extra_root: Path = None):
    roots = [project_root]
    if extra_root and extra_root.resolve() != project_root.resolve():
        roots.append(extra_root)
    for r in roots:
        try:
            state_dir = r / ".hw-state"
            state_dir.mkdir(parents=True, exist_ok=True)
            hist_file = state_dir / "deploy-history.json"
            history = []
            if hist_file.is_file():
                try:
                    history = json.loads(hist_file.read_text(encoding="utf-8"))
                    if not isinstance(history, list):
                        history = []
                except Exception:
                    history = []
            history.append(record)
            hist_file.write_text(json.dumps(history, ensure_ascii=False, indent=2), encoding="utf-8")
        except Exception:
            pass


def update_recovery_point_state(project_root: Path, target_dir: Path, point_record: dict):
    for r in {project_root.resolve(), target_dir.resolve()}:
        try:
            state_dir = r / ".hw-state"
            state_dir.mkdir(parents=True, exist_ok=True)
            rp_file = state_dir / "recovery-point.json"
            rp_file.write_text(json.dumps(point_record, ensure_ascii=False, indent=2), encoding="utf-8")
        except Exception:
            pass


def read_recovery_point_state(project_root: Path, target_dir: Path) -> dict:
    for r in (target_dir.resolve(), project_root.resolve()):
        rp_file = r / ".hw-state/recovery-point.json"
        if rp_file.is_file():
            try:
                data = json.loads(rp_file.read_text(encoding="utf-8"))
                if isinstance(data, dict):
                    return data
            except Exception:
                pass
    return {}


class BaseDeployAdapter:
    def __init__(self, project_root: Path, env_file: Path = None):
        self.project_root = project_root
        self.env_file = env_file or (project_root / ".env")
        self.server_dir = project_root / "server" if (project_root / "server/think").is_file() else project_root

    def read_env_vars(self) -> dict:
        for ef in (self.env_file, self.project_root / ".env.docker", self.server_dir / ".env"):
            if ef and ef.is_file():
                return parse_env_file(ef)
        return {}


class DockerDeployAdapter(BaseDeployAdapter):
    name = "docker"

    def generate_plan(self, artifact_info: dict, cli_opts: dict = None) -> dict:
        cli_opts = cli_opts or {}
        env_vars = self.read_env_vars()
        docker_available = bool(shutil.which("docker"))
        compose_available = bool(detect_docker_compose_cmd())

        compose_file = self.project_root / "docker-compose.yml"
        has_compose = compose_file.is_file()

        http_port = cli_opts.get("http_port") or env_vars.get("HTTP_PORT", "8080")
        https_port = cli_opts.get("https_port") or env_vars.get("HTTPS_PORT", "8443")
        db_driver = (cli_opts.get("db_driver") or env_vars.get("DB_DRIVER") or env_vars.get("DB_TYPE") or "mysql").lower()
        db_pass = env_vars.get("DB_PASS", "")
        domain = cli_opts.get("domain") or env_vars.get("DOMAIN", "")
        has_tls = bool(cli_opts.get("https_cert") and cli_opts.get("https_key"))

        gaps = []
        if not docker_available:
            gaps.append({
                "item": "Docker 运行时环境",
                "severity": "high",
                "description": "未检测到 docker 命令，请在目标环境安装 Docker CE",
            })
        if not compose_available:
            gaps.append({
                "item": "Docker Compose 编排插件",
                "severity": "high",
                "description": "未检测到 docker compose 插件",
            })
        if not db_pass or "CHANGE_ME" in db_pass or "SecPass" in db_pass:
            gaps.append({
                "item": "数据库生产强密码",
                "severity": "medium",
                "description": "DB_PASS 未设置或使用占位符，首次 apply 时将自动生成高强度随机凭证",
            })
        if not domain:
            gaps.append({
                "item": "生产独立域名 DNS 解析",
                "severity": "low",
                "description": f"未指定 --domain，当前按本地端口 {http_port} 暴露，DNS 解析需人工配置",
            })
        if not has_tls:
            gaps.append({
                "item": "正式 HTTPS/TLS 证书",
                "severity": "low",
                "description": "未提供 --https-cert/--https-key，正式受信任证书签发与 443 接入需人工确认",
            })

        volumes = ["mysql_data:/var/lib/mysql", "redis_data:/data"] if db_driver == "mysql" else ["pg_data:/var/lib/postgresql/data", "redis_data:/data"]
        plan_steps = [
            {"step": 1, "name": "校验发布包哈希与清单", "action": "verify_sha256", "detail": f"验证 {artifact_info.get('filename') if artifact_info else '制品'} 及包内文件哈希"},
            {"step": 2, "name": "持久化数据与代码快照备份", "action": "backup", "detail": f"备份代码快照、.env、{db_driver} 数据库转储与 uploads 清单至 backup-manifest.json"},
            {"step": 3, "name": "解包生产资源与持久化保护", "action": "extract_and_protect", "detail": "解压更新制品，首装自动生成 0600 高强度凭证 (APP_DEBUG=false)，增量保留既有 .env/卷/uploads"},
            {"step": 4, "name": "生成生产 Compose/Nginx 并执行受控迁移", "action": "compose_and_migrate", "detail": "docker compose config -q && up -d --build && migrate:run && hw:menu:sync"},
            {"step": 5, "name": "执行真实生产健康探针", "action": "production_health_probe", "detail": "核验 Nginx + PHP-FPM 容器运行态及 HTTP 响应（拒绝 php -S 开发服务器）"},
        ]

        return {
            "target": self.name,
            "auto_apply_supported": True,
            "execution_mode": "docker_compose_production",
            "status": "ready" if compose_available and has_compose else "action_required",
            "configs": {
                "http_port": http_port,
                "https_port": https_port if has_tls else None,
                "domain": domain or None,
                "https_enabled": has_tls,
                "db_driver": db_driver,
                "db_user": env_vars.get("DB_USER", "hwadmin_app"),
                "db_pass_masked": mask_sensitive(db_pass),
                "volumes": volumes,
            },
            "steps": plan_steps,
            "external_gaps": gaps,
            "persistent_protection": [
                ".env / .env.docker (宿主机 0600 密钥)",
                f"{'mysql_data' if db_driver == 'mysql' else 'pg_data'} / redis_data (持久化数据库与缓存卷)",
                "public/uploads/ (媒体与附件目录)",
                "config/install.lock (安装状态锁)",
            ],
        }


class BaotaNginxDeployAdapter(BaseDeployAdapter):
    name = "baota-nginx"

    MANUAL_STEPS = [
        "使用 python3 scripts/hw-deploy.py plan --target=baota-nginx 生成并审阅宝塔站点配置与 Nginx 规则",
        "上传 hwadmin-v*.zip 与 .sha256 到目标服务器，执行 sha256sum -c 校验完整性",
        "在维护窗口执行 python3 scripts/backup_restore.py backup 备份目标库与 server/public/uploads 附件",
        "将制品解压至站点根目录（保留 .env、config/install.lock 与 uploads），在宝塔面板将运行目录设为 /server/public（旧版精简包为 /public）",
        "将 server/public/nginx-baota.conf.example 规则配置到宝塔伪静态与站点配置文件，并执行 nginx -t && nginx -s reload",
        "在站点 server/ 目录执行 php think migrate:run && php think hw:menu:sync，随后访问公网 HTTPS /api/index/ping 与 /admin/ 核验上线",
    ]

    def _is_legacy_flat_deploy(self, artifact_info: dict) -> bool:
        if not artifact_info:
            return False
        atype = str(artifact_info.get("artifact_type") or "").strip().lower()
        filename = str(artifact_info.get("filename") or "").strip().lower()
        return atype == "deploy" or filename.startswith("hwadmin-release-")

    def generate_plan(self, artifact_info: dict, cli_opts: dict = None) -> dict:
        env_vars = self.read_env_vars()
        site_root = env_vars.get("SITE_ROOT", "/www/wwwroot/hwadmin")
        is_flat = self._is_legacy_flat_deploy(artifact_info)
        server_sub = "" if is_flat else "/server"
        run_rel = "/public" if is_flat else "/server/public"
        public_dir = f"{site_root}{run_rel}"
        nginx_example = "public/nginx-baota.conf.example" if is_flat else "server/public/nginx-baota.conf.example"
        migrate_prefix = "" if is_flat else "cd server && "

        gaps = [
            {
                "item": "宝塔自动远程执行器未启用",
                "severity": "high",
                "description": "当前版本不支持 --target=baota-nginx 自动远程 apply，请按 manual_steps 执行手动/半自动迁移",
            },
            {
                "item": "宝塔网站运行目录配置",
                "severity": "high",
                "description": f"宝塔后台必须将网站目录设置为 {site_root}，运行目录必须设置为 {run_rel}",
            },
            {
                "item": "Nginx 动静分离与防执行配置",
                "severity": "high",
                "description": f"需在宝塔伪静态规则中注入 {nginx_example} 中的防脚本执行规则",
            },
            {
                "item": "PHP-FPM 版本",
                "severity": "medium",
                "description": "需在宝塔面板选择 PHP 8.2 ~ 8.4 并安装 pdo_mysql / pdo_pgsql / redis / fileinfo 扩展",
            },
        ]

        plan_steps = [
            {"step": 1, "name": "制品哈希与清单完整性校验", "action": "verify_sha256", "detail": "核验 ZIP、.sha256 签名及 artifact-manifest.json"},
            {"step": 2, "name": "生产数据与代码保护备份", "action": "backup", "detail": f"使用 scripts/backup_restore.py 备份 {site_root} 代码、.env、数据库和 uploads 附件"},
            {"step": 3, "name": "受控解压缩发布包", "action": "extract", "detail": f"覆盖更新框架内核与前端静态编译包，保护持久化目录（运行目录 {run_rel}）"},
            {"step": 4, "name": "执行增量数据迁移与权限规则同步", "action": "migrate_and_menu_sync", "detail": f"{migrate_prefix}php think migrate:run && php think hw:menu:sync"},
            {"step": 5, "name": "执行真实 HTTP 健康探针", "action": "healthcheck", "detail": "校验公网 HTTPS /api/index/ping 与 /adminapi/index/info"},
        ]

        nginx_conf_snippet = (
            "# HwAdmin 宝塔 Nginx 推荐伪静态与安全防护规则\n"
            "location ~* ^/(runtime|application|core|database)/ { return 403; }\n"
            "location ~* ^/(uploads|plugins)/.*\\.(php|phtml|phar)$ { return 403; }\n"
            "location = /admin { rewrite ^(.*)$ /index.php?s=/admin last; }\n"
            "location = /admin/ { rewrite ^(.*)$ /index.php?s=/admin last; }\n"
            "location = /admin/index.html { rewrite ^(.*)$ /index.php?s=/admin last; }\n"
            "location ^~ /admin/ { try_files $uri =404; }\n"
            "location / { if (!-e $request_filename) { rewrite ^(.*)$ /index.php?s=$1 last; break; } }"
        )

        return {
            "target": self.name,
            "auto_apply_supported": False,
            "execution_mode": "manual_guided",
            "status": "manual_required",
            "configs": {
                "site_root": site_root,
                "public_dir": public_dir,
                "recommended_php": "8.3",
                "nginx_rules": nginx_conf_snippet,
            },
            "steps": plan_steps,
            "manual_steps": list(self.MANUAL_STEPS),
            "external_gaps": gaps,
            "persistent_protection": [
                f"{site_root}{server_sub}/.env",
                f"{site_root}{server_sub}/runtime/*.sqlite",
                f"{site_root}{server_sub}/public/uploads/*",
                f"{site_root}{server_sub}/config/install.lock",
            ],
        }


def cmd_plan(args, project_root: Path):
    target = args.target.lower()
    artifact = detect_latest_artifact(project_root, explicit_artifact=getattr(args, "artifact", None))
    cli_opts = {
        "db_driver": getattr(args, "db_driver", None),
        "domain": getattr(args, "domain", None),
        "https_cert": getattr(args, "https_cert", None),
        "https_key": getattr(args, "https_key", None),
        "http_port": getattr(args, "http_port", None),
        "https_port": getattr(args, "https_port", None),
    }

    if target == "docker":
        adapter = DockerDeployAdapter(project_root, Path(args.env_file) if getattr(args, "env_file", None) else None)
    elif target in {"baota-nginx", "baota", "local"}:
        adapter = BaotaNginxDeployAdapter(project_root, Path(args.env_file) if getattr(args, "env_file", None) else None)
    else:
        print(f"❌ 不支持的部署适配器: {target} (仅支持 docker、baota-nginx 或 local)")
        return 1

    plan_data = adapter.generate_plan(artifact, cli_opts=cli_opts)
    plan_data["artifact"] = artifact
    plan_data["framework_version"] = detect_framework_version(project_root)
    plan_data["git_commit"] = get_git_commit(project_root)
    plan_data["plan_generated_at"] = datetime.now(timezone.utc).isoformat()

    if args.json:
        print(json.dumps(plan_data, ensure_ascii=False, indent=2))
    else:
        print("==================================================")
        print(f"🚀 HwAdmin 声明式部署计划 [{plan_data['target'].upper()}]")
        print("==================================================")
        print(f"📦 目标制品: {artifact['filename'] if artifact else '未检测到 release/*.zip 包，建议先执行 hw package'}")
        if artifact:
            print(f"   SHA-256: {artifact['sha256']}")
        print(f"🔖 框架版本: v{plan_data['framework_version']} (Commit: {plan_data['git_commit'][:10]})")
        print("\n📋 编排步骤流水 (Plan Steps):")
        for s in plan_data["steps"]:
            print(f"  [{s['step']}] {s['name']}: {s['detail']}")
        print("\n🛡️ 受保护持久化清单 (不被更新覆盖):")
        for p in plan_data["persistent_protection"]:
            print(f"  - {p}")
        if plan_data["external_gaps"]:
            print("\n⚠️ 外部待办与缺项 (External Gaps):")
            for g in plan_data["external_gaps"]:
                print(f"  - [{g['severity'].upper()}] {g['item']}: {g['description']}")
        print("--------------------------------------------------")
        print("💡 提示: 本计划为只读分析 (plan)，未向目标系统写入任何变更。")
    return 0


def is_protected_rel_path(rel_path: str) -> bool:
    name = Path(rel_path).name
    if rel_path in {
        ".env", "server/.env", ".env.docker",
        "config/install.lock", "server/config/install.lock",
        "config/install.token", "server/config/install.token",
    }:
        return True
    if rel_path.startswith(".hw-deploy/ssl/"):
        return True
    if re.search(r"\.(sqlite3?|db)(?:-(?:wal|shm|journal))?$", name, re.IGNORECASE):
        return True
    if rel_path.startswith(("public/uploads/", "server/public/uploads/")):
        if name not in {".gitkeep", ".gitignore", ".htaccess"}:
            return True
    return False


def should_skip_code_snapshot(rel_path: str) -> bool:
    parts = rel_path.split("/")
    if any(p in {".git", "node_modules", "__pycache__", ".hw-state", "release"} for p in parts):
        return True
    for idx in range(len(parts) - 1):
        if parts[idx] == "src-tauri" and parts[idx + 1] == "target":
            return True
    if rel_path.startswith(("runtime/backups/", "server/runtime/backups/")):
        return True
    if is_protected_rel_path(rel_path):
        return True
    return False


def validate_db_cli_params(host: str, port: str, user: str, name: str):
    for label, val in (("DB_HOST", host), ("DB_PORT", port), ("DB_USER", user), ("DB_NAME", name)):
        s = str(val or "").strip()
        if not s:
            raise ValueError(f"数据库配置参数缺失: {label}")
        if s.startswith("-") or "\n" in s or "\r" in s or "\x00" in s:
            raise ValueError(f"数据库配置参数 {label} 包含非法字符")


def detect_effective_db_config(target_dir: Path, target_server_dir: Path, extra_env: dict = None) -> dict:
    merged = {}
    for candidate in (target_dir / ".env.docker", target_server_dir / ".env", target_dir / ".env"):
        if candidate.is_file():
            merged.update(parse_env_file(candidate))
    if extra_env:
        merged.update({k.upper(): str(v) for k, v in extra_env.items() if v is not None})
    raw_driver = (merged.get("DB_DRIVER") or merged.get("DB_TYPE") or "sqlite").strip().lower()
    if raw_driver not in {"sqlite", "mysql", "pgsql"}:
        raise ValueError(f"不支持的数据库驱动: {raw_driver}")
    default_port = "3306" if raw_driver == "mysql" else ("5432" if raw_driver == "pgsql" else "")
    return {
        "driver": raw_driver,
        "host": merged.get("DB_HOST", "127.0.0.1"),
        "port": str(merged.get("DB_PORT") or default_port),
        "name": merged.get("DB_NAME", "hwadmin" if raw_driver != "sqlite" else str(target_server_dir / "runtime/dev.sqlite")),
        "user": merged.get("DB_USER", "hwadmin_app"),
        "pass": merged.get("DB_PASS", ""),
        "prefix": merged.get("DB_PREFIX", "hw_"),
    }


def resolve_compose_context(target_dir: Path, target_name: str) -> dict | None:
    compose_prod = target_dir / "docker-compose.prod.yml"
    compose_default = target_dir / "docker-compose.yml"
    compose_file = compose_prod if compose_prod.is_file() else (compose_default if compose_default.is_file() else None)
    if not compose_file:
        return None
    env_docker = target_dir / ".env.docker"
    if not env_docker.is_file():
        env_docker = target_dir / ".env"
    if target_name != "docker" and not compose_prod.is_file():
        return None
    compose_cmd = detect_docker_compose_cmd()
    if not compose_cmd:
        return None
    return {
        "compose_cmd": compose_cmd,
        "compose_file": compose_file,
        "env_file": env_docker if env_docker.is_file() else None,
        "cwd": target_dir,
    }


def compose_base_args(ctx: dict) -> list:
    cmd = list(ctx["compose_cmd"]) + ["-f", str(ctx["compose_file"])]
    if ctx.get("env_file"):
        cmd += ["--env-file", str(ctx["env_file"])]
    return cmd


def build_db_dump_command(db_cfg: dict, compose_ctx: dict = None) -> tuple:
    driver = db_cfg["driver"]
    if driver not in {"mysql", "pgsql"}:
        raise ValueError(f"build_db_dump_command 仅支持 mysql/pgsql，收到: {driver}")
    validate_db_cli_params(db_cfg["host"], db_cfg["port"], db_cfg["user"], db_cfg["name"])
    password = db_cfg.get("pass", "")

    if driver == "mysql":
        if compose_ctx and db_cfg["host"] in {"hwadmin-mysql", "mysql", "db"}:
            cmd = compose_base_args(compose_ctx) + [
                "exec", "-T", "-e", "MYSQL_PWD",
                "hwadmin-mysql", "mysqldump",
                "-u", db_cfg["user"],
                "--single-transaction", "--quick", "--skip-lock-tables", "--no-tablespaces",
                db_cfg["name"],
            ]
            return cmd, {"MYSQL_PWD": password}
        if not shutil.which("mysqldump"):
            raise ValueError("缺少数据库备份工具 mysqldump，且容器编排上下文不可用")
        cmd = [
            "mysqldump",
            "-h", db_cfg["host"],
            "-P", str(db_cfg["port"]),
            "-u", db_cfg["user"],
            "--single-transaction", "--quick", "--skip-lock-tables", "--no-tablespaces",
            db_cfg["name"],
        ]
        return cmd, {"MYSQL_PWD": password}

    if compose_ctx and db_cfg["host"] in {"hwadmin-postgres", "postgres", "pg", "db"}:
        cmd = compose_base_args(compose_ctx) + [
            "exec", "-T", "-e", "PGPASSWORD",
            "hwadmin-postgres", "pg_dump",
            "-U", db_cfg["user"],
            "-d", db_cfg["name"],
            "--clean", "--if-exists", "--no-owner", "--no-privileges",
        ]
        return cmd, {"PGPASSWORD": password}
    if not shutil.which("pg_dump"):
        raise ValueError("缺少数据库备份工具 pg_dump，且容器编排上下文不可用")
    cmd = [
        "pg_dump",
        "-h", db_cfg["host"],
        "-p", str(db_cfg["port"]),
        "-U", db_cfg["user"],
        "-d", db_cfg["name"],
        "--clean", "--if-exists", "--no-owner", "--no-privileges",
    ]
    return cmd, {"PGPASSWORD": password}


def build_db_restore_command(db_cfg: dict, compose_ctx: dict = None) -> tuple:
    driver = db_cfg["driver"]
    if driver not in {"mysql", "pgsql"}:
        raise ValueError(f"build_db_restore_command 仅支持 mysql/pgsql，收到: {driver}")
    validate_db_cli_params(db_cfg["host"], db_cfg["port"], db_cfg["user"], db_cfg["name"])
    password = db_cfg.get("pass", "")

    if driver == "mysql":
        if compose_ctx and db_cfg["host"] in {"hwadmin-mysql", "mysql", "db"}:
            cmd = compose_base_args(compose_ctx) + [
                "exec", "-T", "-e", "MYSQL_PWD",
                "hwadmin-mysql", "mysql",
                "-u", db_cfg["user"],
                db_cfg["name"],
            ]
            return cmd, {"MYSQL_PWD": password}
        if not shutil.which("mysql"):
            raise ValueError("缺少数据库恢复工具 mysql，且容器编排上下文不可用")
        cmd = [
            "mysql",
            "-h", db_cfg["host"],
            "-P", str(db_cfg["port"]),
            "-u", db_cfg["user"],
            db_cfg["name"],
        ]
        return cmd, {"MYSQL_PWD": password}

    if compose_ctx and db_cfg["host"] in {"hwadmin-postgres", "postgres", "pg", "db"}:
        cmd = compose_base_args(compose_ctx) + [
            "exec", "-T", "-e", "PGPASSWORD",
            "hwadmin-postgres", "psql",
            "-U", db_cfg["user"],
            "-d", db_cfg["name"],
            "-v", "ON_ERROR_STOP=1",
        ]
        return cmd, {"PGPASSWORD": password}
    if not shutil.which("psql"):
        raise ValueError("缺少数据库恢复工具 psql，且容器编排上下文不可用")
    cmd = [
        "psql",
        "-h", db_cfg["host"],
        "-p", str(db_cfg["port"]),
        "-U", db_cfg["user"],
        "-d", db_cfg["name"],
        "-v", "ON_ERROR_STOP=1",
    ]
    return cmd, {"PGPASSWORD": password}


def collect_migrations_state(server_dir: Path) -> dict:
    """Scan migration PHP files under database/migrations and plugins/*/database/migrations."""
    result = {}
    roots = [server_dir / "database/migrations"]
    plugins_dir = server_dir / "plugins"
    if plugins_dir.is_dir():
        for p in sorted(plugins_dir.iterdir()):
            if p.is_dir() and (p / "database/migrations").is_dir():
                roots.append(p / "database/migrations")

    for r_dir in roots:
        if not r_dir.is_dir():
            continue
        for mf in sorted(r_dir.glob("*.php")):
            if not mf.is_file() or mf.is_symlink():
                continue
            rel = mf.relative_to(server_dir).as_posix()
            m = re.match(r"^(\d+)_", mf.name)
            version = m.group(1) if m else mf.stem
            result[rel] = {
                "version": version,
                "sha256": calculate_sha256(mf),
                "path": mf,
            }
    return result


def collect_migrations_from_snapshot(code_zip_path: Path) -> dict:
    """Fallback migration map extracted from backup code/snapshot.zip."""
    result = {}
    if not code_zip_path.is_file():
        return result
    with zipfile.ZipFile(code_zip_path, mode="r") as zf:
        for info in zf.infolist():
            if info.is_dir():
                continue
            norm = info.filename
            if norm.startswith("server/"):
                norm = norm.removeprefix("server/")
            if (norm.startswith("database/migrations/") or re.match(r"^plugins/[^/]+/database/migrations/", norm)) and norm.endswith(".php"):
                raw = zf.read(info.filename)
                m = re.match(r"^(\d+)_", Path(norm).name)
                version = m.group(1) if m else Path(norm).stem
                result[norm] = {
                    "version": version,
                    "sha256": calculate_bytes_sha256(raw),
                }
    return result


def strip_php_comments_preserving_strings(code: str) -> str:
    """Strip PHP //, #, and /* */ comments while keeping string literals intact."""
    out = []
    i = 0
    n = len(code)
    while i < n:
        ch = code[i]
        if ch in ("'", '"'):
            quote = ch
            out.append(ch)
            i += 1
            while i < n:
                c = code[i]
                out.append(c)
                if c == "\\" and i + 1 < n:
                    out.append(code[i + 1])
                    i += 2
                    continue
                if c == quote:
                    i += 1
                    break
                i += 1
            continue
        if ch == "/" and i + 1 < n and code[i + 1] == "/":
            i += 2
            while i < n and code[i] != "\n":
                i += 1
            continue
        if ch == "#":
            i += 1
            while i < n and code[i] != "\n":
                i += 1
            continue
        if ch == "/" and i + 1 < n and code[i + 1] == "*":
            i += 2
            while i + 1 < n and not (code[i] == "*" and code[i + 1] == "/"):
                i += 1
            i += 2
            out.append(" ")
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def extract_php_method_bodies(php_source: str, method_names=("up", "change")) -> str:
    """Extract only up()/change() bodies so down() cleanup code never triggers false positives."""
    cleaned = strip_php_comments_preserving_strings(php_source)
    bodies = []
    pattern = re.compile(r'\bfunction\s+(' + '|'.join(re.escape(m) for m in method_names) + r')\s*\([^)]*\)(?:\s*:\s*[\w\\|?]+)?\s*\{', re.I)
    for match in pattern.finditer(cleaned):
        idx = match.end() - 1
        depth = 0
        i = idx
        n = len(cleaned)
        while i < n:
            ch = cleaned[i]
            if ch in ("'", '"'):
                quote = ch
                i += 1
                while i < n:
                    if cleaned[i] == "\\" and i + 1 < n:
                        i += 2
                        continue
                    if cleaned[i] == quote:
                        i += 1
                        break
                    i += 1
                continue
            if ch == "{":
                depth += 1
            elif ch == "}":
                depth -= 1
                if depth == 0:
                    bodies.append(cleaned[idx + 1:i])
                    break
            i += 1
    return "\n".join(bodies) if bodies else cleaned


def detect_destructive_migration_ops(php_source: str) -> list:
    body = extract_php_method_bodies(php_source, ("up", "change"))
    found = []
    for regex, label in DESTRUCTIVE_MIGRATION_PATTERNS:
        if regex.search(body):
            if label not in found:
                found.append(label)
    return found


def query_applied_migration_versions(target_server_dir: Path, db_cfg: dict, compose_ctx: dict = None) -> set | None:
    driver = db_cfg.get("driver", "sqlite")
    prefix = db_cfg.get("prefix", "hw_")
    if not re.fullmatch(r"[a-zA-Z0-9_]*", prefix):
        prefix = "hw_"
    candidates = [f"{prefix}migrations", "migrations"]

    if driver == "sqlite":
        db_path = Path(db_cfg.get("name") or (target_server_dir / "runtime/dev.sqlite"))
        if not db_path.is_absolute():
            db_path = target_server_dir / db_path
        if not db_path.is_file():
            return None
        try:
            with closing(sqlite3.connect(str(db_path))) as conn:
                tables = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()}
                for tbl in candidates:
                    if tbl in tables:
                        rows = conn.execute(f"SELECT version FROM {tbl}").fetchall()
                        return {str(r[0]) for r in rows}
            return set()
        except Exception:
            return None

    # MySQL / PostgreSQL query via DB client or container exec
    try:
        validate_db_cli_params(db_cfg["host"], db_cfg["port"], db_cfg["user"], db_cfg["name"])
    except ValueError:
        return None

    for tbl in candidates:
        sql = f"SELECT version FROM {tbl};"
        try:
            if driver == "mysql":
                if compose_ctx and db_cfg["host"] in {"hwadmin-mysql", "mysql", "db"}:
                    cmd = compose_base_args(compose_ctx) + [
                        "exec", "-T", "-e", "MYSQL_PWD",
                        "hwadmin-mysql", "mysql", "-N", "-B", "-u", db_cfg["user"], db_cfg["name"], "-e", sql,
                    ]
                    env_extra = {"MYSQL_PWD": db_cfg.get("pass", "")}
                elif shutil.which("mysql"):
                    cmd = ["mysql", "-N", "-B", "-h", db_cfg["host"], "-P", str(db_cfg["port"]), "-u", db_cfg["user"], db_cfg["name"], "-e", sql]
                    env_extra = {"MYSQL_PWD": db_cfg.get("pass", "")}
                else:
                    return None
            else:
                if compose_ctx and db_cfg["host"] in {"hwadmin-postgres", "postgres", "pg", "db"}:
                    cmd = compose_base_args(compose_ctx) + [
                        "exec", "-T", "-e", "PGPASSWORD",
                        "hwadmin-postgres", "psql", "-t", "-A", "-U", db_cfg["user"], "-d", db_cfg["name"], "-c", sql,
                    ]
                    env_extra = {"PGPASSWORD": db_cfg.get("pass", "")}
                elif shutil.which("psql"):
                    cmd = ["psql", "-t", "-A", "-h", db_cfg["host"], "-p", str(db_cfg["port"]), "-U", db_cfg["user"], "-d", db_cfg["name"], "-c", sql]
                    env_extra = {"PGPASSWORD": db_cfg.get("pass", "")}
                else:
                    return None

            rc, out, _ = run_subprocess(cmd, env=env_extra, timeout=15)
            if rc == 0:
                lines = [ln.strip() for ln in out.decode("utf-8", errors="ignore").splitlines() if ln.strip().isdigit()]
                return set(lines)
        except Exception:
            continue
    return None


def check_code_only_migration_compatibility(
    target_server_dir: Path,
    b_path: Path,
    manifest: dict,
    db_cfg: dict,
    compose_ctx: dict = None,
) -> tuple:
    """
    Compare current target migrations against backup snapshot.
    Block --mode=code-only when destructive schema changes (dropTable/removeColumn/changeColumn/...) exist
    and may have been applied since the backup point.
    """
    backup_migs = {}
    snap_meta = manifest.get("migrations_snapshot")
    if isinstance(snap_meta, dict) and isinstance(snap_meta.get("files"), dict):
        for rel, info in snap_meta["files"].items():
            if isinstance(info, dict):
                backup_migs[rel] = info
    if not backup_migs:
        backup_migs = collect_migrations_from_snapshot(b_path / "code/snapshot.zip")

    current_migs = collect_migrations_state(target_server_dir)
    applied_versions = query_applied_migration_versions(target_server_dir, db_cfg, compose_ctx)

    added_or_modified = []
    for rel, cur_info in current_migs.items():
        old_info = backup_migs.get(rel)
        if old_info is None or old_info.get("sha256") != cur_info.get("sha256"):
            added_or_modified.append((rel, cur_info, old_info is None))

    destructive_hits = []
    non_destructive = []
    for rel, cur_info, is_new in added_or_modified:
        src_text = cur_info["path"].read_text(encoding="utf-8", errors="ignore")
        ops = detect_destructive_migration_ops(src_text)
        version = cur_info["version"]
        if ops:
            # If we definitively verified against DB that this migration was NEVER applied, it is not destructive in DB
            if applied_versions is not None and version not in applied_versions and is_new:
                non_destructive.append({"file": rel, "version": version, "applied": False, "ops": ops})
                continue
            destructive_hits.append({
                "file": rel,
                "version": version,
                "applied": (version in applied_versions) if applied_versions is not None else "unknown",
                "destructive_ops": ops,
            })
        else:
            non_destructive.append({"file": rel, "version": version, "applied": (version in applied_versions) if applied_versions is not None else "unknown"})

    details = {
        "added_or_modified_count": len(added_or_modified),
        "destructive_migrations": destructive_hits,
        "non_destructive_migrations": non_destructive,
        "db_applied_versions_checked": applied_versions is not None,
    }
    if destructive_hits:
        summary_items = ", ".join(f"{h['file']} ({'/'.join(h['destructive_ops'])})" for h in destructive_hits)
        msg = (
            f"检测到破坏性 Schema 迁移变更，禁止仅使用 --mode=code-only 回退代码！"
            f"涉及迁移: {summary_items}。请改用 --mode=full 同步恢复匹配的数据库快照。"
        )
        return False, msg, details
    return True, "Schema 兼容性核验通过（无已应用的破坏性迁移差异）", details


def backup_uploads_consistent(uploads_src: Path, data_bak_dir: Path) -> tuple:
    """
    Consistent tar.gz packaging + per-file SHA-256 manifest for public/uploads.
    Returns (uploads_meta, checksum_updates).
    """
    checksums = {}
    files_manifest = {}
    if uploads_src.is_dir():
        for root, _, files in os.walk(uploads_src):
            for f in sorted(files):
                if f in {".gitkeep", ".gitignore", ".htaccess"}:
                    continue
                full_u = Path(root) / f
                if full_u.is_symlink():
                    continue
                if full_u.is_file():
                    rel_u = full_u.relative_to(uploads_src).as_posix()
                    files_manifest[rel_u] = calculate_sha256(full_u)

    manifest_obj = {
        "version": 1,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "file_count": len(files_manifest),
        "files": files_manifest,
    }
    manifest_file = data_bak_dir / "uploads-manifest.json"
    manifest_file.write_text(json.dumps(manifest_obj, ensure_ascii=False, indent=2), encoding="utf-8")
    safe_chmod(manifest_file, 0o600)
    checksums["data/uploads-manifest.json"] = calculate_sha256(manifest_file)

    uploads_tar = data_bak_dir / "public_uploads.tar.gz"
    with tarfile.open(uploads_tar, "w:gz", dereference=False) as tf:
        if files_manifest:
            for rel_u in sorted(files_manifest):
                tf.add(uploads_src / rel_u, arcname=f"uploads/{rel_u}", recursive=False)
        else:
            root_info = tarfile.TarInfo("uploads")
            root_info.type = tarfile.DIRTYPE
            root_info.mode = 0o755
            tf.addfile(root_info)

    with tarfile.open(uploads_tar, "r:gz") as tf:
        safe_tar_members(tf, prefix="uploads")
    safe_chmod(uploads_tar, 0o600)
    checksums["data/public_uploads.tar.gz"] = calculate_sha256(uploads_tar)

    meta = {
        "uploads_archive": "data/public_uploads.tar.gz",
        "uploads_manifest": "data/uploads-manifest.json",
        "uploads_expected": len(files_manifest) > 0,
        "uploads_file_count": len(files_manifest),
        "uploads_files": [],
    }
    return meta, checksums


def create_backup_snapshot(
    backup_dir: Path,
    target: str,
    target_dir: Path,
    target_server_dir: Path,
    artifact_type: str,
    artifact: dict,
    db_cfg: dict,
    compose_ctx: dict = None,
) -> dict:
    """
    Create a verified backup snapshot (code zip + .env + install.lock + DB dump/SQLite + uploads tar.gz + manifests).
    Raises ValueError/RuntimeError on any backup failure before target files are modified.
    """
    if backup_dir.exists() and (not backup_dir.is_dir() or any(backup_dir.iterdir())):
        raise ValueError("备份目录非空，拒绝覆盖既有恢复点")

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S_%f")
    backup_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    safe_chmod(backup_dir, 0o700)
    code_bak_dir = backup_dir / "code"
    data_bak_dir = backup_dir / "data"
    code_bak_dir.mkdir(parents=True, exist_ok=True)
    data_bak_dir.mkdir(parents=True, exist_ok=True)

    backed_up_files = []
    checksums = {}

    # 1. 代码快照 code/snapshot.zip
    code_snapshot_zip = code_bak_dir / "snapshot.zip"
    code_member_count = 0
    with zipfile.ZipFile(code_snapshot_zip, mode="w", compression=zipfile.ZIP_DEFLATED) as czf:
        if target_dir.is_dir():
            for root, dirs, files in os.walk(target_dir):
                parent_name = Path(root).name
                dirs[:] = [
                    d for d in dirs
                    if d not in {".git", "node_modules", "__pycache__", ".hw-state", "release"}
                    and not (parent_name == "src-tauri" and d == "target")
                ]
                for f in sorted(files):
                    full_p = Path(root) / f
                    if full_p.is_symlink():
                        continue
                    try:
                        full_p.relative_to(backup_dir)
                        continue
                    except ValueError:
                        pass
                    rel_p = full_p.relative_to(target_dir).as_posix()
                    if should_skip_code_snapshot(rel_p):
                        continue
                    czf.write(full_p, arcname=rel_p)
                    code_member_count += 1
                    backed_up_files.append(f"code:{rel_p}")

    if code_member_count == 0:
        with zipfile.ZipFile(code_snapshot_zip, mode="w", compression=zipfile.ZIP_DEFLATED) as czf:
            czf.writestr(".hw-initial-empty-target", timestamp)
    safe_chmod(code_snapshot_zip, 0o600)
    checksums["code/snapshot.zip"] = calculate_sha256(code_snapshot_zip)

    # 2. 持久化配置文件 (.env, .env.docker, install.lock)
    env_backed = None
    for ec in (target_server_dir / ".env", target_dir / ".env"):
        if ec.is_file():
            shutil.copy2(ec, data_bak_dir / ".env")
            safe_chmod(data_bak_dir / ".env", 0o600)
            checksums["data/.env"] = calculate_sha256(data_bak_dir / ".env")
            env_backed = "data/.env"
            backed_up_files.append("data/.env")
            break

    docker_env_backed = None
    docker_env_src = target_dir / ".env.docker"
    if docker_env_src.is_file():
        shutil.copy2(docker_env_src, data_bak_dir / ".env.docker")
        safe_chmod(data_bak_dir / ".env.docker", 0o600)
        checksums["data/.env.docker"] = calculate_sha256(data_bak_dir / ".env.docker")
        docker_env_backed = "data/.env.docker"
        backed_up_files.append("data/.env.docker")

    lock_backed = None
    for lc in (target_server_dir / "config/install.lock", target_dir / "config/install.lock"):
        if lc.is_file():
            shutil.copy2(lc, data_bak_dir / "install.lock")
            safe_chmod(data_bak_dir / "install.lock", 0o600)
            checksums["data/install.lock"] = calculate_sha256(data_bak_dir / "install.lock")
            lock_backed = "data/install.lock"
            backed_up_files.append("data/install.lock")
            break

    # 3. 数据库一致性备份 (SQLite / MySQL / PostgreSQL — 禁止以 SQLite 代替 MySQL/PG)
    sqlite_files_backed = []
    database_dump_rel = None
    is_installed = lock_backed is not None
    db_driver = db_cfg.get("driver", "sqlite")

    if db_driver == "sqlite":
        sqlite_bak_dir = data_bak_dir / "sqlite"
        for rc_dir in (target_server_dir / "runtime", target_dir / "runtime"):
            if rc_dir.is_dir():
                for sf in sorted(rc_dir.iterdir()):
                    if sf.is_file() and re.search(r"\.(sqlite3?|db)$", sf.name, re.IGNORECASE):
                        sqlite_bak_dir.mkdir(parents=True, exist_ok=True)
                        dest_sf = sqlite_bak_dir / sf.name
                        backup_sqlite(sf, dest_sf)
                        safe_chmod(dest_sf, 0o600)
                        rel_k = f"data/sqlite/{sf.name}"
                        checksums[rel_k] = calculate_sha256(dest_sf)
                        sqlite_files_backed.append(rel_k)
                        backed_up_files.append(rel_k)
                break
    elif db_driver in {"mysql", "pgsql"}:
        # 仅当已安装或显式请求了既有库时执行严格转储；若转储失败立即阻断，绝不降级为文件拷贝
        if is_installed or env_backed is not None:
            dump_cmd, dump_env = build_db_dump_command(db_cfg, compose_ctx)
            rc, out_bytes, err_bytes = run_subprocess(dump_cmd, cwd=target_dir, env=dump_env, timeout=300)
            if rc != 0:
                err_txt = (err_bytes or out_bytes or b"").decode("utf-8", errors="ignore").strip()
                raise RuntimeError(f"{db_driver} 一致性备份失败 (exit {rc}): {err_txt[:300]}")
            if not out_bytes or not out_bytes.strip():
                raise RuntimeError(f"{db_driver} 一致性备份失败: 转储输出为空")
            dump_path = data_bak_dir / "database.sql"
            dump_path.write_bytes(out_bytes)
            safe_chmod(dump_path, 0o600)
            database_dump_rel = "data/database.sql"
            checksums[database_dump_rel] = calculate_sha256(dump_path)
            backed_up_files.append(database_dump_rel)

    # 4. public/uploads 一致性打包与逐文件 SHA-256 清单
    uploads_meta, uploads_checksums = backup_uploads_consistent(target_server_dir / "public/uploads", data_bak_dir)
    checksums.update(uploads_checksums)
    for uk in uploads_checksums:
        backed_up_files.append(uk)

    # 5. 记录迁移快照与已应用版本
    mig_state = collect_migrations_state(target_server_dir)
    applied_versions = query_applied_migration_versions(target_server_dir, db_cfg, compose_ctx) if is_installed else set()
    migrations_snapshot = {
        "files": {rel: {"version": info["version"], "sha256": info["sha256"]} for rel, info in mig_state.items()},
        "versions": sorted(info["version"] for info in mig_state.values()),
        "applied_versions": sorted(applied_versions) if applied_versions is not None else None,
    }

    backup_manifest = {
        "backup_id": backup_dir.name,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "target": target,
        "target_dir": str(target_dir),
        "target_server_dir": str(target_server_dir),
        "db_driver": db_driver,
        "artifact_type": artifact_type,
        "artifact_applied": artifact,
        "has_code_snapshot": True,
        "code_member_count": code_member_count,
        "initial_install": not is_installed,
        "backed_up_files": backed_up_files,
        "migrations_snapshot": migrations_snapshot,
        "data_backups": {
            "env_file": env_backed,
            "docker_env_file": docker_env_backed,
            "install_lock": lock_backed,
            "database_dump": database_dump_rel,
            "sqlite_files": sqlite_files_backed,
            **uploads_meta,
        },
        "checksums": checksums,
    }
    manifest_path = backup_dir / "backup-manifest.json"
    manifest_path.write_text(json.dumps(backup_manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    safe_chmod(manifest_path, 0o600)
    return backup_manifest


def generate_prod_nginx_conf(domain: str = None, use_https: bool = False, server_subpath: str = "") -> str:
    server_name = domain.strip() if domain and domain.strip() else "localhost"
    doc_root = f"/var/www/hwadmin{server_subpath}/public"
    ssl_block = ""
    if use_https:
        ssl_block = (
            "    listen 443 ssl;\n"
            "    ssl_certificate /etc/nginx/ssl/fullchain.pem;\n"
            "    ssl_certificate_key /etc/nginx/ssl/privkey.pem;\n"
            "    ssl_protocols TLSv1.2 TLSv1.3;\n"
            "    ssl_session_timeout 10m;\n"
            '    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;\n'
        )
    return (
        "# HwAdmin Production Nginx Configuration (Generated by hw-deploy)\n"
        "server {\n"
        "    listen 80;\n"
        f"{ssl_block}"
        f"    server_name {server_name};\n"
        f"    root {doc_root};\n"
        "    index index.php index.html;\n"
        "    charset utf-8;\n"
        "    client_max_body_size 500M;\n"
        "    server_tokens off;\n"
        "    gzip on;\n"
        "    gzip_static on;\n"
        "    gzip_vary on;\n"
        "    gzip_min_length 1024;\n"
        "    gzip_types text/plain text/css text/javascript application/json application/javascript font/woff2 font/ttf image/svg+xml;\n\n"
        "    location ~* ^/(uploads|plugins)/.*\\.(php\\d*|phtml|phar|sh|cgi|pl|py|asp|aspx|jsp|html?|svg)(/|$) { return 404; }\n"
        "    location ~ /\\. { return 404; }\n"
        "    location ~* \\.(sql|bak|inc|old|env|sqlite|zip)$ { return 404; }\n"
        "    location = /admin/index.html {\n"
        '        add_header Cache-Control "no-cache" always;\n'
        "        rewrite ^(.*)$ /index.php?s=/admin last;\n"
        "    }\n"
        "    location ~* ^/admin/static/ {\n"
        "        try_files $uri =404;\n"
        "        expires 1y;\n"
        "        add_header X-Content-Type-Options nosniff always;\n"
        '        add_header Cache-Control "public, max-age=31536000, immutable";\n'
        "    }\n"
        "    location = /admin { rewrite ^(.*)$ /index.php?s=/admin last; }\n"
        "    location = /admin/ {\n"
        '        add_header Cache-Control "no-cache" always;\n'
        "        rewrite ^(.*)$ /index.php?s=/admin last;\n"
        "    }\n"
        "    location ^~ /admin/ {\n"
        "        try_files $uri =404;\n"
        "    }\n"
        "    location /uploads/ {\n"
        "        try_files $uri =404;\n"
        "        add_header X-Content-Type-Options nosniff always;\n"
        "    }\n"
        "    location /plugins/ { try_files $uri =404; }\n"
        "    location / { try_files $uri $uri/ /index.php?s=$uri&$args; }\n"
        "    location = /index.php {\n"
        "        include fastcgi_params;\n"
        "        fastcgi_param SCRIPT_FILENAME $document_root/index.php;\n"
        '        fastcgi_param HTTP_PROXY "";\n'
        "        fastcgi_pass hwadmin-php:9000;\n"
        "        fastcgi_read_timeout 300;\n"
        "    }\n"
        "    location ~* \\.php { return 404; }\n"
        "}\n"
    )


def generate_prod_compose_yml(db_driver: str, server_subpath: str, use_https: bool) -> str:
    https_ports = '      - "${HTTPS_PORT:-8443}:443"\n' if use_https else ""
    https_vol = "      - ./.hw-deploy/ssl:/etc/nginx/ssl:ro\n" if use_https else ""

    if db_driver == "pgsql":
        db_service_name = "hwadmin-postgres"
        db_volume_name = "pg_data"
        db_service_block = (
            "  hwadmin-postgres:\n"
            "    image: postgres:16-alpine\n"
            "    restart: unless-stopped\n"
            "    environment:\n"
            '      POSTGRES_DB: "${DB_NAME:-hwadmin}"\n'
            '      POSTGRES_USER: "${DB_USER:-hwadmin_app}"\n'
            '      POSTGRES_PASSWORD: "${DB_PASS:?DB_PASS is required}"\n'
            '      TZ: "Asia/Shanghai"\n'
            "    volumes:\n"
            "      - pg_data:/var/lib/postgresql/data\n"
            "    healthcheck:\n"
            '      test: ["CMD-SHELL", "pg_isready -U \\"$${POSTGRES_USER:-hwadmin_app}\\" -d \\"$${POSTGRES_DB:-hwadmin}\\""]\n'
            "      interval: 5s\n"
            "      timeout: 5s\n"
            "      retries: 12\n"
            "      start_period: 10s\n"
            "    networks:\n"
            "      hwadmin-net:\n"
            "        aliases:\n"
            "          - hwadmin-postgres\n"
        )
    else:
        db_service_name = "hwadmin-mysql"
        db_volume_name = "mysql_data"
        db_service_block = (
            "  hwadmin-mysql:\n"
            "    image: mysql:8.0\n"
            "    restart: unless-stopped\n"
            "    command: --default-authentication-plugin=mysql_native_password --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci\n"
            "    environment:\n"
            '      MYSQL_ROOT_PASSWORD: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD is required}"\n'
            '      MYSQL_DATABASE: "${DB_NAME:-hwadmin}"\n'
            '      MYSQL_USER: "${DB_USER:-hwadmin_app}"\n'
            '      MYSQL_PASSWORD: "${DB_PASS:?DB_PASS is required}"\n'
            '      TZ: "Asia/Shanghai"\n'
            "    volumes:\n"
            "      - mysql_data:/var/lib/mysql\n"
            "    healthcheck:\n"
            '      test: ["CMD-SHELL", "mysqladmin ping -h localhost -uroot -p\\"$$MYSQL_ROOT_PASSWORD\\""]\n'
            "      interval: 5s\n"
            "      timeout: 5s\n"
            "      retries: 12\n"
            "      start_period: 10s\n"
            "    networks:\n"
            "      hwadmin-net:\n"
            "        aliases:\n"
            "          - hwadmin-mysql\n"
        )

    return (
        "# HwAdmin Production Docker Compose (Generated by hw-deploy)\n"
        "services:\n"
        "  hwadmin-nginx:\n"
        "    image: nginx:1.26-alpine\n"
        "    restart: unless-stopped\n"
        "    ports:\n"
        '      - "${HTTP_PORT:-8080}:80"\n'
        f"{https_ports}"
        "    volumes:\n"
        "      - ./:/var/www/hwadmin\n"
        "      - ./.hw-deploy/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro\n"
        f"{https_vol}"
        "    depends_on:\n"
        "      - hwadmin-php\n"
        "    networks:\n"
        "      - hwadmin-net\n\n"
        "  hwadmin-php:\n"
        "    build:\n"
        "      context: ./.hw-deploy/docker/php\n"
        "      dockerfile: Dockerfile\n"
        "    restart: unless-stopped\n"
        f"    working_dir: /var/www/hwadmin{server_subpath}\n"
        "    volumes:\n"
        "      - ./:/var/www/hwadmin\n"
        "    environment:\n"
        '      APP_DEBUG: "false"\n'
        f'      DB_DRIVER: "${{DB_DRIVER:-{db_driver}}}"\n'
        f'      DB_TYPE: "${{DB_TYPE:-{db_driver}}}"\n'
        f'      DB_HOST: "${{DB_HOST:-{db_service_name}}}"\n'
        f'      DB_PORT: "${{DB_PORT:-{"5432" if db_driver == "pgsql" else "3306"}}}"\n'
        '      DB_NAME: "${DB_NAME:-hwadmin}"\n'
        '      DB_USER: "${DB_USER:-hwadmin_app}"\n'
        '      DB_PASS: "${DB_PASS:?DB_PASS is required}"\n'
        '      DB_PREFIX: "${DB_PREFIX:-hw_}"\n'
        '      CACHE_DRIVER: "${CACHE_DRIVER:-redis}"\n'
        '      REDIS_HOST: "${REDIS_HOST:-hwadmin-redis}"\n'
        '      REDIS_PORT: "${REDIS_PORT:-6379}"\n'
        '      REDIS_PASSWORD: "${REDIS_PASSWORD:?REDIS_PASSWORD is required}"\n'
        "    depends_on:\n"
        f"      {db_service_name}:\n"
        "        condition: service_healthy\n"
        "      hwadmin-redis:\n"
        "        condition: service_healthy\n"
        "    networks:\n"
        "      hwadmin-net:\n"
        "        aliases:\n"
        "          - hwadmin-php\n\n"
        f"{db_service_block}\n"
        "  hwadmin-redis:\n"
        "    image: redis:7-alpine\n"
        "    restart: unless-stopped\n"
        "    environment:\n"
        '      REDIS_PASSWORD: "${REDIS_PASSWORD:?REDIS_PASSWORD is required}"\n'
        '    command: ["sh", "-c", "exec redis-server --appendonly yes --requirepass \\"$$REDIS_PASSWORD\\""]\n'
        "    volumes:\n"
        "      - redis_data:/data\n"
        "    healthcheck:\n"
        '      test: ["CMD-SHELL", "redis-cli -a \\"$$REDIS_PASSWORD\\" ping | grep PONG"]\n'
        "      interval: 5s\n"
        "      timeout: 5s\n"
        "      retries: 8\n"
        "      start_period: 5s\n"
        "    networks:\n"
        "      hwadmin-net:\n"
        "        aliases:\n"
        "          - hwadmin-redis\n\n"
        "networks:\n"
        "  hwadmin-net:\n"
        "    driver: bridge\n\n"
        "volumes:\n"
        f"  {db_volume_name}:\n"
        "  redis_data:\n"
    )


def parse_compose_ps_json(raw_bytes: bytes) -> list:
    text = raw_bytes.decode("utf-8", errors="ignore").strip()
    if not text:
        return []
    try:
        parsed = json.loads(text)
        if isinstance(parsed, list):
            return parsed
        if isinstance(parsed, dict):
            return [parsed]
    except Exception:
        pass
    items = []
    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            obj = json.loads(line)
            if isinstance(obj, dict):
                items.append(obj)
        except Exception:
            pass
    return items


def run_production_health_probe(
    compose_ctx: dict,
    db_driver: str,
    http_port: str,
    https_port: str = None,
    domain: str = None,
    use_https: bool = False,
) -> tuple:
    """
    真实生产健康探针：
    1. 校验容器编排服务运行态（nginx + php-fpm + redis + mysql/postgres）
    2. 明确检查并拒绝 php -S 内置开发服务器进程与响应头
    3. 探测真实 HTTP/HTTPS 业务端点 (/api/index/ping, /adminapi/index/info, /{admin_entry}/)
    """
    expected_db = "hwadmin-postgres" if db_driver == "pgsql" else "hwadmin-mysql"
    required_services = {"hwadmin-nginx", "hwadmin-php", "hwadmin-redis", expected_db}

    rc_ps, out_ps, err_ps = run_subprocess(
        compose_base_args(compose_ctx) + ["ps", "--format", "json"],
        cwd=compose_ctx["cwd"],
        timeout=20,
    )
    if rc_ps != 0:
        err_txt = (err_ps or out_ps or b"").decode("utf-8", errors="ignore").strip()
        return False, f"查询生产容器运行状态失败 (exit {rc_ps}): {err_txt}"

    ps_items = parse_compose_ps_json(out_ps)
    service_states = {}
    for item in ps_items:
        svc = str(item.get("Service") or item.get("Name") or "")
        state = str(item.get("State") or "").lower()
        health = str(item.get("Health") or "").lower()
        service_states[svc] = {"state": state, "health": health}

    not_running = []
    for req_svc in sorted(required_services):
        info = service_states.get(req_svc)
        if not info or info["state"] != "running" or info["health"] in {"unhealthy", "starting"}:
            not_running.append(f"{req_svc}:{info['state'] if info else 'missing'}")
    if not_running:
        return False, f"生产必需容器服务未处于 running 状态: {', '.join(not_running)}"

    # 验证 PHP 容器内运行的是 php-fpm，严禁将 php -S 当作生产部署成功证据
    rc_proc, out_proc, err_proc = run_subprocess(
        compose_base_args(compose_ctx) + ["exec", "-T", "hwadmin-php", "ps", "-eo", "args"],
        cwd=compose_ctx["cwd"],
        timeout=15,
    )
    if rc_proc != 0:
        err_txt = (err_proc or out_proc or b"").decode("utf-8", errors="ignore").strip()
        return False, f"无法校验 PHP 容器运行进程: {err_txt}"
    proc_text = out_proc.decode("utf-8", errors="ignore")
    if re.search(r"\bphp\s+-S\b", proc_text):
        return False, "安全拦截：检测到 php -S 开发内置服务器正在运行，拒绝将其作为生产部署成功证据"
    if "php-fpm" not in proc_text.lower():
        return False, f"生产 PHP 容器未运行 php-fpm 主进程: {proc_text[:200]}"

    # Root CLI installs can succeed while FPM's www-data worker cannot read 0600
    # host-owned .env or write runtime/uploads. Check as the actual worker account.
    worker_check = (
        "$ok=is_readable('.env') && is_dir('runtime') && is_writable('runtime')"
        " && is_dir('public/uploads') && is_writable('public/uploads'); exit($ok ? 0 : 1);"
    )
    rc_access, _, _ = run_subprocess(
        compose_base_args(compose_ctx) + ["exec", "-T", "--user", "www-data", "hwadmin-php",
                                          "php", "-r", worker_check],
        cwd=compose_ctx["cwd"], timeout=15,
    )
    if rc_access != 0:
        return False, "PHP-FPM www-data 无法读取 .env 或写入 runtime/public/uploads；请修正挂载目录权限后重试，不能以 root CLI 成功放行"

    base_scheme = "https" if use_https else "http"
    target_port = https_port if use_https and https_port else http_port
    base_url = f"{base_scheme}://127.0.0.1:{target_port}"
    req_headers = {"Host": domain} if domain else {}

    ping_ok = False
    ping_payload = {}
    ping_server_header = ""
    last_err = ""
    for _ in range(15):
        try:
            status, hdrs, body_bytes = http_get_response(
                f"{base_url}/api/index/ping",
                headers=req_headers,
                timeout=3.0,
                verify_tls=False,
            )
            ping_server_header = hdrs.get("server", "")
            if re.search(r"PHP/\S*\s*Development\s+Server", ping_server_header, re.I):
                return False, f"安全拦截：目标端口响应 Server={ping_server_header} 为 PHP 内置开发服务器，禁止冒充生产服务"
            body = body_bytes.decode("utf-8", errors="ignore")
            data = json.loads(body)
            if status == 200 and data.get("code") == 1:
                ping_ok = True
                ping_payload = data
                break
            last_err = f"HTTP {status}: {body[:120]}"
        except Exception as e:
            last_err = str(e)
            time.sleep(0.4)

    if not ping_ok:
        return False, f"生产健康探针 {base_url}/api/index/ping 失败: {last_err}"

    # 校验鉴权拦截 /adminapi/index/info 返回 code=401
    try:
        info_status, info_hdrs, info_bytes = http_get_response(
            f"{base_url}/adminapi/index/info",
            headers=req_headers,
            timeout=3.0,
            verify_tls=False,
        )
        info_server = info_hdrs.get("server", "")
        if re.search(r"PHP/\S*\s*Development\s+Server", info_server, re.I):
            return False, f"安全拦截：目标端口响应 Server={info_server} 为 PHP 内置开发服务器"
        info_body = info_bytes.decode("utf-8", errors="ignore")
        info_data = json.loads(info_body)
        if info_data.get("code") != 401:
            return False, f"鉴权探针 /adminapi/index/info 预期 code=401，实际: {info_body[:120]}"
    except Exception as e:
        return False, f"鉴权探针 /adminapi/index/info 请求异常: {e}"

    # 校验前端管理端入口 /{admin_entry}/（支持自定义安全入口）
    admin_entry = resolve_admin_entry(
        base_url,
        headers=req_headers,
        server_roots=[compose_ctx.get("cwd")],
        verify_tls=False,
    )
    try:
        admin_status, admin_hdrs, _ = http_get_response(
            f"{base_url}/{admin_entry}/",
            headers=req_headers,
            timeout=3.0,
            verify_tls=False,
        )
        admin_server = admin_hdrs.get("server", "")
        if re.search(r"PHP/\S*\s*Development\s+Server", admin_server, re.I):
            return False, f"安全拦截：目标端口响应 Server={admin_server} 为 PHP 内置开发服务器"
        if admin_status not in (200, 304):
            return False, f"管理端静态入口 /{admin_entry}/ 返回异常状态码: {admin_status}"
    except Exception as e:
        return False, f"管理端静态入口 /{admin_entry}/ 请求异常: {e}"

    if admin_entry != "admin":
        try:
            legacy_status, _, _ = http_get_response(
                f"{base_url}/admin/",
                headers=req_headers,
                timeout=3.0,
                verify_tls=False,
            )
            if legacy_status == 200:
                return False, f"安全拦截：已配置自定义入口 /{admin_entry}/，但默认 /admin/ 仍返回 200"
        except Exception:
            pass

    return True, {
        "probe_mode": "production_container",
        "builtin_dev_server_rejected": True,
        "server_header": ping_server_header or "nginx",
        "ping_url": f"{base_url}/api/index/ping",
        "ping_code": ping_payload.get("code"),
        "auth_guard_code": 401,
        "admin_entry": admin_entry,
        "admin_entry_url": f"{base_url}/{admin_entry}/",
        "admin_entry_status": admin_status,
        "services": service_states,
    }


def _extract_artifact_to_target(
    zip_path: Path,
    target_dir: Path,
    target_server_dir: Path,
    artifact_type: str,
    backup_dir: Path,
    backup_manifest: dict,
):
    data_bak_dir = backup_dir / "data"
    with zipfile.ZipFile(zip_path, mode="r") as zf:
        for member in zf.infolist():
            if member.is_dir():
                continue
            rel_name = member.filename
            if target_server_dir != target_dir and artifact_type == "deploy":
                if not rel_name.startswith(("sources/", "docs/", "scripts/", "README.md", "AGENTS.md", "CLAUDE.md", "LICENSE")):
                    dest_rel = f"server/{rel_name}"
                else:
                    dest_rel = rel_name
            else:
                dest_rel = rel_name

            dest_path = confined_path(target_dir, dest_rel)
            if is_protected_rel_path(dest_rel) and dest_path.exists():
                continue
            dest_path.parent.mkdir(parents=True, exist_ok=True)
            with zf.open(member, "r") as src_f, open(dest_path, "wb") as dst_f:
                shutil.copyfileobj(src_f, dst_f)

    # 恢复持久化备份（确保 .env / .env.docker / install.lock / SQLite 不被覆盖）
    data_backups = backup_manifest.get("data_backups", {})
    if data_backups.get("env_file") and (data_bak_dir / ".env").is_file():
        target_env = target_server_dir / ".env"
        target_env.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(data_bak_dir / ".env", target_env)
        safe_chmod(target_env, 0o600)
    if data_backups.get("docker_env_file") and (data_bak_dir / ".env.docker").is_file():
        target_denv = target_dir / ".env.docker"
        shutil.copy2(data_bak_dir / ".env.docker", target_denv)
        safe_chmod(target_denv, 0o600)
    if data_backups.get("install_lock") and (data_bak_dir / "install.lock").is_file():
        target_lock = target_server_dir / "config/install.lock"
        target_lock.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(data_bak_dir / "install.lock", target_lock)
    for sf_rel in data_backups.get("sqlite_files", []):
        sf_name = Path(sf_rel).name
        dest_sf = target_server_dir / "runtime" / sf_name
        dest_sf.parent.mkdir(parents=True, exist_ok=True)
        backup_sqlite(backup_dir / sf_rel, dest_sf)

    for sub_d in ("runtime", "public/uploads", "public/plugins", "config"):
        (target_server_dir / sub_d).mkdir(parents=True, exist_ok=True)


def _apply_docker_production(
    args,
    project_root: Path,
    target_dir: Path,
    target_server_dir: Path,
    backup_dir: Path,
    zip_path: Path,
    artifact: dict,
    manifest: dict,
) -> int:
    is_json = getattr(args, "json", False)
    artifact_type = manifest.get("artifact_type", "deploy")
    db_driver_arg = getattr(args, "db_driver", None)
    domain = getattr(args, "domain", None)
    https_cert = getattr(args, "https_cert", None)
    https_key = getattr(args, "https_key", None)
    http_port_arg = getattr(args, "http_port", None)
    https_port_arg = getattr(args, "https_port", None)

    # 校验 TLS 证书参数
    use_https = False
    if https_cert or https_key:
        if not (https_cert and https_key):
            return fail_operation(args, "启用 HTTPS 必须同时提供 --https-cert 与 --https-key", step="preflight")
        cert_p = Path(https_cert)
        key_p = Path(https_key)
        if not cert_p.is_file() or cert_p.is_symlink() or cert_p.stat().st_size == 0:
            return fail_operation(args, f"HTTPS 证书文件不存在或非法: {https_cert}", step="preflight")
        if not key_p.is_file() or key_p.is_symlink() or key_p.stat().st_size == 0:
            return fail_operation(args, f"HTTPS 私钥文件不存在或非法: {https_key}", step="preflight")
        use_https = True

    if db_driver_arg and db_driver_arg.lower() not in {"mysql", "pgsql"}:
        return fail_operation(args, f"Docker 生产部署仅支持 mysql 或 pgsql 数据库驱动，收到: {db_driver_arg}", step="preflight")

    # 检查 Docker 运行时与编排工具
    docker_ok, docker_msg, compose_cmd = check_docker_runtime()
    if not docker_ok:
        return fail_operation(args, docker_msg, step="adapter")

    # 确定当前已有环境（增量升级判断）
    existing_env_file = target_server_dir / ".env"
    existing_docker_env_file = target_dir / ".env.docker"
    is_upgrade = existing_env_file.is_file()
    existing_env_vars = parse_env_file(existing_env_file) if is_upgrade else {}
    existing_docker_vars = parse_env_file(existing_docker_env_file) if existing_docker_env_file.is_file() else {}
    cli_env_vars = parse_env_file(Path(args.env_file)) if getattr(args, "env_file", None) and Path(args.env_file).is_file() else {}

    eff_driver = (
        (db_driver_arg.lower() if db_driver_arg else None)
        or existing_env_vars.get("DB_DRIVER")
        or existing_env_vars.get("DB_TYPE")
        or existing_docker_vars.get("DB_DRIVER")
        or cli_env_vars.get("DB_DRIVER")
        or "mysql"
    ).lower()
    if eff_driver not in {"mysql", "pgsql"}:
        return fail_operation(args, f"Docker 生产部署目标数据库配置为 {eff_driver}，请改用 mysql 或 pgsql", step="preflight")

    # 确定目标现有 compose 上下文（用于在修改目标前备份已有 Docker MySQL/PG 库）
    existing_compose_ctx = resolve_compose_context(target_dir, "docker")
    try:
        cur_db_cfg = detect_effective_db_config(
            target_dir,
            target_server_dir,
            extra_env={"DB_DRIVER": eff_driver, "DB_TYPE": eff_driver},
        )
    except ValueError as err:
        return fail_operation(args, str(err), step="preflight")

    # Step 2: 修改目标前创建一致备份快照（失败立即中断，不修改目标）
    target_dir.mkdir(parents=True, exist_ok=True)
    try:
        backup_manifest = create_backup_snapshot(
            backup_dir=backup_dir,
            target="docker",
            target_dir=target_dir,
            target_server_dir=target_server_dir,
            artifact_type=artifact_type,
            artifact=artifact,
            db_cfg=cur_db_cfg,
            compose_ctx=existing_compose_ctx,
        )
    except Exception as exc:
        return fail_operation(args, f"部署前备份失败，已中止操作且未改动目标环境: {exc}", step="backup")

    if not is_json:
        print(f"✔ [步骤 2/5] 生产代码、数据库与附件快照已备份至: {backup_dir}")

    # Step 3: 解包生产制品并保护持久化目录与既有 .env
    _extract_artifact_to_target(zip_path, target_dir, target_server_dir, artifact_type, backup_dir, backup_manifest)

    # 若需复用本机源码仓 docker/php/Dockerfile（deploy ZIP 不含此文件）
    prod_dockerfile = target_dir / ".hw-deploy/docker/php/Dockerfile"
    prod_dockerfile.parent.mkdir(parents=True, exist_ok=True)
    src_dockerfile = project_root / "docker/php/Dockerfile"
    if src_dockerfile.is_file():
        shutil.copy2(src_dockerfile, prod_dockerfile)
    elif not prod_dockerfile.is_file():
        prod_dockerfile.write_text(FALLBACK_PHP_DOCKERFILE, encoding="utf-8")

    # 准备/保护凭证（首次生成高强度随机凭证并强制 APP_DEBUG=false；增量升级保留既有 .env）
    compose_project = (
        existing_docker_vars.get("COMPOSE_PROJECT_NAME")
        or cli_env_vars.get("COMPOSE_PROJECT_NAME")
        or f"hwadmin_{hashlib.sha256(str(target_dir.resolve()).encode('utf-8')).hexdigest()[:8]}"
    )
    http_port = str(http_port_arg or existing_docker_vars.get("HTTP_PORT") or cli_env_vars.get("HTTP_PORT") or "8080")
    https_port = str(https_port_arg or existing_docker_vars.get("HTTPS_PORT") or cli_env_vars.get("HTTPS_PORT") or "8443")

    def _pick_secret(key: str, default_gen) -> str:
        val = existing_env_vars.get(key) or existing_docker_vars.get(key) or cli_env_vars.get(key) or ""
        if not val or "CHANGE_ME" in val or "SecPass" in val:
            return default_gen()
        return val

    db_host = "hwadmin-postgres" if eff_driver == "pgsql" else "hwadmin-mysql"
    db_port = "5432" if eff_driver == "pgsql" else "3306"
    db_name = existing_env_vars.get("DB_NAME") or existing_docker_vars.get("DB_NAME") or cli_env_vars.get("DB_NAME") or "hwadmin"
    db_user = existing_env_vars.get("DB_USER") or existing_docker_vars.get("DB_USER") or cli_env_vars.get("DB_USER") or "hwadmin_app"
    db_prefix = existing_env_vars.get("DB_PREFIX") or existing_docker_vars.get("DB_PREFIX") or cli_env_vars.get("DB_PREFIX") or "hw_"
    db_pass = _pick_secret("DB_PASS", lambda: secrets.token_urlsafe(24))
    db_root_pass = _pick_secret("DB_ROOT_PASSWORD", lambda: secrets.token_urlsafe(24))
    redis_pass = _pick_secret("REDIS_PASSWORD", lambda: secrets.token_urlsafe(24))
    admin_user = existing_docker_vars.get("ADMIN_INIT_USER") or cli_env_vars.get("ADMIN_INIT_USER") or "admin"

    if not existing_env_file.is_file():
        db_charset = "utf8" if eff_driver == "pgsql" else "utf8mb4"
        env_lines = [
            "APP_DEBUG = false",
            "DEFAULT_LANG = zh-cn",
            f"DB_DRIVER = {eff_driver}",
            f"DB_TYPE = {eff_driver}",
            f"DB_HOST = {format_env_value(db_host)}",
            f"DB_PORT = {db_port}",
            f"DB_NAME = {format_env_value(db_name)}",
            f"DB_USER = {format_env_value(db_user)}",
            f"DB_PASS = {format_env_value(db_pass)}",
            f"DB_CHARSET = {db_charset}",
            f"DB_PREFIX = {format_env_value(db_prefix)}",
            "CACHE_DRIVER = redis",
            "REDIS_HOST = hwadmin-redis",
            "REDIS_PORT = 6379",
            f"REDIS_PASSWORD = {format_env_value(redis_pass)}",
        ]
        if domain:
            env_lines.append(f"APP_DOMAIN = {format_env_value(domain)}")
        write_secret_file(existing_env_file, "\n".join(env_lines) + "\n")
    else:
        # 增量升级：严格保留既有 .env 内容，仅收紧文件权限至 0600
        safe_chmod(existing_env_file, 0o600)

    if not existing_docker_env_file.is_file():
        docker_env_lines = [
            f"COMPOSE_PROJECT_NAME={compose_project}",
            f"HTTP_PORT={http_port}",
            f"HTTPS_PORT={https_port}",
            "APP_DEBUG=false",
            f"DB_DRIVER={eff_driver}",
            f"DB_TYPE={eff_driver}",
            f"DB_HOST={db_host}",
            f"DB_PORT={db_port}",
            f"DB_NAME={db_name}",
            f"DB_USER={db_user}",
            f"DB_PASS={db_pass}",
            f"DB_ROOT_PASSWORD={db_root_pass}",
            f"DB_PREFIX={db_prefix}",
            "CACHE_DRIVER=redis",
            "REDIS_HOST=hwadmin-redis",
            "REDIS_PORT=6379",
            f"REDIS_PASSWORD={redis_pass}",
            f"ADMIN_INIT_USER={admin_user}",
        ]
        if domain:
            docker_env_lines.append(f"DOMAIN={domain}")
        write_secret_file(existing_docker_env_file, "\n".join(docker_env_lines) + "\n")
    else:
        safe_chmod(existing_docker_env_file, 0o600)

    # 复制 TLS 证书（若提供）
    ssl_dir = target_dir / ".hw-deploy/ssl"
    if use_https:
        ssl_dir.mkdir(parents=True, exist_ok=True)
        shutil.copy2(Path(https_cert), ssl_dir / "fullchain.pem")
        shutil.copy2(Path(https_key), ssl_dir / "privkey.pem")
        safe_chmod(ssl_dir / "fullchain.pem", 0o644)
        safe_chmod(ssl_dir / "privkey.pem", 0o600)

    server_subpath = "/server" if target_server_dir != target_dir else ""
    nginx_conf_path = target_dir / ".hw-deploy/nginx/default.conf"
    nginx_conf_path.parent.mkdir(parents=True, exist_ok=True)
    nginx_conf_path.write_text(
        generate_prod_nginx_conf(domain=domain, use_https=use_https, server_subpath=server_subpath),
        encoding="utf-8",
    )

    compose_prod_path = target_dir / "docker-compose.prod.yml"
    compose_prod_path.write_text(
        generate_prod_compose_yml(db_driver=eff_driver, server_subpath=server_subpath, use_https=use_https),
        encoding="utf-8",
    )

    if not is_json:
        print("✔ [步骤 3/5] 生产解包、凭据隔离保护与 Compose/Nginx HTTPS 配置生成完成")

    # Step 4: 执行容器编排 (config -q -> up -d --build -> hw:install -> migrate:run -> hw:menu:sync)
    compose_ctx = {
        "compose_cmd": compose_cmd,
        "compose_file": compose_prod_path,
        "env_file": existing_docker_env_file,
        "cwd": target_dir,
    }
    base_cmd = compose_base_args(compose_ctx)

    rc_cfg, out_cfg, err_cfg = run_subprocess(base_cmd + ["config", "-q"], cwd=target_dir, timeout=60)
    if rc_cfg != 0:
        err_txt = (err_cfg or out_cfg or b"").decode("utf-8", errors="ignore").strip()
        return fail_operation(
            args,
            f"docker compose config -q 校验失败 (exit {rc_cfg}): {err_txt}",
            step="compose_config",
            extra={"recovery_point": str(backup_dir)},
        )

    rc_up, out_up, err_up = run_subprocess(base_cmd + ["up", "-d", "--build"], cwd=target_dir, timeout=600)
    if rc_up != 0:
        err_txt = (err_up or out_up or b"").decode("utf-8", errors="ignore").strip()
        return fail_operation(
            args,
            f"docker compose up -d --build 启动失败 (exit {rc_up}): {err_txt}",
            step="compose_up",
            extra={"recovery_point": str(backup_dir)},
        )

    # 首次冷启动安装（若缺少 install.lock）
    if not (target_server_dir / "config/install.lock").is_file():
        credential_path = target_server_dir / "runtime/initial-admin.txt"
        credential_path.parent.mkdir(parents=True, exist_ok=True)
        if credential_path.is_symlink():
            return fail_operation(args, "初始化凭据文件不得是符号链接", step="install", extra={"recovery_point": str(backup_dir)})
        if credential_path.is_file():
            match = re.search(r"^password=(.+)$", credential_path.read_text(encoding="utf-8"), re.M)
            if not match or len(match.group(1)) < 16:
                return fail_operation(args, "已存在的初始化凭据文件无效，请核查后重试", step="install", extra={"recovery_point": str(backup_dir)})
            initial_password = match.group(1)
        else:
            initial_password = secrets.token_urlsafe(24) + "!Aa1"
            fd = os.open(credential_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8") as cf:
                cf.write(f"username={admin_user}\npassword={initial_password}\n")
            safe_chmod(credential_path, 0o600)

        rc_inst, out_inst, err_inst = run_subprocess(
            base_cmd + [
                "exec", "-T", "hwadmin-php",
                "php", "think", "hw:install",
                f"--username={admin_user}",
                f"--password={initial_password}",
                "--skip-if-installed",
            ],
            cwd=target_dir,
            timeout=180,
        )
        if rc_inst != 0:
            err_txt = (err_inst or out_inst or b"").decode("utf-8", errors="ignore").strip()
            return fail_operation(
                args,
                f"容器内首次安装初始化失败 (exit {rc_inst}): {err_txt}",
                step="install",
                extra={"recovery_point": str(backup_dir)},
            )

    rc_mig, out_mig, err_mig = run_subprocess(
        base_cmd + ["exec", "-T", "hwadmin-php", "php", "think", "migrate:run"],
        cwd=target_dir,
        timeout=180,
    )
    if rc_mig != 0:
        err_txt = (err_mig or out_mig or b"").decode("utf-8", errors="ignore").strip()
        return fail_operation(
            args,
            f"容器内数据库迁移失败 (exit {rc_mig}): {err_txt}",
            step="migrate",
            extra={"recovery_point": str(backup_dir)},
        )

    rc_menu, out_menu, err_menu = run_subprocess(
        base_cmd + ["exec", "-T", "hwadmin-php", "php", "think", "hw:menu:sync"],
        cwd=target_dir,
        timeout=120,
    )
    if rc_menu != 0:
        err_txt = (err_menu or out_menu or b"").decode("utf-8", errors="ignore").strip()
        return fail_operation(
            args,
            f"容器内权限菜单同步失败 (exit {rc_menu}): {err_txt}",
            step="menu_sync",
            extra={"recovery_point": str(backup_dir)},
        )

    if not is_json:
        print("✔ [步骤 4/5] 容器编排、受控迁移 (migrate:run) 与权限菜单同步 (hw:menu:sync) 执行成功")

    # Step 5: 真实生产健康探针（明确拒绝 php -S 开发内置服务器）
    probe_ok, probe_detail = run_production_health_probe(
        compose_ctx=compose_ctx,
        db_driver=eff_driver,
        http_port=http_port,
        https_port=https_port,
        domain=domain,
        use_https=use_https,
    )
    if not probe_ok:
        return fail_operation(
            args,
            f"生产健康检查探针未通过: {probe_detail}",
            step="health_probe",
            extra={"recovery_point": str(backup_dir)},
        )

    external_pending_steps = []
    if not domain:
        external_pending_steps.append("需人工配置生产域名 DNS A/AAAA 解析并绑定公网入口")
    if not use_https:
        external_pending_steps.append("需人工签发并配置正式受信任 TLS/SSL 证书 (Let's Encrypt 或商业证书)")
    if not domain or domain in {"localhost", "127.0.0.1"}:
        external_pending_steps.append("需人工确认云主机安全组/防火墙 80/443 放行并完成公网域名端到端核验 (G07)")
    # These probes access loopback and disable TLS validation. They cannot attest DNS,
    # certificate trust/hostname, firewall reachability or an external public endpoint.
    external_pending_steps.append("待独立外部网络验证生产域名 DNS、受信任 HTTPS 证书与公网业务；本机回环探针不放行 G07")
    g07_public_verified = False

    deploy_record = {
        "action": "apply",
        "status": "success",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "target": "docker",
        "is_upgrade": is_upgrade,
        "db_driver": eff_driver,
        "domain": domain,
        "https_enabled": use_https,
        "target_dir": str(target_dir),
        "target_server_dir": str(target_server_dir),
        "artifact": artifact,
        "backup_dir": str(backup_dir),
        "generated_configs": [
            str(compose_prod_path),
            str(nginx_conf_path),
            str(existing_docker_env_file),
        ],
        "production_runtime": {
            "orchestrator": "docker-compose",
            "web_server": "nginx",
            "php_sapi": "fpm-fcgi",
            "db_driver": eff_driver,
            "builtin_dev_server_rejected": True,
        },
        "g07_public_verified": g07_public_verified,
        "external_pending_steps": external_pending_steps,
        "steps": {
            "verify_artifact": "passed",
            "backup": "passed",
            "extract": "passed",
            "compose_config": "passed",
            "compose_up": "passed",
            "migrate": "passed",
            "menu_sync": "passed",
            "health_probe": probe_detail,
        },
    }
    append_deploy_history(project_root, deploy_record, extra_root=target_dir)
    update_recovery_point_state(
        project_root,
        target_dir,
        {
            "current_backup_id": backup_dir.name,
            "current_backup_dir": str(backup_dir),
            "artifact_sha256": artifact.get("sha256"),
            "updated_at": deploy_record["timestamp"],
            "action": "apply",
        },
    )

    if not is_json:
        print("✔ [步骤 5/5] 真实生产 HTTP/容器健康探针通过 (Nginx + PHP-FPM, 已验证非 php -S)")
        if external_pending_steps:
            print("⚠️ 需人工完成的外部事项 (external_pending_steps):")
            for item in external_pending_steps:
                print(f"   - {item}")
        print("==================================================")
        print("🎉 Docker 生产部署作业成功完成！")
    else:
        print(json.dumps(deploy_record, ensure_ascii=False, indent=2))
    return 0


def cmd_apply(args, project_root: Path):
    target = args.target.lower()
    dry_run = getattr(args, "dry_run", False)
    is_json = getattr(args, "json", False)
    explicit_artifact = getattr(args, "artifact", None)

    # 宝塔未实现自动远程执行：明确拒绝并返回非零退出码与手动/半自动步骤说明
    if target in {"baota-nginx", "baota"} and not dry_run:
        return fail_operation(
            args,
            "当前版本尚未支持 --target=baota-nginx 的自动化远程执行，严禁伪装支持；请先生成 plan 后按手动/半自动步骤迁移。",
            step="adapter",
            extra={"target": "baota-nginx", "manual_steps": list(BaotaNginxDeployAdapter.MANUAL_STEPS)},
        )

    if target not in {"local", "docker", "baota-nginx", "baota"}:
        return fail_operation(args, f"不支持的部署目标: {target}", step="adapter")

    artifact = detect_latest_artifact(project_root, explicit_artifact=explicit_artifact)

    # 当缺少部署 Kontext（无制品且无 docker-compose.yml 且无 target_dir）时，Docker 适配器前置拦截并禁止回退本地 PHP 探针
    if target == "docker" and not dry_run:
        if not explicit_artifact and not artifact and not (project_root / "docker-compose.yml").is_file() and not getattr(args, "target_dir", None):
            return fail_operation(
                args,
                "Docker 生产部署适配器前置条件不足：未发现部署制品或编排上下文（禁止回退到本地 PHP 内置服务器探针）",
                step="adapter",
            )

    if not artifact:
        err_msg = "❌ 错误: 未在 release/ 目录找到可部署制品，请先运行: python3 scripts/build-release.py"
        return fail_operation(args, err_msg, step="verify_artifact")

    zip_path = Path(artifact["path"])

    # 1. 严格校验 ZIP 格式、外部 .sha256 与内部 artifact-manifest.json
    ok, verify_msg, manifest = verify_artifact_strictly(zip_path)
    if not ok:
        return fail_operation(args, f"❌ 错误: 制品完整性校验未通过 — {verify_msg}", step="verify_artifact")

    artifact_type = manifest.get("artifact_type", "deploy")

    if getattr(args, "target_dir", None):
        target_dir = Path(args.target_dir).resolve()
    elif os.environ.get("DEPLOY_TARGET_DIR"):
        target_dir = Path(os.environ["DEPLOY_TARGET_DIR"]).resolve()
    elif (project_root / ".git").exists():
        target_dir = (project_root / ".hw-state/deploy-target").resolve()
    else:
        target_dir = project_root.resolve()

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S_%f")
    backup_dir = (
        Path(args.backup_dir).resolve()
        if getattr(args, "backup_dir", None)
        else (project_root / f"runtime/backups/deploy-{timestamp}").resolve()
    )

    if not is_json:
        print("==================================================")
        print(f"🚀 正在执行 HwAdmin 部署作业 [{target.upper()}]{' [DRY-RUN]' if dry_run else ''}")
        print(f"🎯 目标目录: {target_dir}")
        print("==================================================")
        print(f"✔ [步骤 1/5] 校验制品签名与文件清单通过: {artifact['filename']} (SHA: {artifact['sha256'][:12]}...)")

    if dry_run:
        res = {
            "status": "success",
            "mode": "dry-run",
            "target": target,
            "target_dir": str(target_dir),
            "artifact": artifact,
            "backup_dir": str(backup_dir),
        }
        if is_json:
            print(json.dumps(res, ensure_ascii=False, indent=2))
        else:
            print("✔ [步骤 2/5] [DRY-RUN] 模拟生产状态与代码快照备份")
            print("✔ [步骤 3/5] [DRY-RUN] 模拟逐文件解包与持久化保护")
            print("✔ [步骤 4/5] [DRY-RUN] 模拟执行受控数据库迁移与菜单同步")
            print("✔ [步骤 5/5] [DRY-RUN] 模拟健康探针检查")
            print("==================================================")
            print("🎉 部署演练完成！（DRY-RUN 模式，无实际写入）")
        return 0

    if target_dir == backup_dir or target_dir.is_relative_to(backup_dir):
        return fail_operation(args, "备份目录不能等于或包含部署目标", step="preflight")
    if backup_dir.exists() and (not backup_dir.is_dir() or any(backup_dir.iterdir())):
        return fail_operation(args, "备份目录非空，拒绝覆盖既有恢复点", step="preflight")

    if artifact_type in ("dev", "unified") or (target_dir / "server/think").is_file():
        target_server_dir = target_dir / "server"
    else:
        target_server_dir = target_dir

    # 分派至真实 Docker 生产部署执行器
    if target == "docker":
        return _apply_docker_production(
            args=args,
            project_root=project_root,
            target_dir=target_dir,
            target_server_dir=target_server_dir,
            backup_dir=backup_dir,
            zip_path=zip_path,
            artifact=artifact,
            manifest=manifest,
        )

    # 以下保留 --target=local 的 SQLite 本地目录闭环执行器
    environment = (target_dir / "server/.env") if (target_dir / "server/think").is_file() else (target_dir / ".env")
    if not environment.is_file() and getattr(args, "env_file", None):
        environment = Path(args.env_file)
    if environment.is_file():
        content = environment.read_text(encoding="utf-8")
        if re.search(r"^\s*DB_(?:DRIVER|TYPE)\s*=\s*['\"]?(mysql|pgsql)", content, re.M | re.I):
            return fail_operation(args, "本地执行器仅支持 SQLite 验收；MySQL/PG 请使用 --target=docker 生产部署", step="preflight")

    target_dir.mkdir(parents=True, exist_ok=True)
    local_db_cfg = detect_effective_db_config(target_dir, target_server_dir, extra_env={"DB_DRIVER": "sqlite"})

    try:
        backup_manifest = create_backup_snapshot(
            backup_dir=backup_dir,
            target="local",
            target_dir=target_dir,
            target_server_dir=target_server_dir,
            artifact_type=artifact_type,
            artifact=artifact,
            db_cfg=local_db_cfg,
            compose_ctx=None,
        )
    except Exception as exc:
        return fail_operation(args, f"部署前备份失败: {exc}", step="backup")

    if not is_json:
        print(f"✔ [步骤 2/5] 代码与数据快照已备份至: {backup_dir} (共 {len(backup_manifest['checksums'])} 项受检归档)")

    _extract_artifact_to_target(zip_path, target_dir, target_server_dir, artifact_type, backup_dir, backup_manifest)

    target_env_file = target_server_dir / ".env"
    if not target_env_file.is_file():
        if getattr(args, "env_file", None) and Path(args.env_file).is_file():
            shutil.copy2(Path(args.env_file), target_env_file)
        else:
            sqlite_db_path = (target_server_dir / "runtime/dev.sqlite").as_posix()
            if os.name == "nt" and not has_native_php():
                sqlite_db_path = to_wsl_path(target_server_dir / "runtime/dev.sqlite")
            env_content = (
                "APP_DEBUG = false\n"
                "DEFAULT_LANG = zh-cn\n"
                "DB_DRIVER = sqlite\n"
                "DB_TYPE = sqlite\n"
                f"DB_NAME = {sqlite_db_path}\n"
                "DB_PREFIX = hw_\n"
                "CACHE_DRIVER = file\n"
            )
            write_secret_file(target_env_file, env_content)

    if not (target_server_dir / "vendor/autoload.php").is_file():
        src_vendor = project_root / "server/vendor" if (project_root / "server/vendor/autoload.php").is_file() else project_root / "vendor"
        if (src_vendor / "autoload.php").is_file():
            dest_vendor = target_server_dir / "vendor"
            if dest_vendor.is_symlink():
                dest_vendor.unlink()
            dest_vendor.mkdir(parents=True, exist_ok=True)
            # ThinkPHP App::__construct() uses realpath(__DIR__) inside vendor/topthink/framework,
            # and Composer autoload uses __DIR__ inside vendor/composer. Those must be real paths
            # under target_server_dir so ThinkPHP rootPath never escapes to project_root/server.
            must_copy = {"autoload.php", "composer", "topthink", "bin"}
            for item in src_vendor.iterdir():
                dst_item = dest_vendor / item.name
                if dst_item.exists() or dst_item.is_symlink():
                    continue
                if item.name in must_copy:
                    if item.is_dir():
                        shutil.copytree(item, dst_item, dirs_exist_ok=True)
                    else:
                        shutil.copy2(item, dst_item)
                else:
                    try:
                        os.symlink(item.resolve(), dst_item, target_is_directory=item.is_dir())
                    except Exception:
                        if item.is_dir():
                            shutil.copytree(item, dst_item, dirs_exist_ok=True)
                        else:
                            shutil.copy2(item, dst_item)

    if not is_json:
        print("✔ [步骤 3/5] 制品逐文件解包完成，持久化数据 (.env / 数据库 / uploads) 完整保留")

    if not (target_server_dir / "config/install.lock").is_file():
        credential_path = target_server_dir / "runtime/initial-admin.txt"
        credential_path.parent.mkdir(parents=True, exist_ok=True)
        if credential_path.is_symlink():
            return fail_operation(args, "初始化凭据文件不得是符号链接", step="install")
        if credential_path.is_file():
            match = re.search(r"^password=(.+)$", credential_path.read_text(encoding="utf-8"), re.M)
            if not match or len(match.group(1)) < 16:
                return fail_operation(args, "已存在的初始化凭据文件无效，请核查后重试", step="install")
            initial_password = match.group(1)
        else:
            initial_password = secrets.token_urlsafe(24) + "!Aa1"
            fd = os.open(credential_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8") as credentials:
                credentials.write("username=admin\npassword=" + initial_password + "\n")
            safe_chmod(credential_path, 0o600)
        rc, out, err = run_php_cmd(
            target_server_dir,
            ["think", "hw:install", "--username=admin", f"--password={initial_password}", "--skip-if-installed"],
        )
        if rc != 0:
            return fail_operation(args, f"❌ 错误: 目标环境首次安装初始化失败 (exit {rc}): {err or out}", step="install", extra={"recovery_point": str(backup_dir)})

    rc_mig, out_mig, err_mig = run_php_cmd(target_server_dir, ["think", "migrate:run"])
    if rc_mig != 0:
        return fail_operation(args, f"❌ 错误: 数据库迁移失败 (exit {rc_mig}): {err_mig or out_mig}", step="migrate", extra={"recovery_point": str(backup_dir)})

    rc_menu, out_menu, err_menu = run_php_cmd(target_server_dir, ["think", "hw:menu:sync"])
    if rc_menu != 0:
        return fail_operation(args, f"❌ 错误: 菜单与权限同步失败 (exit {rc_menu}): {err_menu or out_menu}", step="menu_sync", extra={"recovery_point": str(backup_dir)})

    if not is_json:
        print("✔ [步骤 4/5] 数据库迁移 (migrate:run) 与权限菜单同步 (hw:menu:sync) 执行成功")

    probe_ok, probe_detail = run_http_health_probe(target_server_dir)
    if not probe_ok:
        return fail_operation(args, f"❌ 错误: 部署后真实 HTTP 健康检查探针未通过: {probe_detail}", step="health_probe", extra={"recovery_point": str(backup_dir)})

    if not is_json:
        print("✔ [步骤 5/5] 真实 HTTP 健康检查探针通过 (/api/index/ping -> code=1, /adminapi/index/info -> code=401)")
        print("==================================================")
        print("🎉 部署作业成功完成！")

    deploy_record = {
        "action": "apply",
        "status": "success",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "target": target,
        "db_driver": "sqlite",
        "target_dir": str(target_dir),
        "target_server_dir": str(target_server_dir),
        "artifact": artifact,
        "backup_dir": str(backup_dir),
        "steps": {
            "verify_artifact": "passed",
            "backup": "passed",
            "extract": "passed",
            "migrate": "passed",
            "menu_sync": "passed",
            "health_probe": probe_detail,
        },
    }
    append_deploy_history(project_root, deploy_record, extra_root=target_dir)
    update_recovery_point_state(
        project_root,
        target_dir,
        {
            "current_backup_id": backup_dir.name,
            "current_backup_dir": str(backup_dir),
            "artifact_sha256": artifact.get("sha256"),
            "updated_at": deploy_record["timestamp"],
            "action": "apply",
        },
    )

    if is_json:
        print(json.dumps(deploy_record, ensure_ascii=False, indent=2))
    return 0


def _validate_target_path_safety(dest: Path, must_be_file: bool = True):
    for p in [dest, *dest.parents]:
        if p.is_symlink():
            raise ValueError(f"恢复目标路径存在符号链接: {p}")
    for p in dest.parents:
        if p.exists() and not p.is_dir():
            raise ValueError(f"恢复目标父路径不是目录: {p}")
    if dest.exists():
        if must_be_file and not dest.is_file():
            raise ValueError(f"恢复目标不是常规文件: {dest}")
        if not must_be_file and not dest.is_dir():
            raise ValueError(f"恢复目标不是目录: {dest}")


def cmd_rollback(args, project_root: Path):
    start_ts = time.monotonic()
    backup_dir = getattr(args, "backup_dir", None)
    is_json = getattr(args, "json", False)
    mode = getattr(args, "mode", "full").lower()
    target_opt = getattr(args, "target", "docker").lower()

    if not is_json:
        print("==================================================")
        print("⏪ HwAdmin 生产故障回滚与状态恢复")
        print("==================================================")

    if not backup_dir or not Path(backup_dir).is_dir():
        return fail_operation(args, f"❌ 回滚失败: 备份目录不存在: {backup_dir}", step="preflight")

    b_path = Path(backup_dir).resolve()
    manifest_file = b_path / "backup-manifest.json"
    if not manifest_file.is_file():
        return fail_operation(args, f"❌ 回滚失败: 备份目录缺少可信清单 backup-manifest.json ({b_path})", step="preflight")

    try:
        manifest = json.loads(manifest_file.read_text(encoding="utf-8"))
    except Exception as e:
        return fail_operation(args, f"❌ 回滚失败: backup-manifest.json 解析错误: {e}", step="preflight")

    checksums = manifest.get("checksums")
    if not isinstance(checksums, dict) or len(checksums) == 0:
        return fail_operation(args, "❌ 回滚失败: 备份清单无有效备份文件与校验和记录", step="preflight")

    target_dir = (
        Path(args.target_dir).resolve()
        if getattr(args, "target_dir", None)
        else Path(manifest.get("target_dir", str(project_root))).resolve()
    )
    target_server_dir = Path(manifest.get("target_server_dir", str(target_dir))).resolve()
    if getattr(args, "target_dir", None):
        target_server_dir = target_dir / "server" if (target_dir / "server").is_dir() else target_dir

    # 1. 校验所有输入清单与文件 SHA-256（在删除或写入任何目标文件之前完成！）
    staged_uploads = {}
    staging_tmp = None
    try:
        for relative in checksums:
            confined_path(b_path, relative)
        code_zip = confined_path(b_path, "code/snapshot.zip")
        if "code/snapshot.zip" not in checksums or not zipfile.is_zipfile(code_zip):
            raise ValueError("备份缺少受校验的有效代码快照")
        with zipfile.ZipFile(code_zip) as archive:
            validate_archive_members(archive)
            if archive.testzip() is not None:
                raise ValueError("代码快照 CRC 校验失败")

        backups = manifest.get("data_backups", {})
        referenced = [
            backups.get("env_file"),
            backups.get("docker_env_file"),
            backups.get("install_lock"),
            backups.get("database_dump"),
            backups.get("uploads_archive"),
            backups.get("uploads_manifest"),
        ]
        referenced += backups.get("sqlite_files", []) + backups.get("uploads_files", [])
        for relative in filter(None, referenced):
            if relative not in checksums or not relative.startswith("data/"):
                raise ValueError("数据备份未登记在校验清单中: " + relative)
            confined_path(b_path, relative)

        # 逐项校验备份目录下文件存在性与 SHA-256
        for rel_k, expected_sha in checksums.items():
            f_p = confined_path(b_path, rel_k)
            if not f_p.is_file():
                raise ValueError(f"备份文件缺失: {rel_k}")
            actual_sha = calculate_sha256(f_p).lower()
            if actual_sha != str(expected_sha).lower():
                raise ValueError(f"备份文件哈希损坏或不匹配: {rel_k}")

        # 校验附件清单与归档一致性
        uploads_manifest_rel = backups.get("uploads_manifest")
        uploads_archive_rel = backups.get("uploads_archive")
        uploads_expected = bool(backups.get("uploads_expected", False))
        if uploads_manifest_rel:
            um_data = json.loads(confined_path(b_path, uploads_manifest_rel).read_text(encoding="utf-8"))
            expected_files = um_data.get("files", {})
            if not isinstance(expected_files, dict):
                raise ValueError("附件清单 uploads-manifest.json 格式非法")
            if (len(expected_files) > 0 or uploads_expected) and not uploads_archive_rel:
                raise ValueError("备份缺少附件归档 public_uploads.tar.gz，拒绝执行不完整恢复")
            if uploads_archive_rel:
                tar_p = confined_path(b_path, uploads_archive_rel)
                staging_tmp = tempfile.TemporaryDirectory(prefix="hwdeploy-uploads-")
                stage_root = Path(staging_tmp.name)
                with tarfile.open(tar_p, "r:gz") as tf:
                    members = safe_tar_members(tf, prefix="uploads")
                    for member in members:
                        if member.isfile():
                            rel_u = member.name.removeprefix("uploads/")
                            extracted = tf.extractfile(member)
                            if extracted is None:
                                raise ValueError(f"无法读取附件归档成员: {member.name}")
                            raw_bytes = extracted.read()
                            if rel_u in expected_files:
                                actual_u_sha = calculate_bytes_sha256(raw_bytes)
                                if actual_u_sha != str(expected_files[rel_u]).lower():
                                    raise ValueError(f"附件归档内部文件哈希损坏: {rel_u}")
                            out_p = confined_path(stage_root, rel_u)
                            out_p.parent.mkdir(parents=True, exist_ok=True)
                            out_p.write_bytes(raw_bytes)
                            staged_uploads[rel_u] = out_p
                missing_u = [k for k in expected_files if k not in staged_uploads]
                if missing_u:
                    raise ValueError(f"附件归档缺失清单声明的文件: {missing_u}")
        elif uploads_expected and not backups.get("uploads_files"):
            raise ValueError("备份声明包含附件但缺少附件备份内容，拒绝恢复")

        # 识别数据库驱动与上下文
        backup_env_vars = {}
        if backups.get("docker_env_file"):
            backup_env_vars.update(parse_env_file(confined_path(b_path, backups["docker_env_file"])))
        if backups.get("env_file"):
            backup_env_vars.update(parse_env_file(confined_path(b_path, backups["env_file"])))
        db_cfg = detect_effective_db_config(target_dir, target_server_dir, extra_env=backup_env_vars)
        if manifest.get("db_driver") in {"sqlite", "mysql", "pgsql"}:
            db_cfg["driver"] = manifest["db_driver"]
        compose_ctx = resolve_compose_context(target_dir, manifest.get("target") or target_opt)

        # 校验目标关键路径无符号链接/类型冲突
        _validate_target_path_safety(target_server_dir / ".env", must_be_file=True)
        _validate_target_path_safety(target_server_dir / "config/install.lock", must_be_file=True)

        # 校验 full 模式下数据库快照完整性
        if mode == "full":
            if db_cfg["driver"] in {"mysql", "pgsql"}:
                dump_rel = backups.get("database_dump")
                if not dump_rel:
                    raise ValueError(f"备份清单缺少 {db_cfg['driver']} 一致性 SQL 转储 (database_dump)，禁止执行 full 恢复")
                dump_file = confined_path(b_path, dump_rel)
                if dump_file.stat().st_size == 0:
                    raise ValueError(f"{db_cfg['driver']} SQL 转储文件为空，拒绝恢复")
            elif db_cfg["driver"] == "sqlite":
                for sf_rel in backups.get("sqlite_files", []):
                    sf_p = confined_path(b_path, sf_rel)
                    with closing(sqlite3.connect(str(sf_p))) as conn:
                        chk = conn.execute("PRAGMA integrity_check").fetchone()
                        if not chk or chk[0] != "ok":
                            raise ValueError(f"SQLite 备份快照完整性检查失败: {sf_rel}")
                    _validate_target_path_safety(target_server_dir / "runtime" / Path(sf_rel).name, must_be_file=True)

    except (ValueError, OSError, zipfile.BadZipFile, tarfile.TarError, TypeError, sqlite3.DatabaseError) as error:
        if staging_tmp:
            staging_tmp.cleanup()
        return fail_operation(args, f"❌ 回滚前校验失败（未修改当前有效数据）: {error}", step="preflight")

    # 2. Schema 兼容性与破坏性迁移拦截（仅 --mode=code-only 时校验）
    schema_compat_info = None
    if mode == "code-only":
        compat_ok, compat_msg, compat_detail = check_code_only_migration_compatibility(
            target_server_dir=target_server_dir,
            b_path=b_path,
            manifest=manifest,
            db_cfg=db_cfg,
            compose_ctx=compose_ctx,
        )
        schema_compat_info = compat_detail
        if not compat_ok:
            if staging_tmp:
                staging_tmp.cleanup()
            return fail_operation(
                args,
                compat_msg,
                step="schema_compatibility",
                extra={"schema_compatibility": compat_detail},
            )

    restored_code_files = []
    restored_data_files = []

    try:
        # 3. 执行数据库恢复（--mode=full 时先恢复数据库，若失败则代码、附件和 .env 均保持原状！）
        if mode == "full":
            if db_cfg["driver"] in {"mysql", "pgsql"}:
                dump_file = confined_path(b_path, backups["database_dump"])
                rst_cmd, rst_env = build_db_restore_command(db_cfg, compose_ctx)
                rc_rst, out_rst, err_rst = run_subprocess(
                    rst_cmd,
                    cwd=target_dir,
                    env=rst_env,
                    stdin_bytes=dump_file.read_bytes(),
                    timeout=300,
                )
                if rc_rst != 0:
                    err_txt = (err_rst or out_rst or b"").decode("utf-8", errors="ignore").strip()
                    return fail_operation(
                        args,
                        f"❌ {db_cfg['driver']} 数据库恢复失败 (exit {rc_rst})，已保留当前代码与配置: {err_txt[:300]}",
                        step="restore_database",
                    )
                restored_data_files.append(f"{db_cfg['driver']}:{db_cfg['name']}")
            elif db_cfg["driver"] == "sqlite":
                for sf_rel in backups.get("sqlite_files", []):
                    sf_name = Path(sf_rel).name
                    dest_sf = target_server_dir / "runtime" / sf_name
                    dest_sf.parent.mkdir(parents=True, exist_ok=True)
                    backup_sqlite(b_path / sf_rel, dest_sf)
                    restored_data_files.append(str(dest_sf))

        # 4. 恢复代码文件快照 (code/snapshot.zip)
        if code_zip.is_file():
            with zipfile.ZipFile(code_zip, mode="r") as czf:
                snapshot_names = set(czf.namelist())
                if ".hw-initial-empty-target" not in snapshot_names:
                    for root, dirs, files in os.walk(target_dir):
                        parent_name = Path(root).name
                        dirs[:] = [
                            d for d in dirs
                            if d not in {".git", "node_modules", "__pycache__", ".hw-state", "release"}
                            and not (parent_name == "src-tauri" and d == "target")
                        ]
                        for f in files:
                            full_p = Path(root) / f
                            if full_p.is_symlink():
                                continue
                            try:
                                full_p.relative_to(b_path)
                                continue
                            except ValueError:
                                pass
                            rel_p = full_p.relative_to(target_dir).as_posix()
                            if should_skip_code_snapshot(rel_p) or is_protected_rel_path(rel_p):
                                continue
                            if rel_p not in snapshot_names:
                                try:
                                    full_p.unlink()
                                except OSError:
                                    pass

                    for member in czf.infolist():
                        if member.is_dir():
                            continue
                        if mode == "code-only" and is_protected_rel_path(member.filename):
                            continue
                        dest_p = confined_path(target_dir, member.filename)
                        dest_p.parent.mkdir(parents=True, exist_ok=True)
                        with czf.open(member, "r") as src_f, open(dest_p, "wb") as dst_f:
                            shutil.copyfileobj(src_f, dst_f)
                        restored_code_files.append(str(dest_p))

        # 5. 恢复配置与持久化附件文件（仅 --mode=full）
        if mode == "full":
            env_rel = backups.get("env_file")
            if env_rel and (b_path / env_rel).is_file():
                dest_env = target_server_dir / ".env"
                dest_env.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(b_path / env_rel, dest_env)
                safe_chmod(dest_env, 0o600)
                restored_data_files.append(str(dest_env))

            denv_rel = backups.get("docker_env_file")
            if denv_rel and (b_path / denv_rel).is_file():
                dest_denv = target_dir / ".env.docker"
                shutil.copy2(b_path / denv_rel, dest_denv)
                safe_chmod(dest_denv, 0o600)
                restored_data_files.append(str(dest_denv))

            lock_rel = backups.get("install_lock")
            if lock_rel and (b_path / lock_rel).is_file():
                dest_lock = target_server_dir / "config/install.lock"
                dest_lock.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(b_path / lock_rel, dest_lock)
                restored_data_files.append(str(dest_lock))

            uploads_dir = target_server_dir / "public/uploads"
            if backups.get("uploads_manifest"):
                uploads_dir.mkdir(parents=True, exist_ok=True)
                # 清理备份点之后新增的附件，使目录精确恢复至备份状态
                for root, _, files in os.walk(uploads_dir):
                    for f in files:
                        if f in {".gitkeep", ".gitignore", ".htaccess"}:
                            continue
                        cur_u = Path(root) / f
                        rel_u = cur_u.relative_to(uploads_dir).as_posix()
                        if rel_u not in staged_uploads:
                            try:
                                cur_u.unlink()
                            except OSError:
                                pass
                for rel_u, src_u in staged_uploads.items():
                    dest_up = confined_path(uploads_dir, rel_u)
                    dest_up.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(src_u, dest_up)
                    restored_data_files.append(str(dest_up))
            else:
                for up_rel in backups.get("uploads_files", []):
                    if (b_path / up_rel).is_file():
                        sub_rel = up_rel.removeprefix("data/uploads/")
                        dest_up = confined_path(uploads_dir, sub_rel)
                        dest_up.parent.mkdir(parents=True, exist_ok=True)
                        shutil.copy2(b_path / up_rel, dest_up)
                        restored_data_files.append(str(dest_up))
    finally:
        if staging_tmp:
            staging_tmp.cleanup()

    duration_seconds = round(time.monotonic() - start_ts, 4)
    prev_point = read_recovery_point_state(project_root, target_dir)
    target_backup_id = manifest.get("backup_id", b_path.name)
    recovery_point_change = {
        "previous_backup_id": prev_point.get("current_backup_id"),
        "restored_backup_id": target_backup_id,
        "restored_backup_dir": str(b_path),
        "mode": mode,
        "changed": prev_point.get("current_backup_id") != target_backup_id,
    }
    update_recovery_point_state(
        project_root,
        target_dir,
        {
            "current_backup_id": target_backup_id,
            "current_backup_dir": str(b_path),
            "previous_backup_id": prev_point.get("current_backup_id"),
            "updated_at": datetime.now(timezone.utc).isoformat(),
            "action": "rollback",
            "mode": mode,
        },
    )

    restored_all = restored_code_files + restored_data_files
    res_data = {
        "status": "success",
        "action": "rollback",
        "mode": mode,
        "db_driver": db_cfg["driver"],
        "backup_id": target_backup_id,
        "backup_dir": str(b_path),
        "target_dir": str(target_dir),
        "recovery_duration_seconds": duration_seconds,
        "recovery_point_change": recovery_point_change,
        "schema_compatibility": schema_compat_info,
        "restored_code_files": restored_code_files,
        "restored_data_files": restored_data_files,
        "restored_files": restored_all,
        "recovery_separation": {
            "mode": mode,
            "code_rollback_count": len(restored_code_files),
            "data_recovery_count": len(restored_data_files),
        },
        "database_warning": (
            "ℹ️ 当前为 code-only 仅代码回退模式：已通过 Schema 兼容性校验并保留目标环境现有数据库、.env 与上传附件。"
            if mode == "code-only"
            else f"✔ 已完成代码文件回退 ({len(restored_code_files)} 项) 与 {db_cfg['driver']} 数据库/配置/附件全量恢复 ({len(restored_data_files)} 项)。"
        ),
    }

    append_deploy_history(
        project_root,
        {
            "action": "rollback",
            "mode": mode,
            "db_driver": db_cfg["driver"],
            "status": "success",
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "backup_dir": str(b_path),
            "target_dir": str(target_dir),
            "recovery_duration_seconds": duration_seconds,
            "recovery_point_change": recovery_point_change,
            "code_rollback_count": len(restored_code_files),
            "data_recovery_count": len(restored_data_files),
        },
        extra_root=target_dir,
    )

    if is_json:
        print(json.dumps(res_data, ensure_ascii=False, indent=2))
    else:
        print(f"✔ 已从备份 {b_path.name} 成功恢复目标环境 ({target_dir}, 耗时 {duration_seconds}s):")
        print(f"  - 代码文件回退: {len(restored_code_files)} 项")
        print(f"  - 数据与配置恢复: {len(restored_data_files)} 项")
        print("\n" + res_data["database_warning"])
        print("==================================================")
    return 0


def cmd_report(args, project_root: Path):
    format_type = getattr(args, "format", "json").lower()
    is_json = getattr(args, "json", False) or format_type == "json"

    artifact = detect_latest_artifact(project_root)
    git_commit = get_git_commit(project_root)
    framework_version = detect_framework_version(project_root)

    verify_report_file = project_root / ".hw-state/verify-report.json"
    deploy_history_file = project_root / ".hw-state/deploy-history.json"

    verification_summary = {
        "php_lint": "unverified",
        "doctor": "unverified",
        "phpunit": "unverified",
        "smoke_tests": "unverified",
        "pc_types": "unverified",
        "mobile_types": "unverified",
        "package_check": "unverified",
    }
    verify_executed = False
    verify_all_passed = False
    verified_at = None

    if verify_report_file.is_file():
        try:
            v_data = json.loads(verify_report_file.read_text(encoding="utf-8"))
            checks_list = v_data.get("checks", [])
            if isinstance(checks_list, list) and len(checks_list) > 0:
                verify_executed = True
                verified_at = v_data.get("verified_at")
                id_map = {
                    "lint": "php_lint",
                    "doctor": "doctor",
                    "phpunit": "phpunit",
                    "smoke": "smoke_tests",
                    "pc_types": "pc_types",
                    "mobile_types": "mobile_types",
                    "package": "package_check",
                }
                for item in checks_list:
                    cid = item.get("id")
                    if cid in id_map:
                        if item.get("pass") is True:
                            verification_summary[id_map[cid]] = "passed"
                        elif item.get("status") == "skipped":
                            verification_summary[id_map[cid]] = "skipped"
                        else:
                            verification_summary[id_map[cid]] = "failed"
                verify_all_passed = (
                    v_data.get("status") == "pass"
                    and v_data.get("summary", {}).get("all_passed") is True
                    and all(v == "passed" for v in verification_summary.values())
                )
        except Exception:
            verify_executed = False
            verify_all_passed = False

    deploy_history = []
    if deploy_history_file.is_file():
        try:
            dh = json.loads(deploy_history_file.read_text(encoding="utf-8"))
            if isinstance(dh, list):
                deploy_history = dh
        except Exception:
            deploy_history = []

    last_deploy = deploy_history[-1] if deploy_history else None
    last_docker_apply = next(
        (
            item for item in reversed(deploy_history)
            if item.get("action") == "apply"
            and item.get("target") == "docker"
            and item.get("status") == "success"
            and item.get("production_runtime", {}).get("builtin_dev_server_rejected") is True
        ),
        None,
    )

    evidence_matches = False
    if verify_executed and artifact is not None:
        try:
            evidence = v_data.get("evidence", {})
            evidence_matches = (
                evidence.get("source_sha256") == source_fingerprint(project_root)
                and any(
                    item.get("filename") == artifact["filename"] and item.get("sha256") == artifact["sha256"]
                    for item in evidence.get("artifacts", [])
                )
                and verify_artifact_strictly(Path(artifact["path"]))[0]
            )
        except (OSError, ValueError, TypeError):
            evidence_matches = False

    if not verify_executed:
        overall_status = "unverified"
        ready_for_delivery = False
    elif verify_all_passed and evidence_matches:
        overall_status = "ready_for_delivery"
        ready_for_delivery = True
    else:
        overall_status = "verification_failed" if not verify_all_passed else "stale_verification"
        ready_for_delivery = False

    prod_verified = bool(
        last_docker_apply
        and evidence_matches
        and artifact is not None
        and last_docker_apply.get("artifact", {}).get("sha256") == artifact.get("sha256")
    )
    g07_public_verified = bool(last_docker_apply.get("g07_public_verified", False)) if prod_verified else False
    pending_items = (
        last_docker_apply.get("external_pending_steps")
        if last_docker_apply and isinstance(last_docker_apply.get("external_pending_steps"), list)
        else [
            "需用户提供生产服务器或 Docker 宿主机授权",
            "需配置生产域名解析与正式 TLS/SSL 证书",
        ]
    )

    report_data = {
        "report_id": f"REP-{datetime.now().strftime('%Y%m%d%H%M%S')}",
        "framework_version": framework_version,
        "git_commit": git_commit,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "status": overall_status,
        "ready_for_delivery": ready_for_delivery,
        "verification_scope": "docker_production_local" if prod_verified else "source_and_local_artifact",
        "production_deployment_verified": prod_verified,
        "g07_public_verified": g07_public_verified,
        "verified_at": verified_at,
        "evidence_matches_current_source_and_artifact": evidence_matches,
        "artifact": artifact,
        "verification_summary": verification_summary,
        "deployment_evidence": {
            "executed_count": len(deploy_history),
            "last_operation": last_deploy if last_deploy else "unexecuted",
        },
        "persistent_contract": {
            "environment_file": "server/.env & .env.docker (0600 Protected)",
            "database_backup": "MySQL/PostgreSQL SQL Dump / SQLite WAL Snapshot",
            "uploads_directory": "server/public/uploads/ (Consistent tar.gz + SHA-256 Manifest)",
        },
        "external_pending_items": pending_items,
    }

    if is_json:
        print(json.dumps(report_data, ensure_ascii=False, indent=2))
    else:
        md_text = f"""# HwAdmin 生产交付脱敏报告

- **报告标识**: `{report_data['report_id']}`
- **框架版本**: `{report_data['framework_version']}`
- **Git Commit**: `{report_data['git_commit']}`
- **生成时间**: `{report_data['created_at']}`
- **最终状态**: **{overall_status.upper()} (ready_for_delivery={ready_for_delivery})**
- **生产容器验证**: `{prod_verified}` (G07 公网放行: `{g07_public_verified}`)

## 1. 交付制品
- 文件名: `{artifact['filename'] if artifact else 'N/A'}`
- 体积: `{artifact['size_mb'] if artifact else 0} MB`
- SHA-256: `{artifact['sha256'] if artifact else 'N/A'}`

## 2. 自动化质量放行验证 (基于 .hw-state/verify-report.json 真实证据)
- PHP 全量严格语法校验 (`php_lint`): **{verification_summary['php_lint'].upper()}**
- 运行环境与依赖诊断 (`doctor`): **{verification_summary['doctor'].upper()}**
- PHPUnit 自动化测试 (`phpunit`): **{verification_summary['phpunit'].upper()}**
- 核心功能冒烟测试链路 (`smoke_tests`): **{verification_summary['smoke_tests'].upper()}**
- PC 管理端类型检查 (`pc_types`): **{verification_summary['pc_types'].upper()}**
- 移动端类型检查 (`mobile_types`): **{verification_summary['mobile_types'].upper()}**
- 交付包完整性断言校验 (`package_check`): **{verification_summary['package_check'].upper()}**

## 3. 部署与恢复执行证据
- 历史执行次数: `{len(deploy_history)}`
- 最近一次操作: `{json.dumps(last_deploy, ensure_ascii=False) if last_deploy else 'unexecuted'}`
"""
        print(md_text)
    return 0


def main():
    parser = argparse.ArgumentParser(description="HwAdmin 声明式生产部署与运维工具")
    subparsers = parser.add_subparsers(dest="command", help="子命令")

    # plan
    plan_p = subparsers.add_parser("plan", help="生成结构化部署计划")
    plan_p.add_argument("--target", default="docker", choices=["docker", "baota-nginx", "baota", "local"], help="部署目标适配器")
    plan_p.add_argument("--env-file", help="指定环境配置文件")
    plan_p.add_argument("--artifact", help="指定待部署 ZIP 制品路径")
    plan_p.add_argument("--target-dir", help="指定部署目标目录")
    plan_p.add_argument("--db-driver", choices=["mysql", "pgsql", "sqlite"], help="目标数据库驱动")
    plan_p.add_argument("--domain", help="生产站点域名")
    plan_p.add_argument("--https-cert", help="HTTPS TLS 证书文件路径")
    plan_p.add_argument("--https-key", help="HTTPS TLS 私钥文件路径")
    plan_p.add_argument("--http-port", help="HTTP 宿主机映射端口")
    plan_p.add_argument("--https-port", help="HTTPS 宿主机映射端口")
    plan_p.add_argument("--json", action="store_true", help="以 JSON 格式输出")

    # apply
    apply_p = subparsers.add_parser("apply", help="执行真实部署作业")
    apply_p.add_argument("--target", default="docker", choices=["docker", "baota-nginx", "baota", "local"], help="部署目标适配器")
    apply_p.add_argument("--env-file", help="指定环境配置文件")
    apply_p.add_argument("--artifact", help="指定待部署 ZIP 制品路径")
    apply_p.add_argument("--target-dir", help="指定部署目标目录")
    apply_p.add_argument("--backup-dir", help="指定备份输出目录")
    apply_p.add_argument("--db-driver", choices=["mysql", "pgsql"], help="Docker 生产数据库驱动 (mysql|pgsql)")
    apply_p.add_argument("--domain", help="生产站点域名")
    apply_p.add_argument("--https-cert", help="HTTPS TLS 证书文件路径")
    apply_p.add_argument("--https-key", help="HTTPS TLS 私钥文件路径")
    apply_p.add_argument("--http-port", help="HTTP 宿主机映射端口 (默认 8080)")
    apply_p.add_argument("--https-port", help="HTTPS 宿主机映射端口 (默认 8443)")
    apply_p.add_argument("--dry-run", action="store_true", help="模拟演练不实际写入")
    apply_p.add_argument("--json", action="store_true", help="以 JSON 格式输出")

    # rollback
    rb_p = subparsers.add_parser("rollback", help="执行故障回滚与恢复")
    rb_p.add_argument("--backup-dir", required=True, help="备份文件目录路径")
    rb_p.add_argument("--target-dir", help="指定恢复目标目录（默认从 backup-manifest.json 读取）")
    rb_p.add_argument("--target", default="docker", choices=["docker", "baota-nginx", "baota", "local"], help="部署目标适配器")
    rb_p.add_argument("--mode", default="full", choices=["code-only", "full"], help="回滚模式: full (代码+数据快照全量恢复) / code-only (仅回退代码文件)")
    rb_p.add_argument("--json", action="store_true", help="以 JSON 格式输出")

    # report
    rep_p = subparsers.add_parser("report", help="生成基于真实证据的脱敏交付报告")
    rep_p.add_argument("--format", default="json", choices=["json", "markdown"], help="输出格式")
    rep_p.add_argument("--json", action="store_true", help="以 JSON 格式输出")

    args = parser.parse_args()
    script_dir = Path(__file__).resolve().parent
    project_root = script_dir.parent

    if args.command == "plan":
        sys.exit(cmd_plan(args, project_root))
    elif args.command == "apply":
        sys.exit(cmd_apply(args, project_root))
    elif args.command == "rollback":
        sys.exit(cmd_rollback(args, project_root))
    elif args.command == "report":
        sys.exit(cmd_report(args, project_root))
    else:
        parser.print_help()
        sys.exit(0)


if __name__ == "__main__":
    main()
