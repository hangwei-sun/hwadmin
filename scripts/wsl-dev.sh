#!/usr/bin/env bash
# ==============================================================================
# HwAdmin WSL 一键开发/测试/运维工具链
# 用法：bash scripts/wsl-dev.sh <command> [options]
# ==============================================================================
set -euo pipefail

# 基础目录定位
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
# 自动将受管运行时与本地 static-php / composer 所在路径加入 PATH
if [ -d "$PROJECT_ROOT/.hw-state/runtime/bin" ]; then
    export PATH="$PROJECT_ROOT/.hw-state/runtime/bin:$HOME/.local/bin:$PATH"
else
    export PATH="$HOME/.local/bin:$PATH"
fi
if [ -f "$PROJECT_ROOT/server/think" ]; then
    SERVER_DIR="$PROJECT_ROOT/server"
    ADMIN_UI_DIR="$PROJECT_ROOT/admin-ui"
elif [ -f "$PROJECT_ROOT/think" ]; then
    SERVER_DIR="$PROJECT_ROOT"
    ADMIN_UI_DIR="$PROJECT_ROOT/sources/admin-ui"
else
    SERVER_DIR="$PROJECT_ROOT/server"
    ADMIN_UI_DIR="$PROJECT_ROOT/admin-ui"
fi

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

info() { echo -e "${BLUE}[INFO]${NC} $*"; }
success() { echo -e "${GREEN}[✔]${NC} $*"; }
warn() { echo -e "${YELLOW}[!]${NC} $*"; }
error() { echo -e "${RED}[✘]${NC} $*" >&2; }

get_project_hash() {
    if command -v md5sum >/dev/null 2>&1; then
        printf '%s' "$PROJECT_ROOT" | md5sum | cut -c1-8
    elif command -v python3 >/dev/null 2>&1; then
        python3 -c "import hashlib, sys; print(hashlib.md5(sys.argv[1].encode('utf-8')).hexdigest()[:8])" "$PROJECT_ROOT"
    else
        echo "default"
    fi
}

# 打印帮助信息
cmd_help() {
    echo -e "${BOLD}HwAdmin WSL 统一开发助手${NC}"
    echo
    echo "使用方式: bash scripts/wsl-dev.sh <command> [arguments...]"
    echo
    echo -e "${CYAN}可用命令:${NC}"
    echo "  doctor         检查当前系统运行环境（PHP、扩展、Composer、Node、目录权限）[--json] [--mode=sqlite|mysql|pgsql]"
    echo "  bootstrap      非破坏性引导初始化（保留既有配置与账号，仅补充缺失并同步状态）"
    echo "  install-sqlite 极速配置并初始化本地 SQLite 开发数据库（默认非破坏性，加 --force 强制覆盖并自动备份）"
    echo "  reset-sqlite   显式备份并强制重置本地 SQLite 开发数据库"
    echo "  serve          启动后端内置 Web 服务器 (0.0.0.0:8000，按项目路径哈希隔离)"
    echo "  serve-fast     在 WSL 本地目录运行代码快照，共用原数据库和上传目录"
    echo "  stop           安全停止本项目的开发服务进程"
    echo "  smoke          运行完整集成与冒烟测试 (自动处理 .env 隔离防冲突)"
    echo "  test           运行后端 PHPUnit 自动化测试套件 (composer test)"
    echo "  inspect        输出项目结构与路由自省 JSON (php think hw:inspect --json)"
    echo "  menu-sync      从唯一数据源同步核心菜单与权限规则 (php think hw:menu:sync)"
    echo "  migrate        执行数据库迁移 (php think migrate:run)"
    echo "  rollback       回滚上一版本数据库迁移 (php think migrate:rollback)"
    echo "  lint           对 server 源码执行 PHP 语法严格校验 (php -l)"
    echo "  release        构建生产发布包 (默认读取 server/config/hwadmin.php 版本)"
    echo "  help           查看此帮助信息"
    echo
}

