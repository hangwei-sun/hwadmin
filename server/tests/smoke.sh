#!/usr/bin/env bash
# HwAdmin 后端全量冒烟与安全防越权回归测试套件（SQLite，零外部依赖）
# 用法：cd server && bash tests/smoke.sh
# 覆盖：
#   1. 基础全链路（迁移 → 安装 → 插件生成/安装 → 登录 → 控制台 → 权限树/组 → 配置 → 附件上传/秒传/物理清理 → 会员 → 日志脱敏）
#   2. P0/P1 安全与越权回归用例（RBAC 子集与下级约束、超管保护、列表盲注拦截、附件路径穿越防护、插件基类强制校验、插件权限节点前缀隔离、URL Token 拒绝、深层日志脱敏、个人资料改密旧密码校验、CRUD 一键生成器）
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT=$(pwd)
DB_FILE="${TMPDIR:-/tmp}/hwadmin-smoke-$$.sqlite"
TMP_UPLOAD="${TMPDIR:-/tmp}/hwadmin-smoke-up-$$.txt"
TMP_FAKE_IMG="${TMPDIR:-/tmp}/hwadmin-smoke-fake-$$.jpg"
PORT="${SMOKE_PORT:-18787}"
BASE="http://127.0.0.1:${PORT}"

if [ -f .env ]; then
  echo "检测到 server/.env，冒烟测试使用环境变量覆盖，请临时移走 .env 后再运行" >&2
  exit 1
fi

export PHP_DB_DRIVER=sqlite PHP_DB_NAME="$DB_FILE" DB_DRIVER=sqlite DB_TYPE=sqlite DB_NAME="$DB_FILE" PHP_APP_DEBUG=true PHP_DB_PREFIX=hw_
LOCK="$ROOT/config/install.lock"
HAD_LOCK=0; [ -f "$LOCK" ] && HAD_LOCK=1 && mv "$LOCK" "$LOCK.bak"
HAD_DEMO=1; [ -d plugins/smoke ] || HAD_DEMO=0
rm -rf runtime/throttle runtime/*/throttle 2>/dev/null || true

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true
  rm -f "$DB_FILE" "$LOCK" "$TMP_UPLOAD" "$TMP_FAKE_IMG" runtime/admin_entry.txt
  rm -f app/common/model/SmokeCrudTest.php app/adminapi/controller/SmokeCrudTest.php 2>/dev/null || true
  rm -rf plugins/badrule plugins/badsql 2>/dev/null || true
  [ "$HAD_LOCK" = 1 ] && [ -f "$LOCK.bak" ] && mv "$LOCK.bak" "$LOCK" || true
  [ "$HAD_DEMO" = 0 ] && rm -rf plugins/smoke public/plugins/smoke
  find public/uploads -mindepth 1 -not -name '.htaccess' -delete 2>/dev/null || true
  rm -rf runtime/cache runtime/*/cache runtime/throttle runtime/*/throttle 2>/dev/null || true
}
trap cleanup EXIT

fail() { echo "✗ $1"; echo "$2"; exit 1; }
check() { # check <描述> <json> <grep regex>
  if echo "$2" | grep "$3" >/dev/null; then echo "✓ $1"; else fail "$1" "$2"; fi
}
check_str() { # check_str <描述> <json> <fixed string>
  if echo "$2" | grep -F -- "$3" >/dev/null; then echo "✓ $1"; else fail "$1" "$2"; fi
}

php tests/install_access.php
php tests/deployment.php

R=$(php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
$ctrl = new \app\install\controller\Index($app);
try {
    $ctrl->check();
} catch (\think\exception\HttpResponseException $e) {
    echo $e->getResponse()->getContent();
}
')
check "[S01] 未提供令牌时调用安装检测要求令牌" "$R" '"require_token":true'

php think hw:install --username=admin --password=Admin12345 >/dev/null
echo "✓ 命令行安装"
[ "$HAD_DEMO" = 0 ] && php think hw:plugin make smoke >/dev/null
php think hw:plugin install smoke >/dev/null
php think hw:plugin install cms >/dev/null 2>&1 || true
php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
$now = time();
if (!\think\facade\Db::name("plugin")->where("name", "crud")->find()) {
    \think\facade\Db::name("plugin")->insert([
        "name" => "crud", "title" => "应用开发", "version" => "1.0.0", "status" => 1,
        "config" => "{}", "create_time" => $now, "update_time" => $now,
    ]);
} else {
    \think\facade\Db::name("plugin")->where("name", "crud")->update(["status" => 1]);
}
\think\facade\Cache::delete("hwadmin_enabled_plugins");
'
echo "✓ 插件生成与安装"
php think hw:plugin list | grep -q 'smoke.*已启用' && echo "✓ 插件列表状态"

# P1-3 & 2-P0-5：验证恶意插件注册核心节点名 (auth/admin/edit) 必须被拒绝且不污染数据库
mkdir -p plugins/badrule
cat > plugins/badrule/plugin.json << 'EOF'
{
  "name": "badrule",
  "title": "越权规则测试插件",
  "version": "1.0.0",
  "menus": [
    { "type": "button", "name": "auth/admin/edit", "title": "伪造核心权限" }
  ]
}
EOF
if php think hw:plugin install badrule >/dev/null 2>&1; then
  fail "插件权限节点前缀隔离失败：允许注册了核心节点" "badrule installed"
else
  echo "✓ [2-P0-5] 插件越权注册核心权限节点被成功拦截"
fi
rm -rf plugins/badrule

# P1-3：验证含语法错误 SQL 的插件安装失败时自动补偿回滚，不留半安装脏状态
mkdir -p plugins/badsql
cat > plugins/badsql/plugin.json << 'EOF'
{
  "name": "badsql",
  "title": "错误SQL回滚测试",
  "version": "1.0.0",
  "menus": [
    { "type": "menu", "name": "plugin/badsql/index", "title": "测试菜单", "path": "badsql" }
  ]
}
EOF
echo "CREATE TABLE BROKEN SYNTAX ERROR;" > plugins/badsql/install.sql
if php think hw:plugin install badsql >/dev/null 2>&1; then
  fail "插件错误 SQL 未抛错拦截" "badsql installed"
else
  if php think hw:plugin list | grep -q 'badsql.*已'; then
    fail "插件错误 SQL 回滚不彻底" "$(php think hw:plugin list)"
  fi
  echo "✓ [P1-3] 插件 SQL 执行失败抛错并自动回滚脏状态"
fi
rm -rf plugins/badsql

# 3.1：验证 php think hw:crud 一键生成 CRUD 控制器、模型与验证器
php think hw:crud --table=user_group --controller=SmokeCrudTest --force >/dev/null
php -l app/adminapi/controller/SmokeCrudTest.php >/dev/null
php -l app/common/validate/UserGroup.php >/dev/null
rm -f app/adminapi/controller/SmokeCrudTest.php app/common/validate/UserGroup.php
git checkout app/common/model/UserGroup.php >/dev/null 2>&1 || true
echo "✓ [3.1] php think hw:crud 一键代码生成器验证通过"

# [S03] CRUD 生成器默认拒绝系统列、机密列、归属列与资金特权列，且密码自动哈希
php -r '
$db = getenv("PHP_DB_NAME");
$pdo = new PDO("sqlite:" . $db);
$pdo->exec("CREATE TABLE IF NOT EXISTS hw_test_crud_sec (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INT, tenant_id INT, password VARCHAR(255), salt VARCHAR(32), secret VARCHAR(64), money DECIMAL(10,2), rules TEXT, title VARCHAR(100), create_time INT, update_time INT)");
'
php think hw:crud --table=test_crud_sec --controller=TestCrudSec --force >/dev/null
php -l app/adminapi/controller/TestCrudSec.php >/dev/null
php -l app/common/validate/TestCrudSec.php >/dev/null
SEC_CTRL=$(cat app/adminapi/controller/TestCrudSec.php)
if echo "$SEC_CTRL" | grep -q 'password' && echo "$SEC_CTRL" | grep -q 'PasswordHasher::hash' && echo "$SEC_CTRL" | grep -q "dataScope = 'self'" && ! echo "$SEC_CTRL" | grep -E -q "AllowFields = .*'admin_id'" && ! echo "$SEC_CTRL" | grep -E -q "AllowFields = .*'money'"; then
    echo "✓ [S03] CRUD 生成器默认安全字段过滤、密码哈希与归属隔离验证通过"
else
    echo "❌ [S03] CRUD 生成器安全基线未满足"
    exit 1
fi
rm -f app/adminapi/controller/TestCrudSec.php app/common/model/TestCrudSec.php app/common/validate/TestCrudSec.php
php -r '
$db = getenv("PHP_DB_NAME");
$pdo = new PDO("sqlite:" . $db);
$pdo->exec("DROP TABLE IF EXISTS hw_test_crud_sec");
'

php -S 127.0.0.1:"$PORT" -t public public/router.php >/dev/null 2>&1 &
SERVER_PID=$!
sleep 1

R=$(curl -s "$BASE/api/index/ping");                         check "健康检查" "$R" '"code":1'
R=$(curl -s "$BASE/adminapi/index/info");                    check "未登录拦截 401" "$R" '"code":401'

# 图形验证码与登录防护测试
R=$(curl -s "$BASE/adminapi/index/captcha");                 check "获取图形验证码接口" "$R" '"captcha_key"'
check "验证码启用状态" "$R" '"enabled":true'
check "验证码SVG图像格式" "$R" 'data:image.*svg.*base64'
R=$(curl -s -X POST -d 'username=admin&password=Admin12345' "$BASE/adminapi/index/login")
check "开启验证码时未传验证码拦截" "$R" '请输入图形验证码'
R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"username":"admin","password":"Admin12345","captcha_key":"fake","captcha_code":"0000"}' "$BASE/adminapi/index/login")
check "错误验证码拦截" "$R" '图形验证码错误'
R=$(curl -s -X POST -d 'username=admin&password=wrong&captcha_key=__SMOKE_KEY__&captcha_code=__SMOKE_CODE__' "$BASE/adminapi/index/login"); check "错误密码" "$R" '"code":0'
R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"username":"admin","password":"Admin12345","captcha_key":"__SMOKE_KEY__","captcha_code":"__SMOKE_CODE__"}' "$BASE/adminapi/index/login")
check "管理员登录" "$R" '"token"'
TOKEN=$(echo "$R" | sed -E 's/.*"token":"([a-f0-9]+)".*/\1/')
H="Authorization: Bearer $TOKEN"

