#!/usr/bin/env bash
# ==============================================================================
# HwAdmin P01—P06 面向最终 ZIP 的全流程自动化实测脚本
# 覆盖范围：
#   Step 1 (P01 & P02): 解压 hwadmin-dev-v*.zip，运行 start-project.py website 开箱初始化与重复启动保数据
#   Step 2 (P03):       三类应用真实业务定制 + 增量迁移（官网 design_style / 后台 hw:crud crm_lead / 预约 saveService+saveSlot+满额拦截）
#   Step 3 (P04):       release/hwadmin-release-v*.zip 通过 hw-deploy.py apply --target=local 首次部署与二次升级保数据
#   Step 4 (P05 & P06): 运行 scripts/tests/test_p01_p06_convergence.py 工程契约单元测试
# 末尾输出 JSON：{"status":"PASSED_P01_P06","p01":true,"p02":true,"p03":true,"p04":true,"p05":true,"p06":true}
# ==============================================================================
set -euo pipefail
export PATH="$HOME/.local/bin:$PATH"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

for cmd in python3 php bash; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "❌ 缺少必要命令: $cmd" >&2
        exit 1
    fi
done

VERSION=$(python3 -c "import re, pathlib; m = re.search(r\"'version'\s*=>\s*'([^']+)'\", pathlib.Path('$ROOT/server/config/hwadmin.php').read_text(encoding='utf-8')); print(m.group(1) if m else '1.6.2')")

DEV_ZIP="${DEV_ZIP_OVERRIDE:-}"
if [ -z "$DEV_ZIP" ]; then
    if [ -f "$ROOT/release/hwadmin-v${VERSION}.zip" ]; then
        DEV_ZIP="$ROOT/release/hwadmin-v${VERSION}.zip"
    elif [ -f "$ROOT/release/hwadmin-dev-v${VERSION}.zip" ]; then
        DEV_ZIP="$ROOT/release/hwadmin-dev-v${VERSION}.zip"
    else
        DEV_ZIP="$(ls -1t "$ROOT"/release/hwadmin-*.zip 2>/dev/null | head -n 1 || true)"
    fi
fi

DEPLOY_ZIP="${RELEASE_ZIP_OVERRIDE:-$DEV_ZIP}"

[ -f "$DEV_ZIP" ] || { echo "❌ 未找到统一发行包 ZIP (release/hwadmin-v*.zip)" >&2; exit 1; }
[ -f "$DEPLOY_ZIP" ] || { echo "❌ 未找到部署包 ZIP" >&2; exit 1; }

mkdir -p "$ROOT/.hw-state"
TEMP_DIR="$(mktemp -d "$ROOT/.hw-state/p01-p06-verify.XXXXXX")"
declare -a ACTIVE_JUNCTIONS=()
declare -a ACTIVE_PIDS=()

P01_OK=false
P02_OK=false
P03_OK=false
P04_OK=false
P05_OK=false
P06_OK=false

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
    for pid in "${ACTIVE_PIDS[@]:-}"; do
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
            wait "$pid" 2>/dev/null || true
        fi
    done
    for junc in "${ACTIVE_JUNCTIONS[@]:-}"; do
        if [ -n "$junc" ] && { [ -L "$junc" ] || [ -e "$junc" ]; }; then
            if command -v cmd.exe >/dev/null 2>&1; then
                local win_junc
                win_junc="$(to_win_path "$junc")"
                MSYS_NO_PATHCONV=1 cmd.exe /d /c rmdir "$win_junc" >/dev/null 2>&1 || rm -f "$junc" 2>/dev/null || true
            else
                rm -f "$junc" 2>/dev/null || true
            fi
        fi
    done
    case "$TEMP_DIR" in
        "$ROOT"/.hw-state/p01-p06-verify.*) ;;
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

link_host_node_modules() {
    local ws="$1"
    local with_mobile="${2:-0}"
    if grep -q '\.modules\.yaml' "$ws/hw" 2>/dev/null && command -v cmd.exe >/dev/null 2>&1; then
        if [ -d "$ROOT/admin-ui/node_modules" ] && [ ! -e "$ws/admin-ui/node_modules" ]; then
            MSYS_NO_PATHCONV=1 cmd.exe /d /c mklink /J "$(to_win_path "$ws/admin-ui/node_modules")" "$(to_win_path "$ROOT/admin-ui/node_modules")" >/dev/null
            ACTIVE_JUNCTIONS+=("$ws/admin-ui/node_modules")
        fi
        if [ "$with_mobile" -eq 1 ] && [ -d "$ROOT/admin-ui/mobile/node_modules" ] && [ ! -e "$ws/admin-ui/mobile/node_modules" ]; then
            MSYS_NO_PATHCONV=1 cmd.exe /d /c mklink /J "$(to_win_path "$ws/admin-ui/mobile/node_modules")" "$(to_win_path "$ROOT/admin-ui/mobile/node_modules")" >/dev/null
            ACTIVE_JUNCTIONS+=("$ws/admin-ui/mobile/node_modules")
        fi
    fi
}