# 环境诊断
cmd_doctor() {
    local json_output=0
    local mode="auto"
    for arg in "$@"; do
        if [ "$arg" = "--json" ]; then
            json_output=1
        elif [[ "$arg" =~ ^--mode=([a-zA-Z0-9_]+)$ ]]; then
            mode="${BASH_REMATCH[1]}"
        fi
    done

    if [ "$mode" = "auto" ]; then
        if [ -f "$SERVER_DIR/.env" ]; then
            local env_driver
            env_driver=$(grep -E '^\s*DB_(DRIVER|TYPE)\s*=' "$SERVER_DIR/.env" | head -n1 | cut -d'=' -f2 | tr -d ' "\r' | tr '[:upper:]' '[:lower:]' || true)
            if [ "$env_driver" = "mysql" ]; then
                mode="mysql"
            elif [ "$env_driver" = "pgsql" ]; then
                mode="pgsql"
            else
                mode="sqlite"
            fi
        else
            mode="sqlite"
        fi
    fi

    # 诊断检查数据结构
    # 用 python3 执行完整结构化收集与退出码控制（若 python3 存在）
    if command -v python3 >/dev/null 2>&1; then
        python3 - "$json_output" "$mode" "$SERVER_DIR" "$ADMIN_UI_DIR" "$SCRIPT_DIR" << 'EOF'
import sys
import os
import json
import shutil
import subprocess
import importlib.util

is_json = sys.argv[1] == "1"
mode = sys.argv[2]
server_dir = sys.argv[3]
admin_ui_dir = sys.argv[4]
scripts_dir = sys.argv[5]

platform_support_matrix = None
rt_script = os.path.join(scripts_dir, "hw-runtime.py")
if os.path.isfile(rt_script):
    try:
        spec = importlib.util.spec_from_file_location("hw_runtime", rt_script)
        if spec and spec.loader:
            rt_mod = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(rt_mod)
            platform_support_matrix = rt_mod.get_platform_support_matrix()
    except Exception:
        platform_support_matrix = None

checks = []

def run_cmd(cmd):
    try:
        p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, shell=True)
        return p.returncode, p.stdout.strip(), p.stderr.strip()
    except Exception as e:
        return 1, "", str(e)

# 1. PHP Check
php_bin = shutil.which("php")
if not php_bin:
    checks.append({"name": "PHP 运行时", "category": "required", "pass": False, "message": "未找到 php 命令，要求 >= 8.2.0"})
else:
    rc, stdout, _ = run_cmd("php -r \"echo PHP_VERSION;\"")
    if rc == 0 and stdout:
        v_parts = [int(p) for p in stdout.split('.')[:2]]
        if v_parts >= [8, 2]:
            checks.append({"name": "PHP 版本", "category": "required", "pass": True, "message": f"{stdout} (>= 8.2.0)"})
        else:
            checks.append({"name": "PHP 版本", "category": "required", "pass": False, "message": f"{stdout} (过低，要求 >= 8.2.0)"})
    else:
        checks.append({"name": "PHP 运行时", "category": "required", "pass": False, "message": "无法执行 php 命令"})

# 2. PHP Extensions
if php_bin:
    rc, m_out, _ = run_cmd("php -m")
    loaded_exts = set(x.strip().lower() for x in m_out.splitlines() if x.strip())
    
    # 基础扩展
    base_exts = [("pdo", "required"), ("mbstring", "required"), ("json", "required"), ("openssl", "required"), ("curl", "required")]
    for ext, cat in base_exts:
        if ext in loaded_exts:
            checks.append({"name": f"扩展 {ext}", "category": cat, "pass": True, "message": "已加载"})
        else:
            checks.append({"name": f"扩展 {ext}", "category": cat, "pass": False, "message": "缺失"})

    # 按模式检查数据库驱动
    if mode == "sqlite":
        has_sqlite = "pdo_sqlite" in loaded_exts or "sqlite3" in loaded_exts
        checks.append({"name": "扩展 pdo_sqlite / sqlite3", "category": "required", "pass": has_sqlite, "message": "已加载" if has_sqlite else "缺失"})
        checks.append({"name": "扩展 pdo_mysql (可选)", "category": "optional", "pass": "pdo_mysql" in loaded_exts, "message": "已加载" if "pdo_mysql" in loaded_exts else "未安装（SQLite 模式无需）"})
        checks.append({"name": "扩展 pdo_pgsql (可选)", "category": "optional", "pass": "pdo_pgsql" in loaded_exts, "message": "已加载" if "pdo_pgsql" in loaded_exts else "未安装（SQLite 模式无需）"})
    elif mode == "mysql":
        has_mysql = "pdo_mysql" in loaded_exts
        checks.append({"name": "扩展 pdo_mysql", "category": "required", "pass": has_mysql, "message": "已加载" if has_mysql else "缺失"})
    elif mode == "pgsql":
        has_pgsql = "pdo_pgsql" in loaded_exts
        checks.append({"name": "扩展 pdo_pgsql", "category": "required", "pass": has_pgsql, "message": "已加载" if has_pgsql else "缺失"})

# 3. Composer Check
composer_bin = shutil.which("composer")
if composer_bin:
    rc, c_out, _ = run_cmd("composer --version")
    checks.append({"name": "Composer", "category": "required", "pass": True, "message": c_out.splitlines()[0] if c_out else "已安装"})
else:
    checks.append({"name": "Composer", "category": "required", "pass": False, "message": "未找到 composer 命令"})

