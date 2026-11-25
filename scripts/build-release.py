#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
HwAdmin 自动化制品打包脚本
默认生成单一完整统一发行包：
- 统一发行包 (unified，默认)：hwadmin-vX.Y.Z.zip（完整源码仓 + 预构建 server/public/admin/ + 生产 vendor/，兼顾 AI Agent 二开与宝塔/Docker 生产直跑）
- 兼容历史模式 (--mode=deploy|dev|all)：仅保留兼容旧调用脚本
包内均严格写入 artifact-manifest.json 契约文件
"""

import os
import sys
import hashlib
import zipfile
import argparse
import json
import re
import tempfile
from datetime import datetime, timezone
from pathlib import Path

# 确保控制台支持 UTF-8 输出
if sys.stdout.encoding and sys.stdout.encoding.lower() != 'utf-8':
    try:
        sys.stdout.reconfigure(encoding='utf-8')
    except Exception:
        pass


DEFAULT_TOOLCHAINS = {
    "php": ">=8.2.0",
    "composer": ">=2.0.0",
    "node": ">=22.18.0",
    "pnpm": ">=10.0.0"
}

# 统一发行包与 GitHub 仓库 docs/ 严格对齐精简白名单：仅包含用户端与 AI Agent 开发/部署所需的 11 份平铺文档，
# 彻底隔离作者私人研发记录、内部维护规范（private-docs/）与冗余目录（maintainers/、schemas/）。
DEV_PACKAGE_ALLOWED_DOCS = frozenset({
    "docs/系统架构设计.md",
    "docs/部署与运维指南.md",
    "docs/单机生产运维与灾难恢复手册.md",
    "docs/移动端接入指南.md",
    "docs/桌面端接入指南.md",
    "docs/后端开发规范.md",
    "docs/前端开发规范.md",
    "docs/数据库开发规范.md",
    "docs/接口契约规范.md",
    "docs/插件系统规范.md",
    "docs/CHANGELOG.md",
})


def calculate_sha256(file_path: Path) -> str:
    """计算文件的 SHA-256 哈希值"""
    sha256 = hashlib.sha256()
    with open(file_path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            sha256.update(chunk)
    return sha256.hexdigest()


def get_git_commit(project_root: Path) -> str:
    """获取当前 git commit SHA，若失败返回 unknown"""
    try:
        import subprocess
        p = subprocess.run(["git", "rev-parse", "HEAD"], cwd=project_root, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if p.returncode == 0 and p.stdout.strip():
            return p.stdout.strip()
    except Exception:
        pass
    return "unknown"


def build_official_ui_manifest_bytes(version: str, index_html_path: Path) -> bytes:
    """生成官方纯净前端指纹清单 hwadmin-official-ui.json 字节内容。"""
    payload = {
        "official": True,
        "version": version.removeprefix("v"),
        "index_html_sha256": calculate_sha256(index_html_path),
        "built_at": datetime.now(timezone.utc).isoformat(),
    }
    return (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def detect_default_version(project_root: Path) -> str:
    """从 server/config/hwadmin.php 读取默认框架版本号。"""
    config_file = project_root / "server/config/hwadmin.php"
    if config_file.is_file():
        m = re.search(r"'version'\s*=>\s*'(\d+\.\d+\.\d+)'", config_file.read_text(encoding="utf-8"))
        if m:
            return "v" + m.group(1)
    return "v1.6.6"


def render_deploy_docs(version: str) -> dict[str, str]:
    """生成生产部署精简包（artifact_type=deploy）专属文档，消除开发态死链。"""
    ver_num = version.removeprefix("v")
    readme = f"""# HwAdmin 生产部署包 ({version})

> 本压缩包为 **HwAdmin 生产部署精简包 (`artifact_type: deploy`)**，包根目录即为后端运行目录，内置已编译管理端 `public/admin/` 与生产依赖 `vendor/`（含前端源码备份 `sources/admin-ui/`，仅保留运行与部署所需文件）。如需由 AI 智能体从零二开业务应用，请下载完整开发包 `hwadmin-dev-{version}.zip`。

## 快速部署步骤

