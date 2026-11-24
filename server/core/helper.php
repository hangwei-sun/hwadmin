<?php
// +----------------------------------------------------------------------
// | HwAdmin 全局助手函数（composer autoload.files 自动加载）
// | 规则：只放无状态、通用的小函数；业务逻辑放 Service。
// +----------------------------------------------------------------------
declare(strict_types=1);

if (!function_exists('hw_version')) {
    /**
     * 获取框架版本号
     */
    function hw_version(): string
    {
        return (string) config('hwadmin.version', '0.0.0');
    }
}

if (!function_exists('plugin_path')) {
    /**
     * 获取插件目录（绝对路径，以目录分隔符结尾）
     * @param string $name 插件标识，为空返回插件根目录
     */
    function plugin_path(string $name = ''): string
    {
        $base = root_path() . trim((string) config('hwadmin.plugin.path', 'plugins'), '/\\') . DIRECTORY_SEPARATOR;
        return $name === '' ? $base : $base . $name . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('is_installed')) {
    /**
     * 系统是否已完成安装（安装后生成 config/install.lock）
     */
    function is_installed(): bool
    {
        return is_file(config_path() . 'install.lock');
    }
}
