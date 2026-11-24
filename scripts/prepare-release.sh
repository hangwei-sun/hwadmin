#!/usr/bin/env bash
# 在 Windows 的 admin-ui 中先运行 pnpm vue-tsc --noEmit、pnpm test:core 和 pnpm build，
# 再从 WSL 执行 bash scripts/prepare-release.sh [--mode=deploy|dev|all] [v1.3.0]。
set -euo pipefail
export PATH="$HOME/.local/bin:$PATH"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_VERSION=$(python3 -c "import re, pathlib; m = re.search(r\"'version'\s*=>\s*'([^']+)'\", pathlib.Path('$ROOT/server/config/hwadmin.php').read_text(encoding='utf-8')); print('v' + m.group(1) if m else 'v1.6.3')")

MODE="unified"
VERSION="$DEFAULT_VERSION"

for arg in "$@"; do
  if [[ "$arg" =~ ^--mode=(unified|deploy|dev|all)$ ]]; then
    MODE="${BASH_REMATCH[1]}"
  elif [[ "$arg" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    VERSION="$arg"
  fi
done

for cmd in rsync python3; do
  command -v "$cmd" >/dev/null || { echo "缺少必要工具: $cmd" >&2; exit 1; }
done

if [[ "$MODE" == "dev" ]]; then
  echo "⏩ 仅构建 Agent 开发包 (dev)，跳过生产 Composer 隔离安装..."
  python3 "$ROOT/scripts/build-release.py" --mode=dev --version "$VERSION" --output "$ROOT/release"
  exit 0
fi

for cmd in php composer; do
  command -v "$cmd" >/dev/null || { echo "缺少必要工具: $cmd" >&2; exit 1; }
done

[[ -s "$ROOT/admin-ui/dist/index.html" ]] || { echo '缺少前端构建产物，请先运行 pnpm build' >&2; exit 1; }
[[ -f "$ROOT/server/composer.lock" ]] || { echo '缺少 composer.lock，不能构建可复现的生产依赖' >&2; exit 1; }
[[ -f "$ROOT/server/public/uploads/.htaccess" ]] || { echo '缺少上传目录安全配置' >&2; exit 1; }

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/hwadmin-release.XXXXXXXX")"
trap 'rm -rf -- "$STAGE"' EXIT
mkdir -p "$STAGE/server/public/uploads" "$STAGE/server/public/admin"
# 不把任何运行数据、开发依赖、旧前端构建或临时安装凭据带入隔离副本。
rsync -a \
  --exclude='/vendor/' --exclude='/runtime/' --exclude='/public/admin/' \
  --exclude='/public/uploads/' --exclude='/public/plugins/' \
  --include='.env.dev.sqlite.example' --exclude='.env*' --exclude='install.lock*' --exclude='install.token*' \
  --exclude='*.sqlite' --exclude='*.sqlite3' --exclude='*.db' \
  --exclude='*.sqlite-*' --exclude='*.sqlite3-*' --exclude='*.db-*' \
  "$ROOT/server/" "$STAGE/server/"
cp -- "$ROOT/server/public/uploads/.htaccess" "$STAGE/server/public/uploads/.htaccess"
if [[ -f "$ROOT/server/public/uploads/.gitkeep" ]]; then
  cp -- "$ROOT/server/public/uploads/.gitkeep" "$STAGE/server/public/uploads/.gitkeep"
else
  : > "$STAGE/server/public/uploads/.gitkeep"
fi
rsync -a -- "$ROOT/admin-ui/dist/" "$STAGE/server/public/admin/"

(
  cd "$STAGE/server"
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
  composer audit --no-dev --no-interaction
  composer check-platform-reqs --no-dev
)
python3 "$ROOT/scripts/build-release.py" --mode="$MODE" --source "$STAGE/server" --version "$VERSION" --output "$ROOT/release"
