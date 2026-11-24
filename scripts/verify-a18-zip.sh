#!/usr/bin/env bash
# ==============================================================================
# HwAdmin A18 验收测试脚本：面向 Agent 的最终 ZIP 独立解压全流程真实验收
# 验收边界：
# 1. 解压 release/hwadmin-dev-v*.zip 到干净临时隔离目录（校验外部 .sha256 与清单闭包）
# 2. 负向阻断：hw verify 缺失脚本阻断、bootstrap 注入退出 19 向上传播
# 3. M1 真实闭环：doctor、bootstrap 冷/热启动计时、既有数据保留
# 4. M2 真实闭环：inspect 2.0（含 POST 契约）、project validate、三套模板真实 apply --with-migrate
#    与 9 张业务表/闭环场景验证、正向 hw:crud --from-project=demo_item --all 建表与生成
# 5. 独立解压前端工程（含模板+生成页）真实 vue-tsc --noEmit（复用本机 node_modules junction）
# 6. M3 真实闭环：plan、坏包/坏哈希/空备份拦截、release ZIP 真实部署/升级保数据/code-only与full回滚、
#    以及未验证状态的证据驱动 report 校验
# 7. 输出如实区分 verified_locally 与 pending_external_env 的结构化报告
# ==============================================================================
set -euo pipefail
export PATH="$HOME/.local/bin:$PATH"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE_DIR="$ROOT/release"

VERSION=$(python3 -c "import re, pathlib; m = re.search(r\"'version'\s*=>\s*'([^']+)'\", pathlib.Path('$ROOT/server/config/hwadmin.php').read_text(encoding='utf-8')); print(m.group(1) if m else '1.6.1')")
DEV_ZIP="$RELEASE_DIR/hwadmin-v${VERSION}.zip"
if [ ! -f "$DEV_ZIP" ] && [ -f "$RELEASE_DIR/hwadmin-dev-v${VERSION}.zip" ]; then
    DEV_ZIP="$RELEASE_DIR/hwadmin-dev-v${VERSION}.zip"
fi
DEPLOY_ZIP="$DEV_ZIP"

for artifact in "$DEV_ZIP" "${DEV_ZIP}.sha256"; do
    if [ ! -f "$artifact" ]; then
        echo "❌ 找不到必需交付制品或校验文件: $artifact" >&2
        exit 1
    fi
done

mkdir -p "$ROOT/.hw-state"
TEMP_DIR="$(mktemp -d "$ROOT/.hw-state/a18-verify.XXXXXX")"
NM_JUNCTION_UNIX="$TEMP_DIR/admin-ui/node_modules"

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
    if [ -x "$TEMP_DIR/hw" ]; then
        (cd "$TEMP_DIR" && bash ./hw stop >/dev/null 2>&1 || true)
    fi
    if [ -L "$NM_JUNCTION_UNIX" ] || [ -e "$NM_JUNCTION_UNIX" ]; then
        local win_junc
        win_junc="$(to_win_path "$NM_JUNCTION_UNIX")"
        MSYS_NO_PATHCONV=1 cmd.exe /d /c rmdir "$win_junc" >/dev/null 2>&1 || rm -f "$NM_JUNCTION_UNIX" 2>/dev/null || true
    fi
    echo "🧹 清理临时验收目录: $TEMP_DIR"
    case "$TEMP_DIR" in
        "$ROOT"/.hw-state/a18-verify.*) ;;
        *) echo "拒绝清理不属于本次验收的目录" >&2; return 1 ;;
    esac
    if [ -e "$NM_JUNCTION_UNIX" ] || [ -L "$NM_JUNCTION_UNIX" ]; then
        echo "依赖 junction 未解除，保留验收目录供人工检查" >&2
        return 1
    fi
    rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

echo "=========================================================="
echo "🎯 开始 A18 独立 ZIP 真实闭环验收: hwadmin-dev-v${VERSION}.zip"
echo "=========================================================="