1. **配置环境变量**：复制 `.example.env` 为 `.env`（生产推荐 `DB_DRIVER=mysql` 或 `pgsql`，设置 `APP_DEBUG = false`）。若需先用 SQLite 单机预览，请将 `DB_NAME` 设为 `runtime/hwadmin.sqlite`。
2. **Nginx / 站点目录**：站点根目录（Document Root）指向当前目录下的 `public/`，配置 ThinkPHP 伪静态并禁止外网访问 `.env`、`*.sqlite`、`config/install.lock`、`runtime/`。
3. **初始化数据库与管理员**：执行 `php think hw:install`（或访问 `/install/` 完成 Web 安装向导）。如已携带业务 Phinx 迁移，安装器会保留迁移注册的业务菜单。
4. **交付上线体检**：部署完成或调整配置后，在当前目录执行：
   ```bash
   php think hw:doctor --mode=prod --json
   ```
5. **备份与恢复**：
   ```bash
   python3 scripts/backup_restore.py backup
   ```

## 包内主要目录与资料

- `public/`：Web 入口与已构建管理后台（`public/admin/`）
- `app/`、`core/`、`config/`、`database/migrations/`、`plugins/`：后端源码与迁移脚本
- `sources/admin-ui/`：管理端与移动端源码备份（修改前端时进入该目录执行 `pnpm build:sync` 并重新上线）
- `scripts/hw-deploy.py`、`scripts/backup_restore.py`：部署与原子备份恢复脚本
- [部署与运维指南](docs/部署与运维指南.md) · [变更日志](docs/CHANGELOG.md)
"""
    agents = f"""# HwAdmin 生产部署运维契约 ({version})

> 当前工作目录为 **HwAdmin 生产部署精简包 (`artifact_type: deploy`)** 根目录（对应源码仓服务端根目录）。请严格按服务器部署与安全上线流程操作，勿按开发仓目录结构查找本地开发脚本。

## 目录结构

- `app/`：ThinkPHP 8.1 业务应用（`adminapi/` | `api/` | `index/` | `common/` | `install/`）
- `config/`：框架与数据库配置（已默认开启 `'app_express' => true`）
- `database/migrations/`：Phinx 数据库迁移脚本
- `public/`：Web 根目录（Site Document Root），内置 `public/admin/` 构建产物
- `runtime/`：日志与缓存目录（若临时使用 SQLite，数据库统一放 `runtime/hwadmin.sqlite`）
- `sources/admin-ui/`：前端源码备份（按需重构建时同步至 `public/admin/`）
- `scripts/`：`hw-deploy.py`、`backup_restore.py`

## 运维与检查常用命令

```bash
# 1. 执行数据库迁移（需在切换生产 MySQL/PG 的 .env 就绪后运行）
php think migrate:run

# 2. 生产交付态一键体检（检查 APP_DEBUG、SQLite 误用、install.lock、目录权限、未同步前端）
php think hw:doctor --mode=prod --json

# 3. 项目接口与表结构自省
php think hw:inspect --json

# 4. 备份当前运行数据与配置
python3 scripts/backup_restore.py backup
```

参考文档：[部署与运维指南](docs/部署与运维指南.md)
"""
    claude = f"""# HwAdmin 部署环境快速说明 ({version})

当前目录为 HwAdmin {ver_num} 生产部署包根目录（`artifact_type: deploy`）。