# 部署诊断在安装锁定后仍可由超管复查，不能成为匿名或普通管理员入口。
R=$(curl -s -X POST "$BASE/index.php?s=/install/index/deploymentPrepare")
check "[DEPLOY] 安装后匿名诊断被拒绝" "$R" '超级管理员'
R=$(curl -s -H "$H" "$BASE/index.php?s=/install/index/deploymentConfig")
check "[DEPLOY] 诊断配置拒绝 GET" "$R" 'POST'
R=$(curl -s -X POST -H "$H" "$BASE/index.php?s=/install/index/deploymentConfig")
check "[DEPLOY] 超管获取实际配置" "$R" '$hw_block_php'
R=$(curl -s -X POST -H "$H" "$BASE/index.php?s=/install/index/deploymentPrepare")
check "[DEPLOY] 超管创建临时探针" "$R" '"marker":"hw-deployment-'
DEPLOY_ID=$(echo "$R" | sed -E 's/.*"id":"([a-f0-9]+)".*/\1/')
[ -f "public/hw-deploy-$DEPLOY_ID.php" ] || fail "[DEPLOY] 探针不存在" ''
R=$(curl -s -X POST -H "$H" -d "id=$DEPLOY_ID" "$BASE/index.php?s=/install/index/deploymentCleanup")
check "[DEPLOY] 探针清理成功" "$R" '"code":1'
[ ! -e "public/hw-deploy-$DEPLOY_ID.php" ] || fail "[DEPLOY] 探针未清理" ''

# P1-4：验证拒绝通过 URL Query 参数 ?token= 传递令牌
R=$(curl -s "$BASE/adminapi/index/info?token=$TOKEN");       check "[P1-4] 拒绝 URL Query Token" "$R" '"code":401'

R=$(curl -s -H "$H" "$BASE/adminapi/index/info");            check "管理员信息" "$R" '"permissions":\["\*"\]'
R=$(curl -s -H "$H" "$BASE/adminapi/index/menus");           check "菜单树" "$R" '"component":"Layout"'
check "菜单首页顶级节点" "$R" '"title":"首页"'
check "菜单权限顶级节点" "$R" '"title":"权限"'
check "菜单会员顶级节点" "$R" '"title":"会员"'
check "菜单系统顶级节点" "$R" '"title":"系统"'
check "个人中心菜单规则节点" "$R" 'personalCenter'

# 控制台统计面板
R=$(curl -s -H "$H" "$BASE/adminapi/dashboard/index");       check "控制台统计面板" "$R" '"counts"'
check "控制台系统信息" "$R" '"hw_version"'

# 管理员个人资料与改密旧密码校验 (2-P1-3)
R=$(curl -s -H "$H" "$BASE/adminapi/auth.admin/profile");    check "个人资料查看" "$R" '"username":"admin"'
check "默认无个性锁屏密码" "$R" '"has_custom_lock_password":false'
R=$(curl -s -H "$H" -X POST -d 'nickname=超级总管&email=admin@test.com' "$BASE/adminapi/auth.admin/profile"); check "个人资料修改" "$R" '"code":1'
R=$(curl -s -H "$H" -X POST -d 'password=NewPass123' "$BASE/adminapi/auth.admin/profile"); check "[2-P1-3] 个人资料改密未传旧密码拦截" "$R" 'old_password'
R=$(curl -s -H "$H" -X POST -d 'old_password=WrongOld&password=NewPass123' "$BASE/adminapi/auth.admin/profile"); check "[2-P1-3] 个人资料改密旧密码错误拦截" "$R" '当前旧密码不正确'

