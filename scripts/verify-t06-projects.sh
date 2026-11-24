#!/usr/bin/env bash
# T06 三个独立业务项目需求规范与增量二次开发端到端验证脚本
# 从最终开发 ZIP 分别解压出 3 个完全独立的工作区：
#   1) ws-corporate-site（企业官网 + 内容与询盘门户）
#   2) ws-enterprise-admin（业务管理后台）
#   3) ws-booking-system（预约系统小程序 + 管理后台）
# 每个工作区依次执行：首次模板安装迁移与业务验证 -> 真实增量业务需求开发与迁移升级
# （验证已有业务数据、手写 @hw-custom-start 逻辑、跨端契约、权限隔离及真实构建不被破坏）。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

for cmd in python3 php bash; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "❌ 缺少必要命令: $cmd" >&2
        exit 1
    fi
done

mkdir -p "$ROOT/.hw-state"
TEMP_DIR="$(mktemp -d "$ROOT/.hw-state/t06-verify.XXXXXX")"
declare -a ACTIVE_JUNCTIONS=()

to_win_path() {
    local p="$1"
    if command -v wslpath >/dev/null 2>&1; then
        wslpath -w "$p"
    else
        python3 -c "import sys, re; p=sys.argv[1]; m=re.match(r'^/mnt/([a-zA-Z])/(.*)$', p); print(f'{m.group(1).upper()}:\\\\' + m.group(2).replace('/', '\\\\')) if m else print(p)" "$p"
    fi
}

cleanup() {
    set +e
    for junc in "${ACTIVE_JUNCTIONS[@]:-}"; do
        if [ -n "$junc" ] && { [ -L "$junc" ] || [ -e "$junc" ]; }; then
            local win_junc
            win_junc="$(to_win_path "$junc")"
            MSYS_NO_PATHCONV=1 cmd.exe /d /c rmdir "$win_junc" >/dev/null 2>&1 || rm -f "$junc" 2>/dev/null || true
        fi
    done
    case "$TEMP_DIR" in
        "$ROOT"/.hw-state/t06-verify.*) ;;
        *) echo "拒绝清理非本次验收目录: $TEMP_DIR" >&2; return 1 ;;
    esac
    for junc in "${ACTIVE_JUNCTIONS[@]:-}"; do
        if [ -n "$junc" ] && { [ -L "$junc" ] || [ -e "$junc" ]; }; then
            echo "❌ 依赖 junction 未解除，跳过删除 $TEMP_DIR: $junc" >&2
            return 1
        fi
    done
    rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

run_win_pnpm_check() {
    local ws="$1"
    local mode="$2" # pc | mobile

    if [ "$mode" = "pc" ]; then
        [ -d "$ROOT/admin-ui/node_modules" ] || { echo "❌ 宿主机缺少 $ROOT/admin-ui/node_modules" >&2; exit 1; }
        local host_nm="$ROOT/admin-ui/node_modules"
        local iso_nm="$ws/admin-ui/node_modules"
        if [ ! -e "$iso_nm" ]; then
            MSYS_NO_PATHCONV=1 cmd.exe /d /c mklink /J "$(to_win_path "$iso_nm")" "$(to_win_path "$host_nm")" > /dev/null
            ACTIVE_JUNCTIONS+=("$iso_nm")
        fi
        MSYS_NO_PATHCONV=1 cmd.exe /d /c pnpm --dir "$(to_win_path "$ws/admin-ui")" vue-tsc --noEmit
    elif [ "$mode" = "mobile" ]; then
        [ -d "$ROOT/admin-ui/mobile/node_modules" ] || { echo "❌ 宿主机缺少 $ROOT/admin-ui/mobile/node_modules" >&2; exit 1; }
        local host_nm="$ROOT/admin-ui/mobile/node_modules"
        local iso_nm="$ws/admin-ui/mobile/node_modules"
        if [ ! -e "$iso_nm" ]; then
            MSYS_NO_PATHCONV=1 cmd.exe /d /c mklink /J "$(to_win_path "$iso_nm")" "$(to_win_path "$host_nm")" > /dev/null
            ACTIVE_JUNCTIONS+=("$iso_nm")
        fi
        local win_mobile
        win_mobile="$(to_win_path "$ws/admin-ui/mobile")"
        MSYS_NO_PATHCONV=1 cmd.exe /d /c pnpm --dir "$win_mobile" type-check
        MSYS_NO_PATHCONV=1 cmd.exe /d /c pnpm --dir "$win_mobile" build
    fi
}

