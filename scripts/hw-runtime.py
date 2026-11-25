#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
HwAdmin 受管运行时检测与准备工具 (hw runtime)

子命令：
  hw runtime matrix [--json] [--platform=<id>]
  hw runtime status [--json] [--mode=sqlite|mysql|pgsql|docker] [--platform=<id>]
  hw runtime prepare [--json] [--from-cache=<dir>] [--manifest=<path_or_url>]
                     [--url=<url> --sha256=<hex>] [--component=<name>]
                     [--mode=sqlite|mysql|pgsql|docker] [--retries=3]
                     [--timeout=30] [--retry-delay=1.0] [--trusted-host=<host>]
                     [--platform=<id>] [--dry-run]
"""

from __future__ import annotations

import argparse
import hashlib
import http.client
import json
import lzma
import os
import platform
import re
import shlex
import shutil
import stat
import subprocess
import sys
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

# 控制台 UTF-8 输出保障
if sys.stdout.encoding and sys.stdout.encoding.lower() != "utf-8":
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except Exception:
        pass
if sys.stderr.encoding and sys.stderr.encoding.lower() != "utf-8":
    try:
        sys.stderr.reconfigure(encoding="utf-8")
    except Exception:
        pass


SUPPORTED_PLATFORMS: list[dict[str, Any]] = [
    {
        "id": "windows-x64-wsl2",
        "os": "windows",
        "versions": ["10", "11"],
        "arch": ["x86_64"],
        "wsl_distros": ["Ubuntu 22.04", "Ubuntu 24.04", "Ubuntu 26.04"],
        "modes": ["sqlite", "mysql", "pgsql", "docker"],
        "status": "supported",
        "notes": "Windows 10/11 x64 + WSL2 (Ubuntu 22.04/24.04/26.04)；后端在 WSL2 运行，前端可在 Windows 或 WSL2 联调",
    },
    {
        "id": "linux-ubuntu-22.04",
        "os": "linux",
        "distro": "ubuntu",
        "versions": ["22.04"],
        "arch": ["x86_64", "arm64"],
        "modes": ["sqlite", "mysql", "pgsql", "docker"],
        "status": "supported",
        "notes": "Ubuntu 22.04 LTS (x86_64 / arm64)",
    },
    {
        "id": "linux-ubuntu-24.04",
        "os": "linux",
        "distro": "ubuntu",
        "versions": ["24.04"],
        "arch": ["x86_64", "arm64"],
        "modes": ["sqlite", "mysql", "pgsql", "docker"],
        "status": "supported",
        "notes": "Ubuntu 24.04 LTS (x86_64 / arm64)",
    },
    {
        "id": "linux-ubuntu-26.04",
        "os": "linux",
        "distro": "ubuntu",
        "versions": ["26.04"],
        "arch": ["x86_64", "arm64"],
        "modes": ["sqlite", "mysql", "pgsql", "docker"],
        "status": "supported",
        "notes": "Ubuntu 26.04 LTS (x86_64 / arm64)",
    },
    {
        "id": "linux-debian-12",
        "os": "linux",
        "distro": "debian",
        "versions": ["12"],
        "arch": ["x86_64", "arm64"],
        "modes": ["sqlite", "mysql", "pgsql", "docker"],
        "status": "supported",
        "notes": "Debian 12 Bookworm (x86_64 / arm64)",
    },
]

UNSUPPORTED_POLICIES: list[dict[str, Any]] = [
    {
        "pattern": "macos / darwin (x86_64, arm64)",
        "status": "unsupported",
        "reason": "macOS 尚未完成冷机与跨端交付联合验收，暂不承诺支持",
    },
    {
        "pattern": "windows-non-x64 / windows (< 10)",
        "status": "unsupported",
        "reason": "仅支持 Windows 10/11 x64 (WSL2)，不支持 32 位/ARM64 或旧版 Windows",
    },
    {
        "pattern": "other-linux (Ubuntu != 22.04/24.04/26.04, Debian != 12, Alpine/RHEL/CentOS host)",
        "status": "unsupported",
        "reason": "宿主机发行版不在已验收支持矩阵内（容器内镜像运行不等于宿主机直装受支持）",
    },
]

TRUSTED_SOURCE_HOSTS: set[str] = {
    "dl.static-php.dev",
    "getcomposer.org",
    "nodejs.org",
    "npmmirror.com",
    "cdn.npmmirror.com",
    "registry.npmmirror.com",
    "github.com",
    "objects.githubusercontent.com",
    "release-assets.githubusercontent.com",
    "mirrors.tuna.tsinghua.edu.cn",
    "mirrors.aliyun.com",
}

PHP_BASE_EXTENSIONS = ("pdo", "mbstring", "json", "openssl", "curl", "fileinfo", "ctype")
PHP_OPTIONAL_EXTENSIONS = ("gd", "zip", "redis")
SHA256_RE = re.compile(r"^[a-fA-F0-9]{64}$")


def normalize_arch(raw_arch: str) -> str:
    val = (raw_arch or "").strip().lower()
    if val in {"x86_64", "amd64", "x64"}:
        return "x86_64"
    if val in {"arm64", "aarch64"}:
        return "arm64"
    return val or "unknown"


def parse_os_release(text: str) -> dict[str, str]:
    data: dict[str, str] = {}
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, val = line.split("=", 1)
        data[key.strip()] = val.strip().strip('"').strip("'")
    return data


def detect_is_wsl2(release_str: str = "", proc_version: str = "") -> bool:
    if os.environ.get("WSL_DISTRO_NAME") or os.environ.get("WSL_INTEROP"):
        return True
    rel_l = release_str.lower()
    proc_l = proc_version.lower()
    if "microsoft" in rel_l or "wsl" in rel_l or "microsoft" in proc_l or "wsl" in proc_l:
        return True
    return False


def detect_current_platform(
    platform_override: str | None = None,
    system_name: str | None = None,
    release_str: str | None = None,
    machine_arch: str | None = None,
    os_release_text: str | None = None,
    is_wsl2: bool | None = None,
) -> dict[str, Any]:
    if platform_override:
        ov = platform_override.strip().lower()
        if ov in {"windows-x64-wsl2", "windows-10-x64", "windows-11-x64"}:
            return {
                "id": "windows-x64-wsl2",
                "os": "windows",
                "distro": "wsl2-ubuntu",
                "version": "10/11",
                "arch": "x86_64",
                "is_wsl2": False,
                "status": "supported",
                "reason": "命中支持矩阵：Windows 10/11 x64 (WSL2)",
            }
        if ov in {"linux-ubuntu-22.04", "ubuntu-22.04", "linux-ubuntu-22.04-x86_64", "linux-ubuntu-22.04-arm64"}:
            arch = "arm64" if ov.endswith("arm64") else "x86_64"
            return {
                "id": "linux-ubuntu-22.04",
                "os": "linux",
                "distro": "ubuntu",
                "version": "22.04",
                "arch": arch,
                "is_wsl2": False,
                "status": "supported",
                "reason": f"命中支持矩阵：Ubuntu 22.04 ({arch})",
            }
        if ov in {"linux-ubuntu-24.04", "ubuntu-24.04", "linux-ubuntu-24.04-x86_64", "linux-ubuntu-24.04-arm64"}:
            arch = "arm64" if ov.endswith("arm64") else "x86_64"
            return {
                "id": "linux-ubuntu-24.04",
                "os": "linux",
                "distro": "ubuntu",
                "version": "24.04",
                "arch": arch,
                "is_wsl2": False,
                "status": "supported",
                "reason": f"命中支持矩阵：Ubuntu 24.04 ({arch})",
            }
        if ov in {"linux-ubuntu-26.04", "ubuntu-26.04", "linux-ubuntu-26.04-x86_64", "linux-ubuntu-26.04-arm64"}:
            arch = "arm64" if ov.endswith("arm64") else "x86_64"
            return {
                "id": "linux-ubuntu-26.04",
                "os": "linux",
                "distro": "ubuntu",
                "version": "26.04",
                "arch": arch,
                "is_wsl2": False,
                "status": "supported",
                "reason": f"命中支持矩阵：Ubuntu 26.04 ({arch})",
            }
        if ov in {"linux-debian-12", "debian-12", "linux-debian-12-x86_64", "linux-debian-12-arm64"}:
            arch = "arm64" if ov.endswith("arm64") else "x86_64"
            return {
                "id": "linux-debian-12",
                "os": "linux",
                "distro": "debian",
                "version": "12",
                "arch": arch,
                "is_wsl2": False,
                "status": "supported",
                "reason": f"命中支持矩阵：Debian 12 ({arch})",
            }
        return {
            "id": ov,
            "os": ov.split("-")[0] if "-" in ov else ov,
            "distro": ov,
            "version": "unknown",
            "arch": "arm64" if "arm64" in ov else ("x86_64" if "x86_64" in ov or "x64" in ov else "unknown"),
            "is_wsl2": False,
            "status": "unsupported",
            "reason": f"平台 {platform_override} 不在已验收支持矩阵内（仅支持 Windows 10/11 x64 WSL2 Ubuntu 22.04/24.04 与 Linux Ubuntu 22.04/24.04, Debian 12 x86_64/arm64）",
        }

    sys_name = (system_name if system_name is not None else platform.system()).strip().lower()
    rel = release_str if release_str is not None else platform.release()
    arch = normalize_arch(machine_arch if machine_arch is not None else platform.machine())

    if sys_name == "darwin":
        return {
            "id": f"darwin-{arch}",
            "os": "darwin",
            "distro": "macos",
            "version": rel,
            "arch": arch,
            "is_wsl2": False,
            "status": "unsupported",
            "reason": "macOS 尚未完成冷机与跨端联合验收，当前标记为 unsupported",
        }

    if sys_name == "windows":
        try:
            major = int(str(rel).split(".")[0]) if rel else 0
        except ValueError:
            major = 0
        if arch == "x86_64" and major >= 10:
            return {
                "id": "windows-x64-wsl2",
                "os": "windows",
                "distro": "windows",
                "version": str(rel),
                "arch": arch,
                "is_wsl2": False,
                "status": "supported",
                "reason": "命中支持矩阵：Windows 10/11 x64 (需配合 WSL2 Ubuntu 22.04/24.04)",
            }
        return {
            "id": f"windows-{arch}",
            "os": "windows",
            "distro": "windows",
            "version": str(rel),
            "arch": arch,
            "is_wsl2": False,
            "status": "unsupported",
            "reason": f"Windows {rel} ({arch}) 不满足 Windows 10/11 x64 要求",
        }

    if sys_name == "linux":
        if os_release_text is None:
            os_rel_path = Path("/etc/os-release")
            os_release_text = os_rel_path.read_text(encoding="utf-8", errors="ignore") if os_rel_path.is_file() else ""
        os_info = parse_os_release(os_release_text)
        distro = os_info.get("ID", "unknown").lower()
        ver = os_info.get("VERSION_ID", "unknown")
        if is_wsl2 is None:
            proc_ver = ""
            proc_path = Path("/proc/version")
            if proc_path.is_file():
                proc_ver = proc_path.read_text(encoding="utf-8", errors="ignore")
            is_wsl2 = detect_is_wsl2(rel, proc_ver)

        if arch not in {"x86_64", "arm64"}:
            return {
                "id": f"linux-{distro}-{ver}-{arch}",
                "os": "linux",
                "distro": distro,
                "version": ver,
                "arch": arch,
                "is_wsl2": bool(is_wsl2),
                "status": "unsupported",
                "reason": f"架构 {arch} 不受支持，仅支持 x86_64 与 arm64",
            }
        if distro == "ubuntu" and ver in {"22.04", "24.04", "26.04"}:
            return {
                "id": f"linux-ubuntu-{ver}",
                "os": "linux",
                "distro": distro,
                "version": ver,
                "arch": arch,
                "is_wsl2": bool(is_wsl2),
                "status": "supported",
                "reason": f"命中支持矩阵：Ubuntu {ver} ({arch})" + (" [WSL2]" if is_wsl2 else ""),
            }
        if distro == "debian" and (ver == "12" or ver.startswith("12.")):
            return {
                "id": "linux-debian-12",
                "os": "linux",
                "distro": distro,
                "version": ver,
                "arch": arch,
                "is_wsl2": bool(is_wsl2),
                "status": "supported",
                "reason": f"命中支持矩阵：Debian 12 ({arch})",
            }
        return {
            "id": f"linux-{distro}-{ver}",
            "os": "linux",
            "distro": distro,
            "version": ver,
            "arch": arch,
            "is_wsl2": bool(is_wsl2),
            "status": "unsupported",
            "reason": f"Linux 发行版 {distro} {ver} ({arch}) 不在已验收矩阵内（仅支持 Ubuntu 22.04/24.04 与 Debian 12）",
        }

    return {
        "id": f"{sys_name}-{arch}",
        "os": sys_name,
        "distro": sys_name,
        "version": str(rel),
        "arch": arch,
        "is_wsl2": False,
        "status": "unsupported",
        "reason": f"未验收操作系统 {sys_name} ({arch})",
    }


def get_platform_support_matrix(
    platform_override: str | None = None,
    **detect_kwargs: Any,
) -> dict[str, Any]:
    current = detect_current_platform(platform_override=platform_override, **detect_kwargs)
    return {
        "current_platform": current,
        "supported": SUPPORTED_PLATFORMS,
        "unsupported": UNSUPPORTED_POLICIES,
    }


def resolve_project_root(explicit: str | None = None) -> Path:
    if explicit:
        return Path(explicit).resolve()
    script_root = Path(__file__).resolve().parents[1]
    if (script_root / "server/think").is_file() or (script_root / "think").is_file():
        return script_root
    return Path.cwd().resolve()


def _default_run_cmd(argv: list[str], env: dict[str, str] | None = None, timeout: int = 8) -> tuple[int, str, str]:
    try:
        proc = subprocess.run(
            argv,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            timeout=timeout,
        )
        raw_out = proc.stdout or b""
        raw_err = proc.stderr or b""
        # wsl.exe 常输出 UTF-16LE（带空字节）
        out = raw_out.decode("utf-16-le", errors="ignore") if b"\x00" in raw_out else raw_out.decode("utf-8", errors="ignore")
        err = raw_err.decode("utf-16-le", errors="ignore") if b"\x00" in raw_err else raw_err.decode("utf-8", errors="ignore")
        return proc.returncode, out.strip(), err.strip()
    except subprocess.TimeoutExpired:
        return 124, "", "command timed out"
    except FileNotFoundError:
        return 127, "", "command not found"
    except Exception as exc:
        return 1, "", str(exc)


def _parse_semver(text: str) -> tuple[int, int, int] | None:
    m = re.search(r"(\d+)\.(\d+)(?:\.(\d+))?", text or "")
    if not m:
        return None
    return int(m.group(1)), int(m.group(2)), int(m.group(3) or 0)


def _node_meets_minimum(ver: tuple[int, int, int]) -> bool:
    return ver >= (22, 18, 0)


def _node_meets_recommended(ver: tuple[int, int, int]) -> bool:
    major, minor, _ = ver
    return (major, minor) >= (22, 18)


def _build_search_path(project_root: Path, path_override: str | None = None) -> str:
    if path_override is not None:
        return path_override
    entries: list[str] = []
    managed_bin = project_root / ".hw-state/runtime/bin"
    if managed_bin.is_dir():
        entries.append(str(managed_bin))
    local_bin = Path.home() / ".local/bin"
    if local_bin.is_dir():
        entries.append(str(local_bin))
    existing = os.environ.get("PATH", "")
    if existing:
        entries.append(existing)
    return os.pathsep.join(entries)


def _which_in_path(name: str, search_path: str, project_root: Path) -> tuple[str | None, str]:
    if not search_path:
        return None, "missing"
    found = shutil.which(name, path=search_path)
    if not found:
        return None, "missing"
    managed_root = str((project_root / ".hw-state/runtime").resolve())
    try:
        resolved = str(Path(found).resolve())
        source = "managed" if resolved.startswith(managed_root) else "system"
    except Exception:
        source = "system"
    return found, source


def _probe_wsl_windows(runner: Callable[..., tuple[int, str, str]]) -> dict[str, Any]:
    wsl_bin = shutil.which("wsl.exe") or shutil.which("wsl")
    if not wsl_bin:
        return {
            "installed": False,
            "reusable": False,
            "version": None,
            "path": None,
            "distros": [],
            "reboot_required": True,
            "remediation": "以管理员身份打开 PowerShell 执行 `wsl --install -d Ubuntu-24.04`，并在提示时重启 Windows 完成启用",
        }
    rc, out, err = runner([wsl_bin, "-l", "-v"], timeout=6)
    lines = [ln.strip().lstrip("*").strip() for ln in out.splitlines() if ln.strip()]
    distros: list[dict[str, str]] = []
    for ln in lines[1:] if len(lines) > 1 else lines:
        parts = re.split(r"\s{2,}", ln)
        if len(parts) >= 3:
            distros.append({"name": parts[0], "state": parts[1], "version": parts[2]})
        elif ln and not ln.lower().startswith("name"):
            distros.append({"name": ln.split()[0], "state": "unknown", "version": "unknown"})
    has_ubuntu = any("ubuntu" in d["name"].lower() and ("22.04" in d["name"] or "24.04" in d["name"] or d["name"].lower() == "ubuntu") for d in distros)
    reusable = rc == 0 and has_ubuntu
    return {
        "installed": True,
        "reusable": reusable,
        "version": "WSL2" if any(d.get("version") == "2" for d in distros) else "WSL",
        "path": wsl_bin,
        "distros": distros,
        "reboot_required": not reusable,
        "remediation": "" if reusable else "未检测到 Ubuntu 22.04/24.04 WSL2 发行版；请运行 `wsl --install -d Ubuntu-24.04`（必要时重启系统）",
    }


def detect_runtimes(
    project_root: Path,
    mode: str = "sqlite",
    platform_override: str | None = None,
    path_override: str | None = None,
    runner: Callable[..., tuple[int, str, str]] | None = None,
    platform_info: dict[str, Any] | None = None,
) -> dict[str, Any]:
    run_cmd = runner or _default_run_cmd
    plat = platform_info or detect_current_platform(platform_override=platform_override)
    search_path = _build_search_path(project_root, path_override=path_override)
    cmd_env = os.environ.copy()
    cmd_env["PATH"] = search_path

    runtimes: dict[str, Any] = {}
    missing: list[dict[str, Any]] = []

    # Match composer.json and the package engines, not just upstream compatibility.
    php_bin, php_src = _which_in_path("php", search_path, project_root)
    php_entry: dict[str, Any] = {
        "name": "php",
        "installed": False,
        "reusable": False,
        "version": None,
        "path": php_bin,
        "source": php_src,
        "meets_minimum": False,
        "meets_recommended": False,
        "extensions": {"loaded": [], "missing_required": [], "missing_optional": []},
        "remediation": "安装 PHP >= 8.2 及 pdo/mbstring/json/openssl/curl/fileinfo/ctype 与数据库驱动；或使用 `hw runtime prepare --manifest ...`",
    }
    if php_bin:
        rc_v, out_v, _ = run_cmd([php_bin, "-r", "echo PHP_VERSION;"], env=cmd_env)
        ver = _parse_semver(out_v) if rc_v == 0 else None
        if ver:
            php_entry["installed"] = True
            php_entry["version"] = out_v.strip()
            php_entry["meets_minimum"] = ver[:2] >= (8, 2)
            php_entry["meets_recommended"] = ver[:2] >= (8, 2)
            rc_m, out_m, _ = run_cmd([php_bin, "-m"], env=cmd_env)
            loaded = sorted({ln.strip().lower() for ln in out_m.splitlines() if ln.strip() and not ln.startswith("[")}) if rc_m == 0 else []
            req_exts = list(PHP_BASE_EXTENSIONS)
            if mode == "sqlite":
                has_sqlite = "pdo_sqlite" in loaded
                missing_db = [] if has_sqlite else ["pdo_sqlite"]
            elif mode == "mysql":
                missing_db = [] if "pdo_mysql" in loaded else ["pdo_mysql"]
            elif mode == "pgsql":
                missing_db = [] if "pdo_pgsql" in loaded else ["pdo_pgsql"]
            else:
                missing_db = []
            missing_req = [e for e in req_exts if e not in loaded] + missing_db
            missing_opt = [e for e in PHP_OPTIONAL_EXTENSIONS if e not in loaded]
            php_entry["extensions"] = {
                "loaded": loaded,
                "missing_required": missing_req,
                "missing_optional": missing_opt,
            }
            php_entry["reusable"] = bool(php_entry["meets_minimum"] and not missing_req)
            if not php_entry["meets_minimum"]:
                php_entry["remediation"] = f"当前 PHP {php_entry['version']} 过低，要求 >= 8.2（server/composer.json）"
            elif missing_req:
                php_entry["remediation"] = f"当前 PHP {php_entry['version']} 缺少必需扩展: {', '.join(missing_req)}"
            elif not php_entry["meets_recommended"]:
                php_entry["remediation"] = f"PHP {php_entry['version']} 满足 >=8.1 最低要求，建议升级 >=8.2 对齐 server/composer.json"
            else:
                php_entry["remediation"] = ""
    runtimes["php"] = php_entry
    if not php_entry["reusable"] and mode != "docker":
        missing.append({"component": "php", "required": True, "reason": php_entry["remediation"]})

    # 2. Composer
    comp_bin, comp_src = _which_in_path("composer", search_path, project_root)
    comp_entry: dict[str, Any] = {
        "name": "composer",
        "installed": False,
        "reusable": False,
        "version": None,
        "path": comp_bin,
        "source": comp_src,
        "remediation": "安装 Composer v2（https://getcomposer.org/）或通过受管运行时清单准备",
    }
    if comp_bin:
        rc_c, out_c, _ = run_cmd([comp_bin, "--version"], env=cmd_env)
        ver_c = _parse_semver(out_c) if rc_c == 0 else None
        if rc_c == 0 and out_c:
            comp_entry["installed"] = True
            comp_entry["version"] = ".".join(map(str, ver_c)) if ver_c else out_c.splitlines()[0]
            comp_entry["reusable"] = bool(ver_c and ver_c[0] >= 2)
            comp_entry["remediation"] = "" if comp_entry["reusable"] else "需要 Composer >=2"
    runtimes["composer"] = comp_entry
    if not comp_entry["reusable"] and mode != "docker":
        missing.append({"component": "composer", "required": True, "reason": comp_entry["remediation"]})

    # 3. Node.js (admin-ui engines: >=22.18)
    node_bin, node_src = _which_in_path("node", search_path, project_root)
    node_entry: dict[str, Any] = {
        "name": "node",
        "installed": False,
        "reusable": False,
        "version": None,
        "path": node_bin,
        "source": node_src,
        "meets_minimum": False,
        "meets_recommended": False,
        "remediation": "安装 Node.js >=22.18.0（admin-ui engines）",
    }
    if node_bin:
        rc_n, out_n, _ = run_cmd([node_bin, "-v"], env=cmd_env)
        ver_n = _parse_semver(out_n) if rc_n == 0 else None
        if ver_n:
            node_entry["installed"] = True
            node_entry["version"] = out_n.lstrip("v").strip()
            node_entry["meets_minimum"] = _node_meets_minimum(ver_n)
            node_entry["meets_recommended"] = _node_meets_recommended(ver_n)
            node_entry["reusable"] = node_entry["meets_minimum"]
            if not node_entry["reusable"]:
                node_entry["remediation"] = f"当前 Node.js v{node_entry['version']} 版本过低，要求 >=22.18.0"
            elif not node_entry["meets_recommended"]:
                node_entry["remediation"] = f"Node.js v{node_entry['version']} 满足最低兼容基线，建议升级 >= 22.18.0"
            else:
                node_entry["remediation"] = ""
    runtimes["node"] = node_entry
    if not node_entry["reusable"]:
        missing.append({"component": "node", "required": False, "reason": node_entry["remediation"]})

    # 4. pnpm
    pnpm_bin, pnpm_src = _which_in_path("pnpm", search_path, project_root)
    pnpm_entry: dict[str, Any] = {
        "name": "pnpm",
        "installed": False,
        "reusable": False,
        "version": None,
        "path": pnpm_bin,
        "source": pnpm_src,
        "remediation": "执行 `corepack enable pnpm` 或 `npm install -g pnpm@10.33.0` 安装 pnpm",
    }
    if pnpm_bin:
        rc_p, out_p, _ = run_cmd([pnpm_bin, "-v"], env=cmd_env)
        ver_p = _parse_semver(out_p) if rc_p == 0 else None
        if ver_p:
            pnpm_entry["installed"] = True
            pnpm_entry["version"] = out_p.strip()
            pnpm_entry["reusable"] = ver_p[0] == 10
            pnpm_entry["remediation"] = "" if pnpm_entry["reusable"] else f"pnpm {out_p} 不满足工程 engines: >=10 <11"
    runtimes["pnpm"] = pnpm_entry
    if not pnpm_entry["reusable"]:
        missing.append({"component": "pnpm", "required": False, "reason": pnpm_entry["remediation"]})

    # 5. Python (>= 3.9，跳过 WindowsApps 空壳桩并回退到真实 python)
    py_candidates: list[tuple[str, str]] = []
    for py_name in ("python3", "python"):
        cand_bin, cand_src = _which_in_path(py_name, search_path, project_root)
        if cand_bin and cand_bin not in {c[0] for c in py_candidates}:
            py_candidates.append((cand_bin, cand_src))

    py_bin, py_src = (py_candidates[0] if py_candidates else (None, "missing"))
    py_entry: dict[str, Any] = {
        "name": "python",
        "installed": False,
        "reusable": False,
        "version": None,
        "path": py_bin,
        "source": py_src,
        "remediation": "安装 Python >= 3.9",
    }
    for cand_bin, cand_src in py_candidates:
        rc_py, out_py, err_py = run_cmd([cand_bin, "--version"], env=cmd_env)
        ver_py = _parse_semver(out_py or err_py) if rc_py == 0 else None
        if ver_py:
            py_entry["path"] = cand_bin
            py_entry["source"] = cand_src
            py_entry["installed"] = True
            py_entry["version"] = ".".join(map(str, ver_py))
            py_entry["reusable"] = ver_py[:2] >= (3, 9)
            py_entry["remediation"] = "" if py_entry["reusable"] else f"Python {py_entry['version']} 过低，要求 >= 3.9"
            break
    runtimes["python"] = py_entry
    if not py_entry["reusable"]:
        missing.append({"component": "python", "required": True, "reason": py_entry["remediation"]})

    # 6. WSL
    reboot_required = False
    reboot_reason = ""
    if plat.get("os") == "windows":
        wsl_info = _probe_wsl_windows(run_cmd)
        if wsl_info.get("reboot_required"):
            reboot_required = True
            reboot_reason = wsl_info["remediation"]
        if not wsl_info["reusable"]:
            missing.append({"component": "wsl", "required": True, "reason": wsl_info["remediation"]})
        runtimes["wsl"] = wsl_info
    elif plat.get("is_wsl2"):
        runtimes["wsl"] = {
            "installed": True,
            "reusable": True,
            "version": "WSL2",
            "path": None,
            "remediation": "",
        }
    else:
        runtimes["wsl"] = {
            "installed": False,
            "reusable": True,
            "not_applicable": True,
            "version": None,
            "path": None,
            "remediation": "",
        }

    # 7. Docker & Docker Compose
    docker_bin, docker_src = _which_in_path("docker", search_path, project_root)
    docker_entry: dict[str, Any] = {
        "name": "docker",
        "installed": bool(docker_bin),
        "reusable": False,
        "daemon_running": False,
        "compose_available": False,
        "version": None,
        "compose_version": None,
        "path": docker_bin,
        "source": docker_src,
        "remediation": "安装 Docker Engine / Docker Desktop 并开启 Docker Compose v2" + ("（WSL2 请开启 Docker Desktop WSL Integration）" if plat.get("is_wsl2") else ""),
    }
    if docker_bin:
        rc_dv, out_dv, _ = run_cmd([docker_bin, "--version"], env=cmd_env, timeout=4)
        ver_d = _parse_semver(out_dv) if rc_dv == 0 else None
        if ver_d:
            docker_entry["version"] = ".".join(map(str, ver_d))
        rc_cv, out_cv, _ = run_cmd([docker_bin, "compose", "version"], env=cmd_env, timeout=4)
        if rc_cv == 0 and out_cv:
            docker_entry["compose_available"] = True
            ver_c = _parse_semver(out_cv)
            docker_entry["compose_version"] = ".".join(map(str, ver_c)) if ver_c else out_cv
        rc_di, out_di, err_di = run_cmd([docker_bin, "version", "--format", "{{.Server.Version}}"], env=cmd_env, timeout=5)
        if rc_di == 0 and out_di:
            docker_entry["daemon_running"] = True
            docker_entry["reusable"] = docker_entry["compose_available"]
            docker_entry["remediation"] = "" if docker_entry["reusable"] else "Docker Compose v2 不可用"
        else:
            detail = (err_di or out_di or "daemon unreachable").splitlines()[0]
            docker_entry["remediation"] = f"Docker daemon 未就绪或未开启 WSL Integration: {detail}"
    runtimes["docker"] = docker_entry
    if mode == "docker" and not docker_entry["reusable"]:
        missing.append({"component": "docker", "required": True, "reason": docker_entry["remediation"]})

    # 8. Writable permissions
    perm_targets = [".hw-state", ".hw-state/runtime-cache", ".hw-state/runtime"]
    if (project_root / "server").is_dir():
        perm_targets.extend(["server/runtime", "server/config", "server/public/uploads"])
    permissions: list[dict[str, Any]] = []
    for rel in perm_targets:
        target = project_root / rel
        probe = target
        while not probe.exists() and probe != probe.parent:
            probe = probe.parent
        writable = os.access(probe, os.W_OK | os.R_OK)
        permissions.append({"path": rel, "writable": writable, "probe_target": str(probe)})
        if not writable:
            missing.append({"component": f"permission:{rel}", "required": True, "reason": f"目录不可写: {probe}"})

    required_missing = [m for m in missing if m.get("required")]
    ready = plat.get("status") == "supported" and len(required_missing) == 0

    return {
        "platform": plat,
        "platform_support_matrix": get_platform_support_matrix(platform_override=platform_override, **({"system_name": plat["os"], "release_str": plat["version"], "machine_arch": plat["arch"], "is_wsl2": plat.get("is_wsl2", False)} if not platform_override else {})),
        "mode": mode,
        "ready": ready,
        "reboot_required": reboot_required,
        "reboot_reason": reboot_reason,
        "runtimes": runtimes,
        "permissions": permissions,
        "missing": missing,
    }


def calculate_sha256(file_path: Path) -> str:
    h = hashlib.sha256()
    with open(file_path, "rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            h.update(chunk)
    return h.hexdigest().lower()


def validate_sha256_hex(value: str | None) -> str:
    val = (value or "").strip().lower()
    if not SHA256_RE.match(val):
        raise ValueError(f"缺少或无效的 64 位 SHA-256 校验值: {value!r}")
    return val


def validate_source_url(url: str, extra_trusted_hosts: set[str] | None = None) -> urllib.parse.ParseResult:
    parsed = urllib.parse.urlparse(url)
    scheme = (parsed.scheme or "").lower()
    host = (parsed.hostname or "").lower()
    if not host:
        raise ValueError(f"无效的下载 URL（缺少主机名）: {url}")

    if host in {"127.0.0.1", "localhost", "::1"}:
        if scheme not in {"http", "https"}:
            raise ValueError(f"本地测试镜像仅支持 http/https 协议: {url}")
        return parsed

    if scheme != "https":
        raise ValueError(f"受管运行时远程下载强制要求 HTTPS 协议: {url}")

    allowed = set(TRUSTED_SOURCE_HOSTS)
    if extra_trusted_hosts:
        allowed.update(h.strip().lower() for h in extra_trusted_hosts if h.strip())

    if host not in allowed and not any(host.endswith("." + item) for item in allowed):
        raise ValueError(
            f"下载源主机 {host!r} 不在受信任源白名单中；如需使用企业内网镜像请显式传入 --trusted-host={host}"
        )
    return parsed


def download_with_retries(
    url: str,
    dest_file: Path,
    retries: int = 3,
    timeout: float = 30.0,
    retry_delay: float = 1.0,
    extra_trusted_hosts: set[str] | None = None,
) -> tuple[int, int]:
    """在不修改全局代理的前提下执行带重试下载，返回 (attempts, download_ms)。"""
    validate_source_url(url, extra_trusted_hosts=extra_trusted_hosts)
    dest_file.parent.mkdir(parents=True, exist_ok=True)
    part_file = dest_file.with_name(dest_file.name + ".part")
    max_attempts = max(1, int(retries))
    start = time.monotonic()
    last_err: Exception | None = None

    for attempt in range(1, max_attempts + 1):
        try:
            if part_file.exists():
                part_file.unlink()
            req = urllib.request.Request(url, headers={"User-Agent": "HwAdmin-Runtime-Preparer/1.0"})
            class TrustedRedirectHandler(urllib.request.HTTPRedirectHandler):
                def redirect_request(self, request, fp, code, msg, headers, newurl):
                    validate_source_url(newurl, extra_trusted_hosts=extra_trusted_hosts)
                    return super().redirect_request(request, fp, code, msg, headers, newurl)

            opener = urllib.request.build_opener(TrustedRedirectHandler())
            with opener.open(req, timeout=timeout) as resp:
                status = getattr(resp, "status", 200)
                if status and int(status) >= 400:
                    raise urllib.error.HTTPError(url, int(status), f"HTTP {status}", resp.headers, None)
                with open(part_file, "wb") as out:
                    while True:
                        chunk = resp.read(65536)
                        if not chunk:
                            break
                        out.write(chunk)
            part_file.replace(dest_file)
            elapsed_ms = int((time.monotonic() - start) * 1000)
            return attempt, elapsed_ms
        except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError, OSError, http.client.HTTPException) as exc:
            last_err = exc
            if part_file.exists():
                try:
                    part_file.unlink()
                except OSError:
                    pass
            if isinstance(exc, urllib.error.HTTPError) and 400 <= exc.code < 500 and exc.code != 429:
                break
            if attempt < max_attempts:
                time.sleep(min(retry_delay * attempt, 5.0))

    elapsed_ms = int((time.monotonic() - start) * 1000)
    raise RuntimeError(f"下载失败（已重试 {max_attempts} 次，耗时 {elapsed_ms}ms）: {last_err}")


def _validate_rel_member(name: str) -> Path:
    if not name or "\\" in name or name.startswith("/"):
        raise ValueError(f"归档成员路径非法: {name!r}")
    if re.match(r"^[A-Za-z]:", name):
        raise ValueError(f"归档成员包含盘符路径: {name!r}")
    pure = Path(name)
    if ".." in pure.parts or ":" in name or "\x00" in name:
        raise ValueError(f"归档成员存在路径穿越: {name!r}")
    return pure


def _confined_runtime_path(root: Path, relative: str) -> Path:
    rel = _validate_rel_member(relative)
    target = root / rel
    if not target.resolve().is_relative_to(root.resolve()) or target.resolve() == root.resolve():
        raise ValueError(f"运行时路径越界: {relative!r}")
    return target


def _component_name(value: str) -> str:
    if not re.fullmatch(r"[A-Za-z][A-Za-z0-9_-]{0,63}", value):
        raise ValueError(f"运行时组件名称非法: {value!r}")
    return value


def _safe_extract_archive(archive_path: Path, staging_dir: Path) -> None:
    if staging_dir.exists():
        shutil.rmtree(staging_dir)
    staging_dir.mkdir(parents=True, exist_ok=True)
    staging_resolved = staging_dir.resolve()

    lower_name = archive_path.name.lower()
    if lower_name.endswith(".zip"):
        try:
            with zipfile.ZipFile(archive_path, "r") as zf:
                bad = zf.testzip()
                if bad is not None:
                    raise ValueError(f"ZIP 归档 CRC 损坏，坏成员: {bad}")
                for info in zf.infolist():
                    rel = _validate_rel_member(info.filename)
                    mode_bits = (info.external_attr >> 16) & 0o170000
                    if mode_bits == stat.S_IFLNK:
                        raise ValueError(f"拒绝 ZIP 符号链接成员: {info.filename}")
                    target = (staging_resolved / rel).resolve()
                    if target != staging_resolved and staging_resolved not in target.parents:
                        raise ValueError(f"归档成员越界: {info.filename}")
                zf.extractall(staging_resolved)
        except (zipfile.BadZipFile, OSError, EOFError) as exc:
            raise ValueError(f"损坏或无效的 ZIP 归档: {exc}") from exc
        return

    if lower_name.endswith((".tar.gz", ".tgz", ".tar.xz", ".txz", ".tar.bz2", ".tar")):
        try:
            with tarfile.open(archive_path, "r:*") as tf:
                members = tf.getmembers()
                for m in members:
                    if not m.name or m.name in {".", "./"}:
                        continue
                    clean_name = m.name[2:] if m.name.startswith("./") else m.name
                    rel = _validate_rel_member(clean_name)
                    target = (staging_resolved / rel).resolve()
                    if target != staging_resolved and staging_resolved not in target.parents:
                        raise ValueError(f"归档成员越界: {m.name}")
                    if m.issym() or m.islnk():
                        link_dest = (target.parent / m.linkname).resolve() if m.issym() else (staging_resolved / m.linkname.lstrip("/")).resolve()
                        if link_dest != staging_resolved and staging_resolved not in link_dest.parents:
                            raise ValueError(f"归档链接越界: {m.name} -> {m.linkname}")
                    elif not (m.isfile() or m.isdir()):
                        raise ValueError(f"归档包含非法特殊设备/节点成员: {m.name}")
                for m in members:
                    if not m.name or m.name in {".", "./"}:
                        continue
                    # Validate links again against the filesystem produced by previous members.
                    if hasattr(tarfile, "data_filter"):
                        tf.extract(m, staging_resolved, set_attrs=False, filter="data")
                    elif m.issym() or m.islnk():
                        raise ValueError("当前 Python 不支持安全 TAR 链接过滤，请升级 Python")
                    else:
                        target = (staging_resolved / m.name).resolve()
                        if not target.is_relative_to(staging_resolved):
                            raise ValueError(f"归档成员越界: {m.name}")
                        tf.extract(m, staging_resolved, set_attrs=False)
        except (tarfile.TarError, EOFError, OSError, lzma.LZMAError) as exc:
            raise ValueError(f"损坏或无效的 TAR 归档: {exc}") from exc
        return

    raise ValueError(f"不支持的归档格式: {archive_path.name}")


def _unwrap_single_root_dir(staging_dir: Path) -> Path:
    entries = [p for p in staging_dir.iterdir() if p.name != "__MACOSX"]
    if len(entries) == 1 and entries[0].is_dir() and not (staging_dir / "bin").is_dir():
        return entries[0]
    return staging_dir


def _install_component_from_staging(
    staging_dir: Path,
    runtime_dir: Path,
    component: str,
    bin_relpaths: list[str] | None = None,
) -> list[str]:
    source_root = _unwrap_single_root_dir(staging_dir)
    component = _component_name(component)
    comp_dir = _confined_runtime_path(runtime_dir, f"components/{component}")
    if comp_dir.is_symlink():
        raise ValueError("组件目录不得是符号链接")

    linked_bins: list[str] = []
    candidates: list[Path] = []
    if bin_relpaths:
        if not isinstance(bin_relpaths, list) or not all(isinstance(p, str) for p in bin_relpaths):
            raise ValueError("bin_relpaths 必须是相对路径数组")
        for rel in bin_relpaths:
            candidates.append(_confined_runtime_path(source_root, rel))
    else:
        for sub in ("bin", ""):
            base = source_root / sub if sub else source_root
            if base.is_dir():
                for item in sorted(base.iterdir()):
                    if item.is_file() and (
                        item.name in {"php", "php.exe", "composer", "composer.phar", "node", "node.exe", "pnpm", "pnpm.exe", "python3", "python"}
                        or os.access(item, os.X_OK)
                    ):
                        candidates.append(item)

    # Preflight every entry before replacing an already working component.
    relative_bins = []
    for exe in candidates:
        if not exe.is_file():
            raise ValueError(f"声明的可执行入口缺失: {exe}")
        _confined_runtime_path(source_root, exe.relative_to(source_root).as_posix())
        relative_bins.append(exe.relative_to(source_root))
    if not relative_bins:
        raise ValueError("运行时归档没有可执行入口")
    bin_dir = _confined_runtime_path(runtime_dir, "bin")
    bin_dir.mkdir(parents=True, exist_ok=True)
    if comp_dir.exists():
        shutil.rmtree(comp_dir)
    comp_dir.parent.mkdir(parents=True, exist_ok=True)
    shutil.move(str(source_root), str(comp_dir))
    for relative in relative_bins:
        exe = comp_dir / relative
        if os.name != "nt":
            exe.chmod(exe.stat().st_mode | 0o755)
        link_name = "composer" if exe.name == "composer.phar" else exe.name
        dest = bin_dir / link_name
        if dest.exists() or dest.is_symlink():
            dest.unlink()
        try:
            dest.symlink_to(exe)
        except OSError:
            shutil.copy2(exe, dest)
            if os.name != "nt":
                dest.chmod(dest.stat().st_mode | 0o755)
        linked_bins.append(str(dest))
    return linked_bins


def _load_manifest(manifest_arg: str, cache_dir: Path, timeout: float, extra_trusted_hosts: set[str] | None) -> dict[str, Any]:
    if manifest_arg.startswith(("http://", "https://")):
        validate_source_url(manifest_arg, extra_trusted_hosts=extra_trusted_hosts)
        tmp_manifest = cache_dir / "runtime-manifest.json"
        download_with_retries(manifest_arg, tmp_manifest, retries=2, timeout=timeout, extra_trusted_hosts=extra_trusted_hosts)
        return json.loads(tmp_manifest.read_text(encoding="utf-8"))
    manifest_path = Path(manifest_arg)
    if not manifest_path.is_file():
        raise FileNotFoundError(f"运行时清单文件不存在: {manifest_path}")
    return json.loads(manifest_path.read_text(encoding="utf-8"))


def _select_artifacts(
    manifest: dict[str, Any],
    plat: dict[str, Any],
    component_filter: str | None = None,
) -> list[dict[str, Any]]:
    raw_list = manifest.get("artifacts")
    if raw_list is None and "name" in manifest and "sha256" in manifest:
        raw_list = [manifest]
    if not isinstance(raw_list, list) or not raw_list:
        raise ValueError("运行时清单缺少有效的 artifacts 列表")

    plat_id = plat.get("id", "")
    arch = plat.get("arch", "")
    selected: list[dict[str, Any]] = []
    for item in raw_list:
        if not isinstance(item, dict):
            continue
        item_name = str(item.get("name", "")).strip()
        if component_filter and item_name != component_filter:
            continue
        item_plat = item.get("platform")
        item_arch = item.get("arch")
        if item_plat and item_plat not in {plat_id, "any", "all"}:
            # Windows 宿主准备时允许显式指定 windows-x64-wsl2 或对应 linux-ubuntu
            if not (plat_id == "windows-x64-wsl2" and item_plat in {"windows-x64-wsl2", "any"}):
                continue
        if item_arch and item_arch not in {arch, "any", "all"}:
            continue
        selected.append(item)
    return selected


def prepare_runtimes(
    project_root: Path,
    mode: str = "sqlite",
    from_cache: str | None = None,
    manifest_arg: str | None = None,
    url: str | None = None,
    sha256: str | None = None,
    component: str | None = None,
    retries: int = 3,
    timeout: float = 30.0,
    retry_delay: float = 1.0,
    extra_trusted_hosts: set[str] | None = None,
    platform_override: str | None = None,
    path_override: str | None = None,
    dry_run: bool = False,
    runner: Callable[..., tuple[int, str, str]] | None = None,
) -> tuple[int, dict[str, Any]]:
    total_start = time.monotonic()
    plat = detect_current_platform(platform_override=platform_override)
    state_dir = project_root / ".hw-state"
    cache_dir = state_dir / "runtime-cache"
    runtime_dir = state_dir / "runtime"

    result: dict[str, Any] = {
        "status": "fail",
        "platform": plat,
        "mode": mode,
        "cache_dir": str(cache_dir),
        "runtime_dir": str(runtime_dir),
        "reused": [],
        "prepared": [],
        "missing": [],
        "timings_ms": {"download_ms": 0, "verify_ms": 0, "extract_ms": 0, "total_ms": 0},
        "reboot_required": False,
        "reboot_reason": "",
        "error": "",
    }

    # 1. 平台矩阵门禁：未验收平台明确失败退出非零
    if plat.get("status") != "supported":
        result["error_code"] = "unsupported_platform"
        result["error"] = plat.get("reason", "当前平台不受支持")
        result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
        return 2, result

    # 2. 目录写权限前置检查
    if any(not p.resolve().is_relative_to(project_root.resolve()) for p in (state_dir, cache_dir, runtime_dir)):
        result.update(error_code="unsafe_runtime_path", error="受管运行时目录不得通过符号链接逃逸项目目录")
        return 3, result
    probe = state_dir
    while not probe.exists() and probe != probe.parent:
        probe = probe.parent
    if not os.access(probe, os.W_OK | os.R_OK):
        result["error_code"] = "permission_denied"
        result["error"] = f"缓存与运行时目录无读写权限: {probe}，请检查目录归属与 chmod 权限"
        result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
        return 3, result

    if not dry_run:
        cache_dir.mkdir(parents=True, exist_ok=True)
        runtime_dir.mkdir(parents=True, exist_ok=True)

    # 3. 收集要准备的受管归档任务
    artifacts: list[dict[str, Any]] = []
    if url:
        if not sha256:
            result["error_code"] = "missing_sha256"
            result["error"] = "通过 --url 指定受管运行时归档时必须同时提供 64 位 --sha256 校验值"
            result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
            return 4, result
        parsed = urllib.parse.urlparse(url)
        fname = Path(parsed.path).name or f"{component or 'runtime'}.tar.gz"
        artifacts.append({
            "name": component or "custom",
            "version": "custom",
            "url": url,
            "filename": fname,
            "sha256": sha256,
        })
    elif manifest_arg:
        try:
            manifest_data = _load_manifest(manifest_arg, cache_dir, timeout, extra_trusted_hosts)
            artifacts = _select_artifacts(manifest_data, plat, component_filter=component)
        except Exception as exc:
            result["error_code"] = "invalid_manifest"
            result["error"] = str(exc)
            result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
            return 4, result
    elif from_cache:
        cache_src = Path(from_cache)
        auto_manifest = None
        for cand in ("runtime-manifest.json", "manifest.json"):
            if (cache_src / cand).is_file():
                auto_manifest = cache_src / cand
                break
        if auto_manifest is None:
            result["error_code"] = "missing_manifest"
            result["error"] = f"离线缓存目录 {cache_src} 缺少 runtime-manifest.json（必须包含固定 SHA-256 清单，禁止无校验解包）"
            result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
            return 4, result
        try:
            manifest_data = json.loads(auto_manifest.read_text(encoding="utf-8"))
            artifacts = _select_artifacts(manifest_data, plat, component_filter=component)
        except Exception as exc:
            result["error_code"] = "invalid_manifest"
            result["error"] = str(exc)
            result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
            return 4, result

    # 4. 检测可复用运行时
    status_before = detect_runtimes(
        project_root,
        mode=mode,
        platform_override=platform_override,
        path_override=path_override,
        runner=runner,
        platform_info=plat,
    )
    for rt_name, rt_info in status_before["runtimes"].items():
        if rt_info.get("reusable") and not rt_info.get("not_applicable"):
            result["reused"].append({
                "component": rt_name,
                "version": rt_info.get("version"),
                "path": rt_info.get("path"),
                "source": rt_info.get("source", "system"),
            })

    if status_before.get("reboot_required"):
        result["reboot_required"] = True
        result["reboot_reason"] = status_before.get("reboot_reason", "")

    # 5. 若有指定清单/缓存/URL，则严格校验、下载并部署受管运行时
    download_ms_total = 0
    verify_ms_total = 0
    extract_ms_total = 0

    for item in artifacts:
        comp_name = str(item.get("name") or "runtime").strip()
        try:
            _component_name(comp_name)
            expected_sha = validate_sha256_hex(item.get("sha256"))
        except ValueError as exc:
            result["error_code"] = "invalid_sha256"
            result["error"] = str(exc)
            result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
            return 4, result

        filename = str(item.get("filename") or Path(urllib.parse.urlparse(str(item.get("url", ""))).path).name or f"{comp_name}.tar.gz")
        try:
            cache_file = _confined_runtime_path(Path(from_cache) if from_cache else cache_dir, filename)
            if len(Path(filename).parts) != 1 or cache_file.is_symlink():
                raise ValueError("运行时归档名称必须是普通文件名，不能包含目录或符号链接")
        except ValueError as exc:
            result.update(error_code="invalid_manifest", error=str(exc))
            return 4, result
        attempts = 0
        dl_ms = 0

        if from_cache:
            if not cache_file.is_file():
                result["error_code"] = "cache_miss"
                result["error"] = f"离线缓存中缺少归档文件: {cache_file}"
                result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
                return 4, result
        else:
            item_url = str(item.get("url") or "").strip()
            if not item_url:
                result["error_code"] = "missing_source_url"
                result["error"] = f"组件 {comp_name} 缺少下载地址且未提供 --from-cache"
                result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
                return 4, result
            need_download = True
            if cache_file.is_file():
                if calculate_sha256(cache_file) == expected_sha:
                    need_download = False
                else:
                    cache_file.unlink()
            if need_download and not dry_run:
                try:
                    attempts, dl_ms = download_with_retries(
                        item_url,
                        cache_file,
                        retries=retries,
                        timeout=timeout,
                        retry_delay=retry_delay,
                        extra_trusted_hosts=extra_trusted_hosts,
                    )
                    download_ms_total += dl_ms
                except Exception as exc:
                    result["error_code"] = "download_failed"
                    result["error"] = str(exc)
                    result["timings_ms"]["download_ms"] = download_ms_total
                    result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
                    return 5, result

        if dry_run:
            result["prepared"].append({"component": comp_name, "version": item.get("version", "unknown"), "dry_run": True, "sha256": expected_sha})
            continue

        # 强制 SHA-256 校验（不匹配立即拒绝）
        v_start = time.monotonic()
        actual_sha = calculate_sha256(cache_file)
        verify_ms_total += int((time.monotonic() - v_start) * 1000)
        if actual_sha != expected_sha:
            if not from_cache and cache_file.exists():
                try:
                    cache_file.unlink()
                except OSError:
                    pass
            result["error_code"] = "sha256_mismatch"
            result["error"] = f"运行时包 {filename} SHA-256 校验失败！预期 {expected_sha}，实际 {actual_sha}，已拒绝解包"
            result["timings_ms"]["download_ms"] = download_ms_total
            result["timings_ms"]["verify_ms"] = verify_ms_total
            result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
            return 4, result

        # 安全隔离解包与落盘
        e_start = time.monotonic()
        staging = runtime_dir / f".staging-{comp_name}-{os.getpid()}"
        try:
            _safe_extract_archive(cache_file, staging)
            linked = _install_component_from_staging(
                staging,
                runtime_dir,
                comp_name,
                bin_relpaths=item.get("bin_relpaths"),
            )
        except Exception as exc:
            if staging.exists():
                shutil.rmtree(staging, ignore_errors=True)
            result["error_code"] = "corrupt_archive"
            result["error"] = f"运行时归档 {filename} 解包或安装失败: {exc}"
            result["timings_ms"]["download_ms"] = download_ms_total
            result["timings_ms"]["verify_ms"] = verify_ms_total
            result["timings_ms"]["extract_ms"] = extract_ms_total + int((time.monotonic() - e_start) * 1000)
            result["timings_ms"]["total_ms"] = int((time.monotonic() - total_start) * 1000)
            return 6, result
        finally:
            if staging.exists():
                shutil.rmtree(staging, ignore_errors=True)
        extract_ms_total += int((time.monotonic() - e_start) * 1000)

        # 如果是从外部 --from-cache 校验通过安装的，也保留哈希记录于 .hw-state/runtime/installed.json
        manifest_state_file = runtime_dir / "installed.json"
        installed_state: dict[str, Any] = {}
        if manifest_state_file.is_file():
            try:
                installed_state = json.loads(manifest_state_file.read_text(encoding="utf-8"))
            except Exception:
                installed_state = {}
        installed_state[comp_name] = {
            "version": item.get("version", "unknown"),
            "sha256": actual_sha,
            "filename": filename,
            "bins": linked,
            "installed_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        }
        manifest_state_file.write_text(json.dumps(installed_state, ensure_ascii=False, indent=2), encoding="utf-8")

        result["prepared"].append({
            "component": comp_name,
            "version": item.get("version", "unknown"),
            "sha256": actual_sha,
            "attempts": attempts,
            "bins": linked,
        })

    # 6. 二次复查最终运行时就绪状态
    if dry_run:
        result["status"] = "pass"
        result["timings_ms"].update({"download_ms": download_ms_total, "verify_ms": verify_ms_total, "extract_ms": extract_ms_total, "total_ms": int((time.monotonic() - total_start) * 1000)})
        return 0, result

    # 若之前传入了 path_override=""（冷机模拟），准备后要把新建的 .hw-state/runtime/bin 纳入复检 PATH
    final_path_override = path_override
    if path_override is not None and (runtime_dir / "bin").is_dir():
        final_path_override = f"{runtime_dir / 'bin'}{os.pathsep}{path_override}" if path_override else str(runtime_dir / "bin")

    status_after = detect_runtimes(
        project_root,
        mode=mode,
        platform_override=platform_override,
        path_override=final_path_override,
        runner=runner,
        platform_info=plat,
    )
    result["missing"] = status_after["missing"]
    result["runtimes"] = status_after["runtimes"]
    result["timings_ms"] = {
        "download_ms": download_ms_total,
        "verify_ms": verify_ms_total,
        "extract_ms": extract_ms_total,
        "total_ms": int((time.monotonic() - total_start) * 1000),
    }

    req_missing = [m for m in status_after["missing"] if m.get("required")]
    if req_missing:
        result["status"] = "fail"
        result["error_code"] = "missing_required_runtimes"
        result["error"] = "仍有必需运行时未就绪: " + "; ".join(f"{m['component']}({m['reason']})" for m in req_missing)
        return 7, result

    result["status"] = "pass"
    return 0, result


def _print_matrix(matrix: dict[str, Any]) -> None:
    cur = matrix["current_platform"]
    print("========== [HwAdmin 平台支持矩阵] ==========")
    print(f"当前平台: {cur['id']} ({cur['os']}/{cur['arch']}) -> [{cur['status'].upper()}] {cur['reason']}")
    print("\n已验收支持平台 (supported):")
    for item in matrix["supported"]:
        print(f"  - {item['id']} ({', '.join(item['arch'])}) : {item['notes']}")
    print("\n明确不受支持平台 (unsupported):")
    for item in matrix["unsupported"]:
        print(f"  - {item['pattern']} : {item['reason']}")


def _print_status(data: dict[str, Any]) -> None:
    plat = data["platform"]
    print("========== [HwAdmin 运行时自检状态] ==========")
    print(f"当前平台: {plat['id']} ({plat['status']}) | 模式: {data['mode']}")
    for name, info in data["runtimes"].items():
        if info.get("not_applicable"):
            continue
        mark = "[✔]" if info.get("reusable") else "[✘]"
        ver = info.get("version") or "未安装"
        src = f" [{info['source']}]" if info.get("source") and info["source"] != "missing" else ""
        print(f"  {mark} {name}: {ver}{src} {('-> ' + info['remediation']) if info.get('remediation') else ''}")
    if data.get("reboot_required"):
        print(f"  [!] 重启/提权提示: {data['reboot_reason']}")
    print("--------------------------------------------")
    print("状态:", "✔ 就绪" if data["ready"] else "✘ 存在缺失项")


def _print_prepare(data: dict[str, Any]) -> None:
    print("========== [HwAdmin 运行时准备结果] ==========")
    print(f"状态: {data['status']} | 平台: {data['platform']['id']} | 耗时: {data['timings_ms']['total_ms']}ms")
    if data.get("reused"):
        print("已复用运行时:", ", ".join(f"{x['component']}({x['version']})" for x in data["reused"]))
    if data.get("prepared"):
        print("已准备受管运行时:", ", ".join(f"{x['component']}({x['version']})" for x in data["prepared"]))
    if data.get("reboot_required"):
        print(f"[!] 重启提示: {data['reboot_reason']}")
    if data.get("error"):
        print(f"[✘] 错误: {data['error']}", file=sys.stderr)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="hw runtime", description="HwAdmin 跨平台运行时支持矩阵、自检与受管准备器")
    parser.add_argument("--project-root", default=None, help="项目根目录（默认自动探测）")
    sub = parser.add_subparsers(dest="subcmd", required=True)

    p_matrix = sub.add_parser("matrix", help="输出平台支持矩阵")
    p_matrix.add_argument("--json", action="store_true")
    p_matrix.add_argument("--platform", default=None)

    p_status = sub.add_parser("status", help="检测可复用运行时与缺项清单")
    p_status.add_argument("--json", action="store_true")
    p_status.add_argument("--mode", default="sqlite", choices=["sqlite", "mysql", "pgsql", "docker"])
    p_status.add_argument("--platform", default=None)

    p_prep = sub.add_parser("prepare", help="校验并准备受管运行时归档")
    p_prep.add_argument("--json", action="store_true")
    p_prep.add_argument("--mode", default="sqlite", choices=["sqlite", "mysql", "pgsql", "docker"])
    p_prep.add_argument("--from-cache", default=None, help="离线运行时缓存目录（含 runtime-manifest.json）")
    p_prep.add_argument("--manifest", default=None, help="受管运行时清单文件路径或 HTTPS URL")
    p_prep.add_argument("--url", default=None, help="单个受管运行时归档 URL")
    p_prep.add_argument("--sha256", default=None, help="与 --url 配套的 64 位 SHA-256 预期签名")
    p_prep.add_argument("--component", default=None, help="仅准备指定组件名称")
    p_prep.add_argument("--retries", type=int, default=3, help="网络失败最大重试次数")
    p_prep.add_argument("--timeout", type=float, default=30.0, help="单次下载超时秒数")
    p_prep.add_argument("--retry-delay", type=float, default=1.0, help="重试基础退避秒数")
    p_prep.add_argument("--trusted-host", action="append", default=[], help="追加允许的受信任镜像主机名")
    p_prep.add_argument("--platform", default=None, help="覆盖平台识别（诊断/测试用）")
    p_prep.add_argument("--dry-run", action="store_true")

    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    project_root = resolve_project_root(args.project_root)

    if args.subcmd == "matrix":
        data = get_platform_support_matrix(platform_override=args.platform)
        if args.json:
            print(json.dumps(data, ensure_ascii=False, indent=2))
        else:
            _print_matrix(data)
        return 0

    if args.subcmd == "status":
        data = detect_runtimes(project_root, mode=args.mode, platform_override=args.platform)
        if args.json:
            print(json.dumps(data, ensure_ascii=False, indent=2))
        else:
            _print_status(data)
        return 0 if data["ready"] else 1

    if args.subcmd == "prepare":
        code, data = prepare_runtimes(
            project_root=project_root,
            mode=args.mode,
            from_cache=args.from_cache,
            manifest_arg=args.manifest,
            url=args.url,
            sha256=args.sha256,
            component=args.component,
            retries=args.retries,
            timeout=args.timeout,
            retry_delay=args.retry_delay,
            extra_trusted_hosts=set(args.trusted_host or []),
            platform_override=args.platform,
            dry_run=args.dry_run,
        )
        if args.json:
            print(json.dumps(data, ensure_ascii=False, indent=2))
        else:
            _print_prepare(data)
        return code

    return 1


if __name__ == "__main__":
    sys.exit(main())