# 锁屏解锁与个性锁屏密码测试
R=$(curl -s -H "$H" -X POST -d 'password=Admin12345' "$BASE/adminapi/auth.admin/unlock"); check "默认使用登录密码解锁" "$R" '"解锁成功"'
R=$(curl -s -H "$H" -X POST -d 'password=WrongLockPass' "$BASE/adminapi/auth.admin/unlock"); check "错误解锁密码拦截" "$R" '解锁密码错误'
R=$(curl -s -H "$H" -X POST -d 'lock_password=998877' "$BASE/adminapi/auth.admin/profile"); check "未传登录密码设置锁屏密码拦截" "$R" '验证身份'
R=$(curl -s -H "$H" -X POST -d 'current_password=WrongLoginPass&lock_password=998877' "$BASE/adminapi/auth.admin/profile"); check "当前登录密码错误拦截" "$R" '当前后台登录密码不正确'
R=$(curl -s -H "$H" -X POST -d 'current_password=Admin12345&lock_password=998877' "$BASE/adminapi/auth.admin/profile"); check "设置个性锁屏密码" "$R" '"code":1'
R=$(curl -s -H "$H" "$BASE/adminapi/auth.admin/profile"); check "个性锁屏密码标记生效" "$R" '"has_custom_lock_password":true'
R=$(curl -s -H "$H" -X POST -d 'password=998877' "$BASE/adminapi/auth.admin/unlock"); check "个性锁屏密码解锁成功" "$R" '"解锁成功"'
R=$(curl -s -H "$H" -X POST -d 'password=Admin12345' "$BASE/adminapi/auth.admin/unlock"); check "个性锁屏密码下登录密码仍可解锁" "$R" '"解锁成功"'
R=$(curl -s -H "$H" -X POST -d 'current_password=Admin12345&reset_lock_password=1' "$BASE/adminapi/auth.admin/profile"); check "重置恢复默认锁屏密码" "$R" '"code":1'
R=$(curl -s -H "$H" "$BASE/adminapi/auth.admin/profile"); check "重置后恢复默认状态" "$R" '"has_custom_lock_password":false'

# 2-P0-2：验证列表 filter/op 对隐藏敏感字段 password 的盲注提取被彻底忽略
R=$(curl -s -G -H "$H" --data-urlencode 'filter={"password":"$2y$99$nonexistent"}' --data-urlencode 'op={"password":"like"}' "$BASE/adminapi/auth.admin/index")
check "[2-P0-2] 敏感字段 password 筛选盲注被忽略" "$R" '"total":1'

# 创建子角色组 G2（拥有 auth/admin/* 和 auth/group/* 权限）及归属 G2 的普通管理员 editor，用于测试 2-P0-1 越权防护
RULE_IDS=$(curl -s -H "$H" "$BASE/adminapi/auth.rule/index?flat=1&limit=200" | grep -oE '"id":[0-9]+' | cut -d: -f2 | paste -sd, -)
R=$(curl -s -H "$H" -X POST -d "pid=1&name=运营管理组&rules=$RULE_IDS&status=1" "$BASE/adminapi/auth.group/add")
check "角色组新增" "$R" '"code":1'
G2_ID=$(echo "$R" | sed -E 's/.*"id":([0-9]+).*/\1/')

R=$(curl -s -H "$H" -X POST -d "username=editor&password=Editor12345&nickname=编辑&groups=$G2_ID" "$BASE/adminapi/auth.admin/add")
check "CRUD 新增管理员" "$R" '"code":1'
EDITOR_ID=$(echo "$R" | sed -E 's/.*"id":([0-9]+).*/\1/')

R=$(curl -s -H "$H" "$BASE/adminapi/auth.admin/index?keyword=edit"); check "CRUD 列表+搜索" "$R" '"total":1'
check "CRUD 列表附带角色组" "$R" '"groups"'
check "CRUD 隐藏密码字段" "$R" '"username":"editor"'
echo "$R" | grep -q '"password"' && fail "列表泄露密码字段" "$R"

# 角色组管理超管防误操作
R=$(curl -s -H "$H" "$BASE/adminapi/auth.group/index");      check "角色组树形返回" "$R" '"超级管理员"'
R=$(curl -s -H "$H" -X POST -d "id=1&status=0" "$BASE/adminapi/auth.group/edit"); check "超管组防禁用拦截" "$R" '超级管理员组不可禁用'
R=$(curl -s -H "$H" -X POST -d "ids=1" "$BASE/adminapi/auth.group/del"); check "超管组防删除拦截" "$R" '超级管理员组不可删除'

# 权限规则管理
R=$(curl -s -H "$H" "$BASE/adminapi/auth.rule/index");       check "权限规则树形返回" "$R" '"children"'
R=$(curl -s -H "$H" -X POST -d 'pid=0&type=menu&name=test/menu&title=测试菜单' "$BASE/adminapi/auth.rule/add"); check "权限规则新增" "$R" '"code":1'
RULE_ID=$(echo "$R" | sed -E 's/.*"id":([0-9]+).*/\1/')

# 系统配置管理（顺带验证嵌套敏感键名日志脱敏 P1-4）
R=$(curl -s -H "$H" "$BASE/adminapi/system.config/index");   check "系统配置分组列表" "$R" '"groups"'
R=$(curl -s -H "$H" -X POST -H 'Content-Type: application/json' -d '{"values":{"site_name":"HwAdminSmokeTest","smtp_password":"MyTopSecretSmtpPass"}}' "$BASE/adminapi/system.config/edit"); check "系统配置批量保存" "$R" '"code":1'
R=$(curl -s -H "$H" "$BASE/adminapi/system.config/index");   check "系统配置更新验证" "$R" 'HwAdminSmokeTest'

# 验证 PC 前端首页模式与 CMS 门户接管与安全入口动态切换
R=$(curl -s "$BASE/adminapi/index/config"); check "获取公共配置包含安全特性" "$R" '"login_captcha":true'
check "公共配置默认首页模式" "$R" '"home_mode":"portal"'
check "公共配置默认安全入口" "$R" '"admin_entry":"admin"'

# 默认首页展示品牌门户页（隐藏后台入口）
R=$(curl -s "$BASE/"); check "根路径默认渲染品牌门户页" "$R" '全栈工程化特性矩阵'
echo "$R" | grep -q "adminapi" && fail "门户页暴露了后台接口路径" "$R"

