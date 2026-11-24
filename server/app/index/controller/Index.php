<?php
declare(strict_types=1);

namespace app\index\controller;

use core\plugin\PluginManager;
use core\support\AdminEntry;
use think\facade\Db;
use think\Response;

/**
 * 站点门户与动态安全入口控制器
 *
 * 1. 根路径 (/)：根据系统配置 home_mode 与 home_plugin 动态渲染门户页面、官网静态前台或插件接管
 * 2. 入口路径 (/{admin_entry})：动态承载管理后台页面；若 admin_entry 修改后访问原 /admin 则拦截返回 404
 */
class Index
{
    /**
     * PC 首页总调度入口
     */
    public function index(): Response
    {
        if (!is_installed()) {
            return redirect('/install/');
        }

        $configs = $this->loadConfigs();
        $homeMode = (string) ($configs['home_mode'] ?? 'cms');
        $homePlugin = (string) ($configs['home_plugin'] ?? 'cms');
        $adminEntry = (string) ($configs['admin_entry'] ?? 'admin');

        // 1. 自动跳转管理后台
        if ($homeMode === 'redirect_admin') {
            return redirect('/' . trim($adminEntry, '/') . '/');
        }

        // 2. CMS 门户或插件接管（仅当对应插件真实启用时才由插件接管）
        if ($homeMode === 'cms' || ($homeMode === 'custom_plugin' && $homePlugin !== '')) {
            $plugin = ($homeMode === 'cms') ? 'cms' : $homePlugin;
            if ($this->isPluginEnabled($plugin)) {
                $html = $this->resolvePluginView($plugin, $configs);
                if ($html !== null) {
                    return response($html, 200, [
                        'Content-Type'  => 'text/html; charset=utf-8',
                        'Cache-Control' => 'no-cache',
                    ]);
                }
            }
        }

        // 3. 未安装启用 cms 插件且已存在业务官网静态页 public/site/index.html 时，直接展示官网前台
        $siteHtmlPath = public_path() . 'site/index.html';
        if ($homeMode === 'cms' && is_file($siteHtmlPath)) {
            return $this->renderSiteHtml($siteHtmlPath, true, $configs);
        }

        // 4. 默认品牌门户页 (portal)
        $html = $this->resolvePortalView($configs);
        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * 官网静态前台直接访问入口（/index/index/site）
     */
    public function site(): Response
    {
        $siteHtmlPath = public_path() . 'site/index.html';
        if (is_file($siteHtmlPath)) {
            return $this->renderSiteHtml($siteHtmlPath, false, $this->loadConfigs());
        }

        return $this->notFoundResponse();
    }

    /**
     * 动态后台安全入口分发与原 /admin 隐藏拦截
     */
    public function entry(string $entry): Response
    {
        $adminEntry = AdminEntry::getEntry();
        $adminHtmlPath = public_path() . 'admin/index.html';
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cache-Control' => 'no-cache',
        ];

        // 访问默认 /admin
        if ($entry === 'admin') {
            if ($adminEntry !== 'admin') {
                return $this->notFoundResponse();
            }
            if (is_file($adminHtmlPath)) {
                return response(AdminEntry::renderAdminHtml($adminHtmlPath), 200, $headers);
            }
        }

        // 访问自定义安全入口
        if ($entry === $adminEntry && $adminEntry !== 'admin') {
            if (is_file($adminHtmlPath)) {
                return response(AdminEntry::renderAdminHtml($adminHtmlPath), 200, $headers);
            }
        }

        // 访问 /site 时跳转到静态官网入口（非自定义后台入口时）
        if ($entry === 'site' && is_file(public_path() . 'site/index.html')) {
            return redirect('/site/index.html');
        }

        return $this->notFoundResponse();
    }