# 4. Node.js & pnpm Check (对齐 engines: node >= 22.18.0, pnpm >= 10.0.0)
node_bin = shutil.which("node")
if node_bin:
    rc, n_out, _ = run_cmd("node -v")
    ver_str = n_out.lstrip("v").strip()
    try:
        parts = [int(p) for p in ver_str.split('.')[:2]]
        is_ok = parts >= [22, 18]
        msg = f"{n_out} (对齐 engines >= 22.18.0)" if is_ok else f"{n_out} (推荐 >= 22.18.0)"
        checks.append({"name": "Node.js 版本", "category": "optional", "pass": is_ok, "message": msg})
    except Exception:
        checks.append({"name": "Node.js", "category": "optional", "pass": True, "message": n_out})
else:
    checks.append({"name": "Node.js", "category": "optional", "pass": False, "message": "未安装 (如需编译 admin-ui 前端请安装 Node >= 22.18.0)"})

pnpm_bin = shutil.which("pnpm")
if pnpm_bin:
    rc, p_out, _ = run_cmd("pnpm -v")
    p_ver = p_out.strip()
    if p_ver:
        checks.append({"name": "pnpm", "category": "optional", "pass": True, "message": f"v{p_ver} (推荐 >= 10.0.0)"})
    else:
        checks.append({"name": "pnpm", "category": "optional", "pass": False, "message": "未安装"})
else:
    checks.append({"name": "pnpm", "category": "optional", "pass": False, "message": "未安装"})

# 5. Directory write check
for rel_dir in ["runtime", "config", "public/uploads"]:
    dpath = os.path.join(server_dir, rel_dir)
    os.makedirs(dpath, exist_ok=True)
    is_writable = os.access(dpath, os.W_OK)
    checks.append({"name": f"目录可写 server/{rel_dir}", "category": "required", "pass": is_writable, "message": "可写" if is_writable else "权限不足不可写"})

failed_required = [c for c in checks if c["category"] == "required" and not c["pass"]]
all_passed = len(failed_required) == 0

summary = {
    "total": len(checks),
    "passed": sum(1 for c in checks if c["pass"]),
    "failed": len(failed_required),
    "warnings": sum(1 for c in checks if c["category"] == "optional" and not c["pass"])
}

output_data = {
    "status": "pass" if all_passed else "fail",
    "mode": mode,
    "summary": summary,
    "checks": checks,
}
if platform_support_matrix is not None:
    output_data["platform_support_matrix"] = platform_support_matrix

if is_json:
    print(json.dumps(output_data, ensure_ascii=False, indent=2))
else:
    print("\033[1m========== [HwAdmin 环境诊断] ==========\033[0m")
    if platform_support_matrix is not None:
        cur_plat = platform_support_matrix.get("current_platform", {})
        print(f"当前平台: {cur_plat.get('id', 'unknown')} ({cur_plat.get('status', 'unknown')}) - {cur_plat.get('reason', '')}")
    print(f"诊断模式: \033[36m{mode}\033[0m (总计 {summary['total']} 项)")
    for c in checks:
        color = "\033[32m[✔]\033[0m" if c["pass"] else ("\033[31m[✘]\033[0m" if c["category"] == "required" else "\033[33m[!]\033[0m")
        tag = f"({c['category']})" if c["category"] != "required" else ""
        print(f" {color} {c['name']} {tag}: {c['message']}")
    print("----------------------------------------")
    if all_passed:
        print("\033[32m[✔] 恭喜！当前环境已满足要求，所有必需项均达标。\033[0m")
    else:
        print("\033[31m[✘] 存在必需项未达标，请根据上述提示排查修复。\033[0m")

sys.exit(0 if all_passed else 1)
EOF
    else
        # 兜底纯 bash 检查
        info "未安装 python3，使用基础 shell 执行检查..."
        local all_pass=1
        if ! command -v php >/dev/null 2>&1; then
            error "未找到 php 命令"
            all_pass=0
        fi
        if [ "$all_pass" -eq 1 ]; then
            success "基础环境检查通过"
            return 0
        else
            error "基础环境检查未通过"
            return 1
        fi
    fi
}

# 检查数据库与超级管理员是否已完成完整初始化
# 返回值:
#   0  -> 已完整安装（存在 install.lock 且数据库可连接、存在至少 1 名管理员）
#   10 -> 未建表或无超管账号（需执行 hw:install 初始化建表与超管）
#   11 -> 数据库已有超管账号，仅缺失 install.lock（需同步迁移/菜单并补齐 install.lock，保留既有账号密码）
#   其他非零 -> PHP 运行时故障或数据库连接异常（必须向上传播错误）
check_installation_state() {
    local lock_file="$SERVER_DIR/config/install.lock"
    command -v php >/dev/null 2>&1 || { error "未找到 php 命令"; return 127; }
    local rc=0
    (
        cd "$SERVER_DIR"
        php -r '
require "vendor/autoload.php";
$app = new \think\App();
$app->initialize();
try {
    $count = (int) \think\facade\Db::name("admin")->count();
    exit($count > 0 ? 0 : 10);
} catch (\Throwable $e) {
    $msg = strtolower($e->getMessage());
    if (str_contains($msg, "no such table") || str_contains($msg, "doesn'\''t exist") || str_contains($msg, "undefined table") || str_contains($msg, "base table or view not found")) {
        exit(10);
    }
    fwrite(STDERR, "Database check failed: " . $e->getMessage() . PHP_EOL);
    exit(20);
}
'
    ) || rc=$?

    if [ "$rc" -ne 0 ] && [ "$rc" -ne 10 ]; then
        return "$rc"
    fi
    if [ "$rc" -eq 0 ] && [ ! -f "$lock_file" ]; then
        return 11
    fi
    return "$rc"
}

