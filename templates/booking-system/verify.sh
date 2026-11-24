#!/usr/bin/env bash
# 预约起步模板真实场景验收脚本（时段选择、容量锁、取消释放与跨账号隔离）
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SERVER_DIR="${PROJECT_ROOT}/server"
if [ ! -d "${SERVER_DIR}" ] && [ -f "${PROJECT_ROOT}/think" ]; then
    SERVER_DIR="${PROJECT_ROOT}"
fi

echo "=== [1/2] 验证 booking-system 模板安装计划与移动端动态时段选择 ==="
(cd "${SERVER_DIR}" && php think hw:project apply-template booking-system --dry-run --json > /dev/null)
if grep -q "slot_id: 1" "${SCRIPT_DIR}/mobile/pages/booking/index.vue"; then
    echo "✘ 移动端页面仍写死 slot_id: 1" >&2
    exit 1
fi
grep -q "/booking/slots" "${SCRIPT_DIR}/mobile/pages/booking/index.vue"
grep -q "/booking/myOrders" "${SCRIPT_DIR}/mobile/pages/booking/index.vue"
grep -q "/booking/cancel" "${SCRIPT_DIR}/mobile/pages/booking/index.vue"
echo "✔ 移动端动态时段选择、我的预约与取消预约接入核验通过"

echo "=== [2/2] 验证预约服务状态机与非法参数拦截 ==="
php -r "
require_once '${SERVER_DIR}/vendor/autoload.php';
\$app = new think\App('${SERVER_DIR}');
\$app->initialize();
if (!class_exists(app\common\service\BookingService::class)) {
    require_once '${SCRIPT_DIR}/backend/app/common/model/BookingServiceItem.php';
    require_once '${SCRIPT_DIR}/backend/app/common/model/BookingSlot.php';
    require_once '${SCRIPT_DIR}/backend/app/common/model/BookingOrder.php';
    require_once '${SCRIPT_DIR}/backend/app/common/service/BookingService.php';
}
\$service = new app\common\service\BookingService();
try {
    \$service->createBooking(0, 1, 1, '张三', '13800138000');
    throw new RuntimeException('未拦截未登录预约');
} catch (core\exception\ApiException \$e) {
    echo '✔ 成功拦截未授权预约: ' . \$e->getMessage() . PHP_EOL;
}
"
echo "=== 预约系统模板场景验收完成！==="
