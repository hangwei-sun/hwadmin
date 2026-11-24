#!/usr/bin/env bash
# 官网起步模板真实场景验收脚本（栏目/文章/产品发布、SEO/Sitemap/Robots 与在线询盘）
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SERVER_DIR="${PROJECT_ROOT}/server"
if [ ! -d "${SERVER_DIR}" ] && [ -f "${PROJECT_ROOT}/think" ]; then
    SERVER_DIR="${PROJECT_ROOT}"
fi

echo "=== [1/3] 验证 corporate-site 模板安装计划与源文件完整性 ==="
(cd "${SERVER_DIR}" && php think hw:project apply-template corporate-site --dry-run --json > /dev/null)
echo "✔ 模板计划与源文件预检通过"

echo "=== [2/3] 验证文章/产品 SEO 元数据、Sitemap XML、Robots 与询盘业务规则 ==="
php -r "
require_once '${SERVER_DIR}/vendor/autoload.php';
\$app = new think\App('${SERVER_DIR}');
\$app->initialize();
if (!class_exists(app\common\service\SiteArticleService::class)) {
    require_once '${SCRIPT_DIR}/backend/app/common/model/SiteCategory.php';
    require_once '${SCRIPT_DIR}/backend/app/common/model/SiteArticle.php';
    require_once '${SCRIPT_DIR}/backend/app/common/model/SiteInquiry.php';
    require_once '${SCRIPT_DIR}/backend/app/common/service/SiteArticleService.php';
}
\$service = new app\common\service\SiteArticleService();
\$seo = \$service->formatSeoMetadata([
    'id' => 10,
    'type' => 'product',
    'title' => 'AI 智能网关',
    'slug' => 'ai-gateway',
    'summary' => '企业级智能网关',
    'seo_title' => 'AI 智能网关 - HwAdmin',
], 'https://example.com');
if (\$seo['canonical'] !== 'https://example.com/product/ai-gateway') {
    throw new RuntimeException('Canonical 生成错误: ' . \$seo['canonical']);
}
\$robots = \$service->buildRobots('https://example.com');
if (!str_contains(\$robots['content'], 'Sitemap: https://example.com/api/site/sitemap')) {
    throw new RuntimeException('Robots.txt 缺失 Sitemap 声明');
}
try {
    \$service->submitInquiry(['name' => '', 'mobile' => '', 'content' => '']);
    throw new RuntimeException('未拦截空数据');
} catch (core\exception\ApiException \$e) {
    echo '✔ 成功拦截非法询盘并完成 SEO/Robots 校验: ' . \$e->getMessage() . PHP_EOL;
}
"

echo "=== [3/3] 企业官网模板场景验收完成！==="