# 快速初始化 SQLite 开发库（默认非破坏性）
cmd_install_sqlite() {
    local force=0
    for arg in "$@"; do
        [ "$arg" = "--force" ] && force=1
    done

    local db_path="$SERVER_DIR/runtime/dev.sqlite"
    local env_file="$SERVER_DIR/.env"
    local lock_file="$SERVER_DIR/config/install.lock"

    mkdir -p "$SERVER_DIR/runtime" "$SERVER_DIR/public/uploads" "$SERVER_DIR/public/plugins"

    if [ "$force" -eq 1 ] && [ -f "$db_path" ]; then
        local backup_dir="$SERVER_DIR/runtime/backup-sqlite-$(date +%Y%m%d%H%M%S)"
        mkdir -p "$backup_dir"
        warn "正在执行强制重置，已自动将旧数据与配置备份至: $backup_dir"
        [ -f "$db_path" ] && cp -f "$db_path" "$backup_dir/"
        [ -f "$env_file" ] && cp -f "$env_file" "$backup_dir/"
        rm -f "$db_path" "$lock_file"
    fi

    # 写入 .env 配置（若不存在或强刷）
    if [ ! -f "$env_file" ] || [ "$force" -eq 1 ]; then
        cat > "$env_file" <<EOF
APP_DEBUG = true
DEFAULT_LANG = zh-cn

DB_DRIVER = sqlite
DB_TYPE = sqlite
DB_NAME = ${db_path}
DB_HOST =
DB_PORT =
DB_USER =
DB_PASS =
DB_PREFIX = hw_

CACHE_DRIVER = file

HW_ADMIN_TOKEN_TTL = 86400
HW_USER_TOKEN_TTL = 2592000
HW_CORS_ORIGINS = http://localhost:5200,http://127.0.0.1:5200,http://localhost:5173,http://127.0.0.1:5173
EOF
        success "已生成本地 .env (数据库驱动: sqlite, 路径: server/runtime/dev.sqlite)"
    fi

    local state_rc=0
    check_installation_state || state_rc=$?
    if [ "$state_rc" -ne 0 ] && [ "$state_rc" -ne 10 ] && [ "$state_rc" -ne 11 ]; then
        error "SQLite 环境状态校验失败 (exit $state_rc)"
        return "$state_rc"
    fi

    cd "$SERVER_DIR"
    if [ "$state_rc" -eq 0 ] && [ "$force" -eq 0 ]; then
        info "检测到本地 SQLite 开发环境已完整就绪 (保留既有数据与账号)，正在同步增量迁移与菜单..."
        php think migrate:run
        php think hw:menu:sync
        return 0
    fi

    if [ "$state_rc" -eq 11 ] && [ "$force" -eq 0 ]; then
        info "检测到数据库已有管理员账号但缺少 install.lock，正在同步迁移、菜单并恢复安装锁（保留既有账号）..."
        php think migrate:run
        php think hw:menu:sync
        php -r 'require "vendor/autoload.php"; $app = new \think\App(); $app->initialize(); (new \core\install\Installer($app))->lock();'
        return 0
    fi

    # 处理部分安装状态（如存在 .env 但无表/无管理员或无 install.lock）
    rm -f "$lock_file"
    info "开始执行系统安装与核心迁移..."
    local install_cmd=(php think hw:install --username=admin --password=Admin12345 --skip-if-installed)
    if [ "$force" -eq 1 ]; then
        install_cmd+=(--force)
    fi
    "${install_cmd[@]}"
    php think migrate:run
    php think hw:menu:sync

    echo
    success "================================================="
    success "🎉 HwAdmin 本地 SQLite 敏捷开发环境初始化完成！"
    success "管理后台地址: http://127.0.0.1:8000"
    success "超级管理员账号: admin"
    success "超级管理员密码: Admin12345"
    success "提示: 执行 'bash scripts/wsl-dev.sh serve' 即可启动后端服务！"
    success "================================================="
}

