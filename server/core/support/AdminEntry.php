<?php
declare(strict_types=1);

namespace core\support;

use think\facade\Db;

/**
 * 管理后台安全入口管理
 */
class AdminEntry
{
    protected static ?string $cachedEntry = null;

    /**
     * 获取当前系统配置的后台入口路径（小写，不带前后斜杠）
     */
    public static function getEntry(): string
    {
        if (self::$cachedEntry !== null) {
            return self::$cachedEntry;
        }

        $runtimeFile = self::getRuntimeFilePath();
        if (is_file($runtimeFile)) {
            $val = trim((string) @file_get_contents($runtimeFile));
            if ($val !== '' && self::isValid($val)) {
                self::$cachedEntry = $val;
                return $val;
            }
        }

        // 尝试从数据库配置表读取
        try {
            if (is_installed()) {
                $val = Db::name('config')->where('name', 'admin_entry')->value('value');
                if ($val !== null && trim((string) $val) !== '') {
                    $entry = trim((string) $val);
                    if (self::isValid($entry)) {
                        self::$cachedEntry = $entry;
                        @file_put_contents($runtimeFile, $entry);
                        return $entry;
                    }
                }
            }
        } catch (\Throwable) {
            // 数据库未就绪
        }

        self::$cachedEntry = 'admin';
        return 'admin';
    }

    /**
     * 清理进程内静态入口缓存（常驻内存 Worker / 单元测试防跨请求静态污染）
     */
    public static function clearCache(): void
    {
        self::$cachedEntry = null;
    }

    /**
     * 设置后台安全入口并同步写入运行时缓存
     */
    public static function setEntry(string $entry): void
    {
        $entry = trim($entry);
        self::$cachedEntry = $entry;

        $runtimeFile = self::getRuntimeFilePath();
        $runtimeDir = dirname($runtimeFile);
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }
        @file_put_contents($runtimeFile, $entry);
    }

    /**
     * 获取固定的根 runtime 文件路径
     */
    public static function getRuntimeFilePath(): string
    {
        if (function_exists('app')) {
            try {
                $rootPath = app()->getRootPath();
                if (!empty($rootPath)) {
                    return rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'admin_entry.txt';
                }
            } catch (\Throwable) {
                // 回退到基于文件路径推导
            }
        }
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'admin_entry.txt';
    }

    /**
     * 校验入口字符串是否符合安全规范（3-32位英文数字连字符，非系统保留字）
     */
    public static function isValid(string $entry): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $entry)) {
            return false;
        }

        $reserved = ['api', 'adminapi', 'install', 'static', 'storage', 'plugins', 'index', 'core', 'config', 'runtime', 'view'];
        return !in_array(strtolower($entry), $reserved, true);
    }

    /**
     * 读取并改写后台入口 HTML 的相对资源路径，确保在自定义入口路径下（如 /shw/ 或 /shw）也能正确加载 /admin/ 静态资源
     */
    public static function renderAdminHtml(string $adminHtmlPath): string
    {
        if (!is_file($adminHtmlPath)) {
            return '';
        }

        $html = (string) file_get_contents($adminHtmlPath);
        return str_replace(
            ['href="./', 'src="./', "href='./", "src='./"],
            ['href="/admin/', 'src="/admin/', "href='/admin/", "src='/admin/"],
            $html
        );
    }
}
