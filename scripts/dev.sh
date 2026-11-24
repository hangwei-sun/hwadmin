#!/usr/bin/env bash
# ==============================================================================
# HwAdmin 一键全栈本地联调启动脚本（PHP 后端 + Vite 前端）
#
# 用法：
#   bash scripts/dev.sh                    # 使用现有 .env（若无则自动初始化 SQLite 开发库）
#   bash scripts/dev.sh --sqlite           # 指定独立隔离 SQLite 库并自动安装初始化
#   bash scripts/dev.sh --auto-port        # 端口冲突时自动递增选择隔离端口
#   PHP_PORT=8000 VITE_PORT=15173 bash scripts/dev.sh
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
if [ -d "$PROJECT_ROOT/.hw-state/runtime/bin" ]; then
  export PATH="$PROJECT_ROOT/.hw-state/runtime/bin:$HOME/.local/bin:$PATH"
else
  export PATH="$HOME/.local/bin:$PATH"
fi

if [ -f "$PROJECT_ROOT/server/think" ]; then
  SERVER_DIR="$PROJECT_ROOT/server"
  ADMIN_UI_DIR="$PROJECT_ROOT/admin-ui"
  ADMIN_UI_REL="admin-ui"
else
  SERVER_DIR="$PROJECT_ROOT"
  ADMIN_UI_DIR="$PROJECT_ROOT/sources/admin-ui"
  ADMIN_UI_REL="sources/admin-ui"
fi

get_project_hash() {
  if command -v md5sum >/dev/null 2>&1; then
    printf '%s' "$PROJECT_ROOT" | md5sum | cut -c1-8
  elif command -v python3 >/dev/null 2>&1; then
    python3 -c "import hashlib, sys; print(hashlib.md5(sys.argv[1].encode('utf-8')).hexdigest()[:8])" "$PROJECT_ROOT"
  else
    echo "default"
  fi
}

is_port_busy() {
  local check_port="$1"
  if command -v lsof >/dev/null 2>&1; then
    local pids
    pids=$(lsof -ti :"$check_port" 2>/dev/null || true)
    [ -n "$pids" ] && return 0
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

PROJ_HASH="$(get_project_hash)"
SNAPSHOT_DIR="/tmp/hwadmin-serve-${PROJ_HASH}"
SERVE_PID_FILE="${SNAPSHOT_DIR}/serve.pid"
DEV_PID_FILE="$PROJECT_ROOT/.hw-state/dev-php.pid"
mkdir -p "$SNAPSHOT_DIR" "$PROJECT_ROOT/.hw-state"

PHP_PORT="${PHP_PORT:-8000}"
VITE_PORT="${VITE_PORT:-15173}"
USE_ISOLATED_SQLITE=0
AUTO_PORT="${AUTO_PORT:-0}"
BACKEND_ONLY=0

for arg in "$@"; do
  case "$arg" in
    --sqlite)
      USE_ISOLATED_SQLITE=1
      ;;
    --auto-port)
      AUTO_PORT=1
      ;;
    --backend-only)
      BACKEND_ONLY=1
      ;;
    --php-port=*)
      PHP_PORT="${arg#--php-port=}"
      ;;
    --vite-port=*)
      VITE_PORT="${arg#--vite-port=}"
      ;;
    --stop|stop)
      exec bash "$SCRIPT_DIR/wsl-dev.sh" stop
      ;;
    *)
      ;;
  esac
done

# 端口冲突检测与隔离处理（绝不误杀其他项目进程）
resolve_port_conflict() {
  local target_port="$1"
  local label="$2"
  local allow_mine="${3:-0}"
  if is_port_busy "$target_port"; then
    local is_mine=0
    if [ "$allow_mine" -eq 1 ] && command -v lsof >/dev/null 2>&1; then
      local occupying_pid="" my_pid=""
      occupying_pid=$(lsof -ti :"$target_port" 2>/dev/null || true)
      [ -f "$DEV_PID_FILE" ] && my_pid=$(cat "$DEV_PID_FILE" 2>/dev/null || true)
      [ -z "$my_pid" ] && [ -f "$SERVE_PID_FILE" ] && my_pid=$(cat "$SERVE_PID_FILE" 2>/dev/null || true)
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
    fi
    if [ "$is_mine" -eq 1 ]; then
      echo "[dev] 检测到本项目旧服务正在占用 ${label} 端口 ${target_port}，正在重启..." >&2
      bash "$SCRIPT_DIR/wsl-dev.sh" stop >/dev/null 2>&1 || true
      sleep 0.5
      echo "$target_port"
      return 0
    fi
    if [ "$AUTO_PORT" = "1" ]; then
      local offset cand
      for offset in $(seq 1 50); do
        cand=$((target_port + offset))
        if ! is_port_busy "$cand"; then
          echo "[dev] ${label} 端口 ${target_port} 已被占用，已自动隔离切换至 ${cand}" >&2
          echo "$cand"
          return 0
        fi
      done
    fi
    echo "[dev] ❌ ${label} 端口 ${target_port} 已被其他进程占用！请更换端口或传参 --auto-port" >&2
    return 1
  fi
  echo "$target_port"
}

PHP_PORT="$(resolve_port_conflict "$PHP_PORT" "PHP 后端" 1)"

PHP_PID=""
cleanup() {
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
    local children=""
    children=$(pgrep -P "$PHP_PID" 2>/dev/null || true)
    kill "$PHP_PID" $children 2>/dev/null || true
    sleep 0.2
    kill -9 "$PHP_PID" $children 2>/dev/null || true
  fi
  rm -f "$SERVE_PID_FILE" "$DEV_PID_FILE"
}
trap cleanup EXIT INT TERM