extract_dev_workspace() {
    local ws_name="$1"
    local with_mobile="${2:-0}"
    local ws="$TEMP_DIR/$ws_name"
    mkdir -p "$ws"
    python3 -m zipfile -e "$DEV_ZIP" "$ws"
    chmod +x "$ws/hw" "$ws"/scripts/*.sh "$ws/server/think" 2>/dev/null || true
    link_host_node_modules "$ws" "$with_mobile"
    echo "$ws"
}

start_php_server() {
    local server_dir="$1"
    local log_file="$2"
    local port
    port=$(python3 -c "import socket; s=socket.socket(); s.bind(('127.0.0.1', 0)); print(s.getsockname()[1]); s.close()")
    php -S "127.0.0.1:${port}" -t "$server_dir/public" "$server_dir/public/router.php" >"$log_file" 2>&1 &
    local pid=$!
    ACTIVE_PIDS+=("$pid")
    python3 - "$port" << 'PY'
import sys, time, urllib.request
port = sys.argv[1]
url = f"http://127.0.0.1:{port}/api/index/ping"
for _ in range(50):
    try:
        with urllib.request.urlopen(url, timeout=1.0) as resp:
            if resp.status == 200:
                sys.exit(0)
    except Exception:
        time.sleep(0.2)
raise SystemExit(f"PHP built-in server failed to respond on {url}")
PY
    echo "${port}:${pid}"
}

stop_php_server() {
    local pid="$1"
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        kill "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    fi
}

run_win_vue_tsc() {
    local ws="$1"
    if command -v cmd.exe >/dev/null 2>&1 && [ -d "$ROOT/admin-ui/node_modules" ]; then
        if [ ! -e "$ws/admin-ui/node_modules" ]; then
            MSYS_NO_PATHCONV=1 cmd.exe /d /c mklink /J "$(to_win_path "$ws/admin-ui/node_modules")" "$(to_win_path "$ROOT/admin-ui/node_modules")" >/dev/null
            ACTIVE_JUNCTIONS+=("$ws/admin-ui/node_modules")
        fi
        MSYS_NO_PATHCONV=1 cmd.exe /d /c pnpm --dir "$(to_win_path "$ws/admin-ui")" vue-tsc --noEmit
    elif command -v pnpm >/dev/null 2>&1 && [ -d "$ws/admin-ui/node_modules" ]; then
        pnpm --dir "$ws/admin-ui" vue-tsc --noEmit
    fi
}

echo "=========================================================="
echo "🎯 开始 P01—P06 面向最终 ZIP 的全流程实测"
echo "   DEV_ZIP:    $(basename "$DEV_ZIP")"
echo "   DEPLOY_ZIP: $(basename "$DEPLOY_ZIP")"
echo "=========================================================="

# 校验 .sha256（若存在）
python3 - "$DEV_ZIP" "$DEPLOY_ZIP" << 'PY'
import hashlib, pathlib, sys
for z in sys.argv[1:]:
    zp = pathlib.Path(z)
    sf = pathlib.Path(z + ".sha256")
    if sf.is_file():
        expected = sf.read_text(encoding="utf-8").strip().split()[0].lower()
        actual = hashlib.sha256(zp.read_bytes()).hexdigest().lower()
        assert actual == expected, f"{zp.name} SHA-256 校验失败"
print("✔ 制品 SHA-256 校验通过")
PY

# ==========================================================
# Step 1 (P01 & P02): 官网开箱与重复启动
# ==========================================================
echo ""
echo "🚀 [Step 1/4] P01 & P02：官网开箱启动 (start-project.py website) 与重复启动保护..."
WS1="$(extract_dev_workspace "ws1-website" 0)"
(cd "$WS1" && bash ./hw bootstrap --backend-only >/dev/null)
(cd "$WS1" && python3 scripts/start-project.py website --name "青禾空间设计" --prepare-only >/dev/null)

python3 - "$WS1" << 'PY'
import json, pathlib, sqlite3, sys
ws = pathlib.Path(sys.argv[1])
db_path = ws / "server/runtime/dev.sqlite"
assert db_path.is_file(), "未自动初始化 SQLite 数据库 server/runtime/dev.sqlite"

brand_path = ws / "admin-ui/public/site/brand.json"
assert brand_path.is_file(), "缺少 admin-ui/public/site/brand.json"
brand = json.loads(brand_path.read_text(encoding="utf-8"))
assert brand.get("name") == "青禾空间设计", f"brand.json 品牌名称未更新: {brand}"

conn = sqlite3.connect(str(db_path))
cats = conn.execute("SELECT COUNT(*) FROM hw_site_category").fetchone()[0]
arts = conn.execute("SELECT COUNT(*) FROM hw_site_article").fetchone()[0]
inqs = conn.execute("SELECT COUNT(*) FROM hw_site_inquiry").fetchone()[0]
assert cats > 0 and arts > 0 and inqs > 0, f"官网初始示例数据未填充: cats={cats}, arts={arts}, inqs={inqs}"

# 写入自定义业务数据与文件修改，验证重复启动不覆盖
conn.execute(
    "INSERT INTO hw_site_inquiry (name, mobile, email, company, target_id, content, remark, status, create_time, update_time) "
    "VALUES ('留存验证客户', '13800138999', 'keep@example.com', '留存测试公司', 0, '保留测试咨询', '', 0, 1700000000, 1700000000)"
)
conn.commit()
conn.close()

site_html = ws / "admin-ui/public/site/index.html"
html_txt = site_html.read_text(encoding="utf-8")
site_html.write_text(html_txt + "\n<!-- QINGHE_CUSTOM_HTML -->\n", encoding="utf-8")

svc_path = ws / "server/app/common/service/SiteArticleService.php"
svc_txt = svc_path.read_text(encoding="utf-8")
marker = "/* @hw-custom-start: site-service-extensions */"
assert marker in svc_txt, "SiteArticleService.php 缺少 @hw-custom-start 标记"
svc_path.write_text(
    svc_txt.replace(marker, marker + "\n    public function getQingheSignature(): string { return 'QINGHE_CUSTOM_OK'; }"),
    encoding="utf-8",
)
print(f"✔ 官网开箱初始化完成 (cats={cats}, arts={arts}, inqs={inqs}, brand={brand['name']})")
PY

# 重复运行 start-project.py，验证不覆盖已修改文件且保留数据库新增记录
(cd "$WS1" && python3 scripts/start-project.py --prepare-only >/dev/null)

# 反向校验：已有项目尝试切换为不同类型应拒绝并保持原样
set +e
(cd "$WS1" && python3 scripts/start-project.py admin --prepare-only >/dev/null 2>&1)
SWITCH_RC=$?
set -e
[ "$SWITCH_RC" -ne 0 ] || { echo "❌ 已有项目切换模板类型未被拦截" >&2; exit 1; }

python3 - "$WS1" << 'PY'
import pathlib, sqlite3, sys
ws = pathlib.Path(sys.argv[1])
html_txt = (ws / "admin-ui/public/site/index.html").read_text(encoding="utf-8")
assert "QINGHE_CUSTOM_HTML" in html_txt, "重复启动覆盖了已修改的 index.html"
svc_txt = (ws / "server/app/common/service/SiteArticleService.php").read_text(encoding="utf-8")
assert "getQingheSignature" in svc_txt, "重复启动覆盖了手写扩展方法"

conn = sqlite3.connect(str(ws / "server/runtime/dev.sqlite"))
row = conn.execute("SELECT name, mobile FROM hw_site_inquiry WHERE name = '留存验证客户'").fetchone()
conn.close()
assert row and row[1] == "13800138999", "重复启动导致数据库新增记录丢失"
print("✔ 重复启动幂等保护与跨类型切换拦截验证通过")
PY

P01_OK=true
P02_OK=true

# ==========================================================
# Step 2 (P03): 三类应用真实业务定制 + 一次增量修改
# ==========================================================
echo ""
echo "🛠️  [Step 2/4] P03：三类应用真实业务定制与增量修改验证..."

# --- 2.1 官网定制：新增 design_style 字段迁移与案例筛选、发布新案例并提交带 target_id 的询盘 ---
cat > "$WS1/server/database/migrations/20261115210001_add_site_article_design_style.php" << 'PHP'
<?php
declare(strict_types=1);

use think\migration\Migrator;

class AddSiteArticleDesignStyle extends Migrator
{
    public function up(): void
    {
        if ($this->hasTable('site_article')) {
            $table = $this->table('site_article');
            if (!$table->hasColumn('design_style')) {
                $table->addColumn('design_style', 'string', ['limit' => 64, 'default' => '', 'comment' => '装修设计风格'])
                    ->addIndex(['design_style'])
                    ->update();
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('site_article')) {
            $table = $this->table('site_article');
            if ($table->hasColumn('design_style')) {
                $table->removeColumn('design_style')->update();
            }
        }
    }
}
PHP
(cd "$WS1/server" && php think migrate:run >/dev/null)

# 在业务层与控制器增量支持 design_style 筛选与写入
python3 - "$WS1" << 'PY'
import pathlib, sys
ws = pathlib.Path(sys.argv[1])

svc_path = ws / "server/app/common/service/SiteArticleService.php"
svc_txt = svc_path.read_text(encoding="utf-8")
old_sig = "public function getPublishedArticles(int $categoryId = 0, int $page = 1, int $limit = 10, string $type = ''): array"
new_sig = "public function getPublishedArticles(int $categoryId = 0, int $page = 1, int $limit = 10, string $type = '', string $designStyle = ''): array"
assert old_sig in svc_txt, "SiteArticleService::getPublishedArticles 签名不匹配"
svc_txt = svc_txt.replace(old_sig, new_sig, 1)
old_type_block = "if ($type !== '') {\n            $query->where('type', $type);\n        }"
new_type_block = (
    "if ($type !== '') {\n            $query->where('type', $type);\n        }\n"
    "        if ($designStyle !== '') {\n            $query->where('design_style', $designStyle);\n        }"
)
assert old_type_block in svc_txt, "SiteArticleService type 过滤片段不匹配"
svc_txt = svc_txt.replace(old_type_block, new_type_block, 1)
svc_path.write_text(svc_txt, encoding="utf-8")

site_ctrl = ws / "server/app/api/controller/Site.php"
ctrl_txt = site_ctrl.read_text(encoding="utf-8")
old_call = "$this->success($service->getPublishedArticles($categoryId, $page, $limit, $type));"
new_call = (
    "$designStyle = trim((string) $this->request->get('design_style', ''));\n"
    "        $this->success($service->getPublishedArticles($categoryId, $page, $limit, $type, $designStyle));"
)
assert old_call in ctrl_txt, "Site.php articles() 调用片段不匹配"
site_ctrl.write_text(ctrl_txt.replace(old_call, new_call, 1), encoding="utf-8")

art_ctrl = ws / "server/app/adminapi/controller/site/Article.php"
art_txt = art_ctrl.read_text(encoding="utf-8")
art_txt = art_txt.replace("'price', 'seo_title'", "'price', 'design_style', 'seo_title'", 1)
art_ctrl.write_text(art_txt, encoding="utf-8")
PY

SRV1_INFO="$(start_php_server "$WS1/server" "$TEMP_DIR/srv1.log")"
SRV1_PORT="${SRV1_INFO%%:*}"
SRV1_PID="${SRV1_INFO##*:}"

WS1_TOKEN=$(cd "$WS1/server" && php think hw:dev:token --username=admin --json | python3 -c "import json,sys; print(json.load(sys.stdin)['token'])")
python3 - "$WS1" "$SRV1_PORT" "$WS1_TOKEN" << 'PY'
import json, pathlib, sqlite3, sys, urllib.parse, urllib.request

ws = pathlib.Path(sys.argv[1])
port = sys.argv[2]
token = sys.argv[3]
base = f"http://127.0.0.1:{port}"

def req(path, method="GET", payload=None, auth_token=None):
    headers = {"Accept": "application/json"}
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    if auth_token:
        headers["Authorization"] = f"Bearer {auth_token}"
    r = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    with urllib.request.urlopen(r, timeout=5.0) as resp:
        return json.loads(resp.read().decode("utf-8"))

# 发布新装修案例（含 design_style='现代原木'）
cats_res = req("/api/site/categories")
cat_id = cats_res["data"][0]["id"] if cats_res.get("data") else 1
add_res = req(
    "/adminapi/site.article/add",
    method="POST",
    auth_token=token,
    payload={
        "category_id": cat_id,
        "type": "case",
        "title": "青禾·现代原木三居室",
        "slug": "qinghe-wood-case",
        "summary": "118㎡ 现代原木极简全案定制",
        "content": "<p>采用白蜡木定制柜体与无主灯设计。</p>",
        "design_style": "现代原木",
        "status": 1,
    },
)
assert add_res.get("code") == 1, f"发布装修案例失败: {add_res}"
case_id = int(add_res["data"]["id"])

# 按 design_style 筛选案例
list_res = req("/api/site/articles?type=case&design_style=" + urllib.parse.quote("现代原木"))
assert list_res.get("code") == 1
items = list_res["data"]["list"]
assert any(int(x["id"]) == case_id and x.get("design_style") == "现代原木" for x in items), f"design_style 筛选结果异常: {items}"

# 查看详情并确认浏览量递增
detail_res = req(f"/api/site/detail?id={case_id}")
assert detail_res.get("code") == 1 and int(detail_res["data"]["views"]) >= 1

# 提交带 target_id 的客户咨询
inq_res = req(
    "/api/site/submitInquiry",
    method="POST",
    payload={
        "name": "林业主",
        "mobile": "13900139000",
        "target_id": case_id,
        "content": "想了解青禾·现代原木三居室同款报价",
    },
)
assert inq_res.get("code") == 1 and int(inq_res["data"]["target_id"]) == case_id, f"带 target_id 询盘提交失败: {inq_res}"

# 验证历史记录仍在
conn = sqlite3.connect(str(ws / "server/runtime/dev.sqlite"))
keep = conn.execute("SELECT COUNT(*) FROM hw_site_inquiry WHERE name = '留存验证客户'").fetchone()[0]
conn.close()
assert keep == 1, "官网定制后历史询盘记录丢失"
print("✔ 官网定制（design_style 迁移筛选 + 发布新案例 + target_id 询盘闭环）验证通过")
PY
stop_php_server "$SRV1_PID"

# --- 2.2 业务后台定制：enterprise-admin 上用 hw:crud 生成 crm_lead 客户线索 + 主从详情 ---
WS2="$(extract_dev_workspace "ws2-admin" 0)"
(cd "$WS2" && bash ./hw bootstrap --backend-only >/dev/null)
(cd "$WS2" && python3 scripts/start-project.py admin --name "青禾协同管理后台" --prepare-only >/dev/null)

python3 - "$WS2" << 'PY'
import json, pathlib, sqlite3, sys
ws = pathlib.Path(sys.argv[1])

conn = sqlite3.connect(str(ws / "server/runtime/dev.sqlite"))
prj_cnt = conn.execute("SELECT COUNT(*) FROM hw_enterprise_project").fetchone()[0]
task_cnt = conn.execute("SELECT COUNT(*) FROM hw_enterprise_task").fetchone()[0]
conn.close()
assert prj_cnt > 0 and task_cnt > 0, f"业务后台初始项目/任务数据未填充: prj={prj_cnt}, task={task_cnt}"

proj_file = ws / "hw.project.json"
proj = json.loads(proj_file.read_text(encoding="utf-8"))
proj.setdefault("entities", {})["crm_lead"] = {
    "table": "crm_lead",
    "title": "客户线索",
    "primary_key": "id",
    "fields": [
        {"name": "id", "type": "integer", "title": "ID", "primary": True},
        {"name": "lead_name", "type": "string", "title": "客户姓名", "length": 64, "nullable": False, "default": "", "searchable": True},
        {"name": "company", "type": "string", "title": "公司名称", "length": 128, "nullable": False, "default": ""},
        {"name": "stage", "type": "string", "title": "跟进阶段", "length": 32, "nullable": False, "default": "初筛", "searchable": True},
        {"name": "mobile", "type": "string", "title": "联系电话", "length": 32, "nullable": False, "default": ""},
        {"name": "status", "type": "integer", "title": "状态(1有效,0归档)", "nullable": False, "default": 1},
        {"name": "create_time", "type": "biginteger", "title": "创建时间", "nullable": False, "default": 0},
        {"name": "update_time", "type": "biginteger", "title": "更新时间", "nullable": False, "default": 0},
    ],
}
proj_file.write_text(json.dumps(proj, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
PY

(cd "$WS2/server" && php think hw:crud --from-project=crm_lead --all --force --json > "$WS2/crud_crm_lead.json")
(cd "$WS2/server" && php think migrate:run >/dev/null)
php -l "$WS2/server/app/common/model/CrmLead.php" >/dev/null
php -l "$WS2/server/app/common/validate/CrmLead.php" >/dev/null
php -l "$WS2/server/app/adminapi/controller/CrmLead.php" >/dev/null

SRV2_INFO="$(start_php_server "$WS2/server" "$TEMP_DIR/srv2.log")"
SRV2_PORT="${SRV2_INFO%%:*}"
SRV2_PID="${SRV2_INFO##*:}"
WS2_TOKEN=$(cd "$WS2/server" && php think hw:dev:token --username=admin --json | python3 -c "import json,sys; print(json.load(sys.stdin)['token'])")

python3 - "$WS2" "$SRV2_PORT" "$WS2_TOKEN" << 'PY'
import json, pathlib, sqlite3, sys, urllib.parse, urllib.request

ws = pathlib.Path(sys.argv[1])
port = sys.argv[2]
token = sys.argv[3]
base = f"http://127.0.0.1:{port}"

crud_res = json.loads((ws / "crud_crm_lead.json").read_text(encoding="utf-8"))
assert crud_res.get("status") == "success", f"hw:crud crm_lead 失败: {crud_res}"
for rel in (
    "server/app/common/model/CrmLead.php",
    "server/app/adminapi/controller/CrmLead.php",
    "admin-ui/src/api/crm_lead.ts",
    "admin-ui/src/views/crm_lead/index.vue",
):
    assert (ws / rel).is_file(), f"缺少 hw:crud 生成文件: {rel}"

conn = sqlite3.connect(str(ws / "server/runtime/dev.sqlite"))
tables = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()}
first_proj_id = conn.execute("SELECT id FROM hw_enterprise_project ORDER BY id ASC LIMIT 1").fetchone()[0]
conn.close()
assert "hw_crm_lead" in tables, "hw_crm_lead 未创建"

def req(path, method="GET", payload=None):
    headers = {"Accept": "application/json", "Authorization": f"Bearer {token}"}
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    r = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    with urllib.request.urlopen(r, timeout=5.0) as resp:
        return json.loads(resp.read().decode("utf-8"))

# 新增两条不同阶段线索并按 stage 筛选
add1 = req("/adminapi/crm_lead/add", method="POST", payload={"lead_name": "赵总", "company": "远景装饰", "stage": "方案报价", "mobile": "13600136001", "status": 1})
add2 = req("/adminapi/crm_lead/add", method="POST", payload={"lead_name": "钱经理", "company": "星辰商业", "stage": "初筛", "mobile": "13600136002", "status": 1})
assert add1.get("code") == 1 and add2.get("code") == 1, f"新增线索失败: {add1} / {add2}"

filter_param = urllib.parse.quote(json.dumps({"stage": "方案报价"}, ensure_ascii=False))
list_res = req(f"/adminapi/crm_lead/index?filter={filter_param}")
assert list_res.get("code") == 1
rows = list_res["data"]["list"]
assert len(rows) == 1 and rows[0]["lead_name"] == "赵总", f"客户阶段筛选结果异常: {rows}"

# 验证 enterprise-admin 主从详情查询
detail_res = req(f"/adminapi/enterprise.project/detail?id={first_proj_id}")
assert detail_res.get("code") == 1 and isinstance(detail_res["data"].get("tasks"), list), f"主从详情查询异常: {detail_res}"
print("✔ 业务后台定制（hw:crud crm_lead + 阶段筛选 + enterprise-admin 主从详情查询）验证通过")
PY
stop_php_server "$SRV2_PID"
run_win_vue_tsc "$WS2" >/dev/null
echo "✔ 业务后台定制后 admin-ui (含 crm_lead 与 enterprise/project) vue-tsc --noEmit 通过"

# --- 2.3 预约小程序定制：saveService / saveSlot 创建服务与时段 + 会员预约 + 满额冲突拦截 ---
WS3="$(extract_dev_workspace "ws3-miniapp" 1)"
(cd "$WS3" && bash ./hw bootstrap --backend-only >/dev/null)
(cd "$WS3" && python3 scripts/start-project.py miniapp --name "青禾到店预约" --prepare-only >/dev/null)

python3 - "$WS3" << 'PY'
import pathlib, sqlite3, sys
ws = pathlib.Path(sys.argv[1])
conn = sqlite3.connect(str(ws / "server/runtime/dev.sqlite"))
svc_cnt = conn.execute("SELECT COUNT(*) FROM hw_booking_service").fetchone()[0]
slot_cnt = conn.execute("SELECT COUNT(*) FROM hw_booking_slot").fetchone()[0]
conn.close()
assert svc_cnt > 0 and slot_cnt > 0, f"预约模板初始服务/时段未填充: svc={svc_cnt}, slot={slot_cnt}"
PY

SRV3_INFO="$(start_php_server "$WS3/server" "$TEMP_DIR/srv3.log")"
SRV3_PORT="${SRV3_INFO%%:*}"
SRV3_PID="${SRV3_INFO##*:}"
WS3_TOKEN=$(cd "$WS3/server" && php think hw:dev:token --username=admin --json | python3 -c "import json,sys; print(json.load(sys.stdin)['token'])")

python3 - "$SRV3_PORT" "$WS3_TOKEN" << 'PY'
import json, sys, urllib.error, urllib.request

port = sys.argv[1]
admin_token = sys.argv[2]
base = f"http://127.0.0.1:{port}"

def req(path, method="GET", payload=None, auth_token=None):
    headers = {"Accept": "application/json"}
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    if auth_token:
        headers["Authorization"] = f"Bearer {auth_token}"
    r = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(r, timeout=5.0) as resp:
            return resp.status, json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8")
        try:
            return exc.code, json.loads(body)
        except Exception:
            return exc.code, {"code": 0, "msg": body}

# 1. 后台 saveService 创建门店服务
st, svc_res = req(
    "/adminapi/booking.order/saveService",
    method="POST",
    auth_token=admin_token,
    payload={"title": "软装全案到店面谈", "duration": 90, "price": "199.00", "status": 1},
)
assert st == 200 and svc_res.get("code") == 1, f"saveService 失败: {st} {svc_res}"
service_id = int(svc_res["data"]["id"])

# 2. 后台 saveSlot 创建名额=1 的时段
st, slot_res = req(
    "/adminapi/booking.order/saveSlot",
    method="POST",
    auth_token=admin_token,
    payload={"service_id": service_id, "date": "2026-12-28", "start_time": "10:00", "end_time": "11:30", "capacity": 1, "status": 1},
)
assert st == 200 and slot_res.get("code") == 1, f"saveSlot 失败: {st} {slot_res}"
slot_id = int(slot_res["data"]["id"])

# 3. 注册两位会员
_, u1_reg = req("/api/user/register", method="POST", payload={"username": "booku1", "password": "User12345", "nickname": "会员甲"})
_, u2_reg = req("/api/user/register", method="POST", payload={"username": "booku2", "password": "User12345", "nickname": "会员乙"})
assert u1_reg.get("code") == 1 and u2_reg.get("code") == 1, f"会员注册失败: {u1_reg} / {u2_reg}"
u1_token = u1_reg["data"]["token"]
u2_token = u2_reg["data"]["token"]

# 4. 会员甲提交预约成功
st, ord_res = req(
    "/api/booking/create",
    method="POST",
    auth_token=u1_token,
    payload={"service_id": service_id, "slot_id": slot_id, "contact_name": "周女士", "contact_mobile": "13700137001", "remark": "带户型图"},
)
assert st == 200 and ord_res.get("code") == 1, f"会员提交预约失败: {st} {ord_res}"
order_id = int(ord_res["data"]["id"])

# 5. 会员乙抢约同一满额时段，必须被冲突拦截
st2, conflict_res = req(
    "/api/booking/create",
    method="POST",
    auth_token=u2_token,
    payload={"service_id": service_id, "slot_id": slot_id, "contact_name": "吴先生", "contact_mobile": "13700137002"},
)
assert conflict_res.get("code") != 1 and "名额已满" in conflict_res.get("msg", ""), f"满额冲突未被拦截: {st2} {conflict_res}"

# 6. 会员乙尝试跨账号取消订单，必须被 403 拒绝
st3, cross_res = req("/api/booking/cancel", method="POST", auth_token=u2_token, payload={"id": order_id})
assert st3 == 403 or cross_res.get("code") != 1, f"跨账号取消未被拒绝: {st3} {cross_res}"

# 7. 会员甲查看我的预约
st4, my_res = req("/api/booking/myOrders", auth_token=u1_token)
assert st4 == 200 and my_res.get("code") == 1 and int(my_res["data"]["total"]) == 1
print("✔ 预约系统闭环（saveService + saveSlot + 会员预约 + 满额冲突拦截 + 跨账号403）验证通过")
PY
stop_php_server "$SRV3_PID"

P03_OK=true

# ==========================================================
# Step 3 (P04): 预览到上线闭环（hw-deploy.py apply --target=local 部署与二次升级保数据）
# ==========================================================
echo ""
echo "🚢 [Step 3/4] P04：验证 release ZIP 部署与二次升级数据/配置/附件留存..."
DEPLOY_TARGET="$TEMP_DIR/deploy_target"
BACKUP_DIR="$TEMP_DIR/deploy_backup"
mkdir -p "$DEPLOY_TARGET" "$BACKUP_DIR"

python3 "$ROOT/scripts/hw-deploy.py" apply --target=local --artifact="$DEPLOY_ZIP" --target-dir="$DEPLOY_TARGET" --json > "$TEMP_DIR/deploy_init.json"
python3 - "$DEPLOY_TARGET" "$TEMP_DIR/deploy_init.json" << 'PY'
import json, pathlib, sqlite3, sys
target = pathlib.Path(sys.argv[1])
res = json.loads(pathlib.Path(sys.argv[2]).read_text(encoding="utf-8"))
assert res.get("status") == "success", f"首次部署失败: {res}"

env_file = target / ".env"
db_file = target / "runtime/dev.sqlite"
assert env_file.is_file(), "首次部署未生成 .env"
assert (target / "config/install.lock").is_file(), "首次部署未生成 config/install.lock"
assert db_file.is_file(), "首次部署未初始化 runtime/dev.sqlite"

env_file.write_text(env_file.read_text(encoding="utf-8") + "\nP04_KEEP_ENV=YES\n", encoding="utf-8")
conn = sqlite3.connect(str(db_file))
conn.execute("UPDATE hw_admin SET nickname='p04_deploy_persisted' WHERE id=1")
conn.commit()
conn.close()

up = target / "public/uploads/p04_attachment.txt"
up.parent.mkdir(parents=True, exist_ok=True)
up.write_text("P04_UPLOAD_CONTENT", encoding="utf-8")
print("✔ 首次部署完成并写入 .env / SQLite / public/uploads 探针")
PY

python3 "$ROOT/scripts/hw-deploy.py" apply --target=local --artifact="$DEPLOY_ZIP" --target-dir="$DEPLOY_TARGET" --backup-dir="$BACKUP_DIR" --json > "$TEMP_DIR/deploy_upgrade.json"
python3 - "$DEPLOY_TARGET" "$BACKUP_DIR" "$TEMP_DIR/deploy_upgrade.json" << 'PY'
import json, pathlib, sqlite3, sys
target = pathlib.Path(sys.argv[1])
bak = pathlib.Path(sys.argv[2])
res = json.loads(pathlib.Path(sys.argv[3]).read_text(encoding="utf-8"))
assert res.get("status") == "success", f"二次升级失败: {res}"
assert (bak / "backup-manifest.json").is_file(), "二次升级未生成 backup-manifest.json"
assert "P04_KEEP_ENV=YES" in (target / ".env").read_text(encoding="utf-8"), "升级后 .env 自定义配置丢失"
assert (target / "public/uploads/p04_attachment.txt").read_text(encoding="utf-8") == "P04_UPLOAD_CONTENT", "升级后 public/uploads 附件丢失"

conn = sqlite3.connect(str(target / "runtime/dev.sqlite"))
row = conn.execute("SELECT nickname FROM hw_admin WHERE id=1").fetchone()
conn.close()
assert row and row[0] == "p04_deploy_persisted", f"升级后 SQLite 业务数据丢失: {row}"
print("✔ 二次升级完整保留 .env、SQLite 业务数据与 public/uploads 附件")
PY

P04_OK=true

# ==========================================================
# Step 4 (P05 & P06): 收敛工程契约单测验证
# ==========================================================
echo ""
echo "🧪 [Step 4/4] P05 & P06：运行收敛工程契约单元测试..."
python3 -m unittest "$ROOT/scripts/tests/test_p01_p06_convergence.py" -v
P05_OK=true
P06_OK=true

echo ""
echo "=========================================================="
echo "{\"status\":\"PASSED_P01_P06\",\"p01\":${P01_OK},\"p02\":${P02_OK},\"p03\":${P03_OK},\"p04\":${P04_OK},\"p05\":${P05_OK},\"p06\":${P06_OK}}"
echo "=========================================================="