echo "=========================================================="
echo "🎯 开始 T06 三独立业务项目（首次安装 + 增量开发）验证"
echo "=========================================================="

# 1. 构建/获取最终开发 ZIP
DEV_ZIP="${DEV_ZIP_OVERRIDE:-}"
if [ -z "$DEV_ZIP" ]; then
    ARTIFACT_DIR="$TEMP_DIR/_artifact"
    mkdir -p "$ARTIFACT_DIR"
    echo "📦 [0/3] 打包当前最新统一发行包 ZIP (build-release.py --mode=unified)..."
    python3 "$ROOT/scripts/build-release.py" --mode=unified --output "$ARTIFACT_DIR" >/dev/null
    DEV_ZIP="$(ls -1 "$ARTIFACT_DIR"/hwadmin-v*.zip | head -n 1)"
fi
[ -f "$DEV_ZIP" ] || { echo "❌ 未找到开发/统一包 ZIP: $DEV_ZIP" >&2; exit 1; }
echo "✔ 使用制品: $(basename "$DEV_ZIP")"
python3 - "$ROOT" "$DEV_ZIP" <<'PY'
import importlib.util, pathlib, sys
sys.path.insert(0, str(pathlib.Path(sys.argv[1]) / 'scripts'))
spec = importlib.util.spec_from_file_location('t06_deploy', pathlib.Path(sys.argv[1]) / 'scripts/hw-deploy.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
ok, error, manifest = module.verify_artifact_strictly(pathlib.Path(sys.argv[2]))
if not ok:
    raise SystemExit(f'T06 ZIP integrity check failed: {error}')
if manifest.get('artifact_type') not in ('unified', 'dev'):
    raise SystemExit('T06 requires a unified or dev artifact')
print('T06 artifact_sha256=' + module.calculate_sha256(pathlib.Path(sys.argv[2])))
PY

extract_and_bootstrap() {
    local ws_name="$1"
    local tpl="$2"
    local ws="$TEMP_DIR/$ws_name"
    mkdir -p "$ws"
    python3 -m zipfile -e "$DEV_ZIP" "$ws"
    chmod +x "$ws/hw" "$ws"/scripts/*.sh "$ws/server/think" 2>/dev/null || true

    (cd "$ws" && bash ./hw bootstrap --backend-only >/dev/null)
    (cd "$ws/server" && php think hw:project apply-template "$tpl" --with-migrate --force --json > "$ws/apply_${tpl}.json")
    python3 - "$ws/apply_${tpl}.json" "$tpl" << 'PY'
import json, pathlib, sys
d = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
assert d.get("status") == "success", f"模板 {sys.argv[2]} 安装失败: {d}"
assert len(d.get("executed_migrations", [])) > 0, f"模板 {sys.argv[2]} 未执行迁移"
PY
    (cd "$ws" && bash "templates/${tpl}/verify.sh" >/dev/null)
    (cd "$ws/server" && php think hw:project validate --json > "$ws/val_base.json")
}

# ==========================================================
# 工作区 1：企业官网 + 内容与询盘门户 (corporate-site)
# ==========================================================
echo ""
echo "🌐 [1/3] 验证独立工作区: corporate-site（官网内容 + 增量案例行业筛选与询盘跟进）"
extract_and_bootstrap "ws-corporate-site" "corporate-site"
WS1="$TEMP_DIR/ws-corporate-site"

# Stage A：写入基线内容与询盘，并注入手写 @hw-custom-start 业务逻辑
HW_WS="$WS1" php -d zend.assertions=1 -d assert.exception=1 << 'PHP'
<?php
try {
$ws = getenv('HW_WS');
require_once $ws . '/server/vendor/autoload.php';
$app = new think\App($ws . '/server');
$app->initialize();
$now = time();

$catId = (int) think\facade\Db::name('site_category')->insertGetId([
    'name' => '金融案例', 'slug' => 'finance', 'sort' => 10, 'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);
$artId = (int) think\facade\Db::name('site_article')->insertGetId([
    'category_id' => $catId,
    'type' => 'case',
    'title' => '城商行数字中台案例',
    'slug' => 'city-bank-case',
    'summary' => '双库高可用架构案例',
    'content' => '<p>案例正文</p>',
    'seo_title' => '城商行数字中台案例 SEO',
    'seo_keywords' => '银行,中台,高可用',
    'seo_description' => '城商行数字化升级标杆案例',
    'canonical_url' => 'https://example.com/cases/city-bank-case',
    'status' => 1,
    'create_time' => $now,
    'update_time' => $now,
]);
$inqId = (int) think\facade\Db::name('site_inquiry')->insertGetId([
    'name' => '张经理', 'mobile' => '13800138000', 'email' => 'zhang@example.com',
    'company' => '华东数据', 'content' => '咨询私有化部署', 'status' => 0, 'remark' => '',
    'create_time' => $now, 'update_time' => $now,
]);
if ($catId <= 0 || $artId <= 0 || $inqId <= 0) {
    throw new \RuntimeException('Stage A corporate-site seed failed');
}
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP

# 在手写 @hw-custom-start 扩展区注入自定义逻辑以验证增量升级保留
python3 - "$WS1" << 'PY'
import pathlib, sys
ws = pathlib.Path(sys.argv[1])
svc = ws / "server/app/common/service/SiteArticleService.php"
txt = svc.read_text(encoding="utf-8")
marker = "/* @hw-custom-start: site-service-extensions */"
assert marker in txt, "缺少 site-service-extensions 标记"
txt = txt.replace(marker, marker + "\n    public function getCustomWatermark(): string { return 'CORP_CUSTOM_OK'; }")
svc.write_text(txt, encoding="utf-8")
PY

# Stage B：增量迁移（新增 site_article.industry）+ 行业筛选 + 询盘跟进流转 + SEO/Sitemap/Robots
cat > "$WS1/server/database/migrations/20261115200001_add_site_case_industry.php" << 'PHP'
<?php
declare(strict_types=1);

use think\migration\Migrator;

class AddSiteCaseIndustry extends Migrator
{
    public function up(): void
    {
        if ($this->hasTable('site_article')) {
            $table = $this->table('site_article');
            if (!$table->hasColumn('industry')) {
                $table->addColumn('industry', 'string', ['limit' => 64, 'default' => '', 'comment' => '案例所属行业'])
                    ->addIndex(['industry'])
                    ->update();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('site_article')) {
            $table = $this->table('site_article');
            if ($table->hasColumn('industry')) {
                $table->removeColumn('industry')->update();
            }
        }
    }
}
PHP
(cd "$WS1/server" && php think migrate:run >/dev/null)

# 在控制器与服务增量支持 industry 筛选并验证：历史数据不丢 + 自定义标记留存 + SEO/Sitemap/Robots + 询盘流转
HW_WS="$WS1" php -d zend.assertions=1 -d assert.exception=1 << 'PHP'
<?php
try {
$ws = getenv('HW_WS');
require_once $ws . '/server/vendor/autoload.php';
$app = new think\App($ws . '/server');
$app->initialize();
$now = time();

// 更新历史案例行业并新增制造行业案例
think\facade\Db::name('site_article')->where('slug', 'city-bank-case')->update(['industry' => '金融']);
think\facade\Db::name('site_article')->insert([
    'category_id' => 1, 'type' => 'case', 'title' => '智能工厂MES案例', 'slug' => 'smart-factory-mes',
    'summary' => '制造行业案例', 'content' => '<p>制造正文</p>', 'industry' => '制造',
    'seo_title' => '智能工厂MES', 'canonical_url' => 'https://example.com/cases/smart-factory-mes',
    'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);

// 验证历史数据留存与行业筛选
$finCases = think\facade\Db::name('site_article')->where('type', 'case')->where('industry', '金融')->select()->toArray();
assert(count($finCases) === 1 && $finCases[0]['slug'] === 'city-bank-case', '历史案例数据丢失或行业筛选异常');

// 验证 @hw-custom-start 保留逻辑可执行
$svc = new app\common\service\SiteArticleService();
assert(method_exists($svc, 'getCustomWatermark') && $svc->getCustomWatermark() === 'CORP_CUSTOM_OK', '@hw-custom-start 逻辑丢失');

// 验证询盘跟进与状态流转 (0 -> 1 -> 2)
$inqId = (int) think\facade\Db::name('site_inquiry')->where('mobile', '13800138000')->value('id');
$updated = $svc->followUpInquiry($inqId, 1, '已电话回访，约定周三演示');
assert((int) $updated['status'] === 1 && str_contains((string) $updated['remark'], '周三演示'));
$completed = $svc->followUpInquiry($inqId, 2, '签约完成');
assert((int) $completed['status'] === 2);

// 验证 SEO / Canonical / Sitemap / Robots 端点输出
$detail = $svc->getArticleDetail(0, 'https://example.com', '', 'city-bank-case');
$seo = $detail['seo'] ?? [];
assert(str_contains((string) ($seo['canonical'] ?? ''), 'city-bank-case'), 'SEO Canonical 异常');
$sitemapRes = $svc->buildSitemap('https://example.com');
$sitemap = (string) ($sitemapRes['xml'] ?? '');
assert(str_contains($sitemap, '<urlset') && str_contains($sitemap, 'city-bank-case'), 'Sitemap 缺少历史案例 URL');
$robotsRes = $svc->buildRobots('https://example.com');
$robots = (string) ($robotsRes['content'] ?? '');
assert(str_contains($robots, 'Sitemap: https://example.com/api/site/sitemap'), 'Robots 缺少 Sitemap 指引');

// 验证无障碍属性与响应式 CSS 在工作区前端页面存在
$artVue = file_get_contents($ws . '/admin-ui/src/views/site/article/index.vue');
$inqVue = file_get_contents($ws . '/admin-ui/src/views/site/inquiry/index.vue');
assert(str_contains($artVue, 'aria-label') && str_contains($artVue, '<el-empty') && str_contains($artVue, '@media (max-width: 768px)'));
assert(str_contains($inqVue, 'aria-label') && str_contains($inqVue, '<el-empty') && str_contains($inqVue, 'followUp'));
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP

(cd "$WS1/server" && php think hw:project validate --json > "$WS1/val_inc.json")
(cd "$WS1/server" && php think hw:project export-types --json > "$WS1/types_inc.json")
run_win_pnpm_check "$WS1" pc
echo "✔ 工作区 1 (corporate-site) 首次交付 + 增量升级 + 跨端契约 & vue-tsc 全部通过"

# ==========================================================
# 工作区 2：业务管理后台 (enterprise-admin)
# ==========================================================
echo ""
echo "🏢 [2/3] 验证独立工作区: enterprise-admin（项目台账 + 增量里程碑/客户关联/预算精度/403越权隔离）"
extract_and_bootstrap "ws-enterprise-admin" "enterprise-admin"
WS2="$TEMP_DIR/ws-enterprise-admin"

# Stage A：写入两个非超管操作员的基线项目，并写入 @hw-custom-start 逻辑
HW_WS="$WS2" php -d zend.assertions=1 -d assert.exception=1 << 'PHP'
<?php
try {
$ws = getenv('HW_WS');
require_once $ws . '/server/vendor/autoload.php';
$app = new think\App($ws . '/server');
$app->initialize();
$now = time();

$groupId = (int) think\facade\Db::name('auth_group')->insertGetId([
    'name' => '项目经理组', 'rules' => '1', 'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);
$salt = 'ent99';
$opA = (int) think\facade\Db::name('admin')->insertGetId([
    'username' => 'pm_alpha', 'nickname' => '经理甲', 'password' => sha1(md5('Pass123456') . $salt), 'salt' => $salt,
    'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);
$opB = (int) think\facade\Db::name('admin')->insertGetId([
    'username' => 'pm_beta', 'nickname' => '经理乙', 'password' => sha1(md5('Pass123456') . $salt), 'salt' => $salt,
    'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);
think\facade\Db::name('auth_group_access')->insertAll([
    ['admin_id' => $opA, 'group_id' => $groupId],
    ['admin_id' => $opB, 'group_id' => $groupId],
]);

$svc = new app\common\service\EnterpriseProjectService();
$resA = $svc->createProjectWithTasks(['title' => 'Alpha 基线项目', 'budget' => '188800.5', 'priority' => 1], [['title' => '需求冻结', 'assignee' => 'pm_alpha']], $opA);
$resB = $svc->createProjectWithTasks(['title' => 'Beta 保密项目', 'budget' => '520000.00', 'priority' => 2], [['title' => '架构评审', 'assignee' => 'pm_beta']], $opB);
assert($resA['budget'] === '188800.50');
assert((int) $resA['created_by'] === $opA && (int) $resB['created_by'] === $opB);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP

python3 - "$WS2" << 'PY'
import pathlib, sys
ws = pathlib.Path(sys.argv[1])
svc = ws / "server/app/common/service/EnterpriseProjectService.php"
txt = svc.read_text(encoding="utf-8")
marker = "/* @hw-custom-start: enterprise-service-extensions */"
assert marker in txt, "缺少 enterprise-service-extensions 标记"
txt = txt.replace(marker, marker + "\n    public function getCustomBadge(): string { return 'ENT_CUSTOM_OK'; }")
svc.write_text(txt, encoding="utf-8")
PY

# Stage B：增量迁移新增 customer_name 与 milestone_note
cat > "$WS2/server/database/migrations/20261115200002_add_enterprise_milestone_customer.php" << 'PHP'
<?php
declare(strict_types=1);

use think\migration\Migrator;

class AddEnterpriseMilestoneCustomer extends Migrator
{
    public function up(): void
    {
        if ($this->hasTable('enterprise_project')) {
            $table = $this->table('enterprise_project');
            if (!$table->hasColumn('customer_name')) {
                $table->addColumn('customer_name', 'string', ['limit' => 128, 'default' => '', 'comment' => '关联客户名称']);
            }
            if (!$table->hasColumn('milestone_note')) {
                $table->addColumn('milestone_note', 'string', ['limit' => 255, 'default' => '', 'comment' => '当前里程碑备注']);
            }
            $table->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('enterprise_project')) {
            $table = $this->table('enterprise_project');
            if ($table->hasColumn('milestone_note')) {
                $table->removeColumn('milestone_note');
            }
            if ($table->hasColumn('customer_name')) {
                $table->removeColumn('customer_name');
            }
            $table->update();
        }
    }
}
PHP
(cd "$WS2/server" && php think migrate:run >/dev/null)

HW_WS="$WS2" php -d zend.assertions=1 -d assert.exception=1 << 'PHP'
<?php
try {
$ws = getenv('HW_WS');
require_once $ws . '/server/vendor/autoload.php';
$app = new think\App($ws . '/server');
$app->initialize();

$opA = (int) think\facade\Db::name('admin')->where('username', 'pm_alpha')->value('id');
$opB = (int) think\facade\Db::name('admin')->where('username', 'pm_beta')->value('id');
$svc = new app\common\service\EnterpriseProjectService();
assert(method_exists($svc, 'getCustomBadge') && $svc->getCustomBadge() === 'ENT_CUSTOM_OK', '@hw-custom-start 逻辑丢失');

// 更新历史项目的增量字段（验证历史数据与关联任务完好保留）
$projA = think\facade\Db::name('enterprise_project')->where('title', 'Alpha 基线项目')->find();
$projB = think\facade\Db::name('enterprise_project')->where('title', 'Beta 保密项目')->find();
assert($projA && $projB);
think\facade\Db::name('enterprise_project')->where('id', (int) $projA['id'])->update([
    'customer_name' => '华润数字科技',
    'milestone_note' => 'M1 阶段里程碑已验收',
]);
$tasksA = think\facade\Db::name('enterprise_task')->where('project_id', (int) $projA['id'])->count();
assert((int) $tasksA === 1, '增量迁移破坏了原有子任务数据');

// 正反例 1：decimal(12,2) 预算精度校验
assert($svc::normalizeBudget('9999999999.99') === '9999999999.99');
$invalidRejected = false;
try {
    $svc::normalizeBudget('123.456');
} catch (core\exception\ApiException $e) {
    $invalidRejected = true;
}
assert($invalidRejected, '三位小数预算未被 decimal(12,2) 校验拦截');

// 正反例 2：非超管导出仅限本人数据
$exportA = $svc->exportProjects([], $opA, false);
assert($exportA['total'] === 1 && str_contains($exportA['csv'], 'Alpha 基线项目') && !str_contains($exportA['csv'], 'Beta 保密项目'));

// 正反例 3：非超管越权删除他人项目必须 403 拒绝
$rejected403 = false;
try {
    $svc->deleteProject((int) $projB['id'], $opA, false);
} catch (core\exception\ApiException $e) {
    $rejected403 = ($e->getCode() === 403 || $e->getHttpStatus() === 403);
}
assert($rejected403, '非超管越权删除他人项目未返回 403');
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP

(cd "$WS2/server" && php think hw:project validate --json > "$WS2/val_inc.json")
(cd "$WS2/server" && php think hw:project export-types --json > "$WS2/types_inc.json")
run_win_pnpm_check "$WS2" pc
echo "✔ 工作区 2 (enterprise-admin) 首次交付 + 增量升级 + 越权隔离 & vue-tsc 全部通过"

# ==========================================================
# 工作区 3：预约小程序 + 管理后台 (booking-system)
# ==========================================================
echo ""
echo "📅 [3/3] 验证独立工作区: booking-system（在线预约 + 增量分类过滤/容量拦截/头像上传/跨账号403/双端构建）"
extract_and_bootstrap "ws-booking-system" "booking-system"
WS3="$TEMP_DIR/ws-booking-system"

# Stage A：写入基线服务、容量=1时段、两位会员、初始订单 + @hw-custom-start
HW_WS="$WS3" php -d zend.assertions=1 -d assert.exception=1 << 'PHP'
<?php
try {
$ws = getenv('HW_WS');
require_once $ws . '/server/vendor/autoload.php';
$app = new think\App($ws . '/server');
$app->initialize();
$now = time();

$svcId = (int) think\facade\Db::name('booking_service')->insertGetId([
    'title' => '企业架构咨询', 'cover' => '', 'duration' => 60, 'price' => '800.00',
    'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);
$slotId = (int) think\facade\Db::name('booking_slot')->insertGetId([
    'service_id' => $svcId, 'date' => '2026-12-01', 'start_time' => '10:00', 'end_time' => '11:00',
    'capacity' => 1, 'booked_count' => 0, 'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);
$salt = 'bk99';
$u1 = (int) think\facade\Db::name('user')->insertGetId([
    'username' => 'bk_member_1', 'nickname' => '预约会员1', 'password' => sha1(md5('Pass123456') . $salt),
    'salt' => $salt, 'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);
$u2 = (int) think\facade\Db::name('user')->insertGetId([
    'username' => 'bk_member_2', 'nickname' => '预约会员2', 'password' => sha1(md5('Pass123456') . $salt),
    'salt' => $salt, 'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);

$svc = new app\common\service\BookingService();
$order = $svc->createBooking($u1, $svcId, $slotId, '王先生', '13900139001', '基线初始预约');
assert((int) $order['id'] > 0);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP

python3 - "$WS3" << 'PY'
import pathlib, sys
ws = pathlib.Path(sys.argv[1])
svc = ws / "server/app/common/service/BookingService.php"
txt = svc.read_text(encoding="utf-8")
marker = "/* @hw-custom-start: booking-service-extensions */"
assert marker in txt, "缺少 booking-service-extensions 标记"
txt = txt.replace(marker, marker + "\n    public function getCustomPolicy(): string { return 'BOOK_CUSTOM_OK'; }")
svc.write_text(txt, encoding="utf-8")
PY

# Stage B：增量迁移为 booking_service 增加 category 分类列
cat > "$WS3/server/database/migrations/20261115200003_add_booking_service_category.php" << 'PHP'
<?php
declare(strict_types=1);

use think\migration\Migrator;

class AddBookingServiceCategory extends Migrator
{
    public function up(): void
    {
        if ($this->hasTable('booking_service')) {
            $table = $this->table('booking_service');
            if (!$table->hasColumn('category')) {
                $table->addColumn('category', 'string', ['limit' => 64, 'default' => '常规服务', 'comment' => '服务分类'])
                    ->addIndex(['category'])
                    ->update();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('booking_service')) {
            $table = $this->table('booking_service');
            if ($table->hasColumn('category')) {
                $table->removeColumn('category')->update();
            }
        }
    }
}
PHP
(cd "$WS3/server" && php think migrate:run >/dev/null)

HW_WS="$WS3" php -d zend.assertions=1 -d assert.exception=1 << 'PHP'
<?php
try {
$ws = getenv('HW_WS');
require_once $ws . '/server/vendor/autoload.php';
$app = new think\App($ws . '/server');
$app->initialize();
$now = time();

$u1 = (int) think\facade\Db::name('user')->where('username', 'bk_member_1')->value('id');
$u2 = (int) think\facade\Db::name('user')->where('username', 'bk_member_2')->value('id');
$svcId = (int) think\facade\Db::name('booking_service')->where('title', '企业架构咨询')->value('id');
$slotId = (int) think\facade\Db::name('booking_slot')->where('service_id', $svcId)->value('id');

think\facade\Db::name('booking_service')->where('id', $svcId)->update(['category' => '技术咨询']);
think\facade\Db::name('booking_service')->insert([
    'title' => 'UI 走查服务', 'category' => '设计评审', 'cover' => '', 'duration' => 45,
    'price' => '500.00', 'status' => 1, 'create_time' => $now, 'update_time' => $now,
]);

$svc = new app\common\service\BookingService();
assert(method_exists($svc, 'getCustomPolicy') && $svc->getCustomPolicy() === 'BOOK_CUSTOM_OK', '@hw-custom-start 逻辑丢失');

// 1. 分类过滤与历史订单保留
$techServices = $svc->getServices('技术咨询');
assert(count($techServices) === 1 && $techServices[0]['title'] === '企业架构咨询');
$u1Orders = $svc->getMemberOrders($u1);
assert($u1Orders['total'] === 1 && $u1Orders['list'][0]['remark'] === '基线初始预约');

// 2. 时段容量冲突拦截（容量=1 已被 u1 占满，u2 预约必须失败）
$capacityBlocked = false;
try {
    $svc->createBooking($u2, $svcId, $slotId, '李女士', '13900139002', '冲突抢约');
} catch (core\exception\ApiException $e) {
    $capacityBlocked = str_contains($e->getMessage(), '名额已满');
}
assert($capacityBlocked, '满额时段容量冲突未被拦截');

// 3. 跨账号取消预约 403 拒绝
$orderId = (int) $u1Orders['list'][0]['id'];
$crossBlocked403 = false;
try {
    $svc->transition($orderId, app\common\service\BookingService::STATUS_CANCELLED, $u2);
} catch (core\exception\ApiException $e) {
    $crossBlocked403 = ($e->getCode() === 403 || $e->getHttpStatus() === 403);
}
assert($crossBlocked403, '跨账号取消他人预约未被 403 拦截');

// 4. 校验会员上传头像端点路由 & 真图上传控制器可用
$userCtrlFile = file_get_contents($ws . '/server/app/api/controller/User.php');
assert(str_contains($userCtrlFile, 'public function upload()') && str_contains($userCtrlFile, 'getimagesize'));
$mobileBooking = file_get_contents($ws . '/admin-ui/mobile/src/pages/booking/index.vue');
assert(str_contains($mobileBooking, 'uploadAvatar') && !str_contains($mobileBooking, 'slot_id: 1'));
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP

(cd "$WS3/server" && php think hw:project validate --json > "$WS3/val_inc.json")
(cd "$WS3/server" && php think hw:project export-types --json > "$WS3/types_inc.json")
run_win_pnpm_check "$WS3" pc
run_win_pnpm_check "$WS3" mobile
echo "✔ 工作区 3 (booking-system) 首次交付 + 增量升级 + 冲突/越权拦截 + PC vue-tsc + 移动端 type-check & build 全部通过"

echo ""
echo "=========================================================="
echo "🎉 T06 三个隔离工作区的模板与增量业务脚本检查通过（不代表独立 Agent 需求交付）"
echo '{"status":"PASSED_SCRIPTED_WORKSPACES","workspaces":3,"independent_agent_verified":false,"cold_machine_verified":false}'
echo "=========================================================="
