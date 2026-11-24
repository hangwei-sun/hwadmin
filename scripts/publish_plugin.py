#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
HwAdmin 应用商城插件全局更新、版本迭代与自动发布工具

版本号规则（与主框架 Vx.y.z 命名看齐）：
  - 大版本 x：只能由 hangwei 明确指令修改，默认禁止自动递增（需 --allow-major）
  - 次版本 y (minor)：新增用户可见功能/模板/配置、接口契约变化、数据库结构或核心行为调整 -> (x, y+1, 0)
  - 修订号 z (patch)：bug 修复、样式/文案精修、自适应优化、文档或单点微调（拿不准按 patch）-> (x, y, z+1)
  - 单调递增基准：取「本地 plugin.json 版本」与「应用商城最新线上版本」中的较大值作为基准递增
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import re
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path
from typing import Any

if sys.platform == "win32":
    try:
        sys.stdout.reconfigure(encoding="utf-8")
        sys.stderr.reconfigure(encoding="utf-8")
    except Exception:
        pass

SEMVER_RE = re.compile(r"^v?(\d+)\.(\d+)\.(\d+)$")

EXCLUDED_BASENAMES = {
    ".DS_Store",
    "Thumbs.db",
    "desktop.ini",
    ".git",
    ".svn",
    "__pycache__",
}


def parse_semver(raw: str) -> tuple[int, int, int]:
    """解析 x.y.z（允许前导 v）为三元组。"""
    m = SEMVER_RE.match(str(raw or "").strip())
    if not m:
        raise ValueError(f"非法语义化版本号: {raw!r}（必须为 x.y.z 格式）")
    return int(m.group(1)), int(m.group(2)), int(m.group(3))


def format_semver(parts: tuple[int, int, int]) -> str:
    return f"{parts[0]}.{parts[1]}.{parts[2]}"


def max_semver(*versions: str | None) -> str:
    """返回多个版本字符串中的最大版本号。"""
    parsed = [parse_semver(v) for v in versions if v and SEMVER_RE.match(str(v).strip())]
    if not parsed:
        return "1.0.0"
    return format_semver(max(parsed))


def bump_semver(current: str, bump_type: str, allow_major: bool = False) -> str:
    """按主框架对齐规则计算下一个迭代版本号。"""
    major, minor, patch = parse_semver(current)
    if bump_type == "patch":
        return format_semver((major, minor, patch + 1))
    if bump_type == "minor":
        return format_semver((major, minor + 1, 0))
    if bump_type == "major":
        if not allow_major:
            raise ValueError("大版本 x 只能由项目负责人明确指令修改；如确认升级大版本请传入 --allow-major")
        return format_semver((major + 1, 0, 0))
    raise ValueError(f"未知递增类型: {bump_type}")


def should_skip_plugin_file(rel_posix: str) -> bool:
    parts = rel_posix.split("/")
    if any(p in EXCLUDED_BASENAMES for p in parts):
        return True
    filename = parts[-1]
    if filename.startswith(".env") and not filename.endswith(".example"):
        return True
    if re.search(r"\.(sqlite3?|db)(?:-(?:wal|shm|journal))?$", filename, re.IGNORECASE):
        return True
    if filename.endswith((".log", ".tmp", ".bak", ".pyc")):
        return True
    return False


def fetch_remote_plugin_version(market_url: str, plugin_name: str, timeout: int = 6) -> str | None:
    """从应用商城网关查询指定插件当前线上版本号。"""
    try:
        import requests

        sess = requests.Session()
        sess.trust_env = False
        base = market_url.rstrip("/")
        if "/api/plugin/market/gateway" not in base:
            base = base + "/api/plugin/market/gateway"
        resp = sess.get(f"{base}/catalog", timeout=timeout)
        if resp.status_code != 200:
            return None
        data = resp.json()
        if data.get("code") != 1:
            return None
        plugins_map = (data.get("data") or {}).get("plugins") or {}
        if isinstance(plugins_map, dict) and plugin_name in plugins_map:
            ver = plugins_map[plugin_name].get("latest_version") or plugins_map[plugin_name].get("version")
            if ver and SEMVER_RE.match(str(ver).strip()):
                return format_semver(parse_semver(str(ver)))
    except Exception:
        return None
    return None


