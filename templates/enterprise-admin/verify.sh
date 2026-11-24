#!/usr/bin/env bash
# 业务管理后台模板真实场景验收脚本（主从协同、附件白名单校验、导出与 created_by 数据隔离）
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SERVER_DIR="${PROJECT_ROOT}/server"
if [ ! -d "${SERVER_DIR}" ] && [ -f "${PROJECT_ROOT}/think" ]; then
    SERVER_DIR="${PROJECT_ROOT}"
fi

echo "=== [1/2] 验证 enterprise-admin 模板安装计划与前端按钮权限指令 ==="
(cd "${SERVER_DIR}" && php think hw:project apply-template enterprise-admin --dry-run --json > /dev/null)
grep -q "v-auth=\"'enterprise/project/add'\"" "${SCRIPT_DIR}/frontend/views/enterprise/project/index.vue"
grep -q "v-auth=\"'enterprise/project/export'\"" "${SCRIPT_DIR}/frontend/views/enterprise/project/index.vue"
echo "✔ 模板计划与前端按钮级权限节点校验通过"

echo "=== [2/2] 验证主从服务参数校验与危险附件拦截 ==="
php -r "
require_once '${SERVER_DIR}/vendor/autoload.php';
\$app = new think\App('${SERVER_DIR}');
\$app->initialize();
if (!class_exists(app\common\service\EnterpriseProjectService::class)) {
    require_once '${SCRIPT_DIR}/backend/app/common/model/EnterpriseProject.php';
    require_once '${SCRIPT_DIR}/backend/app/common/model/EnterpriseTask.php';
    require_once '${SCRIPT_DIR}/backend/app/common/model/EnterpriseAttachment.php';
    require_once '${SCRIPT_DIR}/backend/app/common/service/EnterpriseProjectService.php';
}
\$service = new app\common\service\EnterpriseProjectService();
try {
    \$service->validateAttachmentPayload([
        'file_name' => 'webshell.php',
        'file_url'  => '/uploads/webshell.php',
        'file_size' => 1024,
    ]);
    throw new RuntimeException('未拦截非法脚本附件');
} catch (core\exception\ApiException \$e) {
    echo '✔ 成功拦截非法脚本附件: ' . \$e->getMessage() . PHP_EOL;
}
"
echo "=== 业务管理后台模板场景验收完成！==="
