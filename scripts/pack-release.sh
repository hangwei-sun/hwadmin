#!/usr/bin/env bash
# ==============================================================================
# HwAdmin 发布包一键构建入口
# 支持:
#   bash scripts/pack-release.sh [--mode=unified|deploy|dev|all] [v1.6.8]
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$SCRIPT_DIR/prepare-release.sh" "$@"