def update_json_version(manifest_path: Path, new_version: str) -> dict[str, Any]:
    raw = manifest_path.read_text(encoding="utf-8")
    data = json.loads(raw)
    new_text, count = re.subn(
        r'("version"\s*:\s*")([^"]*)(")',
        rf"\g<1>{new_version}\g<3>",
        raw,
        count=1,
    )
    if count == 0:
        new_text = json.dumps({**data, "version": new_version}, ensure_ascii=False, indent=2) + "\n"
    data["version"] = new_version
    with manifest_path.open("w", encoding="utf-8", newline="\n") as fh:
        fh.write(new_text)
    return data


def sync_plugin_sources(src_dir: Path, dst_dir: Path) -> list[str]:
    """将插件源码同步至市场母仓 storage/plugin_sources/{name}（保留市场端额外微视图文件）。"""
    dst_dir.mkdir(parents=True, exist_ok=True)
    copied: list[str] = []
    for root, dirs, files in os.walk(src_dir):
        dirs[:] = [d for d in dirs if d not in EXCLUDED_BASENAMES]
        for fname in files:
            full_path = Path(root) / fname
            rel_posix = full_path.relative_to(src_dir).as_posix()
            if should_skip_plugin_file(rel_posix):
                continue
            target_path = dst_dir / rel_posix
            target_path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(full_path, target_path)
            copied.append(rel_posix)
    return sorted(copied)


def sync_fallback_catalog_versions(
    hwadmin_root: Path,
    market_root: Path | None,
    plugin_name: str,
    new_version: str,
) -> list[str]:
    """同步客户端离线回退目录与市场种子目录中对应插件的基线版本号。"""
    updated: list[str] = []

    updater_file = hwadmin_root / "server" / "core" / "plugin" / "SystemUpdater.php"
    if updater_file.is_file():
        text = updater_file.read_text(encoding="utf-8")
        pattern = re.compile(
            rf"('{re.escape(plugin_name)}'\s*=>\s*\[\s*'latest_version'\s*=>\s*')([^']+)(')",
            re.MULTILINE,
        )
        new_text, count = pattern.subn(rf"\g<1>{new_version}\g<3>", text, count=1)
        if count > 0 and new_text != text:
            with updater_file.open("w", encoding="utf-8", newline="\n") as fh:
                fh.write(new_text)
            updated.append(updater_file.relative_to(hwadmin_root).as_posix())

    if market_root:
        market_plugin_php = market_root / "server" / "plugins" / "market" / "Plugin.php"
        if market_plugin_php.is_file():
            text = market_plugin_php.read_text(encoding="utf-8")
            pattern = re.compile(
                rf"('name'\s*=>\s*'{re.escape(plugin_name)}'.*?'latest_version'\s*=>\s*')([^']+)(')",
                re.DOTALL,
            )
            new_text, count = pattern.subn(rf"\g<1>{new_version}\g<3>", text, count=1)
            if count > 0 and new_text != text:
                with market_plugin_php.open("w", encoding="utf-8", newline="\n") as fh:
                    fh.write(new_text)
                updated.append(market_plugin_php.as_posix())

    return updated


def build_plugin_zip(source_dir: Path, out_dir: Path, plugin_name: str, version: str) -> dict[str, Any]:
    """打包插件 ZIP（根目录直放 plugin.json）并生成不带 v 的兼容别名包。"""
    out_dir.mkdir(parents=True, exist_ok=True)
    zip_filename = f"{plugin_name}-v{version}.zip"
    alt_filename = f"{plugin_name}-{version}.zip"
    zip_path = out_dir / zip_filename
    alt_path = out_dir / alt_filename

    packed_files: list[str] = []
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
        for root, dirs, files in os.walk(source_dir):
            dirs[:] = sorted(d for d in dirs if d not in EXCLUDED_BASENAMES)
            for fname in sorted(files):
                full_path = Path(root) / fname
                rel_posix = full_path.relative_to(source_dir).as_posix()
                if should_skip_plugin_file(rel_posix):
                    continue
                zf.write(full_path, rel_posix)
                packed_files.append(rel_posix)

    if "plugin.json" not in packed_files:
        raise RuntimeError(f"打包结果缺少根目录 plugin.json: {zip_path}")

    if alt_path != zip_path:
        shutil.copy2(zip_path, alt_path)

    sha256 = hashlib.sha256(zip_path.read_bytes()).hexdigest()
    return {
        "zip_path": zip_path,
        "alt_path": alt_path,
        "zip_filename": zip_filename,
        "zip_size": zip_path.stat().st_size,
        "sha256": sha256,
        "file_count": len(packed_files),
    }