if [ "$USE_ISOLATED_SQLITE" -eq 1 ]; then
  ISO_DB="$SERVER_DIR/runtime/dev-isolated-$$.sqlite"
  mkdir -p "$SERVER_DIR/runtime"
  export APP_ENV=local APP_DEBUG=true DB_DRIVER=sqlite DB_TYPE=sqlite DB_NAME="$ISO_DB" DB_PREFIX=hw_
  echo "[dev] 初始化隔离 SQLite 数据库: $ISO_DB"
  (cd "$SERVER_DIR" && php think hw:install --username=admin --password=Admin12345 --force)
elif [ ! -f "$SERVER_DIR/.env" ]; then
  echo "[dev] 未检测到后端 .env，自动初始化本地 SQLite 开发环境..."
  bash "$SCRIPT_DIR/wsl-dev.sh" bootstrap
fi

BACKEND_LOG="$PROJECT_ROOT/.hw-state/dev-backend.log"
echo "[dev] 启动 PHP 后端服务: http://127.0.0.1:${PHP_PORT} (项目哈希: ${PROJ_HASH})"
(
  cd "$SERVER_DIR"
  export PHP_CLI_SERVER_WORKERS=4
  exec php -c "$SERVER_DIR/php.ini" -S "0.0.0.0:${PHP_PORT}" -t public public/router.php
) >"$BACKEND_LOG" 2>&1 &
PHP_PID=$!
echo "$PHP_PID" > "$SERVE_PID_FILE"
echo "$PHP_PID" > "$DEV_PID_FILE"

# 等待后端健康探针就绪后再拉起 Vite
BACKEND_READY=0
for _ in $(seq 1 30); do
  if ! kill -0 "$PHP_PID" 2>/dev/null; then
    echo "[dev] ❌ PHP 后端服务启动失败 (端口 ${PHP_PORT})，日志: ${BACKEND_LOG}" >&2
    cat "$BACKEND_LOG" >&2 || true
    exit 1
  fi
  if command -v curl >/dev/null 2>&1; then
    if curl -fsS "http://127.0.0.1:${PHP_PORT}/api/index/ping" 2>/dev/null | grep -q '"code":1'; then
      BACKEND_READY=1
      break
    fi
  elif command -v python3 >/dev/null 2>&1; then
    if python3 -c "import urllib.request, json, sys; d=json.loads(urllib.request.urlopen('http://127.0.0.1:${PHP_PORT}/api/index/ping', timeout=1).read()); sys.exit(0 if d.get('code')==1 else 1)" 2>/dev/null; then
      BACKEND_READY=1
      break
    fi
  else
    sleep 0.4
    BACKEND_READY=1
    break
  fi
  sleep 0.2
done

if [ "$BACKEND_READY" -ne 1 ]; then
  echo "[dev] ❌ PHP 后端健康检查超时 (端口 ${PHP_PORT})，日志: ${BACKEND_LOG}" >&2
  cat "$BACKEND_LOG" >&2 || true
  exit 1
fi

print_start_urls() {
  local php_port="$1"
  local vite_port="${2:-}"
  local start_state="$PROJECT_ROOT/.hw-state/start.json"
  [ -f "$start_state" ] || return 0
  command -v python3 >/dev/null 2>&1 || return 0
  python3 - "$start_state" "$php_port" "$vite_port" "$ADMIN_UI_REL" << 'PY'
import json, sys
from pathlib import Path

state = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
kind = state.get('kind', '')
php_port = sys.argv[2]
vite_port = sys.argv[3]
ui_rel = sys.argv[4]

print('================================================')
if kind == 'website':
    if vite_port:
        print(f'[dev] 官网前台预览: http://127.0.0.1:{vite_port}/admin/site/index.html')
    print(f'[dev] 后端正式首页: http://127.0.0.1:{php_port}/')
    if vite_port:
        print(f'[dev] 业务管理后台: http://127.0.0.1:{vite_port}/admin/')
    else:
        print(f'[dev] 业务管理后台: http://127.0.0.1:{php_port}/admin/')
elif kind == 'admin':
    target = f'http://127.0.0.1:{vite_port}/admin/' if vite_port else f'http://127.0.0.1:{php_port}/admin/'
    print(f'[dev] 业务管理后台: {target}')
elif kind == 'miniapp':
    target = f'http://127.0.0.1:{vite_port}/admin/' if vite_port else f'http://127.0.0.1:{php_port}/admin/'
    print(f'[dev] 业务管理后台: {target}')
    print(f'[dev] 小程序编译命令: pnpm --dir {ui_rel}/mobile dev:mp')
    print(f'[dev] 微信开发者工具导入目录: {ui_rel}/mobile/dist/dev/mp-weixin')
print('[dev] 默认超管账号: admin / Admin12345')
print('================================================')
PY
}

if [ "$BACKEND_ONLY" -eq 0 ] && command -v pnpm >/dev/null 2>&1 && command -v node >/dev/null 2>&1 && [ -d "$ADMIN_UI_DIR/node_modules" ]; then
  VITE_PORT="$(resolve_port_conflict "$VITE_PORT" "Vite 前端" 0)"
  print_start_urls "$PHP_PORT" "$VITE_PORT"
  echo "[dev] 启动 Vite 前端服务: http://127.0.0.1:${VITE_PORT}/admin/"
  export VITE_HWADMIN_PROXY_TARGET="http://127.0.0.1:${PHP_PORT}"
  export PORT="${VITE_PORT}"
  cd "$ADMIN_UI_DIR"
  pnpm dev
else
  print_start_urls "$PHP_PORT" ""
  echo "[dev] 后端服务已在前台保持运行 (http://127.0.0.1:${PHP_PORT})；如需前端联调可在 Windows 下运行：pnpm --dir ${ADMIN_UI_REL} dev"
  wait "$PHP_PID"
fi