# CMS 栏目与公开接口
R=$(curl -s "$BASE/api/plugin/cms/article/categories"); check "CMS免登录栏目列表接口" "$R" '"code":1'
R=$(curl -s "$BASE/api/plugin/cms/article/index"); check "CMS免登录文章列表接口" "$R" '"code":1'

# 修改 home_mode 为 cms 并验证首页接管
R=$(curl -s -H "$H" -X POST -H 'Content-Type: application/json' -d '{"values":{"home_mode":"cms"}}' "$BASE/adminapi/system.config/edit")
check "切换首页模式为 CMS" "$R" '"code":1'
R=$(curl -s "$BASE/"); check "根路径 CMS 首页接管渲染" "$R" 'CMS 门户'

# 修改后台安全入口为 custom_console，验证默认 /admin 隐藏拦截返回 404
R=$(curl -s -H "$H" -X POST -H 'Content-Type: application/json' -d '{"values":{"admin_entry":"custom_console"}}' "$BASE/adminapi/system.config/edit")
check "修改后台安全入口为 custom_console" "$R" '"code":1'

# 测试非法保留字校验拦截
R=$(curl -s -H "$H" -X POST -H 'Content-Type: application/json' -d '{"values":{"admin_entry":"api"}}' "$BASE/adminapi/system.config/edit")
check "安全入口使用系统保留字拦截" "$R" '系统保留字'

# 访问默认 /admin 返回 404
ADMIN_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/admin")
[ "$ADMIN_STATUS" = "404" ] || fail "自定义入口后默认 /admin 未返回 404 隐藏状态 (code: $ADMIN_STATUS)" ''
echo "✓ [SEC] 默认 /admin 隐藏拦截生效 (HTTP 404)"

# 访问新入口 /custom_console 返回后台入口 HTML (200)
CUSTOM_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/custom_console")
[ "$CUSTOM_STATUS" = "200" ] || fail "自定义入口 /custom_console 访问异常 (code: $CUSTOM_STATUS)" ''
echo "✓ [SEC] 自定义入口 /custom_console 正常承载后台 (HTTP 200)"

# 恢复 admin_entry 与 home_mode 配置
R=$(curl -s -H "$H" -X POST -H 'Content-Type: application/json' -d '{"values":{"admin_entry":"admin","home_mode":"portal"}}' "$BASE/adminapi/system.config/edit")
check "恢复安全入口与首页模式配置" "$R" '"code":1'
ADMIN_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/admin")
[ "$ADMIN_STATUS" = "200" ] || fail "恢复后 /admin 无法正常访问 (code: $ADMIN_STATUS)" ''
echo "✓ [SEC] 恢复后 /admin 正常访问 (HTTP 200)"

# 会员与会员组管理 + multi / select 便捷接口测试
R=$(curl -s -H "$H" "$BASE/adminapi/user.group/index");      check "会员分组列表" "$R" '"code":1'
R=$(curl -s -H "$H" -X POST -d 'name=VIP组' "$BASE/adminapi/user.group/add"); check "会员分组新增" "$R" '"code":1'
R=$(curl -s -H "$H" "$BASE/adminapi/user.group/select");     check "[3.1] 下拉选择器 select 接口" "$R" '"labelField":"name"'
R=$(curl -s -H "$H" -X POST -d 'username=smokeuser&password=Password123&nickname=烟雾测试会员' "$BASE/adminapi/user.user/add"); check "会员新增" "$R" '"code":1'
USER_ID=$(echo "$R" | sed -E 's/.*"id":([0-9]+).*/\1/')

# 通用移动端使用独立会员身份，覆盖现有 /api 会话的完整生命周期。
R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{"account":"smokeuser","password":"Password123"}' "$BASE/api/user/login")
check "[MOBILE] 会员账号登录" "$R" '"code":1'
MEMBER_TOKEN=$(echo "$R" | php -r '$r=json_decode(stream_get_contents(STDIN), true); echo $r["data"]["token"] ?? "";')
[ -n "$MEMBER_TOKEN" ] || fail "[MOBILE] 登录未返回会员令牌" ''
R=$(curl -s -H "Authorization: Bearer $MEMBER_TOKEN" "$BASE/api/user/info")
check "[MOBILE] 获取会员资料" "$R" '"username":"smokeuser"'
R=$(curl -s -H "Authorization: Bearer $MEMBER_TOKEN" "$BASE/adminapi/index/info")
check "[MOBILE] 会员令牌不能访问管理员接口" "$R" '"code":401'
R=$(curl -s -H "Authorization: Bearer $MEMBER_TOKEN" -X POST "$BASE/api/user/logout")
check "[MOBILE] 服务端退出会员登录" "$R" '"code":1'
R=$(curl -s -H "Authorization: Bearer $MEMBER_TOKEN" "$BASE/api/user/info")
check "[MOBILE] 退出后会员令牌失效" "$R" '"code":401'
unset MEMBER_TOKEN
R=$(curl -s -H "$H" -X POST -d "ids=$USER_ID&field=status&value=0" "$BASE/adminapi/user.user/multi"); check "[3.1] 批量更新 multi 接口" "$R" '"count":1'
R=$(curl -s -H "$H" "$BASE/adminapi/user.user/index?keyword=smokeuser"); check "会员列表查询" "$R" '"username":"smokeuser"'
echo "$R" | grep -q '"password"' && fail "会员列表泄露密码字段" "$R"

# 附件管理（上传、伪造图片拦截、双扩展名拦截、秒传与物理文件删除验证 P1-2 & 2-P0-3）
R=$(curl -s -H "$H" -X POST -d 'url=/../.env' "$BASE/adminapi/system.attachment/add"); check "[2-P0-3] 附件直接 add 接口已屏蔽" "$R" '"code":0'
echo "not an image content" > "$TMP_FAKE_IMG"
R=$(curl -s -H "$H" -F "file=@$TMP_FAKE_IMG;filename=fake.jpg" "$BASE/adminapi/system.attachment/upload"); check "[P1-2] 伪造图片内容上传被拦截" "$R" '"code":0'
echo "hello hwadmin smoke attachment upload test" > "$TMP_UPLOAD"
R=$(curl -s -H "$H" -F "file=@$TMP_UPLOAD;filename=evil.php.txt" "$BASE/adminapi/system.attachment/upload"); check "[P1-2] 危险双扩展名上传被拦截" "$R" '安全拦截'
R=$(curl -s -H "$H" -F "file=@$TMP_UPLOAD" "$BASE/adminapi/system.attachment/upload"); check "附件文件上传" "$R" '"url"'
ATT_ID=$(echo "$R" | sed -E 's/.*"id":([0-9]+).*/\1/')
ATT_URL=$(echo "$R" | sed -E 's/.*"url":"([^"]+)".*/\1/' | sed 's/\\//g')
ATT_FILE="$ROOT/public$ATT_URL"
[ -f "$ATT_FILE" ] || fail "附件物理文件未落盘" "$ATT_FILE"
R=$(curl -s -H "$H" -F "file=@$TMP_UPLOAD" "$BASE/adminapi/system.attachment/upload"); check "附件秒传命中" "$R" '"fast_upload":true'
R=$(curl -s -H "$H" "$BASE/adminapi/system.attachment/index"); check "附件列表查询" "$R" '"total":'
R=$(curl -s -H "$H" -X POST -d "ids=$ATT_ID" "$BASE/adminapi/system.attachment/del"); check "附件删除" "$R" '"code":1'
[ ! -f "$ATT_FILE" ] && echo "✓ [P1-2] 附件删除后孤儿物理文件已同步清理" || fail "附件删除后物理文件残留 ($R)" "$ATT_FILE"