def upload_via_http(
    market_url: str,
    zip_path: Path,
    plugin_name: str,
    version: str,
    changelog: str,
    admin_token: str,
) -> dict[str, Any]:
    """通过应用商城后台上传接口发布插件 ZIP。"""
    import requests

    sess = requests.Session()
    sess.trust_env = False
    base = market_url.rstrip("/")
    url = f"{base}/adminapi/plugin/market/release/uploadZip"
    headers = {}
    if admin_token:
        headers["Authorization"] = f"Bearer {admin_token}"
        headers["X-Token"] = admin_token

    with zip_path.open("rb") as fh:
        resp = sess.post(
            url,
            headers=headers,
            files={"file": (zip_path.name, fh, "application/zip")},
            data={
                "plugin_name": plugin_name,
                "version": version,
                "changelog": changelog,
                "set_current": "1",
            },
            timeout=30,
        )
    payload = resp.json()
    if resp.status_code != 200 or payload.get("code") != 1:
        raise RuntimeError(f"HTTP 上传发布失败: status={resp.status_code}, body={payload}")
    return payload


def load_project_secrets(hwadmin_root: Path, market_root: Path | None) -> dict[str, str]:
    """从本地被 gitignore 忽略的 PROJECT_SECRETS.md 读取 KEY=VALUE 配置。"""
    values: dict[str, str] = {}
    candidates = [hwadmin_root / "PROJECT_SECRETS.md"]
    if market_root:
        candidates.append(market_root / "PROJECT_SECRETS.md")

    for path in candidates:
        if not path.is_file():
            continue
        for raw_line in path.read_text(encoding="utf-8").splitlines():
            line = raw_line.strip()
            if "=" not in line or line.startswith("#"):
                continue
            k, v = line.split("=", 1)
            k = k.strip()
            v = v.strip()
            if len(v) >= 2 and v[0] == v[-1] and v[0] in {"'", '"'}:
                v = v[1:-1]
            if k and k not in values:
                values[k] = v
    return values


def resolve_market_url(cli_url: str, hwadmin_root: Path, market_root: Path | None) -> str:
    """解析应用商城网关地址：命令行参数 > 环境变量 HW_MARKET_URL > PROJECT_SECRETS.md。"""
    if cli_url and cli_url.strip():
        return cli_url.strip()
    env_url = os.getenv("HW_MARKET_URL", "").strip()
    if env_url:
        return env_url
    return load_project_secrets(hwadmin_root, market_root).get("HW_MARKET_URL", "").strip()


def load_baota_secrets(
    hwadmin_root: Path,
    market_root: Path | None,
    cli_host: str = "",
    cli_port: str = "",
    cli_user: str = "",
) -> dict[str, str]:
    """读取宝塔服务器凭据（命令行参数 > 环境变量 > 本地被 gitignore 的 PROJECT_SECRETS.md）。"""
    secrets = load_project_secrets(hwadmin_root, market_root)
    creds = {
        "host": cli_host.strip() or os.getenv("BAOTA_HOST", "").strip() or secrets.get("BAOTA_HOST", "").strip(),
        "port": cli_port.strip() or os.getenv("BAOTA_PORT", "").strip() or secrets.get("BAOTA_PORT", "").strip() or "22",
        "user": cli_user.strip() or os.getenv("BAOTA_USER", "").strip() or secrets.get("BAOTA_USER", "").strip(),
        "pass": os.getenv("BAOTA_PASS", "").strip() or secrets.get("BAOTA_PASS", "").strip(),
    }
    return creds


