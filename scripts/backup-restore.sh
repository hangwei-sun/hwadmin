#!/usr/bin/env bash
set -euo pipefail
export PATH="$HOME/.local/bin:$PATH"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
command -v python3 >/dev/null || { echo '需要 Python 3.10+ 执行安全归档校验与恢复' >&2; exit 1; }
exec python3 "$SCRIPT_DIR/backup_restore.py" "$@"