# 强制重置 SQLite
cmd_reset_sqlite() {
    warn "准备强制重置本地 SQLite 开发数据库..."
    cmd_install_sqlite --force
}

# 幂等系统引导
cmd_bootstrap() {
    info "正在执行 HwAdmin 幂等系统引导与就绪检查..."
    local force=0
    for arg in "$@"; do
        [ "$arg" = "--force" ] && force=1
    done

    mkdir -p "$SERVER_DIR/runtime" "$SERVER_DIR/public/uploads" "$SERVER_DIR/public/plugins"

    if [ ! -f "$SERVER_DIR/.env" ] || [ "$force" -eq 1 ]; then
        info "未检测到 server/.env 或指定了 --force，配置轻量级 SQLite 基础开发配置..."
        cmd_install_sqlite "$@"
    else
        info "已检测到既有 server/.env，正在校验安装完整性与数据库状态..."
        local state_rc=0
        check_installation_state || state_rc=$?
        if [ "$state_rc" -ne 0 ] && [ "$state_rc" -ne 10 ] && [ "$state_rc" -ne 11 ]; then
            error "系统引导失败：PHP 运行时或数据库连接校验未通过 (exit $state_rc)"
            return "$state_rc"
        fi
        cd "$SERVER_DIR"
        if [ "$state_rc" -eq 10 ]; then
            info "检测到仅有 .env 或部分安装状态（缺少数据表或超管账号），正在执行安装初始化..."
            rm -f "$SERVER_DIR/config/install.lock"
            php think hw:install --username=admin --password=Admin12345 --skip-if-installed
        fi
        php think migrate:run
        php think hw:menu:sync
        if [ "$state_rc" -eq 11 ]; then
            info "检测到数据库已有管理员但缺少 install.lock，正在恢复安装锁（保留既有账号密码）..."
            php -r 'require "vendor/autoload.php"; $app = new \think\App(); $app->initialize(); (new \core\install\Installer($app))->lock();'
        fi
    fi

    success "================================================="
    success "🎉 HwAdmin 引导就绪！"
    success "项目目录: $PROJECT_ROOT"
    success "如需启动服务，请运行: ./hw dev 或 bash scripts/wsl-dev.sh serve"
    success "================================================="
}

# 启动服务（默认使用高速本地快照 + 8并发Workers）
cmd_serve() {
    cmd_serve_fast "$@"
}

is_port_busy() {
    local check_port="$1"
    if command -v lsof >/dev/null 2>&1; then
        local pids
        pids=$(lsof -ti :"$check_port" 2>/dev/null || true)
        if [ -n "$pids" ]; then
            return 0
        fi
    fi
    if command -v python3 >/dev/null 2>&1; then
        python3 - "$check_port" << 'PY'
import socket, sys
port = int(sys.argv[1])
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
try:
    s.bind(("0.0.0.0", port))
except OSError:
    sys.exit(0)
finally:
    s.close()
c = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
c.settimeout(0.3)
try:
    if c.connect_ex(("127.0.0.1", port)) == 0:
        sys.exit(0)
finally:
    c.close()
sys.exit(1)
PY
        return $?
    fi
    return 1
}