- 当前包定位为生产部署与运维运行态；如需端到端本地敏捷二开体验，请使用完整开发包 `hwadmin-dev-{version}.zip`。
- 上线前配置 `.env`（生产使用 MySQL/PostgreSQL 且 `APP_DEBUG = false`）、网站根目录指向 `public/`、执行 `php think migrate:run` / `php think hw:install`。
- 交付前务必运行 `php think hw:doctor --mode=prod --json` 并确认所有必需检查通过；详细步骤见 [部署与运维指南](docs/部署与运维指南.md) 与 [AGENTS.md](AGENTS.md)。
"""
    return {
        "README.md": readme,
        "AGENTS.md": agents,
        "CLAUDE.md": claude,
    }


def should_exclude_deploy(rel_path: str, filename: str) -> bool:
    """生产部署包排除规则"""
    if rel_path.startswith('public/storage/'):
        return True
    parts = rel_path.split('/')
    if any(part in {'.git', '.svn', 'node_modules', '__pycache__'} for part in parts):
        return True
    if rel_path.startswith(('plugins/smoke/', 'plugins/badrule/', 'plugins/badsql/')):
        return True
    if filename.startswith(('install.lock', 'install.token')):
        return True
    if filename.endswith(('.pem', '.key', '.zip', '.map')):
        return True
    if rel_path in {'worker.php', 'php.ini', '.gitignore'}:
        return True
    if filename.startswith(".env") and not filename.endswith(".example"):
        return True
    if rel_path in {"config/install.lock", "config/install.token"} or filename in {"install.lock", "install.token"}:
        return True
    if rel_path == "tests" or rel_path.startswith("tests/"):
        return True
    if filename in {".DS_Store", "Thumbs.db", "desktop.ini"}:
        return True
    if filename.endswith((".bak", ".smokebak", ".swp", ".swo", "~")):
        return True
    if filename.endswith(".pyc") or "__pycache__" in rel_path:
        return True
    if re.search(r'\.(sqlite3?|db)(?:-(?:wal|shm|journal))?$', filename, re.IGNORECASE):
        return True
    if filename.endswith(".log"):
        return True
    if rel_path.startswith("runtime/"):
        if filename not in {".gitkeep", ".gitignore"}:
            return True
    if rel_path.startswith("public/uploads/"):
        if filename not in {".gitkeep", ".gitignore", ".htaccess"}:
            return True
    if rel_path.startswith("public/plugins/"):
        if filename not in {".gitkeep", ".gitignore"}:
            return True
    return False


def find_forbidden_authorization(files_to_pack):
    """发现已确认会向第三方发送调试数据或注入授权限制的运行时代码。"""
    forbidden = []
    markers = (b"vite-plugin-vitebar", b"vite-plugin-unplugin")
    for file_path, rel_path, _ in files_to_pack:
        if rel_path.startswith("vendor/topthink/think-dumper/"):
            forbidden.append(rel_path)
        elif rel_path.startswith("public/admin/") and rel_path.endswith(".js"):
            content = file_path.read_bytes()
            if any(marker in content for marker in markers):
                forbidden.append(rel_path)
    return forbidden


def build_deploy_package(version: str, project_root: Path, server_dir: Path, release_dir: Path) -> int:
    """构建精简部署包 hwadmin-release-vX.Y.Z.zip"""
    zip_filename = f"hwadmin-release-{version}.zip"
    zip_path = release_dir / zip_filename
    sha256_path = release_dir / f"{zip_filename}.sha256"

    print("\n==================================================")
    print(f"📦 正在构建 HwAdmin 生产部署包 (deploy): {version}")
    print(f"📁 源码基准: {server_dir}")
    print(f"🎯 产物路径: {zip_path}")
    print("==================================================")

    installed_file = server_dir / 'vendor/composer/installed.json'
    if not installed_file.is_file():
        print('❌ 缺少生产 Composer 依赖，请先在隔离副本执行 composer install --no-dev --optimize-autoloader')
        return 1
    installed = json.loads(installed_file.read_text(encoding='utf-8'))
    if isinstance(installed, list) or installed.get('dev', True):
        print('❌ 发布依赖包含开发模式，请在隔离副本执行 composer install --no-dev --optimize-autoloader')
        return 1
    if any(p['name'] in {'topthink/think-trace', 'topthink/think-dumper'} for p in installed['packages']):
        print('❌ 发布依赖含调试组件')
        return 1

    # 前端校验
    frontend_root = project_root / 'admin-ui' if (project_root / 'admin-ui/package.json').is_file() else project_root / 'sources/admin-ui'
    frontend_dist = frontend_root / 'dist'
    if not (frontend_dist / 'index.html').is_file():
        print(f'❌ 缺少前端构建产物，请先在 {frontend_root.relative_to(project_root).as_posix()} 执行 pnpm build')
        return 1

    for keep_rel in ["runtime/.gitkeep", "public/uploads/.gitkeep", "public/plugins/.gitkeep"]:
        keep_file = server_dir / keep_rel
        keep_file.parent.mkdir(parents=True, exist_ok=True)
        if not keep_file.exists():
            keep_file.touch()

    files_to_pack = []
    total_uncompressed_bytes = 0

    for root, dirs, files in os.walk(server_dir):
        if ".git" in dirs:
            dirs.remove(".git")
        for file in files:
            file_full_path = Path(root) / file
            rel_path = file_full_path.relative_to(server_dir).as_posix()
            if should_exclude_deploy(rel_path, file):
                continue
            if file_full_path.is_symlink():
                raise ValueError(f'发布文件不得是符号链接: {rel_path}')
            file_size = file_full_path.stat().st_size
            files_to_pack.append((file_full_path, rel_path, file_size))
            total_uncompressed_bytes += file_size

    # 附带通用文档与脚本（去重覆盖）
    packed_map = {rel_path: (file_full_path, rel_path, file_size) for file_full_path, rel_path, file_size in files_to_pack}
    for relative in ['scripts/backup-restore.sh', 'scripts/backup_restore.py',
                     'scripts/hw-deploy.py', 'scripts/verification_evidence.py',
                     'docs/CHANGELOG.md', 'docs/部署与运维指南.md',
                     'docs/桌面端接入指南.md', 'docs/移动端接入指南.md']:
        path = project_root / relative
        if path.is_file():
            packed_map[relative] = (path, relative, path.stat().st_size)
    project_license = project_root / 'LICENSE.txt'
    if project_license.is_file():
        packed_map['LICENSE-project.txt'] = (project_license, 'LICENSE-project.txt', project_license.stat().st_size)

    deploy_docs = render_deploy_docs(version)
    doc_tmp_ctx = tempfile.TemporaryDirectory(prefix="hwadmin-deploy-docs-")
    try:
        doc_tmp_dir = Path(doc_tmp_ctx.name)
        for doc_name, doc_content in deploy_docs.items():
            for forbidden_ref in ("START_HERE.md", "wsl-dev.sh", "docs/conventions/", "docs/adr/", "server/AGENTS.md", "admin-ui/AGENTS.md", "./hw"):
                if forbidden_ref in doc_content:
                    raise ValueError(f"部署包专用文档 {doc_name} 不得包含开发包死链引用: {forbidden_ref}")
            doc_path = doc_tmp_dir / doc_name
            doc_path.write_text(doc_content, encoding="utf-8")
            packed_map[doc_name] = (doc_path, doc_name, doc_path.stat().st_size)

        admin_index_html = server_dir / "public/admin/index.html"
        if admin_index_html.is_file():
            official_ui_path = doc_tmp_dir / "hwadmin-official-ui.json"
            official_ui_path.write_bytes(build_official_ui_manifest_bytes(version, admin_index_html))
            packed_map["public/admin/hwadmin-official-ui.json"] = (
                official_ui_path,
                "public/admin/hwadmin-official-ui.json",
                official_ui_path.stat().st_size,
            )

        files_to_pack = list(packed_map.values())

        # 包含前端源码备份 sources/admin-ui
        if frontend_root.exists():
            for directory, dirs, files in os.walk(frontend_root):
                dirs[:] = [d for d in dirs if d not in {'node_modules', 'dist', '.git', '.vite',
                                                       'docs', 'docs-local', '.cache', 'unpackage',
                                                       'dist-desktop', 'mock'}]
                if Path(directory) == frontend_root / 'src-tauri':
                    dirs[:] = [d for d in dirs if d not in {'target', 'gen'}]
                for name in sorted(files):
                    path = Path(directory) / name
                    relative = path.relative_to(frontend_root).as_posix()
                    if (name.startswith('.env') and not name.endswith('.example')) or name.endswith(('.log', '.tsbuildinfo', '.map')) or path.is_symlink():
                        continue
                    if (name in {'auto-imports.d.ts', 'components.d.ts'} and relative.startswith('types/')) or relative in {
                        'mobile/src/manifest.json',
                        'mobile/src/pages.json',
                        'mobile/src/types/auto-import.d.ts',
                        'mobile/src/types/uni-layouts.d.ts',
                        'mobile/src/types/uni-pages.d.ts',
                    }:
                        continue
                    files_to_pack.append((path, 'sources/admin-ui/' + relative, path.stat().st_size))

        forbidden = find_forbidden_authorization(files_to_pack)
        if forbidden:
            print("❌ 发布包包含已禁止的第三方授权或远程调试代码：")
            for rel_path in forbidden:
                print(f"   - {rel_path}")
            return 1

        manifest_map = {relative: calculate_sha256(path) for path, relative, _ in files_to_pack}
        git_commit = get_git_commit(project_root)

        required_files = [
            "public/index.php",
            "public/admin/index.html",
            "vendor/autoload.php",
            "app/install/controller/Index.php",
            "app/adminapi/controller/Index.php",
            "core/base/AdminController.php",
            "runtime/.gitkeep",
            "public/uploads/.gitkeep",
            "think",
            "composer.json",
            ".example.env",
            "README.md",
            "AGENTS.md",
            "CLAUDE.md"
        ]
        forbidden_rules = [
            ".env",
            "config/install.lock",
            "*.sqlite",
            "*.log",
            "tests/*"
        ]

        # Keep the legacy metadata for older offline tools, but register its bytes in
        # the authoritative manifest just like every other archive payload.
        legacy_manifest = json.dumps({'version': version[1:], 'files': dict(manifest_map)}, ensure_ascii=False, indent=2)
        manifest_map['manifest.json'] = hashlib.sha256(legacy_manifest.encode('utf-8')).hexdigest()
        artifact_manifest = {
            "artifact_type": "deploy",
            "framework_version": version[1:],
            "layout_version": "1",
            "git_commit": git_commit,
            "created_at": datetime.now(timezone.utc).isoformat(),
            "toolchains": DEFAULT_TOOLCHAINS,
            "required_files": required_files,
            "forbidden_patterns": forbidden_rules,
            "files": manifest_map
        }

        if zip_path.exists():
            zip_path.unlink()

        with zipfile.ZipFile(zip_path, mode="w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
            for file_full_path, rel_path, _ in files_to_pack:
                zf.write(file_full_path, arcname=rel_path)
            zf.writestr('artifact-manifest.json', json.dumps(artifact_manifest, ensure_ascii=False, indent=2))
            zf.writestr('manifest.json', legacy_manifest)
    finally:
        doc_tmp_ctx.cleanup()

    # 校验
    with zipfile.ZipFile(zip_path, mode="r") as zf:
        if zf.testzip():
            print(f"❌ 严重错误: 压缩包 CRC 校验失败: {zip_path}")
            return 1
        all_archived = set(zf.namelist())

    missing = [f for f in required_files if f not in all_archived]
    if missing:
        print(f"❌ 部署包缺失核心文件: {missing}")
        return 1

    for item in all_archived:
        if item == ".env" or item == "config/install.lock" or item.endswith(".sqlite") or item.endswith(".log") or item.startswith("tests/"):
            print(f"❌ 部署包泄漏禁止文件: {item}")
            return 1

    sha256_hash = calculate_sha256(zip_path)
    sha256_path.write_text(f"{sha256_hash} *{zip_filename}\n", encoding="utf-8")
    print(f"✔ 生产部署包构建完成: {zip_filename} ({zip_path.stat().st_size / (1024*1024):.2f} MB, SHA: {sha256_hash[:12]}...)")
    return 0


def build_dev_package(
    version: str,
    project_root: Path,
    release_dir: Path,
    zip_filename: str = None,
    artifact_type: str = "dev",
    server_dir_override: Path = None,
) -> int:
    """构建完整仓库发行包（支持 unified 统一发行包与兼容 dev 包）"""
    zip_filename = zip_filename or f"hwadmin-dev-{version}.zip"
    zip_path = release_dir / zip_filename
    sha256_path = release_dir / f"{zip_filename}.sha256"
    mode_label = "统一完整发行包 (unified)" if artifact_type == "unified" else "Agent 开发包 (dev)"

    print("\n==================================================")
    print(f"📦 正在构建 HwAdmin {mode_label}: {version}")
    print(f"📁 源码基准: {project_root}")
    print(f"🎯 产物路径: {zip_path}")
    print("==================================================")

    files_to_pack = []
    total_uncompressed_bytes = 0

    # 根目录关键入口与配置文件
    root_files = [
        "hw", "hw.ps1", "hw.cmd",
        "README.md", "START_HERE.md", "AGENTS.md", "CLAUDE.md", "LICENSE.txt",
        "hw.project.json", ".gitignore",
        "docker-compose.yml", "docker-compose.dev.yml", ".example.env", ".env.docker.example"
    ]
    for rf in root_files:
        p = project_root / rf
        if p.is_file():
            files_to_pack.append((p, rf, p.stat().st_size))

    # 0. docker/ 容器构建与配置文件目录
    docker_dir = project_root / "docker"
    if docker_dir.exists():
        for root, dirs, files in os.walk(docker_dir):
            dirs[:] = [d for d in dirs if d not in {"__pycache__", ".git"}]
            for file in files:
                full_p = Path(root) / file
                rel = full_p.relative_to(project_root).as_posix()
                if full_p.is_symlink() or file.endswith((".log", ".swp", ".bak")):
                    continue
                files_to_pack.append((full_p, rel, full_p.stat().st_size))

    # 1. server/ 目录
    server_dir = server_dir_override if (server_dir_override and server_dir_override.exists()) else (project_root / "server")
    if server_dir.exists():
        for root, dirs, files in os.walk(server_dir):
            if ".git" in dirs:
                dirs.remove(".git")
            dirs[:] = [d for d in dirs if d not in {"__pycache__"}]
            for file in files:
                full_p = Path(root) / file
                rel = "server/" + full_p.relative_to(server_dir).as_posix()
                if full_p.is_symlink():
                    continue
                # 排除规则：.env(非example)、install.lock、dev.sqlite、*.log、runtime内除.gitkeep外内容
                if file.startswith(".env") and not file.endswith(".example"):
                    continue
                if rel in {"server/config/install.lock", "server/config/install.token"} or file in {"install.lock", "install.token"}:
                    continue
                if re.search(r'\.(sqlite3?|db)(?:-(?:wal|shm|journal))?$', file, re.IGNORECASE):
                    continue
                if file.endswith((".log", ".bak", ".swp")):
                    continue
                if rel.startswith("server/runtime/") and file not in {".gitkeep", ".gitignore"}:
                    continue
                if rel.startswith("server/public/uploads/") and file not in {".gitkeep", ".gitignore", ".htaccess"}:
                    continue
                if rel.startswith('server/public/storage/'):
                    continue
                if rel.startswith("server/public/plugins/") and file not in {".gitkeep", ".gitignore"}:
                    continue
                if rel == "server/public/admin/hwadmin-official-ui.json":
                    continue
                files_to_pack.append((full_p, rel, full_p.stat().st_size))

    # 2. admin-ui/ 目录
    admin_ui_dir = project_root / "admin-ui"
    if admin_ui_dir.exists():
        for root, dirs, files in os.walk(admin_ui_dir):
            dirs[:] = [d for d in dirs if d not in {
                'node_modules', 'dist', '.git', '.vite', '.cache',
                'unpackage', 'dist-desktop', 'mock', 'target', 'gen'
            }]
            for file in files:
                full_p = Path(root) / file
                rel = full_p.relative_to(project_root).as_posix()
                if full_p.is_symlink():
                    continue
                if file.startswith(".env") and not file.endswith(".example") and rel not in {
                    "admin-ui/.env.development",
                    "admin-ui/.env.production",
                    "admin-ui/.env.test",
                }:
                    continue
                if file.endswith((".log", ".tsbuildinfo", ".map", ".swp")):
                    continue
                if rel in {
                    "admin-ui/mobile/src/manifest.json",
                    "admin-ui/mobile/src/pages.json",
                    "admin-ui/mobile/src/types/auto-import.d.ts",
                    "admin-ui/mobile/src/types/uni-layouts.d.ts",
                    "admin-ui/mobile/src/types/uni-pages.d.ts",
                }:
                    continue
                files_to_pack.append((full_p, rel, full_p.stat().st_size))

    # 3. scripts/ 目录（全量纳入白名单脚本与 scripts/tests/* 自动化测试套件）
    scripts_dir = project_root / "scripts"
    packed_rels = {rel for _, rel, _ in files_to_pack}
    dev_script_whitelist = [
        "scripts/wsl-dev.sh",
        "scripts/start-project.py",
        "scripts/hw-deploy.py",
        "scripts/hw-runtime.py",
        "scripts/backup_restore.py",
        "scripts/backup-restore.sh",
        "scripts/verification_evidence.py",
        "scripts/verify-a18-zip.sh",
        "scripts/verify-t06-projects.sh",
    ]
    if scripts_dir.exists():
        for root, dirs, files in os.walk(scripts_dir):
            dirs[:] = [d for d in dirs if d not in {"__pycache__"}]
            for file in sorted(files):
                full_p = Path(root) / file
                rel = full_p.relative_to(project_root).as_posix()
                if full_p.is_symlink() or file.endswith((".pyc", ".log", ".swp")):
                    continue
                if rel not in packed_rels:
                    files_to_pack.append((full_p, rel, full_p.stat().st_size))
                    packed_rels.add(rel)
    for rel in dev_script_whitelist:
        p = project_root / rel
        if p.is_file() and not p.is_symlink() and rel not in packed_rels:
            files_to_pack.append((p, rel, p.stat().st_size))
            packed_rels.add(rel)

    # 4. docs/ 目录（仅纳入 DEV_PACKAGE_ALLOWED_DOCS 开发、部署与合规文档）
    for rel in sorted(DEV_PACKAGE_ALLOWED_DOCS):
        full_p = project_root / rel
        if full_p.is_file() and not full_p.is_symlink():
            files_to_pack.append((full_p, rel, full_p.stat().st_size))

    # 5. templates/ 业务起步模板目录
    templates_dir = project_root / "templates"
    if templates_dir.exists():
        for root, dirs, files in os.walk(templates_dir):
            dirs[:] = [d for d in dirs if d not in {"__pycache__"}]
            for file in files:
                full_p = Path(root) / file
                rel = full_p.relative_to(project_root).as_posix()
                if file.endswith((".pyc", ".log", ".swp")):
                    continue
                files_to_pack.append((full_p, rel, full_p.stat().st_size))

    manifest_map = {relative: calculate_sha256(path) for path, relative, _ in files_to_pack}
    official_ui_bytes = None
    admin_index_html = server_dir / "public/admin/index.html"
    if admin_index_html.is_file():
        official_ui_bytes = build_official_ui_manifest_bytes(version, admin_index_html)
        manifest_map["server/public/admin/hwadmin-official-ui.json"] = hashlib.sha256(official_ui_bytes).hexdigest()
    git_commit = get_git_commit(project_root)

    required_files = [
        "hw",
        "hw.ps1",
        "hw.cmd",
        "README.md",
        "AGENTS.md",
        "CLAUDE.md",
        "hw.project.json",
        ".gitignore",
        "docker-compose.yml",
        "docker-compose.dev.yml",
        ".env.docker.example",
        "docker/php/Dockerfile",
        "docker/nginx/default.conf",
        "server/think",
        "server/composer.json",
        "server/core/base/AdminController.php",
        "server/tests/smoke.sh",
        "server/AGENTS.md",
        "admin-ui/package.json",
        "admin-ui/AGENTS.md",
        "admin-ui/mobile/AGENTS.md",
        "scripts/wsl-dev.sh",
        "scripts/hw-deploy.py",
        "scripts/backup_restore.py",
        "scripts/backup-restore.sh",
        "scripts/verification_evidence.py",
        "scripts/verify-a18-zip.sh",
        "scripts/tests/test_backup_restore.py",
        "scripts/tests/test_deploy_integrity.py",
        "scripts/tests/test_release_authorization_guard.py",
        "scripts/tests/test_release_sqlite_artifacts.py",
        "scripts/tests/test_t03_t04_prod_deploy_recovery.py",
        "server/core/support/hw.project.schema.json",
        "START_HERE.md",
        "scripts/start-project.py"
    ]
    # 若协同分支已落地新增运维/验收脚本及测试，纳入开发包必需白名单校验
    for optional_script in (
        "scripts/hw-runtime.py",
        "scripts/verify-t06-projects.sh",
        "scripts/tests/test_t01_t02_runtime_docker.py",
    ):
        if (project_root / optional_script).is_file() and optional_script not in required_files:
            required_files.append(optional_script)
    tests_dir = project_root / "scripts/tests"
    if tests_dir.is_dir():
        for test_file in sorted(tests_dir.glob("test_*.py")):
            rel_test = test_file.relative_to(project_root).as_posix()
            if rel_test not in required_files:
                required_files.append(rel_test)
    forbidden_rules = [
        "server/.env",
        "server/config/install.lock",
        "*.sqlite",
        "*.log",
        "*/node_modules/*"
    ]

    artifact_manifest = {
        "artifact_type": artifact_type,
        "framework_version": version[1:],
        "layout_version": "1",
        "git_commit": git_commit,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "toolchains": DEFAULT_TOOLCHAINS,
        "required_files": required_files,
        "forbidden_patterns": forbidden_rules,
        "files": manifest_map
    }

    if zip_path.exists():
        zip_path.unlink()

    with zipfile.ZipFile(zip_path, mode="w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for file_full_path, rel_path, _ in files_to_pack:
            zf.write(file_full_path, arcname=rel_path)
        if official_ui_bytes is not None:
            zf.writestr("server/public/admin/hwadmin-official-ui.json", official_ui_bytes)
        zf.writestr('artifact-manifest.json', json.dumps(artifact_manifest, ensure_ascii=False, indent=2))

    with zipfile.ZipFile(zip_path, mode="r") as zf:
        if zf.testzip():
            print(f"❌ 严重错误: 压缩包 CRC 校验失败: {zip_path}")
            return 1
        all_archived = set(zf.namelist())

    missing = [f for f in required_files if f not in all_archived]
    if missing:
        print(f"❌ 开发包缺失核心文件: {missing}")
        return 1

    # 校验 AGENTS.md / CLAUDE.md / START_HERE.md 引用的相对文件链接均存在于开发包内
    broken_links = []
    for doc_name in ("AGENTS.md", "CLAUDE.md", "START_HERE.md"):
        doc_path = project_root / doc_name
        if not doc_path.is_file():
            continue
        doc_text = doc_path.read_text(encoding="utf-8")
        for target in re.findall(r"\[[^\]]+\]\(([^)#\s]+)\)", doc_text):
            if target.startswith(("http://", "https://", "mailto:")):
                continue
            rel = target.removeprefix("./").rstrip("/")
            if rel not in all_archived and not any(p.startswith(rel + "/") for p in all_archived):
                broken_links.append(f"{doc_name} -> {target}")
    if broken_links:
        print(f"❌ 开发包文档包含无效引用路径: {broken_links}")
        return 1

    # 校验 docker-compose*.yml 引用的所有本地构建上下文与挂载配置文件闭包
    compose_referenced = set()
    for compose_name in ("docker-compose.yml", "docker-compose.dev.yml"):
        compose_path = project_root / compose_name
        if not compose_path.is_file():
            continue
        content = compose_path.read_text(encoding="utf-8")
        current_ctx = None
        for raw_line in content.splitlines():
            line = raw_line.strip()
            m_ctx = re.match(r"context:\s*['\"]?(\./[^'\"\s]+)['\"]?", line)
            if m_ctx:
                current_ctx = m_ctx.group(1).removeprefix("./").rstrip("/")
                continue
            m_df = re.match(r"dockerfile:\s*['\"]?([^'\"\s]+)['\"]?", line)
            if m_df and current_ctx:
                compose_referenced.add(f"{current_ctx}/{m_df.group(1)}")
                current_ctx = None
                continue
            m_vol = re.match(r"-\s*['\"]?(\./[^:'\"\s]+):[^:'\"\s]+", line)
            if m_vol:
                vol_src = m_vol.group(1).removeprefix("./").rstrip("/")
                if vol_src and (project_root / vol_src).is_file():
                    compose_referenced.add(vol_src)
    missing_compose_refs = sorted(f for f in compose_referenced if f not in all_archived)
    if missing_compose_refs:
        print(f"❌ 开发包缺失 docker-compose 引用的构建/挂载文件: {missing_compose_refs}")
        return 1

    for item in all_archived:
        if item == "server/.env" or item == "server/config/install.lock" or item.endswith(".sqlite") or item.endswith(".log") or "node_modules/" in item:
            print(f"❌ 开发包泄漏禁止文件: {item}")
            return 1

    sha256_hash = calculate_sha256(zip_path)
    sha256_path.write_text(f"{sha256_hash} *{zip_filename}\n", encoding="utf-8")
    done_label = "统一完整发行包" if artifact_type == "unified" else "Agent 开发包"
    print(f"✔ {done_label}构建完成: {zip_filename} ({zip_path.stat().st_size / (1024*1024):.2f} MB, SHA: {sha256_hash[:12]}...)")
    return 0


def build_artifacts(mode: str = "unified", version: str = None, source: str = None, output: str = None) -> int:
    script_dir = Path(__file__).resolve().parent
    project_root = script_dir.parent
    if version is None:
        version = detect_default_version(project_root)
    if not re.fullmatch(r'v?\d+\.\d+\.\d+', version):
        raise ValueError('版本号必须是语义版本，例如 v1.3.0')
    version = 'v' + version.removeprefix('v')

    default_server = project_root / "server" if (project_root / "server/think").is_file() else project_root
    server_dir = Path(source).resolve() if source else default_server
    release_dir = Path(output).resolve() if output else project_root / "release"
    release_dir.mkdir(parents=True, exist_ok=True)

    mode = mode.lower()
    if mode == "unified":
        return build_dev_package(
            version,
            project_root,
            release_dir,
            zip_filename=f"hwadmin-{version}.zip",
            artifact_type="unified",
            server_dir_override=server_dir if source else None,
        )

    if mode in {"deploy", "all"}:
        deploy_rc = build_deploy_package(version, project_root, server_dir, release_dir)
        if deploy_rc != 0:
            return deploy_rc

    if mode in {"dev", "all"}:
        dev_rc = build_dev_package(version, project_root, release_dir)
        if dev_rc != 0:
            return dev_rc

    return 0


def main():
    parser = argparse.ArgumentParser(description="HwAdmin 制品自动化构建工具")
    parser.add_argument("--mode", default="unified", choices=["unified", "deploy", "dev", "all"], help="构建模式: unified (统一发行包，默认) / deploy / dev / all")
    parser.add_argument("--version", "-v", default=None, help="发布包版本号，默认读取 server/config/hwadmin.php")
    parser.add_argument("--source", help="已安装生产依赖的 server 隔离副本")
    parser.add_argument("--output", help="制品输出目录")
    args = parser.parse_args()

    exit_code = build_artifacts(mode=args.mode, version=args.version, source=args.source, output=args.output)
    sys.exit(exit_code)


if __name__ == "__main__":
    main()
