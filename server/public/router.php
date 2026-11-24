<?php
declare(strict_types=1);

// +----------------------------------------------------------------------
// | HwAdmin 内置 PHP 开发服务器路由器 (php -S)
// +----------------------------------------------------------------------

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

// 禁止直接访问任何以点开头的敏感文件或敏感扩展名文件
if (preg_match('#(?:^|/)\.|\.(?:env|lock|token|sqlite|sql|bak|sh|log|inc)$#i', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// 禁止在 uploads 目录中请求执行任何脚本文件（S08）
if (preg_match('#^/uploads/.*\.(?:php\d*|phtml|phar|sh|pl|cgi|py|jsp|asp|aspx)$#i', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// 拦截已确认含模板授权限制的历史构建；这些旧文件可能仍保留在部署目录。
if (preg_match('#^/admin/static/js/(?:index-(?:B90ludh5|DRB4f58U|CB1tkGEK|DeU_C10w)|vsv-icon-(?:CpK_sqA-|ZopMm1A_|Bm9aixqR|CkhPEWur))\.js$#i', $uri)) {
    http_response_code(404);
    return true;
}

// 获取后台安全入口路径（优先 runtime 缓存，其次 sqlite 库，最后默认 admin）
$adminEntry = 'admin';
$runtimeEntryFile = dirname(__DIR__) . '/runtime/admin_entry.txt';
if (!is_file($runtimeEntryFile) && is_file(dirname(__DIR__) . '/runtime/adminapi/admin_entry.txt')) {
    $runtimeEntryFile = dirname(__DIR__) . '/runtime/adminapi/admin_entry.txt';
}
if (is_file($runtimeEntryFile)) {
    $c = trim((string) @file_get_contents($runtimeEntryFile));
    if ($c !== '' && preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $c)) {
        $adminEntry = $c;
    }
} else {
    $dbName = getenv('DB_NAME') ?: getenv('PHP_DB_NAME');
    if (!$dbName) {
        foreach (['runtime/hwadmin.sqlite', 'runtime/dev.sqlite'] as $relSqlite) {
            $candidateSqlite = dirname(__DIR__) . '/' . $relSqlite;
            if (is_file($candidateSqlite)) {
                $dbName = $candidateSqlite;
                break;
            }
        }
    }
    if ($dbName && is_file($dbName)) {
        try {
            $pdo = new PDO('sqlite:' . $dbName);
            $prefix = getenv('DB_PREFIX') ?: getenv('PHP_DB_PREFIX') ?: 'hw_';
            $stmt = $pdo->query("SELECT value FROM {$prefix}config WHERE name = 'admin_entry' LIMIT 1");
            if ($stmt && ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
                $val = trim((string) ($row['value'] ?? ''));
                if ($val !== '' && preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $val)) {
                    $adminEntry = $val;
                    @file_put_contents($runtimeEntryFile, $adminEntry);
                }
            }
        } catch (\Throwable) {
        }
    }
}

$cleanPath = rtrim($uri, '/');
if ($cleanPath === '') {
    $cleanPath = '/';
}

// 1. 检查是否访问默认 /admin 或 /admin/index.html
$isAdminPath = in_array($cleanPath, ['/admin', '/admin/index.html'], true) || $uri === '/admin/index.html';
if ($isAdminPath) {
    if ($adminEntry !== 'admin') {
        // 自定义安全入口已生效，默认 /admin 必须拦截隐藏，严禁返回后台页面，返回 404
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404 Not Found</title></head><body><h1 style="text-align:center;margin-top:100px;font-family:sans-serif;color:#64748b;">404 Not Found</h1></body></html>';
        return true;
    }

    if (is_file(__DIR__ . '/admin/index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        echo str_replace(
            ['href="./', 'src="./', "href='./", "src='./"],
            ['href="/admin/', 'src="/admin/', "href='/admin/", "src='/admin/"],
            (string) file_get_contents(__DIR__ . '/admin/index.html')
        );
        return true;
    }
}

// 2. 检查是否访问自定义安全入口
$isCustomEntry = ($adminEntry !== 'admin') && (
    $cleanPath === '/' . $adminEntry ||
    $cleanPath === '/' . $adminEntry . '/index.html' ||
    $uri === '/' . $adminEntry . '/index.html'
);
if ($isCustomEntry) {
    if (is_file(__DIR__ . '/admin/index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        echo str_replace(
            ['href="./', 'src="./', "href='./", "src='./"],
            ['href="/admin/', 'src="/admin/', "href='/admin/", "src='/admin/"],
            (string) file_get_contents(__DIR__ . '/admin/index.html')
        );
        return true;
    }
}

// 3. 若请求路径对应 public/ 下的真实物理文件则直接返回 false 由内置服务器处理
$filePath = __DIR__ . $uri;
if ($uri !== '/' && is_file($filePath)) {
    return false;
}

// 4. 其余所有请求交由单一入口 index.php 执行
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';

require __DIR__ . '/index.php';
