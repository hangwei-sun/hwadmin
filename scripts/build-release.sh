#!/usr/bin/env bash
# ==============================================================================
# HwAdmin 一键面向大众开放下载的发行版构建打包脚本
# 输出目标：release/hwadmin-v{version}.zip & release/hwadmin-latest.zip
# 排除规则：.git, node_modules, Vite-vue, .env*, install.lock/token, *.sqlite, tests, runtime 缓存
# ==============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER_DIR="$ROOT/server"
RELEASE_DIR="$ROOT/release"

# 1. 解析目标版本号 (优先读取传入参数，其次读取 server/version.json)
DEFAULT_VER="1.6.4"
if [ -f "$SERVER_DIR/version.json" ]; then
    DETECTED_VER=$(grep -o '"version": *"[^"]*"' "$SERVER_DIR/version.json" | cut -d'"' -f4 || true)
    if [ -n "$DETECTED_VER" ]; then
        DEFAULT_VER="$DETECTED_VER"
    fi
fi

RAW_VERSION="${1:-$DEFAULT_VER}"
VERSION="${RAW_VERSION#v}" # 统一为纯数字版本，如 1.3.0

echo "=========================================================="
echo "🚀 开始构建 HwAdmin v${VERSION} 标准生产发行安装包..."
echo "=========================================================="

# 2. 检查前置工具
for tool in zip python3; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "❌ 错误: 缺少必要命令行工具: $tool" >&2
        exit 1
    fi
done

# 3. 准备隔离构建暂存目录
mkdir -p "$RELEASE_DIR"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/hwadmin-dist.XXXXXXXX")"
trap 'rm -rf -- "$STAGE"' EXIT

echo "📦 正在复制服务端生产源码至隔离构建空间..."
mkdir -p "$STAGE/hwadmin"

# 使用 rsync 干净复制 server 目录到暂存区，严格过滤敏感与开发文件
rsync -a \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='node_modules/' \
    --exclude='Vite-vue/' \
    --exclude='admin-ui/' \
    --exclude='.env*' \
    --exclude='*.sqlite*' \
    --exclude='*.db*' \
    --exclude='install.lock' \
    --exclude='install.token' \
    --exclude='runtime/cache/*' \
    --exclude='runtime/log/*' \
    --exclude='runtime/temp/*' \
    --exclude='runtime/backups/*' \
    --exclude='tests/' \
    --exclude='worker.php' \
    "$SERVER_DIR/" "$STAGE/hwadmin/"

# 确保运行时关键目录占位存在
mkdir -p "$STAGE/hwadmin/runtime/cache" \
         "$STAGE/hwadmin/runtime/log" \
         "$STAGE/hwadmin/runtime/temp" \
         "$STAGE/hwadmin/public/uploads" \
         "$STAGE/hwadmin/public/plugins"

touch "$STAGE/hwadmin/runtime/.gitkeep"

# 4. 执行 ZIP 打包
TARGET_ZIP="$RELEASE_DIR/hwadmin-v${VERSION}.zip"
LATEST_ZIP="$RELEASE_DIR/hwadmin-latest.zip"
rm -f "$TARGET_ZIP" "$LATEST_ZIP"

echo "🗜️  正在压缩打包生产发行包..."
(
    cd "$STAGE"
    zip -q -r -9 "$TARGET_ZIP" "hwadmin"
)

# 同步复制生成 latest.zip
cp -f "$TARGET_ZIP" "$LATEST_ZIP"

# 5. 计算并生成 SHA-256 校验文件
SHA256_VAL=$(python3 -c "import hashlib; print(hashlib.sha256(open('$TARGET_ZIP', 'rb').read()).hexdigest())")
echo "$SHA256_VAL  hwadmin-v${VERSION}.zip" > "$RELEASE_DIR/hwadmin-v${VERSION}.zip.sha256"
echo "$SHA256_VAL  hwadmin-latest.zip" > "$RELEASE_DIR/hwadmin-latest.zip.sha256"

# 6. 计算体积并输出结果
ZIP_SIZE=$(du -h "$TARGET_ZIP" | cut -f1)

echo "----------------------------------------------------------"
echo "🎉 HwAdmin 生产发行包构建完成！"
echo "📁 发行版本文件: release/hwadmin-v${VERSION}.zip (${ZIP_SIZE})"
echo "📁 最新通用镜像: release/hwadmin-latest.zip (${ZIP_SIZE})"
echo "🔑 SHA-256 校验: ${SHA256_VAL}"
echo "✨ 用户解压后即可直接访问 Web 向导 (/install/) 完成免配置安装！"
echo "=========================================================="