def resolve_ssh_host_key_policy() -> tuple[Any, bool]:
    """解析 SSH 主机指纹策略：默认严格 RejectPolicy，仅 HW_SSH_TRUST_TOFU=1 时允许首次信任。"""
    import paramiko

    if os.getenv("HW_SSH_TRUST_TOFU", "").strip() == "1":
        return paramiko.AutoAddPolicy(), True
    return paramiko.RejectPolicy(), False


def publish_to_baota(
    hwadmin_root: Path,
    market_root: Path | None,
    source_dir: Path,
    zip_info: dict[str, Any],
    plugin_name: str,
    version: str,
    changelog: str,
    verify_url: str,
    cli_host: str = "",
    cli_port: str = "",
    cli_user: str = "",
) -> dict[str, Any]:
    """通过 SSH/SFTP 将插件源码与发布包同步到宝塔应用商城并激活最新版本。"""
    import paramiko
    import requests

    creds = load_baota_secrets(
        hwadmin_root,
        market_root,
        cli_host=cli_host,
        cli_port=cli_port,
        cli_user=cli_user,
    )
    missing = [
        env_key
        for field, env_key in (("host", "BAOTA_HOST"), ("user", "BAOTA_USER"), ("pass", "BAOTA_PASS"))
        if not creds[field]
    ]
    if missing:
        raise RuntimeError(
            f"缺少宝塔 SSH 必要配置 ({', '.join(missing)})；"
            "请通过命令行参数 (--baota-host/--baota-user)、环境变量或本地 PROJECT_SECRETS.md 配置"
        )
    if not verify_url.strip():
        raise RuntimeError(
            "缺少应用商城核验地址；请通过 --market-url、环境变量 HW_MARKET_URL 或 PROJECT_SECRETS.md 配置"
        )

    ssh = paramiko.SSHClient()
    ssh.load_system_host_keys()
    known_hosts = Path.home() / ".ssh" / "known_hosts"
    if known_hosts.is_file():
        ssh.load_host_keys(str(known_hosts))
    policy, is_tofu = resolve_ssh_host_key_policy()
    if is_tofu:
        print("⚠️ 已启用 HW_SSH_TRUST_TOFU=1，将首次信任未知 SSH 主机指纹", file=sys.stderr)
    ssh.set_missing_host_key_policy(policy)
    try:
        ssh.connect(
            creds["host"],
            port=int(creds["port"]),
            username=creds["user"],
            password=creds["pass"],
            timeout=15,
            banner_timeout=30,
        )
    except paramiko.SSHException as exc:
        raise RuntimeError(
            f"SSH 主机指纹校验或连接失败 ({creds['host']}:{creds['port']}): {exc}。"
            "请先将主机公钥写入 ~/.ssh/known_hosts，或仅在首次可信环境设置 HW_SSH_TRUST_TOFU=1"
        ) from exc
    sftp = ssh.open_sftp()

    def run_sudo(cmd: str) -> tuple[str, str, int]:
        b64 = base64.b64encode(cmd.encode("utf-8")).decode("ascii")
        full_cmd = f"sudo -k -S -p '' bash -c \"exec </dev/null; echo {b64} | base64 -d | bash\""
        stdin, stdout, stderr = ssh.exec_command(full_cmd)
        stdin.write(creds["pass"] + "\n")
        stdin.flush()
        stdin.channel.shutdown_write()
        out = stdout.read().decode("utf-8", errors="replace").strip()
        err = stderr.read().decode("utf-8", errors="replace").strip()
        code = stdout.channel.recv_exit_status()
        return out, err, code

    remote_market = "/www/wwwroot/market"
    try:
        # 1. 上传源目录压缩包到 /tmp 并解压至 storage/plugin_sources/{plugin_name}
        with tempfile.TemporaryDirectory() as tmpdir:
            src_bundle = Path(tmpdir) / f"{plugin_name}_src.zip"
            with zipfile.ZipFile(src_bundle, "w", zipfile.ZIP_DEFLATED) as zf:
                for root, dirs, files in os.walk(source_dir):
                    dirs[:] = sorted(d for d in dirs if d not in EXCLUDED_BASENAMES)
                    for fname in sorted(files):
                        fp = Path(root) / fname
                        rel = fp.relative_to(source_dir).as_posix()
                        if not should_skip_plugin_file(rel):
                            zf.write(fp, rel)
            remote_src_zip = f"/tmp/{plugin_name}_src_{version}.zip"
            sftp.put(str(src_bundle), remote_src_zip)

        remote_pkg_zip = f"/tmp/{zip_info['zip_filename']}"
        sftp.put(str(zip_info["zip_path"]), remote_pkg_zip)

        # 如果本地存在更新后的 PackageBuilderService.php / Release.php，也同步至远端 market 插件
        if market_root:
            pbs_local = market_root / "server" / "plugins" / "market" / "service" / "PackageBuilderService.php"
            rel_local = market_root / "server" / "plugins" / "market" / "controller" / "admin" / "Release.php"
            if pbs_local.is_file():
                sftp.put(str(pbs_local), "/tmp/PackageBuilderService.php")
                run_sudo(
                    f"cp -f /tmp/PackageBuilderService.php {remote_market}/plugins/market/service/PackageBuilderService.php "
                    f"&& rm -f /tmp/PackageBuilderService.php"
                )
            if rel_local.is_file():
                sftp.put(str(rel_local), "/tmp/Release.php")
                run_sudo(
                    f"cp -f /tmp/Release.php {remote_market}/plugins/market/controller/admin/Release.php "
                    f"&& rm -f /tmp/Release.php"
                )

        changelog_b64 = base64.b64encode(changelog.encode("utf-8")).decode("ascii")
        remote_cmd = f"""
set -e
mkdir -p {remote_market}/storage/plugin_sources/{plugin_name} {remote_market}/storage/market_packages
unzip -o -q {remote_src_zip} -d {remote_market}/storage/plugin_sources/{plugin_name}/
cp -f {remote_pkg_zip} {remote_market}/storage/market_packages/{zip_info['zip_filename']}
cp -f {remote_pkg_zip} {remote_market}/storage/market_packages/{plugin_name}-{version}.zip
rm -f {remote_src_zip} {remote_pkg_zip}
chown -R www:www {remote_market}/storage/plugin_sources/{plugin_name} {remote_market}/storage/market_packages {remote_market}/plugins/market
cd {remote_market}
sudo -u www php -r '
require "vendor/autoload.php";
$app = new \\think\\App(__DIR__);
$app->initialize();
$cl = base64_decode("{changelog_b64}") ?: "";
$res = \\plugins\\market\\service\\PackageBuilderService::publishPluginVersion("{plugin_name}", "{version}", $cl);
echo json_encode($res, JSON_UNESCAPED_UNICODE) . PHP_EOL;
'
rm -rf {remote_market}/runtime/cache/*
/etc/init.d/php-fpm-83 restart >/dev/null 2>&1 || true
"""
        out, err, code = run_sudo(remote_cmd)
        if code != 0:
            raise RuntimeError(f"远端商城发布失败 (code={code}): {err or out}")

        # 2. 端到端核验网关返回的最新版本
        sess = requests.Session()
        sess.trust_env = False
        cat_url = verify_url.rstrip("/") + "/api/plugin/market/gateway/catalog"
        r = sess.get(cat_url, timeout=10)
        cat_json = r.json()
        plugin_entry = ((cat_json.get("data") or {}).get("plugins") or {}).get(plugin_name) or {}
        if plugin_entry.get("latest_version") != version:
            raise RuntimeError(
                f"网关版本核验不一致：期望 {version}，实际 {plugin_entry.get('latest_version')}"
            )

        return {
            "remote_output": out,
            "verified_latest_version": plugin_entry.get("latest_version"),
            "download_url": plugin_entry.get("download_url"),
        }
    finally:
        sftp.close()
        ssh.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="HwAdmin 应用商城插件自动迭代版本号并打包发布工具")
    parser.add_argument("--plugin", required=True, help="插件代号，例如 cms")
    parser.add_argument(
        "--bump",
        choices=["patch", "minor", "major"],
        default="patch",
        help="迭代类型：patch (默认，小改+z) / minor (大改+y归零z) / major (需 --allow-major)",
    )
    parser.add_argument("--allow-major", action="store_true", help="显式允许升级大版本 x")
    parser.add_argument("--version", default="", help="显式指定目标版本号（须大于当前版本除非 --force）")
    parser.add_argument("--force", action="store_true", help="允许不递增直接使用指定 --version")
    parser.add_argument("--changelog", default="", help="本次版本更新说明")
    parser.add_argument("--hwadmin-root", default="", help="hwadmin 主仓库根目录")
    parser.add_argument("--market-root", default="", help="hwadmin-market 商城仓库根目录")
    parser.add_argument(
        "--market-url",
        default="",
        help="应用商城网关地址（也可通过环境变量 HW_MARKET_URL 或 PROJECT_SECRETS.md 配置）",
    )
    parser.add_argument("--baota-host", default="", help="宝塔 SSH 主机（也可通过 BAOTA_HOST 或 PROJECT_SECRETS.md 配置）")
    parser.add_argument("--baota-port", default="", help="宝塔 SSH 端口（默认 22）")
    parser.add_argument("--baota-user", default="", help="宝塔 SSH 用户名（也可通过 BAOTA_USER 或 PROJECT_SECRETS.md 配置）")
    parser.add_argument("--no-remote-check", action="store_true", help="跳过线上版本号查询，仅基于本地版本递增")
    parser.add_argument("--upload-http", action="store_true", help="通过后台 HTTP API 上传 ZIP 发布")
    parser.add_argument("--admin-token", default=os.getenv("MARKET_ADMIN_TOKEN", ""), help="商城后台上传 Token")
    parser.add_argument("--deploy-baota", action="store_true", help="同步至宝塔线上应用商城并激活最新版本")
    parser.add_argument("--out-dir", default="", help="自定义 ZIP 输出目录")
    parser.add_argument("--dry-run", action="store_true", help="仅演算版本号和同步计划，不修改文件")

    args = parser.parse_args()
    plugin_name = args.plugin.strip().lower()
    if not re.match(r"^[a-z][a-z0-9_]{1,49}$", plugin_name):
        print(f"❌ 非法插件代号: {plugin_name}", file=sys.stderr)
        return 2

    script_dir = Path(__file__).resolve().parent
    repo_root = script_dir.parent
    is_market_repo = (repo_root / "server" / "plugins" / "market").is_dir() and not (
        repo_root / "server" / "plugins" / "cms"
    ).is_dir()
    if args.hwadmin_root:
        hwadmin_root = Path(args.hwadmin_root).resolve()
    elif is_market_repo and (repo_root.parent / "hwadmin").is_dir():
        hwadmin_root = (repo_root.parent / "hwadmin").resolve()
    else:
        hwadmin_root = repo_root

    if args.market_root:
        market_root = Path(args.market_root).resolve()
    elif is_market_repo:
        market_root = repo_root
    elif (hwadmin_root.parent / "hwadmin-market").is_dir():
        market_root = (hwadmin_root.parent / "hwadmin-market").resolve()
    else:
        market_root = None

    plugin_dir = hwadmin_root / "server" / "plugins" / plugin_name
    if not (plugin_dir / "plugin.json").is_file() and market_root:
        candidate = market_root / "server" / "storage" / "plugin_sources" / plugin_name
        if (candidate / "plugin.json").is_file():
            plugin_dir = candidate

    manifest_path = plugin_dir / "plugin.json"
    if not manifest_path.is_file():
        print(f"❌ 未找到插件清单: {manifest_path}", file=sys.stderr)
        return 2

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    local_ver = format_semver(parse_semver(str(manifest.get("version", "1.0.0"))))

    market_source_ver: str | None = None
    if market_root:
        ms_json = market_root / "server" / "storage" / "plugin_sources" / plugin_name / "plugin.json"
        if ms_json.is_file():
            try:
                market_source_ver = format_semver(
                    parse_semver(str(json.loads(ms_json.read_text(encoding="utf-8")).get("version", "1.0.0")))
                )
            except Exception:
                pass

    market_url = resolve_market_url(args.market_url, hwadmin_root, market_root)
    if (args.upload_http or args.deploy_baota) and not market_url:
        print(
            "❌ 缺少应用商城地址：请通过 --market-url、环境变量 HW_MARKET_URL 或 PROJECT_SECRETS.md 中的 HW_MARKET_URL 配置",
            file=sys.stderr,
        )
        return 2

    if args.no_remote_check:
        remote_ver = None
    elif market_url:
        remote_ver = fetch_remote_plugin_version(market_url, plugin_name)
    else:
        remote_ver = None
        print("ℹ️ 未配置 --market-url / HW_MARKET_URL，已跳过线上版本查询（仅基于本地版本计算）。")
    base_ver = max_semver(local_ver, market_source_ver, remote_ver)

    if args.version:
        target_ver = format_semver(parse_semver(args.version))
        if not args.force and parse_semver(target_ver) <= parse_semver(base_ver):
            print(f"❌ 目标版本 {target_ver} 必须大于当前基准版本 {base_ver}", file=sys.stderr)
            return 2
    else:
        target_ver = bump_semver(base_ver, args.bump, allow_major=args.allow_major)

    changelog = args.changelog.strip() or f"v{target_ver} 插件功能优化与体验升级"
    out_dir = (
        Path(args.out_dir).resolve()
        if args.out_dir
        else (
            market_root / "server" / "storage" / "market_packages"
            if market_root
            else hwadmin_root / "release" / "plugins"
        )
    )

    print(f"🔌 插件: {plugin_name}")
    print(f"📌 本地版本: {local_ver} | 市场源码版本: {market_source_ver or '-'} | 线上版本: {remote_ver or '-'}")
    print(f"🚀 计算基准: {base_ver} -> 迭代目标 ({args.bump}): v{target_ver}")

    if args.dry_run:
        print("🧪 [dry-run] 演练完成，未写入任何文件。")
        return 0

    # 1. 更新插件主目录 plugin.json 版本号
    update_json_version(manifest_path, target_ver)

    # 2. 若 hwadmin-market 存在，同步插件源码到 storage/plugin_sources/{plugin_name}
    package_source_dir = plugin_dir
    if market_root:
        market_source_dir = market_root / "server" / "storage" / "plugin_sources" / plugin_name
        if plugin_dir.resolve() != market_source_dir.resolve():
            synced_files = sync_plugin_sources(plugin_dir, market_source_dir)
            print(f"📁 已同步 {len(synced_files)} 个源码文件至 {market_source_dir}")
        package_source_dir = market_source_dir

    # 3. 同步回退目录与市场种子版本号
    updated_refs = sync_fallback_catalog_versions(hwadmin_root, market_root, plugin_name, target_ver)
    if updated_refs:
        print(f"🔄 已同步版本基准引用: {', '.join(updated_refs)}")

    # 4. 构建标准 ZIP 包
    zip_info = build_plugin_zip(package_source_dir, out_dir, plugin_name, target_ver)
    print(
        f"📦 已生成发行包: {zip_info['zip_path']} "
        f"({zip_info['zip_size']} bytes, {zip_info['file_count']} files, sha256={zip_info['sha256'][:16]}...)"
    )

    # 5. 可选：通过 HTTP API 上传发布
    if args.upload_http:
        res = upload_via_http(market_url, zip_info["zip_path"], plugin_name, target_ver, changelog, args.admin_token)
        print(f"☁️ HTTP 上传发布成功: {res.get('msg')}")

    # 6. 可选：通过宝塔 SSH 同步到线上应用商城并发布激活
    if args.deploy_baota:
        baota_res = publish_to_baota(
            hwadmin_root,
            market_root,
            package_source_dir,
            zip_info,
            plugin_name,
            target_ver,
            changelog,
            market_url,
            cli_host=args.baota_host,
            cli_port=args.baota_port,
            cli_user=args.baota_user,
        )
        print(
            f"✅ 线上应用商城发布并核验通过: latest_version={baota_res['verified_latest_version']} "
            f"| download_url={baota_res['download_url']}"
        )

    return 0


if __name__ == "__main__":
    sys.exit(main())
