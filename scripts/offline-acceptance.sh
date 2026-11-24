#!/usr/bin/env bash
# 在隔离网络命名空间内运行发布包的 SQLite 冒烟测试。
# 用法：bash scripts/offline-acceptance.sh [发布包路径]
set -euo pipefail

# 自动将本地 static-php 与 composer 所在路径加入 PATH
export PATH="$HOME/.local/bin:$PATH"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_VERSION=$(python3 -c "import re, pathlib; m = re.search(r\"'version'\s*=>\s*'([^']+)'\", pathlib.Path('$ROOT/server/config/hwadmin.php').read_text(encoding='utf-8')); print('v' + m.group(1) if m else 'v1.1.1')")
ARCHIVE="${1:-$ROOT/release/hwadmin-release-${DEFAULT_VERSION}.zip}"
for cmd in unshare ip python3 strace rsync curl php; do
    command -v "$cmd" >/dev/null || { echo "缺少命令: $cmd" >&2; exit 1; }
done
[ -f "$ARCHIVE" ] || { echo "发布包不存在: $ARCHIVE" >&2; exit 1; }
[ -f "$ARCHIVE.sha256" ] || { echo "发布包缺少 SHA-256 校验文件" >&2; exit 1; }
(cd "$(dirname "$ARCHIVE")" && sha256sum -c "$(basename "$ARCHIVE").sha256")

WORK="$(mktemp -d /tmp/hwadmin-offline-accept-XXXXXX)"
python3 -m zipfile -e "$ARCHIVE" "$WORK/server"
python3 - "$WORK/server" <<'PY'
import hashlib, json, pathlib, sys
root = pathlib.Path(sys.argv[1])
manifest = json.loads((root / 'manifest.json').read_text())
for relative, expected in manifest['files'].items():
    if hashlib.sha256((root / relative).read_bytes()).hexdigest() != expected:
        raise SystemExit('制品内部校验失败: ' + relative)
print('制品版本及逐文件清单校验通过:', manifest['version'])
PY
# 冒烟测试会清理上传文件和测试插件，因此只允许它操作全新解压的临时目录。
rsync -a "$ROOT/server/tests/" "$WORK/server/tests/"
mkdir -p "$WORK/scripts"
cp "$ROOT/scripts/wsl-dev.sh" "$WORK/scripts/wsl-dev.sh"
find "$WORK/server/tests" -type f -print0 | sort -z | xargs -0 sha256sum > "$WORK/test-sources.sha256"
echo "隔离测试工作目录: $WORK"

unshare -Urnpf --mount-proc bash -euo pipefail -c '
    work="$1"
    export PATH="$HOME/.local/bin:$PATH"
    ip link set lo up
    echo "隔离网络接口:"
    ip -brief address
    [ "$(ip -o link show | wc -l)" -eq 1 ] && ip -o link show lo >/dev/null || {
        echo "存在回环之外的网卡" >&2; exit 1;
    }
    if ip route get 1.1.1.1 >/dev/null 2>&1; then
        echo "隔离命名空间存在外部路由" >&2
        exit 1
    fi
    # 在临时解压目录放置无害占位文件，验证旧授权脚本即使存在也不能被开发服务提供。
    printf "blocked-test\n" > "$work/server/public/admin/static/js/index-DRB4f58U.js"
    (cd "$work/server" && php -S 127.0.0.1:28786 -t public public/router.php >/dev/null 2>&1) &
    guard_pid=$!
    for unused in 1 2 3 4 5; do
        if curl -s -o /dev/null "http://127.0.0.1:28786/admin/"; then break; fi
        sleep 1
    done
    blocked_status=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:28786/admin/static/js/index-DRB4f58U.js")
    kill "$guard_pid" 2>/dev/null || true
    wait "$guard_pid" 2>/dev/null || true
    [ "$blocked_status" = 404 ] || { echo "旧授权脚本拦截失败: $blocked_status" >&2; exit 1; }
    echo "旧授权脚本访问拦截通过"
    cd "$work"
    bash scripts/wsl-dev.sh lint
    export SMOKE_PORT=28787
    set +e
    strace -f -e trace=connect,sendto,sendmsg,sendmmsg -o "$work/network.trace" \
        bash scripts/wsl-dev.sh smoke 2>&1 | tee "$work/smoke.log"
    smoke_status=${PIPESTATUS[0]}
    set -e
    python3 - "$work/network.trace" <<"PY"
import ipaddress, pathlib, re, sys
lines = pathlib.Path(sys.argv[1]).read_text(errors="replace").splitlines()
ip_operations = [line for line in lines if ("AF_INET," in line or "AF_INET6," in line)]
external = []
for line in ip_operations:
    match = re.search(r"inet_addr\(\"([^\"]+)\"\)", line) or re.search(r"inet_pton\(AF_INET6, \"([^\"]+)\"", line)
    try:
        if not match or not ipaddress.ip_address(match.group(1)).is_loopback:
            external.append(line)
    except ValueError:
        external.append(line)
print(f"网络连接审计：IP 目标操作 {len(ip_operations)} 次，非回环目标 {len(external)} 次")
if external:
    print("\n".join(external))
    raise SystemExit(1)
PY
    [ "$smoke_status" -eq 0 ] || { echo "冒烟测试失败（退出码 $smoke_status）" >&2; exit "$smoke_status"; }
' _ "$WORK"
echo "后端断网冒烟及出站连接审计通过；日志: $WORK"