# 操作日志管理与递归脱敏验证 (P1-4)
R=$(curl -s -H "$H" "$BASE/adminapi/auth.admin_log/index");  check "操作日志自动记录" "$R" '"username":"admin"'
check_str "操作日志密码脱敏" "$R" '***'
echo "$R" | grep -q 'MyTopSecretSmtpPass' && fail "[P1-4] 嵌套敏感字段未脱敏" "$R"
echo "✓ [P1-4] 操作日志深层嵌套敏感字段递归脱敏生效"
R=$(curl -s -H "$H" -X POST -d 'username=bad' "$BASE/adminapi/auth.admin_log/add"); check "日志禁止新增 405" "$R" '"code":0'

# 插件管理与控制器基类强制校验 (2-P0-4)
R=$(curl -s -H "$H" "$BASE/adminapi/plugin/smoke/index/index"); check "插件后台路由" "$R" '"welcome":"Hello HwAdmin"'
R=$(curl -s "$BASE/api/plugin/smoke/index/hello");           check "插件前台路由" "$R" 'hello from smoke'
R=$(curl -s -H "$H" "$BASE/adminapi/system.plugin/index");   check "插件管理列表" "$R" '"name":"smoke"'

# ==================== 官方唯一预置插件：内容管理 (cms) 冒烟 ====================
R=$(curl -s -H "$H" "$BASE/adminapi/system.plugin/index")
check "[CMS Plugin] 插件管理列表包含预置 cms 插件" "$R" '"name":"cms"'
check "[CMS Plugin] cms 预置插件处于已安装状态" "$R" '"installed":true'

R=$(curl -s -H "$H" "$BASE/adminapi/system.plugin/config?name=cms")
check "[CMS Plugin] 读取 CMS 插件配置" "$R" '"site_name"'

R=$(curl -s -H "$H" -X POST -H 'Content-Type: application/json' -d '{"name":"cms","config":{"site_name":"HwAdmin 资讯头条","page_size":12}}' "$BASE/adminapi/system.plugin/config")
check "[CMS Plugin] 保存 CMS 插件配置" "$R" '"code":1'

R=$(curl -s "$BASE/api/plugin/cms/article/index")
check "[CMS Plugin] 前台文章列表接口返回 code: 1" "$R" '"code":1'
check_str "[CMS Plugin] 前台文章数据包含示范资讯" "$R" '大模型驱动的敏捷研发'

R=$(curl -s -H "$H" "$BASE/adminapi/plugin/cms/article/index")
check "[CMS Plugin] 后台文章列表接口返回 code: 1" "$R" '"code":1'

cat > plugins/smoke/controller/admin/Unsafe.php << 'EOF'
<?php
namespace plugins\smoke\controller\admin;
class Unsafe {
    public function open() { return ['leaked' => true]; }
}
EOF
R=$(curl -s "$BASE/adminapi/plugin/smoke/unsafe/open")
check "[2-P0-4] 未继承 AdminController 的插件控制器被 404 拦截" "$R" '"code":0'
rm -f plugins/smoke/controller/admin/Unsafe.php

# ==================== 2-P0-1 普通管理员越权回归测试矩阵 ====================
R=$(curl -s -X POST -d 'username=editor&password=Editor12345&captcha_key=__SMOKE_KEY__&captcha_code=__SMOKE_CODE__' "$BASE/adminapi/index/login")
T2=$(echo "$R" | sed -E 's/.*"token":"([a-f0-9]+)".*/\1/')
R=$(curl -s -X POST -H "Authorization: Bearer $T2" "$BASE/index.php?s=/install/index/deploymentPrepare")
check "[DEPLOY] 普通管理员不得创建探针" "$R" '超级管理员'
H2="Authorization: Bearer $T2"

# 1. 普通管理员尝试新建 rules='*' 的超管子组 => 必须 403 拒绝
R=$(curl -s -H "$H2" -X POST -d "pid=$G2_ID&name=伪超管组&rules=*" "$BASE/adminapi/auth.group/add")
check "[2-P0-1] 拦截普通管理员创建 rules='*' 角色组" "$R" '"code":403'

# 2. 普通管理员尝试修改自己直接所属的角色组 G2 => 必须 403 拒绝
R=$(curl -s -H "$H2" -X POST -d "id=$G2_ID&name=篡改本组" "$BASE/adminapi/auth.group/edit")
check "[2-P0-1] 拦截普通管理员篡改自身所属角色组" "$R" '"code":403'

# 3. 普通管理员尝试在子组中分配自己未拥有的规则 ID ($RULE_ID) => 必须 403 拒绝
R=$(curl -s -H "$H2" -X POST -d "pid=$G2_ID&name=越权子组&rules=$RULE_ID" "$BASE/adminapi/auth.group/add")
check "[2-P0-1] 拦截普通管理员分配超出自身范围的规则" "$R" '"code":403'

# 4. 普通管理员尝试将自己加入超级管理员组 (groups=1) => 必须 403 拒绝
R=$(curl -s -H "$H2" -X POST -d "id=$EDITOR_ID&groups=1" "$BASE/adminapi/auth.admin/edit")
check "[2-P0-1] 拦截普通管理员将自己提权至超管组" "$R" '"code":403'

# 5. 普通管理员尝试重置初始超管 (id=1) 的密码 => 必须拒绝
R=$(curl -s -H "$H2" -X POST -d "id=1&password=Hacked12345" "$BASE/adminapi/auth.admin/edit")
check "[2-P0-1] 拦截普通管理员修改初始超管密码" "$R" '"code":0\|"code":403'

# 6. 普通管理员尝试修改或删除权限规则 auth/rule => 必须 403 拒绝
R=$(curl -s -H "$H2" -X POST -d "id=$RULE_ID&name=auth/group/edit&title=篡改规则" "$BASE/adminapi/auth.rule/edit")
check "[2-P0-1] 拦截非超管修改权限规则" "$R" '"code":403'

# 清理测试规则
R=$(curl -s -H "$H" -X POST -d "ids=$RULE_ID" "$BASE/adminapi/auth.rule/del"); check "权限规则级联删除" "$R" '"code":1'

