<?php
declare(strict_types=1);

namespace core\plugin;

use core\exception\ApiException;

/**
 * 插件静态资源发布：plugins/{name}/public/ → public/plugins/{name}/
 *
 * 前端运行时加载的插件页面包、图片等放在插件 public/ 目录，启用时复制，禁用/卸载时删除。
 * 采用复制而非软链接：Windows/容器/宝塔环境下软链接常因权限或 open_basedir 失效。
 */
final class AssetPublisher
{
    public static function publish(string $name): void
    {
        $src = plugin_path($name) . 'public';
        if (!is_dir($src)) {
            return;
        }
        $dst = self::target($name);
        // 增量覆写同步插件静态资源，保留用户自定义静态文件（如 .custom 或扩展资源）
        self::copy($src, $dst);
    }

    public static function unpublish(string $name): void
    {
        self::remove(self::target($name));
    }

    public static function target(string $name): string
    {
        return public_path() . trim((string) config('hwadmin.plugin.asset_path', 'plugins'), '/\\') . DIRECTORY_SEPARATOR . $name;
    }

    private static function copy(string $src, string $dst): void
    {
        if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) {
            throw new ApiException("发布插件静态资源失败，无法创建目录：{$dst}");
        }
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . DIRECTORY_SEPARATOR . $item;
            $to = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($from)) {
                self::copy($from, $to);
            } elseif (!str_ends_with(strtolower($item), '.php')) {
                // 不发布任何 PHP 文件到 web 根目录；若目标已存在则先尝试移除以兼容不同属主的旧文件
                if (is_file($to)) {
                    @unlink($to);
                }
                if (!@copy($from, $to)) {
                    throw new ApiException("发布插件静态资源失败，请检查目录写入权限：{$to}");
                }
            }
        }
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::remove($path);
            } elseif (!@unlink($path) && is_file($path)) {
                throw new ApiException("清理插件旧静态资源失败，请检查文件权限：{$path}");
            }
        }
        if (!@rmdir($dir) && is_dir($dir)) {
            throw new ApiException("清理插件旧静态资源目录失败，请检查目录权限：{$dir}");
        }
    }
}