# 将代码快照放在 WSL 本地文件系统，按项目路径哈希隔离，并启用后台增量实时同步
cmd_serve_fast() {
    command -v rsync >/dev/null 2>&1 || { error "serve-fast 需要安装 rsync"; return 1; }
    local auto_port="${AUTO_PORT:-0}"
    for arg in "$@"; do
        case "$arg" in
            --auto-port) auto_port=1 ;;
            --port=*) PORT="${arg#--port=}" ;;
        esac
    done
    local proj_hash
    proj_hash=$(get_project_hash)
    local snapshot="/tmp/hwadmin-serve-${proj_hash}"
    local pid_file="${snapshot}/serve.pid"
    local sync_pid_file="${snapshot}/sync.pid"
    local dev_pid_file="$PROJECT_ROOT/.hw-state/dev-php.pid"
    local port="${PORT:-8000}"

    mkdir -p "$snapshot" "$PROJECT_ROOT/.hw-state"

    # 端口占用检测与处理（支持多 worker 子进程、lsof 缺失兜底与 --auto-port 自动隔离）
    if is_port_busy "$port"; then
        local occupying_pid=""
        if command -v lsof >/dev/null 2>&1; then
            occupying_pid=$(lsof -ti :"$port" 2>/dev/null || true)
        fi
        local my_pid=""
        local is_mine=0
        [ -f "$pid_file" ] && my_pid=$(cat "$pid_file" 2>/dev/null || true)
        [ -z "$my_pid" ] && [ -f "$dev_pid_file" ] && my_pid=$(cat "$dev_pid_file" 2>/dev/null || true)
        if [ -n "$my_pid" ] && [ -n "$occupying_pid" ]; then
            if echo "$occupying_pid" | grep -qx "$my_pid"; then
                is_mine=1
            else
                local p_item ppid_item
                for p_item in $occupying_pid; do
                    ppid_item=$(ps -o ppid= -p "$p_item" 2>/dev/null | tr -d ' ' || true)
                    if [ "$ppid_item" = "$my_pid" ]; then
                        is_mine=1
                        break
                    fi
                done
            fi
        fi
        if [ "$is_mine" -eq 1 ]; then
            warn "检测到本项目旧服务进程 (PID: $my_pid) 正在占用端口 $port，正在重启..."
            cmd_stop >/dev/null 2>&1 || true
            sleep 1
        elif [ "$auto_port" = "1" ]; then
            local orig_port="$port"
            local found_free=0
            local offset
            for offset in $(seq 1 50); do
                local cand=$((orig_port + offset))
                if ! is_port_busy "$cand"; then
                    port="$cand"
                    found_free=1
                    warn "端口 $orig_port 已被占用，已自动切换至隔离端口 $port"
                    break
                fi
            done
            if [ "$found_free" -eq 0 ]; then
                error "端口 $orig_port 及后续 50 个端口均已被占用"
                return 1
            fi
        else
            error "端口 $port 当前被外部进程${occupying_pid:+ (PID: $(echo "$occupying_pid" | tr '\n' ' '))}占用，请使用 PORT=$((port+1)) 或 --auto-port 启动。"
            return 1
        fi
    fi

    sync_snapshot_once() {
        rsync -a --delete \
            --exclude=serve.pid \
            --exclude=sync.pid \
            --exclude=runtime \
            --exclude=public/uploads \
            --exclude=public/admin \
            --exclude=public/plugins \
            --exclude=.env \
            --exclude=config/install.lock \
            --exclude=config/install.token \
            "$SERVER_DIR/" "$snapshot/"
        if [ -f "$SERVER_DIR/.env" ]; then
            if [ ! -f "$snapshot/.env" ] || [ "$SERVER_DIR/.env" -nt "$snapshot/.env" ]; then
                cp -f "$SERVER_DIR/.env" "$snapshot/.env"
            fi
        fi
        if [ -f "$SERVER_DIR/config/install.lock" ] && [ ! -L "$snapshot/config/install.lock" ]; then
            ln -sfn "$SERVER_DIR/config/install.lock" "$snapshot/config/install.lock"
        fi
        if [ -f "$SERVER_DIR/config/install.token" ] && [ ! -L "$snapshot/config/install.token" ]; then
            ln -sfn "$SERVER_DIR/config/install.token" "$snapshot/config/install.token"
        fi
    }

    info "正在高速同步代码至 WSL 本地隔离缓存: $snapshot"
    sync_snapshot_once
    mkdir -p "$SERVER_DIR/runtime" "$SERVER_DIR/public/uploads" "$SERVER_DIR/public/admin" "$SERVER_DIR/public/plugins"
    ln -sfn "$SERVER_DIR/runtime" "$snapshot/runtime"
    ln -sfn "$SERVER_DIR/public/uploads" "$snapshot/public/uploads"
    ln -sfn "$SERVER_DIR/public/admin" "$snapshot/public/admin"
    ln -sfn "$SERVER_DIR/public/plugins" "$snapshot/public/plugins"
    if [ -f "$SERVER_DIR/config/install.lock" ]; then
        ln -sfn "$SERVER_DIR/config/install.lock" "$snapshot/config/install.lock"
    fi
    if [ -f "$SERVER_DIR/config/install.token" ]; then
        ln -sfn "$SERVER_DIR/config/install.token" "$snapshot/config/install.token"
    fi
    php -r 'if (isset($argv[1]) && file_exists($argv[1])) { $p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA journal_mode = DELETE"); }' "$SERVER_DIR/runtime/dev.sqlite" 2>/dev/null || true

    info "启动 HwAdmin 极速开发服务器 (8 Workers + 实时增量同步)，项目标识: $proj_hash，监听端口: $port"
    info "后端 API 地址: http://127.0.0.1:${port}/api/index/ping"
    info "按 Ctrl+C 停止服务..."

    local s_pid=""
    local sync_pid=""
    cleanup_serve() {
        local p c
        for p in "$sync_pid" "$s_pid"; do
            if [ -n "$p" ] && kill -0 "$p" 2>/dev/null; then
                c=$(pgrep -P "$p" 2>/dev/null || true)
                kill $p $c 2>/dev/null || true
                kill -9 $p $c 2>/dev/null || true
            fi
        done
        rm -f "$pid_file" "$sync_pid_file" "$dev_pid_file"
    }
    trap cleanup_serve EXIT INT TERM

    export PHP_CLI_SERVER_WORKERS=8
    cd "$snapshot"
    php \
        -c "$snapshot/php.ini" \
        -d upload_max_filesize=500M \
        -d post_max_size=500M \
        -d memory_limit=512M \
        -d max_execution_time=300 \
        -d max_input_time=300 \
        -S "0.0.0.0:${port}" -t public public/router.php &
    s_pid=$!
    echo "$s_pid" > "$pid_file"
    echo "$s_pid" > "$dev_pid_file"

    # 启动后短暂探活，确保进程未因端口冲突或配置错误秒退
    sleep 0.4
    if ! kill -0 "$s_pid" 2>/dev/null; then
        error "PHP 内置服务器启动失败 (端口: $port)"
        wait "$s_pid"
        return 1
    fi

    # 启动轻量后台增量同步循环，确保源码改动秒级同步至快照目录
    (
        while kill -0 "$s_pid" 2>/dev/null; do
            sync_snapshot_once >/dev/null 2>&1 || true
            sleep 1
        done
    ) &
    sync_pid=$!
    echo "$sync_pid" > "$sync_pid_file"

    wait "$s_pid"
}