# P1-5：验证登录防爆破限流（同一账号+IP 连续输错 5 次后触发限流拦截）
for i in 1 2 3 4 5; do
  curl -s -X POST -d 'username=editor&password=WrongPass&captcha_key=__SMOKE_KEY__&captcha_code=__SMOKE_CODE__' "$BASE/adminapi/index/login" >/dev/null
done
R=$(curl -s -X POST -d 'username=editor&password=Editor12345&captcha_key=__SMOKE_KEY__&captcha_code=__SMOKE_CODE__' "$BASE/adminapi/index/login")
check "[P1-5] 连续失败触发 LoginThrottle 防爆破限流" "$R" '频繁'
rm -rf runtime/throttle 2>/dev/null || true

# ==================== S01 安装入口保护与配置文件访问拦截 ====================
R=$(curl -s -I "$BASE/.env")
check "[S01] 禁止直接下载 .env 配置文件" "$R" '403 Forbidden'

R=$(curl -s -I "$BASE/config/install.lock")
check "[S01] 禁止直接访问 install.lock" "$R" '403 Forbidden'

R=$(curl -s -X POST "$BASE/install/index/run")
check "[S01] 已安装系统再次调用安装被阻断" "$R" '"code":0\|"code":403\|已安装'

R=$(curl -s -H 'X-Requested-With: XMLHttpRequest' "$BASE/install/index/check")
check "[S01] 已安装状态下访问检测接口被安装锁拦截" "$R" '系统已安装'

# ==================== S02 数据范围 (DataScope) Fail-Close 校验 ====================
php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
$ctrl = new class {
    use \core\crud\CrudTrait;
    public $model;
    public $dataScope;
    public $dataScopeField;
    public $auth;
    protected function tableFields(): array { return ["id", "username"]; }
    public function __construct() {
        $this->model = new \app\common\model\Admin();
        $this->dataScope = "self";
        $this->dataScopeField = "non_existent_col";
        $this->auth = new class {
            public function check() { return true; }
            public function isSuper() { return false; }
            public function id() { return 10; }
        };
    }
    public function testApply() {
        return $this->applyDataScope($this->model->db());
    }
};
try {
    $ctrl->testApply();
    echo "FAIL_OPEN";
} catch (\core\exception\ApiException $e) {
    if ($e->getHttpStatus() === 500) {
        echo "FAIL_CLOSE_OK";
    } else {
        echo "ERR_" . $e->getMessage();
    }
}
' > /tmp/hw_datascope_test.txt
R=$(cat /tmp/hw_datascope_test.txt); rm -f /tmp/hw_datascope_test.txt
check "[S02] 归属字段缺失时拒绝访问 (Fail-Close)" "$R" 'FAIL_CLOSE_OK'

php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
$ctrl = new class {
    use \core\crud\CrudTrait;
    public $model;
    public $dataScope;
    public $dataScopeField;
    public $auth;
    protected function tableFields(): array { return ["id", "username"]; }
    public function __construct() {
        $this->model = new \app\common\model\Admin();
        $this->dataScope = "unknown_scope";
        $this->dataScopeField = "id";
        $this->auth = new class {
            public function check() { return true; }
            public function isSuper() { return false; }
            public function id() { return 10; }
        };
    }
    public function testApply() {
        return $this->applyDataScope($this->model->db());
    }
};
try {
    $ctrl->testApply();
    echo "FAIL_OPEN";
} catch (\core\exception\ApiException $e) {
    if ($e->getHttpStatus() === 500) {
        echo "FAIL_CLOSE_UNKNOWN_OK";
    }
}
' > /tmp/hw_datascope_unknown.txt
R=$(cat /tmp/hw_datascope_unknown.txt); rm -f /tmp/hw_datascope_unknown.txt
check "[S02] 未知数据范围拒绝访问 (Fail-Close)" "$R" 'FAIL_CLOSE_UNKNOWN_OK'

# 未登录时调用 self 数据范围必须 401 拒绝
php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
$ctrl = new class {
    use \core\crud\CrudTrait;
    public $model;
    public $dataScope;
    public $dataScopeField;
    public $auth;
    protected function tableFields(): array { return ["id", "username"]; }
    public function __construct() {
        $this->model = new \app\common\model\Admin();
        $this->dataScope = "self";
        $this->dataScopeField = "id";
        $this->auth = new class {
            public function check() { return false; }
            public function isSuper() { return false; }
            public function id() { return 0; }
        };
    }
    public function testApply() {
        return $this->applyDataScope($this->model->db());
    }
};
try {
    $ctrl->testApply();
    echo "FAIL_OPEN";
} catch (\core\exception\ApiException $e) {
    if ($e->getHttpStatus() === 401) {
        echo "FAIL_CLOSE_401_OK";
    }
}
' > /tmp/hw_datascope_unauth.txt
R=$(cat /tmp/hw_datascope_unauth.txt); rm -f /tmp/hw_datascope_unauth.txt
check "[S02] 未登录调用 self 范围 401 拦截 (Fail-Close)" "$R" 'FAIL_CLOSE_401_OK'

# 普通管理员新增注入归属人 ID 防伪造
php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
$ctrl = new class {
    use \core\crud\CrudTrait;
    public $model;
    public $dataScope;
    public $dataScopeField;
    public $auth;
    public $tableFieldsCache = ["id", "admin_id", "title"];
    public function __construct() {
        $this->model = new \app\common\model\Admin();
        $this->dataScope = "self";
        $this->dataScopeField = "admin_id";
        $this->auth = new class {
            public function check() { return true; }
            public function isSuper() { return false; }
            public function id() { return 25; }
        };
    }
    protected function tableFields(): array { return $this->tableFieldsCache; }
    public function testInject(array $data) {
        return $this->injectDataScopeOnAdd($data);
    }
};
$out = $ctrl->testInject(["title" => "test", "admin_id" => 999]);
if (($out["admin_id"] ?? 0) === 25) {
    echo "INJECT_OK";
}
' > /tmp/hw_inject_test.txt
R=$(cat /tmp/hw_inject_test.txt); rm -f /tmp/hw_inject_test.txt
check "[S02] 新增时强制注入当前登录者归属防篡改" "$R" 'INJECT_OK'

# ==================== S04 菜单只读性与运行时无写操作 ====================
R=$(curl -s -H "$H" "$BASE/adminapi/index/menus")
check "[S04] 菜单列表只读加载正常" "$R" '"code":1'