    /**
     * 判断插件是否已安装并启用
     */
    protected function isPluginEnabled(string $plugin): bool
    {
        try {
            return (new PluginManager(app()))->isEnabled($plugin);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 渲染官网静态前台 HTML
     */
    protected function renderSiteHtml(string $siteHtmlPath, bool $fromRoot, array $configs = []): Response
    {
        $html = (string) file_get_contents($siteHtmlPath);
        if ($fromRoot) {
            $html = str_replace(
                ["fetch('brand.json')", 'fetch("brand.json")', "fetch('./brand.json')", 'fetch("./brand.json")'],
                ["fetch('/site/brand.json')", 'fetch("/site/brand.json")', "fetch('/site/brand.json')", 'fetch("/site/brand.json")'],
                $html
            );
        }

        $siteFavicon = $this->resolveFaviconUrl($configs);
        if (str_contains($html, '{{site_favicon}}')) {
            $html = str_replace('{{site_favicon}}', htmlspecialchars($siteFavicon, ENT_QUOTES, 'UTF-8'), $html);
        } elseif (!preg_match('/<link\b[^>]*rel=["\'][^"\']*icon[^"\']*["\']/i', $html) && stripos($html, '</head>') !== false) {
            $iconTag = '<link rel="icon" href="' . htmlspecialchars($siteFavicon, ENT_QUOTES, 'UTF-8') . '">' . "\n";
            $html = preg_replace('/<\/head>/i', $iconTag . '</head>', $html, 1) ?? $html;
        }

        return response($html, 200, [
            'Content-Type'           => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-cache',
        ]);
    }

    /**
     * 加载基础配置项
     */
    protected function loadConfigs(): array
    {
        try {
            return Db::name('config')
                ->whereIn('group', ['basic', 'security'])
                ->column('value', 'name');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 渲染默认品牌门户模板
     */
    protected function resolvePortalView(array $configs): string
    {
        $viewPath = app_path() . 'view/portal/index.html';
        if (is_file($viewPath)) {
            $content = (string) file_get_contents($viewPath);
            return $this->replaceTemplateVars($content, $configs);
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>HwAdmin</title></head><body style="text-align:center;padding:100px;font-family:sans-serif;"><h1>HwAdmin · 让你的 AI 智能体读懂并快速创造应用</h1><p>系统运行正常</p></body></html>';
    }

    /**
     * 解析插件接管首页模板
     */
    protected function resolvePluginView(string $plugin, array $configs): ?string
    {
        $candidatePaths = [
            root_path() . "plugins/{$plugin}/public/index.html",
            public_path() . "plugins/{$plugin}/index.html",
            root_path() . "plugins/{$plugin}/view/index.html",
            app_path() . "view/{$plugin}/index.html",
        ];

        foreach ($candidatePaths as $path) {
            if (is_file($path)) {
                $content = (string) file_get_contents($path);
                return $this->replaceTemplateVars($content, $configs);
            }
        }

        return null;
    }

    /**
     * 替换模板变量
     */
    protected function replaceTemplateVars(string $content, array $configs): string
    {
        $siteName = trim((string) ($configs['site_name'] ?? ''));
        if ($siteName === '') {
            $siteName = 'HwAdmin';
        }
        $siteDesc = (string) ($configs['site_description'] ?? 'HwAdmin — 让你的 AI 智能体读懂并快速创造应用的开源全栈智能框架，支持网站、管理后台与微信小程序快速开发部署。');
        $siteLogo = $this->normalizeAssetUrl((string) ($configs['site_logo'] ?? ''));
        $siteFavicon = $this->resolveFaviconUrl($configs);
        $siteCopyright = (string) ($configs['site_copyright'] ?? 'Copyright © 2026 HwAdmin. All rights reserved.');
        $beian = (string) ($configs['beian'] ?? '');

        $logoPath = $siteLogo !== '' ? $siteLogo : '/brand/logo-square.webp';
        $logoRender = sprintf(
            '<img src="%s" alt="%s" />',
            htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8')
        );

        $vars = [
            '{{site_name}}'        => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
            '{{site_description}}' => htmlspecialchars($siteDesc, ENT_QUOTES, 'UTF-8'),
            '{{site_logo}}'        => htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8'),
            '{{site_logo_render}}' => $logoRender,
            '{{site_favicon}}'     => htmlspecialchars($siteFavicon, ENT_QUOTES, 'UTF-8'),
            '{{site_copyright}}'   => htmlspecialchars($siteCopyright, ENT_QUOTES, 'UTF-8'),
            '{{beian}}'            => htmlspecialchars($beian, ENT_QUOTES, 'UTF-8'),
        ];

        return strtr($content, $vars);
    }

    /**
     * 解析站点 Favicon 地址：优先使用后台配置的 site_favicon，未配置时与后台保持一致
     */
    protected function resolveFaviconUrl(array $configs): string
    {
        $configured = $this->normalizeAssetUrl((string) ($configs['site_favicon'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        if (is_file(public_path() . 'favicon.svg')) {
            return '/favicon.svg';
        }
        if (is_file(public_path() . 'admin/favicon.svg')) {
            return '/admin/favicon.svg';
        }
        return '/favicon.ico';
    }

    /**
     * 规范化站点资源路径（与前端 resolveConfiguredAsset 保持一致）
     */
    protected function normalizeAssetUrl(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }
        if (preg_match('/^(?:https?:|data:|blob:)/i', $normalized)) {
            return $normalized;
        }
        return str_starts_with($normalized, '/') ? $normalized : '/' . $normalized;
    }

    /**
     * 返回 404 Not Found 响应
     */
    protected function notFoundResponse(): Response
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404 Not Found</title></head><body><h1 style="text-align:center;margin-top:100px;font-family:sans-serif;color:#64748b;">404 Not Found</h1></body></html>';
        return response($html, 404, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