# 停止本项目服务
cmd_stop() {
    local proj_hash
    proj_hash=$(get_project_hash)
    local snapshot="/tmp/hwadmin-serve-${proj_hash}"
    local pid_file="${snapshot}/serve.pid"
    local sync_pid_file="${snapshot}/sync.pid"
    local dev_pid_file="$PROJECT_ROOT/.hw-state/dev-php.pid"
    local stopped=0

    for pf in "$sync_pid_file" "$pid_file" "$dev_pid_file"; do
        if [ -f "$pf" ]; then
            local pid children
            pid=$(cat "$pf" 2>/dev/null || true)
            if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
                info "正在停止本项目服务进程 (PID: $pid)..."
                children=$(pgrep -P "$pid" 2>/dev/null || true)
                kill $pid $children 2>/dev/null || true
                sleep 0.2
                kill -9 $pid $children 2>/dev/null || true
                stopped=1
            fi
            rm -f "$pf"
        fi
    done

    if [ "$stopped" -eq 0 ] && command -v pgrep >/dev/null 2>&1; then
        local snap_pids
        snap_pids=$(pgrep -f "$snapshot" 2>/dev/null || true)
        if [ -n "$snap_pids" ]; then
            kill $snap_pids 2>/dev/null || true
            sleep 0.2
            kill -9 $snap_pids 2>/dev/null || true
            stopped=1
        fi
    fi

    if [ "$stopped" -eq 1 ]; then
        success "已停止本项目开发服务器。"
        return 0
    fi
    info "本项目当前无运行中的开发服务进程。"
}

# 冒烟测试只操作隔离副本，不移动开发配置或删除开发上传文件。
cmd_smoke() {
    command -v rsync >/dev/null || { error "运行隔离冒烟需要 rsync"; return 1; }
    local work
    work=$(mktemp -d /tmp/hwadmin-smoke-suite-XXXXXX)
    info "冒烟测试隔离目录: $work"
    mkdir -p "$work/server"
    rsync -a --exclude=.env --exclude='.env.*' --exclude=runtime \
        --exclude=public/uploads --exclude=public/plugins \
        --exclude=config/install.lock --exclude='config/install.lock.*' \
        --exclude=config/install.token --exclude='config/install.token.*' \
        "$SERVER_DIR/" "$work/server/"
    mkdir -p "$work/server/runtime" "$work/server/public/uploads" "$work/server/public/plugins"
    [ -f "$SERVER_DIR/public/uploads/.htaccess" ] && cp "$SERVER_DIR/public/uploads/.htaccess" "$work/server/public/uploads/.htaccess" || true
    if (cd "$work/server" && bash tests/smoke.sh); then
        success "冒烟测试通过（隔离目录保留供核查: $work）"
    else
        error "冒烟测试失败，检查隔离目录: $work"
        return 1
    fi
}

# 数据库迁移
cmd_migrate() {
    info "执行数据库迁移..."
    if [ -f "$SERVER_DIR/.env" ] && grep -q "hwadmin-mysql" "$SERVER_DIR/.env" && command -v docker >/dev/null 2>&1 && [ -n "$(docker ps -q -f name=^hwadmin-php$ 2>/dev/null)" ]; then
        docker exec hwadmin-php php think migrate:run
    else
        cd "$SERVER_DIR"
        php think migrate:run
    fi
    success "迁移完成！"
}

# 数据库回滚
cmd_rollback() {
    warn "执行数据库迁移回滚..."
    if [ -f "$SERVER_DIR/.env" ] && grep -q "hwadmin-mysql" "$SERVER_DIR/.env" && command -v docker >/dev/null 2>&1 && [ -n "$(docker ps -q -f name=^hwadmin-php$ 2>/dev/null)" ]; then
        docker exec hwadmin-php php think migrate:rollback
    else
        cd "$SERVER_DIR"
        php think migrate:rollback
    fi
    success "回滚完成！"
}