# ==================== S05 插件安装阶段性回滚与清理 ====================
php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
$dir = plugin_path("test_s05");
@mkdir($dir, 0755, true);
file_put_contents($dir . "plugin.json", json_encode([
    "name" => "test_s05", "title" => "S05 Test", "version" => "1.0.0", "require" => ["hwadmin" => ">=0.1.0"]
]));
file_put_contents($dir . "install.sql", "CREATE TABLE __PREFIX__test_s05_tab (id INT PRIMARY KEY);\nINSERT INTO __PREFIX__non_existent_tab_xxx VALUES (1);\n");
file_put_contents($dir . "uninstall.sql", "DROP TABLE IF EXISTS __PREFIX__test_s05_tab;\n");
$pm = new \core\plugin\PluginManager($app);
try {
    $pm->install("test_s05");
    echo "FAIL_INSTALLED";
} catch (\Throwable $e) {
    // 检查表是否已被补偿机制安全撤销清理
    $prefix = (string) (\think\facade\Db::connect()->getConfig("prefix") ?: "hw_");
    try {
        \think\facade\Db::execute("SELECT 1 FROM {$prefix}test_s05_tab");
        echo "TABLE_STILL_EXISTS";
    } catch (\Throwable) {
        echo "TAB_REMOVED_OK";
    }
}
' 2>/dev/null > /tmp/hw_s05_test.txt || true
rm -rf plugins/test_s05
R=$(cat /tmp/hw_s05_test.txt 2>/dev/null); rm -f /tmp/hw_s05_test.txt
check "[S05] 插件 SQL 部分执行失败自动触发 uninstall.sql 补偿回滚" "$R" 'TAB_REMOVED_OK'

# ==================== S06 锁屏防爆破与限流原子保护 ====================
for i in 1 2 3 4 5; do
  curl -s -H "$H" -X POST -d 'password=WrongLockPassword' "$BASE/adminapi/auth.admin/unlock" >/dev/null
done
R=$(curl -s -H "$H" -X POST -d 'password=WrongLockPassword' "$BASE/adminapi/auth.admin/unlock")
check "[S06] 连续多次输错锁屏密码触发限流拦截" "$R" '过于频繁\|频繁'

php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
\core\auth\LoginThrottle::clear("admin_unlock", "admin", "127.0.0.1");
'

# ==================== S07 查询过滤白名单与侧信道敏感列阻断 ====================
php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
$params = new \core\crud\QueryParams();
$params->filter = ["smtp_password" => "secret", "lock_password" => "123"];
$params->sort = "lock_password";
$query = (new \app\common\model\Admin())->db();
$params->apply($query, ["id", "username", "smtp_password", "lock_password"], ["username"], "id", ["id", "username"]);
$sql = $query->buildSql();
if (!str_contains($sql, "smtp_password") && !str_contains($sql, "lock_password")) {
    echo "SAFE_FILTER_SORT_OK";
} else {
    echo "LEAK_FOUND: " . $sql;
}
' > /tmp/hw_s07_test.txt
R=$(cat /tmp/hw_s07_test.txt); rm -f /tmp/hw_s07_test.txt
check "[S07] 含有 password/token/secret 字段被禁止用于 filter 和 sort" "$R" 'SAFE_FILTER_SORT_OK'

php -r '
require "vendor/autoload.php";
$app = new think\App();
$app->initialize();
try {
    $now = time();
    \think\facade\Db::name("user")->insert([
        "username" => "dup_test_user", "password" => "123", "create_time" => $now, "update_time" => $now
    ]);
    \think\facade\Db::name("user")->insert([
        "username" => "dup_test_user", "password" => "456", "create_time" => $now, "update_time" => $now
    ]);
    echo "USER_NOT_UNIQUE";
} catch (\Throwable) {
    echo "USER_UNIQUE_OK";
}
\think\facade\Db::name("user")->where("username", "dup_test_user")->delete();
' > /tmp/hw_s07_user_unique.txt
R=$(cat /tmp/hw_s07_user_unique.txt); rm -f /tmp/hw_s07_user_unique.txt
check "[S07] 会员表 username 唯一索引约束生效" "$R" 'USER_UNIQUE_OK'

