#!/usr/bin/env bash
# ==============================================================================
# HwAdmin 高性能极速后端服务启动器（统一委托至 scripts/wsl-dev.sh serve-fast）
# 1. 按项目路径哈希隔离至 /tmp/hwadmin-serve-<hash>
# 2. 后台秒级增量同步源码改动，实时生效
# 3. 共享真实数据库 dev.sqlite、上传目录 uploads、静态资源 admin
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$SCRIPT_DIR/wsl-dev.sh" serve-fast "$@"