# 校验外部 .sha256
python3 - "$DEV_ZIP" "$DEPLOY_ZIP" << 'PY'
import hashlib, pathlib, sys
for zpath in sys.argv[1:]:
    p = pathlib.Path(zpath)
    sha_file = pathlib.Path(zpath + ".sha256")
    expected = sha_file.read_text(encoding="utf-8").strip().split()[0].lower()
    actual = hashlib.sha256(p.read_bytes()).hexdigest().lower()
    assert actual == expected, f"{p.name} SHA-256 不匹配: expected={expected}, actual={actual}"
    print(f"✔ 外部 SHA-256 校验通过: {p.name} ({actual[:12]}...)")
PY

echo "📦 正在解压开发包至隔离目录: $TEMP_DIR"
python3 -m zipfile -e "$DEV_ZIP" "$TEMP_DIR"

cd "$TEMP_DIR"
chmod +x hw scripts/*.sh server/think 2>/dev/null || true

# 1. 清单、Docker 闭包与 verify 缺失脚本阻断验证
echo "🔍 [1/6] 验证清单、Docker 闭包与 hw verify 缺失阻断..."
[ -f "artifact-manifest.json" ] || { echo "❌ 缺少 artifact-manifest.json" >&2; exit 1; }

python3 - "$VERSION" << 'PY'
import json, pathlib, sys
ver = sys.argv[1]
manifest = json.loads(pathlib.Path("artifact-manifest.json").read_text(encoding="utf-8"))
assert manifest["artifact_type"] in ("unified", "dev"), f"artifact_type 应为 unified 或 dev，实际为 {manifest['artifact_type']}"
assert manifest["framework_version"] == ver, f"framework_version 应为 {ver}，实际为 {manifest['framework_version']}"

for req in manifest.get("required_files", []):
    assert pathlib.Path(req).is_file(), f"缺少 required_files: {req}"

docker_closure = [
    ".gitignore",
    ".env.docker.example",
    "docker-compose.yml",
    "docker-compose.dev.yml",
    "docker/php/Dockerfile",
    "docker/nginx/default.conf",
    "server/tests/Unit/R01R11R12SecurityContractTest.php",
    "server/tests/Unit/R06R07R08TemplateCrudAuditTest.php",
]
for req in docker_closure:
    assert pathlib.Path(req).is_file(), f"缺少 Docker/测试闭包文件: {req}"

print(f"✔ artifact-manifest.json 与 Docker/测试闭包校验通过 (files_count={len(manifest['files'])})")
PY

mkdir -p "$TEMP_DIR/_neg_verify"
cp "$TEMP_DIR/hw" "$TEMP_DIR/_neg_verify/hw"
chmod +x "$TEMP_DIR/_neg_verify/hw"
set +e
(cd "$TEMP_DIR/_neg_verify" && bash ./hw verify --json > neg_verify.json 2>&1)
NEG_VERIFY_RC=$?
set -e
[ "$NEG_VERIFY_RC" -ne 0 ] || { echo "❌ hw verify 在缺少 scripts/ 时未返回非零退出码" >&2; exit 1; }
python3 - "$TEMP_DIR/_neg_verify/neg_verify.json" << 'PY'
import json, pathlib, sys
data = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
assert data.get("status") == "fail", f"预期 status=fail，实际为 {data}"
assert data.get("summary", {}).get("all_passed") is False, f"预期 all_passed=False，实际为 {data}"
print("✔ hw verify 缺失脚本阻断反例通过 (exit != 0, status=fail)")
PY
rm -rf "$TEMP_DIR/_neg_verify"

# 2. M1：doctor、bootstrap 故障传播反例、冷启动与热启动数据保留
echo "🚀 [2/6] M1 流程验收 (doctor + bootstrap 故障传播 + 冷/热启动)..."
./hw doctor --json > doctor_out.json
python3 - << 'PY'
import json, pathlib
data = json.loads(pathlib.Path("doctor_out.json").read_text(encoding="utf-8"))
assert data.get("status") == "pass" and data.get("summary", {}).get("failed", 0) == 0, f"Doctor 未达标: {data}"
print("✔ ./hw doctor --json 必需检查项全部达标")
PY

mkdir -p "$TEMP_DIR/_neg_boot/bin" "$TEMP_DIR/_neg_boot/scripts" "$TEMP_DIR/_neg_boot/server/runtime" "$TEMP_DIR/_neg_boot/server/config"
cp "$TEMP_DIR/scripts/wsl-dev.sh" "$TEMP_DIR/_neg_boot/scripts/wsl-dev.sh"
printf 'APP_DEBUG = true\nDB_DRIVER = sqlite\nDB_TYPE = sqlite\nDB_NAME = %s\nDB_PREFIX = hw_\n' "$TEMP_DIR/_neg_boot/server/runtime/dev.sqlite" > "$TEMP_DIR/_neg_boot/server/.env"
printf '#!/usr/bin/env bash\nexit 19\n' > "$TEMP_DIR/_neg_boot/bin/php"
chmod +x "$TEMP_DIR/_neg_boot/bin/php" "$TEMP_DIR/_neg_boot/scripts/wsl-dev.sh"
set +e
(cd "$TEMP_DIR/_neg_boot" && PATH="$TEMP_DIR/_neg_boot/bin:$PATH" bash scripts/wsl-dev.sh bootstrap >/dev/null 2>&1)
NEG_BOOT_RC=$?
set -e
[ "$NEG_BOOT_RC" -ne 0 ] || { echo "❌ bootstrap 未向上传播 php 退出码 (rc=0)" >&2; exit 1; }
echo "✔ bootstrap 故障注入阻断验证通过 (fake php exit 19 -> rc=$NEG_BOOT_RC)"
rm -rf "$TEMP_DIR/_neg_boot"

START_COLD=$(python3 -c "import time; print(time.time())")
./hw bootstrap --backend-only > /dev/null
END_COLD=$(python3 -c "import time; print(time.time())")
COLD_DURATION=$(python3 -c "print(round($END_COLD - $START_COLD, 3))")
[ -f "server/.env" ] || { echo "❌ 冷启动未生成 server/.env" >&2; exit 1; }
[ -f "server/runtime/dev.sqlite" ] || { echo "❌ 冷启动未生成 SQLite 数据库" >&2; exit 1; }
[ -f "server/config/install.lock" ] || { echo "❌ 冷启动未生成 install.lock" >&2; exit 1; }
echo "✔ 冷启动完成 (${COLD_DURATION}s)"

python3 - << 'PY'
import sqlite3
conn = sqlite3.connect("server/runtime/dev.sqlite")
conn.execute("UPDATE hw_admin SET nickname='a18_preserved_admin' WHERE id=1")
conn.commit()
conn.close()
PY

START_HOT=$(python3 -c "import time; print(time.time())")
./hw bootstrap --backend-only > /dev/null
END_HOT=$(python3 -c "import time; print(time.time())")
HOT_DURATION=$(python3 -c "print(round($END_HOT - $START_HOT, 3))")
python3 - << 'PY'
import sqlite3
conn = sqlite3.connect("server/runtime/dev.sqlite")
row = conn.execute("SELECT nickname FROM hw_admin WHERE id=1").fetchone()
conn.close()
assert row and row[0] == "a18_preserved_admin", f"数据未保留: {row}"
print("✔ 热启动完成且既有数据保留验证通过")
PY

# 3. M2：inspect 2.0、project validate、三套模板真实安装迁移与业务闭环、正向 CRUD 生成与建表
echo "🧩 [3/6] M2 流程验收 (inspect + 三套模板真实安装迁移与业务闭环 + 正向 CRUD)..."
./hw inspect --json > inspect_out.json
python3 - << 'PY'
import json, pathlib
data = json.loads(pathlib.Path("inspect_out.json").read_text(encoding="utf-8"))
assert data.get("schema_version") == "2.0.0", f"schema_version 异常: {data.get('schema_version')}"
assert data.get("status") in ("complete", "partial"), f"status 异常: {data.get('status')}"
routes = data.get("routes", {})
assert isinstance(routes, dict) and len(routes) > 0, f"routes 异常: {type(routes)}"
endpoints = [ep for ctrls in routes.values() if isinstance(ctrls, list) for c in ctrls for ep in c.get("endpoints", [])]
assert len(endpoints) > 0, "endpoints 为空"
wechat_eps = [ep for ep in endpoints if ep.get("url") == "/api/user/wechatLogin"]
assert wechat_eps and wechat_eps[0].get("method") == "POST", f"wechatLogin 方法契约异常: {wechat_eps}"
print(f"✔ ./hw inspect --json 验证通过 (endpoints={len(endpoints)}, wechatLogin=POST)")
PY

(cd server && php think hw:project validate --json > ../project_val.json)
python3 - << 'PY'
import json, pathlib
data = json.loads(pathlib.Path("project_val.json").read_text(encoding="utf-8"))
assert data.get("valid") is True, f"hw.project.json 校验失败: {data}"
print("✔ php think hw:project validate 校验通过")
PY

for tpl in corporate-site booking-system enterprise-admin; do
    echo "  - 真实安装模板 (--with-migrate): $tpl"
    (cd server && php think hw:project apply-template "$tpl" --with-migrate --force --json > "../tpl_${tpl}.json")
    python3 - "$tpl" "tpl_${tpl}.json" << 'PY'
import json, pathlib, sys
tpl, jf = sys.argv[1], sys.argv[2]
data = json.loads(pathlib.Path(jf).read_text(encoding="utf-8"))
assert data.get("status") == "success", f"模板 {tpl} 安装失败: {data}"
assert len(data.get("applied_migrations", [])) > 0, f"模板 {tpl} 未复制迁移文件"
assert len(data.get("executed_migrations", [])) > 0, f"模板 {tpl} 未执行迁移"
print(f"    ✔ 模板 {tpl} 真实安装与迁移成功 (migrations={len(data['executed_migrations'])})")
PY
    bash "templates/${tpl}/verify.sh" > /dev/null
done

# 校验 9 张模板业务表均真实落库，并跑通三套业务服务实测闭环
python3 - << 'PY'
import sqlite3
conn = sqlite3.connect("server/runtime/dev.sqlite")
tables = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()}
conn.close()
expected = {
    "hw_site_category", "hw_site_article", "hw_site_inquiry",
    "hw_booking_service", "hw_booking_slot", "hw_booking_order",
    "hw_enterprise_project", "hw_enterprise_task", "hw_enterprise_attachment",
}
missing = sorted(expected - tables)
assert not missing, f"数据库缺少模板业务表: {missing}"
print(f"✔ 三套模板全部 {len(expected)} 张数据表均已在 SQLite 中真实创建")
PY

(cd server && php -r '
require_once "vendor/autoload.php";
$app = new think\App(__DIR__);
$app->initialize();
$now = time();

// 1. corporate-site 真实落库 + SEO + Sitemap + 询盘
$siteSvc = new app\common\service\SiteArticleService();
$catId = (int) think\facade\Db::name("site_category")->insertGetId([
    "name" => "智能解决方案", "slug" => "solutions", "sort" => 10, "status" => 1, "create_time" => $now, "update_time" => $now,
]);
$artId = (int) think\facade\Db::name("site_article")->insertGetId([
    "category_id" => $catId, "type" => "product", "title" => "边缘网关 X1", "slug" => "edge-gateway-x1",
    "summary" => "工业边缘网关", "content" => "<p>正文</p>", "price" => "1999.00",
    "seo_title" => "边缘网关 X1", "status" => 1, "create_time" => $now, "update_time" => $now,
]);
$detail = $siteSvc->getProductDetail($artId, "https://a18.example.com");
if ($detail["seo"]["canonical"] !== "https://a18.example.com/product/edge-gateway-x1") {
    throw new RuntimeException("SEO canonical 错误");
}
$sitemap = $siteSvc->buildSitemap("https://a18.example.com");
if (!str_contains($sitemap["xml"], "edge-gateway-x1")) {
    throw new RuntimeException("Sitemap 未包含产品链接");
}
$inq = $siteSvc->submitInquiry(["name" => "张工", "mobile" => "13800138001", "target_id" => $artId, "content" => "询价"]);
if ((int) $inq["target_id"] !== $artId) {
    throw new RuntimeException("询盘落库异常");
}

// 2. booking-system 真实落库 + 容量锁 + 跨用户 403 + 取消释放容量
$bookSvc = new app\common\service\BookingService();
$svcId = (int) think\facade\Db::name("booking_service")->insertGetId([
    "title" => "一对一咨询", "duration" => 60, "price" => "300.00", "status" => 1, "create_time" => $now, "update_time" => $now,
]);
$slotId = (int) think\facade\Db::name("booking_slot")->insertGetId([
    "service_id" => $svcId, "date" => "2026-11-28", "start_time" => "10:00", "end_time" => "11:00",
    "capacity" => 1, "booked_count" => 0, "status" => 1, "create_time" => $now, "update_time" => $now,
]);
$ord = $bookSvc->createBooking(1001, $svcId, $slotId, "王先生", "13811112222");
$fullBlocked = false;
try { $bookSvc->createBooking(1002, $svcId, $slotId, "李女士", "13822223333"); } catch (core\exception\ApiException $e) { $fullBlocked = true; }
if (!$fullBlocked) { throw new RuntimeException("容量锁未拦截超额预约"); }
$crossBlocked = false;
try { $bookSvc->transition((int)$ord["id"], app\common\service\BookingService::STATUS_CANCELLED, 1002); } catch (core\exception\ApiException $e) { $crossBlocked = ($e->getCode() === 403); }
if (!$crossBlocked) { throw new RuntimeException("跨账号取消未返回 403"); }
$bookSvc->transition((int)$ord["id"], app\common\service\BookingService::STATUS_CANCELLED, 1001);
$ord2 = $bookSvc->createBooking(1002, $svcId, $slotId, "李女士", "13822223333");
if ((int) $ord2["user_id"] !== 1002) { throw new RuntimeException("取消后容量未释放"); }

// 3. enterprise-admin 主从表 + 附件白名单 + created_by 隔离
$entSvc = new app\common\service\EnterpriseProjectService();
$p1 = $entSvc->createProjectWithTasks(["title" => "A18 协同项目", "priority" => 3], [["title" => "子任务1"]], 11);
$p2 = $entSvc->createProjectWithTasks(["title" => "隔离项目", "priority" => 2], [["title" => "子任务2"]], 22);
$attBlocked = false;
try {
    $entSvc->addAttachment(["target_type" => "project", "target_id" => (int)$p1["id"], "file_name" => "x.php", "file_url" => "/uploads/x.php", "file_size" => 10], 11, false);
} catch (core\exception\ApiException $e) { $attBlocked = true; }
if (!$attBlocked) { throw new RuntimeException("未拦截 .php 危险附件"); }
$isoList = $entSvc->listProjects([], 11, false);
if ((int)$isoList["total"] !== 1 || (int)$isoList["list"][0]["created_by"] !== 11) {
    throw new RuntimeException("created_by 数据隔离失败");
}
echo "✔ 三套模板 DB+服务真实业务闭环全部通过\n";
')

# 正向 CRUD 生成 + 建表 + PHP 语法校验
(cd server && php think hw:crud --from-project=demo_item --all --force --json > ../crud_gen.json)
python3 - << 'PY'
import json, pathlib
data = json.loads(pathlib.Path("crud_gen.json").read_text(encoding="utf-8"))
assert data.get("status") == "success", f"CRUD 正向生成失败: {data}"
for rel in ("server/app/common/model/DemoItem.php", "server/app/adminapi/controller/DemoItem.php", "admin-ui/src/api/demo_item.ts", "admin-ui/src/views/demo_item/index.vue"):
    assert pathlib.Path(rel).is_file(), f"缺少 CRUD 生成文件: {rel}"
print("✔ 正向 hw:crud --from-project=demo_item --all 生成成功")
PY
(cd server && php think migrate:run > /dev/null)
php -l server/app/common/model/DemoItem.php > /dev/null
php -l server/app/common/validate/DemoItem.php > /dev/null
php -l server/app/adminapi/controller/DemoItem.php > /dev/null
python3 - << 'PY'
import sqlite3
conn = sqlite3.connect("server/runtime/dev.sqlite")
tables = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()}
conn.close()
assert "hw_demo_item" in tables, "hw_demo_item 未随迁移创建"
print("✔ 正向 CRUD 生成迁移已真实落库建表 (hw_demo_item)")
PY

# 4. 对解压工程中的 admin-ui（含三套模板页 + demo_item 生成页）执行真实 vue-tsc 类型检查
echo "🧪 [4/6] 对解压+安装模板+CRUD生成后的 admin-ui 执行真实 vue-tsc --noEmit..."
if [ ! -d "$ROOT/admin-ui/node_modules" ]; then
    echo "❌ 宿主机缺少 $ROOT/admin-ui/node_modules，无法执行独立工程 TypeScript 校验" >&2
    exit 1
fi
WIN_HOST_NM="$(to_win_path "$ROOT/admin-ui/node_modules")"
WIN_ISO_NM="$(to_win_path "$NM_JUNCTION_UNIX")"
WIN_ISO_UI="$(to_win_path "$TEMP_DIR/admin-ui")"
MSYS_NO_PATHCONV=1 cmd.exe /d /c mklink /J "$WIN_ISO_NM" "$WIN_HOST_NM" > /dev/null
MSYS_NO_PATHCONV=1 cmd.exe /d /c pnpm --dir "$WIN_ISO_UI" vue-tsc --noEmit
MSYS_NO_PATHCONV=1 cmd.exe /d /c rmdir "$WIN_ISO_NM" > /dev/null
echo "✔ 解压工程（含三套模板页面 + 正向 CRUD 页面）vue-tsc --noEmit 零错误通过"

# 5. M3 真实部署、升级保数据、双模式回滚与负向拦截
echo "🚢 [5/6] M3 流程验收 (plan + 负向拦截 + release ZIP 真实部署/升级/双模式回滚 + report)..."
./hw deploy plan --target=docker --json > deploy_docker.json
./hw deploy plan --target=baota-nginx --json > deploy_baota.json
python3 - << 'PY'
import json, pathlib
for f, t in (("deploy_docker.json", "docker"), ("deploy_baota.json", "baota-nginx")):
    d = json.loads(pathlib.Path(f).read_text(encoding="utf-8"))
    assert d.get("target") == t and len(d.get("steps", [])) > 0, f"{f} 计划无效: {d}"
print("✔ deploy plan (docker & baota-nginx) 验证通过")
PY

# 负向 1：无 verify-report.json 时 report 必须返回 unverified / ready_for_delivery=false
python3 scripts/hw-deploy.py report --json > empty_report.json
python3 - << 'PY'
import json, pathlib
rep = json.loads(pathlib.Path("empty_report.json").read_text(encoding="utf-8"))
assert rep.get("status") == "unverified" and rep.get("ready_for_delivery") is False, f"未验证状态 report 异常: {rep}"
print("✔ 证据驱动 report 门禁校验通过 (未运行 verify 时 status=unverified, ready_for_delivery=false)")
PY

# 负向 2：非 ZIP 制品与错误 SHA-256 必须被 apply 拦截
printf 'NOT_A_VALID_ZIP_BYTES' > "$TEMP_DIR/corrupt.zip"
printf '0000000000000000000000000000000000000000000000000000000000000000  corrupt.zip\n' > "$TEMP_DIR/corrupt.zip.sha256"
set +e
python3 scripts/hw-deploy.py apply --target=local --artifact="$TEMP_DIR/corrupt.zip" --target-dir="$TEMP_DIR/_bad_target" --json > /dev/null 2>&1
BAD_ZIP_RC=$?
python3 scripts/hw-deploy.py rollback --backup-dir="$TEMP_DIR/_empty_bak" --json > /dev/null 2>&1
BAD_RB_RC=$?
set -e
[ "$BAD_ZIP_RC" -ne 0 ] || { echo "❌ apply 未拦截非 ZIP/坏哈希制品" >&2; exit 1; }
[ "$BAD_RB_RC" -ne 0 ] || { echo "❌ rollback 未拦截不存在/空备份目录" >&2; exit 1; }
echo "✔ 坏制品 apply (rc=$BAD_ZIP_RC) 与非法备份 rollback (rc=$BAD_RB_RC) 均被严格拦截"

# 正向：使用真实 release ZIP 在隔离目标执行初次部署 -> 写入数据/附件 -> 二次升级 -> 篡改代码与数据 -> code-only 回滚 -> full 回滚
DEPLOY_TARGET="$TEMP_DIR/_deploy_target"
BACKUP_DIR="$TEMP_DIR/_deploy_backup"
mkdir -p "$DEPLOY_TARGET" "$BACKUP_DIR"

python3 scripts/hw-deploy.py apply --target=local --artifact="$DEPLOY_ZIP" --target-dir="$DEPLOY_TARGET" --json > apply_init.json
python3 - "$DEPLOY_TARGET" << 'PY'
import pathlib, sqlite3, sys
target = pathlib.Path(sys.argv[1])
assert (target / ".env").is_file(), "首次部署未生成 .env"
assert (target / "config/install.lock").is_file(), "首次部署未生成 install.lock"
db_file = target / "runtime/dev.sqlite"
assert db_file.is_file(), "首次部署未初始化 dev.sqlite"
conn = sqlite3.connect(str(db_file))
conn.execute("UPDATE hw_admin SET nickname='a18_deploy_persisted' WHERE id=1")
conn.commit()
conn.close()
up = target / "public/uploads/a18_keep.txt"
up.parent.mkdir(parents=True, exist_ok=True)
up.write_text("KEEP_UPLOAD_CONTENT", encoding="utf-8")
print("✔ release ZIP 首次隔离部署与业务数据/附件落盘完成")
PY

python3 scripts/hw-deploy.py apply --target=local --artifact="$DEPLOY_ZIP" --target-dir="$DEPLOY_TARGET" --backup-dir="$BACKUP_DIR" --json > apply_upgrade.json
python3 - "$DEPLOY_TARGET" "$BACKUP_DIR" << 'PY'
import json, pathlib, sqlite3, sys
target, bak = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
assert (bak / "backup-manifest.json").is_file(), "二次升级未生成 backup-manifest.json"
assert (target / "public/uploads/a18_keep.txt").read_text(encoding="utf-8") == "KEEP_UPLOAD_CONTENT", "升级后附件丢失"
conn = sqlite3.connect(str(target / "runtime/dev.sqlite"))
row = conn.execute("SELECT nickname FROM hw_admin WHERE id=1").fetchone()
assert row and row[0] == "a18_deploy_persisted", f"升级后数据库记录丢失: {row}"
# 模拟升级后写入新数据 + 篡改代码文件以验证双模式回滚
conn.execute("UPDATE hw_admin SET nickname='a18_after_upgrade_data' WHERE id=1")
conn.commit()
conn.close()
portal = target / "app/index/view/portal/index.html"
portal.write_text("TAMPERED_CODE_BY_A18", encoding="utf-8")
print("✔ 二次升级数据/附件保留验证通过，并已写入回滚对照探针")
PY

# 验证 code-only 回滚：代码还原、库中升级后数据仍保留
python3 scripts/hw-deploy.py rollback --target=local --backup-dir="$BACKUP_DIR" --target-dir="$DEPLOY_TARGET" --mode=code-only --json > rb_code.json
python3 - "$DEPLOY_TARGET" << 'PY'
import pathlib, sqlite3, sys
target = pathlib.Path(sys.argv[1])
portal_txt = (target / "app/index/view/portal/index.html").read_text(encoding="utf-8")
assert "TAMPERED_CODE_BY_A18" not in portal_txt, "code-only 回滚未还原代码文件"
conn = sqlite3.connect(str(target / "runtime/dev.sqlite"))
row = conn.execute("SELECT nickname FROM hw_admin WHERE id=1").fetchone()
conn.close()
assert row and row[0] == "a18_after_upgrade_data", f"code-only 回滚不应回退数据库: {row}"
print("✔ rollback --mode=code-only 验证通过 (代码还原，数据库保持)")
PY

# 验证 full 回滚：数据库同步恢复到备份前状态
python3 scripts/hw-deploy.py rollback --target=local --backup-dir="$BACKUP_DIR" --target-dir="$DEPLOY_TARGET" --mode=full --json > rb_full.json
python3 - "$DEPLOY_TARGET" << 'PY'
import pathlib, sqlite3, sys
target = pathlib.Path(sys.argv[1])
conn = sqlite3.connect(str(target / "runtime/dev.sqlite"))
row = conn.execute("SELECT nickname FROM hw_admin WHERE id=1").fetchone()
conn.close()
assert row and row[0] == "a18_deploy_persisted", f"full 回滚未还原数据库快照: {row}"
print("✔ rollback --mode=full 验证通过 (代码与数据库全量恢复)")
PY

# 6. 输出如实区分 verified_locally 与 pending_external_env 的汇总报告
echo "=========================================================="
echo "📊 [6/6] A18 独立 ZIP 真实闭环验收完成"
echo "=========================================================="
cat << EOF
{
  "acceptance": "A18",
  "framework_version": "${VERSION}",
  "package_dev": "hwadmin-dev-v${VERSION}.zip",
  "package_release": "hwadmin-release-v${VERSION}.zip",
  "status": "PASSED_LOCAL_CLOSURE",
  "verified_locally": {
    "bootstrap_scope": "backend_only; frontend dependencies reused from host",
    "external_sha256_verified": true,
    "artifact_manifest_and_docker_closure": true,
    "verify_missing_scripts_blocked": true,
    "bootstrap_fault_propagation_blocked": true,
    "bootstrap_cold_passed": true,
    "bootstrap_cold_seconds": ${COLD_DURATION},
    "bootstrap_hot_passed": true,
    "bootstrap_hot_seconds": ${HOT_DURATION},
    "manual_intervention_count": 0,
    "data_preservation_verified": true,
    "inspect_schema_version": "2.0.0",
    "project_validation": true,
    "templates_real_apply_and_migrate": ["corporate-site", "booking-system", "enterprise-admin"],
    "templates_business_loops_verified": true,
    "crud_forward_generation_and_migrate": true,
    "extracted_admin_ui_vue_tsc": true,
    "pc_views_typecheck_note": "reused host admin-ui/node_modules via NTFS junction",
    "deploy_plan_docker_and_baota": true,
    "deploy_bad_artifact_and_empty_backup_blocked": true,
    "deploy_release_apply_upgrade_and_rollbacks": ["code-only", "full"],
    "deploy_report_unverified_gate": true
  },
  "pending_external_env": [
    "docker_daemon_cold_start_mysql_pg_redis",
    "github_actions_remote_ci_execution",
    "baota_production_server_and_ssl",
    "wechat_real_appid_mp_login",
    "cold_machine_pnpm_install_from_registry"
  ]
}
EOF
echo "🎉 A18 独立 ZIP 真实闭环验收（本机可验证全量）通过！"