# ==================== S08 私有附件受控存储与安全鉴权下载 ====================
echo "Secret Private Attachment Content" > /tmp/hw_private_test.txt
R=$(curl -s -H "$H" -F "file=@/tmp/hw_private_test.txt;filename=secret.txt" -F "storage=private" "$BASE/adminapi/system.attachment/upload")
rm -f /tmp/hw_private_test.txt
PRIV_ATTACH_ID=$(echo "$R" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
check "[S08] 私有附件上传成功并返回受控下载地址" "$R" '"code":1'

if [ -n "$PRIV_ATTACH_ID" ]; then
  DL_CONTENT=$(curl -s -H "$H" "$BASE/adminapi/system.attachment/download?id=$PRIV_ATTACH_ID")
  check "[S08] 带有效凭据下载私有附件正常获取" "$DL_CONTENT" 'Secret Private Attachment Content'

  DL_UNAUTH=$(curl -s "$BASE/adminapi/system.attachment/download?id=$PRIV_ATTACH_ID")
  check "[S08] 未带凭据访问私有附件被401拦截" "$DL_UNAUTH" '"code":401\|401 Unauthorized'

  DEL_R=$(curl -s -H "$H" -X POST -d "ids=$PRIV_ATTACH_ID" "$BASE/adminapi/system.attachment/del")
  check "[S08] 私有附件删除成功" "$DEL_R" '"code":1'
fi

# ==================== S09 全局安全头、CSP 与 RequestId 追踪 ====================
RESP_HEADERS=$(curl -s -I "$BASE/adminapi/index/info" -H "$H")
check "[S09] 响应头包含 X-Content-Type-Options" "$RESP_HEADERS" 'X-Content-Type-Options'
check "[S09] 响应头 nosniff 保护" "$RESP_HEADERS" 'nosniff'
check "[S09] 响应头包含 X-Frame-Options" "$RESP_HEADERS" 'X-Frame-Options'
check "[S09] 响应头包含 X-Request-Id" "$RESP_HEADERS" 'X-Request-Id:'
check "[S09] 响应头包含 Content-Security-Policy" "$RESP_HEADERS" 'Content-Security-Policy:'

# ==================== S10 企业级公共中台与运维工具箱新特性回归 ====================
# 1. 框架与插件在线更新与备份快照引擎 (SystemUpdater)
R=$(curl -s -H "$H" "$BASE/adminapi/system.plugin/checkUpdates")
check "[S10-1] 后台在线检测更新接口正常" "$R" '"code":1'
check "[S10-1] 返回框架或补丁更新状态" "$R" '"framework"'
check "[S10-1] 返回插件更新清单" "$R" '"plugins"'

# 2. 可视化 CRUD 插件接口
R=$(curl -s -H "$H" "$BASE/adminapi/plugin/crud/generator/tables")
check "[S10-2] CRUD 生成器获取表列表成功" "$R" '"code":1'
check "[S10-2] 表列表中包含核心表" "$R" 'admin'

R=$(curl -s -H "$H" "$BASE/adminapi/plugin/crud/generator/columns?table=admin")
check "[S10-2] CRUD 生成器读取字段推导成功" "$R" '"code":1'
check "[S10-2] 字段信息包含 username" "$R" 'username'

# 3. 数据字典
R=$(curl -s -H "$H" "$BASE/adminapi/system.dict/index")
check "[S10-3] 获取数据字典分类列表成功" "$R" '"code":1'
check "[S10-3] 包含预置字典 common_status" "$R" 'common_status'

R=$(curl -s -H "$H" "$BASE/adminapi/system.dict/map?codes=common_status,sys_yes_no")
check "[S10-3] 批量获取字典键值映射成功" "$R" '"code":1'
check "[S10-3] 字典映射包含正常状态项" "$R" '正常'

# 4. 可视化定时任务
R=$(curl -s -H "$H" "$BASE/adminapi/system.crontab/index")
check "[S10-4] 获取定时任务列表成功" "$R" '"code":1'
check "[S10-4] 包含预置任务" "$R" '清理过期验证码与临时缓存'

CRON_ID=$(echo "$R" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
if [ -n "$CRON_ID" ]; then
  R=$(curl -s -H "$H" -X POST -d "id=$CRON_ID" "$BASE/adminapi/system.crontab/runOnce")
  check "[S10-4] 立即执行一次定时任务成功" "$R" '"code":1'
  check "[S10-4] 返回执行耗时" "$R" '"duration_ms"'
fi

# 5. 开发者运维中心
R=$(curl -s -H "$H" "$BASE/adminapi/system.devops/apiDocs")
check "[S10-5] 自动反射全栈 API 接口文档成功" "$R" '"code":1'
check "[S10-5] 接口文档包含 adminapi" "$R" 'adminapi'

R=$(curl -s -H "$H" "$BASE/adminapi/system.devops/tables")
check "[S10-5] 获取数据库表空间体检成功" "$R" '"code":1'
check "[S10-5] 返回数据表列表" "$R" 'tables'

# ==================== S11 时序大盘、实时通知中心、全栈缓存与端云市场账号闭环 ====================
# 1. Dashboard 动态时序趋势与安全态势大盘
R=$(curl -s -H "$H" "$BASE/adminapi/dashboard/index")
check "[S11-1] 控制台大盘聚合接口正常响应" "$R" '"code":1'
check "[S11-1] 大盘返回近7天时序趋势 trend_7d" "$R" '"trend_7d"'
check "[S11-1] 大盘时序数据包含日期数组 dates" "$R" '"dates"'
check "[S11-1] 大盘时序数据包含请求统计 requests" "$R" '"requests"'
check "[S11-1] 大盘时序数据包含登录统计 logins" "$R" '"logins"'
check "[S11-1] 大盘返回系统安全态势评分 security_score" "$R" '"security_score"'
check "[S11-1] 安全态势包含检查细项 checks" "$R" '"checks"'
check "[S11-1] 大盘返回最新操作审计 recent_logs" "$R" '"recent_logs"'
check "[S11-1] 统计指标包含扩展字典与定时任务数" "$R" '"dict_count"'

# 2. 实时系统通知中心与巡检待办
R=$(curl -s -H "$H" "$BASE/adminapi/dashboard/notices")
check "[S11-2] 系统实时通知中心接口正常" "$R" '"code":1'
check "[S11-2] 返回通知列表 list" "$R" '"list"'
check "[S11-2] 返回未读统计 unread" "$R" '"unread"'

# 标记通知已读
R=$(curl -s -H "$H" -X POST -d "id=sec_admin_entry" "$BASE/adminapi/dashboard/readNotice")
check "[S11-2] 标记指定通知已读成功" "$R" '"code":1'

R=$(curl -s -H "$H" -X POST -d "clear_all=1" "$BASE/adminapi/dashboard/readNotice")
check "[S11-2] 一键全量标记通知已读成功" "$R" '"code":1'

# 3. 一键清理全栈系统缓存
R=$(curl -s -H "$H" -X POST -d "type=all" "$BASE/adminapi/dashboard/clearCache")
check "[S11-3] 一键清理全栈缓存成功" "$R" '"code":1'
check "[S11-3] 返回已清理文件数与释放空间" "$R" '"freed_size"'

R=$(curl -s -H "$H" -X POST -d "type=data" "$BASE/adminapi/dashboard/clearCache")
check "[S11-3] 单独清理数据缓存成功" "$R" '"code":1'

# 4. 端云市场网关配置与严格账号鉴权闭环
R=$(curl -s -H "$H" "$BASE/adminapi/system.plugin/market")
check "[S11-4] 获取应用市场目录与渠道配置正常" "$R" '"code":1'
check "[S11-4] 返回默认官方市场渠道字段 default_market_url" "$R" '"default_market_url"'

R=$(curl -s -H "$H" "$BASE/adminapi/system.plugin/marketAccount")
check "[S11-4] 获取市场绑定状态接口正常" "$R" '"code":1'
check "[S11-4] 包含绑定标识 bound" "$R" '"bound"'

# 严禁离线伪造登录或未绑定强制同步授权
R=$(curl -s -H "$H" -X POST -d "username=dev_enterprise&password=TestPassword123" "$BASE/adminapi/system.plugin/loginMarketAccount")
check "[S11-4] 离线或无效账号拒绝伪造绑定" "$R" '"code":0'

R=$(curl -s -H "$H" -X POST "$BASE/adminapi/system.plugin/syncMarketLicenses")
check "[S11-4] 未绑定令牌拒绝同步商城授权" "$R" '"code":0'

R=$(curl -s -H "$H" -X POST -d "name=cms" "$BASE/adminapi/system.plugin/downloadMarket")
check "[S11-4] 未登录绑定官网账号拒绝从应用商城安装应用" "$R" '"need_login":true'

R=$(curl -s -H "$H" -X POST -d "market_server_url=ftp://invalid.example.com" "$BASE/adminapi/system.plugin/saveMarketUrl")
check "[S11-4] 非法商城节点地址协议被拒绝" "$R" '"code":0'

R=$(curl -s -H "$H" -X POST -d "market_server_url=" "$BASE/adminapi/system.plugin/saveMarketUrl")
check "[S11-4] 清空自定义节点恢复官方默认渠道" "$R" '"code":1'

# 退出市场账号绑定
R=$(curl -s -H "$H" -X POST "$BASE/adminapi/system.plugin/logoutMarketAccount")
check "[S11-4] 解绑退出市场账号成功" "$R" '"code":1'

R=$(curl -s -H "$H" -X POST "$BASE/adminapi/index/logout");  check "退出登录" "$R" '"code":1'
R=$(curl -s -H "$H" "$BASE/adminapi/index/info");            check "退出后令牌失效" "$R" '"code":401'

php think hw:plugin uninstall smoke >/dev/null && echo "✓ 插件卸载"
php tests/isolation_test.php
echo "全部通过"