# PHP 代码语法检查
cmd_lint() {
    info "对 server 源码执行严格语法校验 (php -l)..."
    cd "$SERVER_DIR"
    local error_count=0
    local lint_dirs=()
    local d
    for d in app core config database plugins public tests; do
        [ -d "$d" ] && lint_dirs+=("$d")
    done
    if [ "${#lint_dirs[@]}" -gt 0 ]; then
        while IFS= read -r -d '' file; do
            if ! php -l "$file" >/dev/null 2>&1; then
                error "语法错误: $file"
                php -l "$file"
                error_count=$((error_count + 1))
            fi
        done < <(find "${lint_dirs[@]}" -type f -name "*.php" -print0)
    fi
    for file in think worker.php; do
        [ -f "$file" ] || continue
        if ! php -l "$file" >/dev/null 2>&1; then
            error "语法错误: $file"
            error_count=$((error_count + 1))
        fi
    done

    if [ "$error_count" -eq 0 ]; then
        success "全库 PHP 文件语法校验全部通过！"
    else
        error "共发现 $error_count 个文件存在语法错误，请修复！"
        exit 1
    fi
}

# 生产发布包自动化构建
cmd_release() {
    info "正在构建 HwAdmin 生产发布包 (调用 python3 scripts/build-release.py)..."
    python3 "$SCRIPT_DIR/build-release.py" "$@"
    success "发布包构建完成！"
}

# PHPUnit 自动化测试套件
cmd_test() {
    info "运行后端 PHPUnit 自动化测试套件..."
    cd "$SERVER_DIR"
    if [ ! -f vendor/bin/phpunit ]; then
        error "未找到 vendor/bin/phpunit（发布包默认仅含生产依赖），请先在当前后端根目录 ($SERVER_DIR) 执行: composer install"
        exit 1
    fi
    command -v rsync >/dev/null || { error "隔离 PHPUnit 需要 rsync"; return 1; }
    local work
    work=$(mktemp -d /tmp/hwadmin-phpunit-suite-XXXXXX)
    mkdir -p "$work/server" "$work/admin-ui/src/types" "$work/admin-ui/mobile/src/types"
    rsync -a --exclude=.env --exclude='.env.*' --exclude=runtime \
        --exclude=public/uploads --exclude=public/storage --exclude=public/plugins --exclude=public/admin \
        --exclude='install.lock*' --exclude='install.token*' "$SERVER_DIR/" "$work/server/"
    for directory in templates; do
        [ ! -d "$PROJECT_ROOT/$directory" ] || rsync -a "$PROJECT_ROOT/$directory/" "$work/$directory/"
    done
    [ ! -f "$PROJECT_ROOT/hw.project.json" ] || cp "$PROJECT_ROOT/hw.project.json" "$work/"
    for directory in src/types mobile/src/types; do
        [ ! -d "$ADMIN_UI_DIR/$directory" ] || rsync -a "$ADMIN_UI_DIR/$directory/" "$work/admin-ui/$directory/"
    done
    info "PHPUnit 隔离目录: $work"
    (cd "$work/server" && composer test "$@")
    success "PHPUnit 自动化测试全部通过！"
}

# 项目自省 JSON
cmd_inspect() {
    cd "$SERVER_DIR"
    php think hw:inspect --json "$@"
}

# 菜单单一数据源同步
cmd_menu_sync() {
    cd "$SERVER_DIR"
    php think hw:menu:sync "$@"
}

# 入口路由
case "${1:-help}" in
    doctor)
        shift
        cmd_doctor "$@"
        ;;
    bootstrap)
        shift
        cmd_bootstrap "$@"
        ;;
    install-sqlite)
        shift
        cmd_install_sqlite "$@"
        ;;
    reset-sqlite)
        shift
        cmd_reset_sqlite "$@"
        ;;
    serve)
        shift
        cmd_serve "$@"
        ;;
    serve-fast)
        shift
        cmd_serve_fast "$@"
        ;;
    stop)
        shift
        cmd_stop "$@"
        ;;
    smoke)
        cmd_smoke
        ;;
    test)
        shift
        cmd_test "$@"
        ;;
    inspect)
        shift
        cmd_inspect "$@"
        ;;
    menu-sync)
        shift
        cmd_menu_sync "$@"
        ;;
    migrate)
        cmd_migrate
        ;;
    rollback)
        cmd_rollback
        ;;
    lint)
        cmd_lint
        ;;
    release)
        shift
        cmd_release "$@"
        ;;
    help|--help|-h)
        cmd_help
        ;;
    *)
        error "未知命令: $1"
        cmd_help
        exit 1
        ;;
esac
