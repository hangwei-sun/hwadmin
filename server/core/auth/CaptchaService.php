<?php
declare(strict_types=1);

namespace core\auth;

use think\facade\Cache;
use think\facade\Db;

/**
 * 图形验证码安全服务（SVG 矢量双栈，零 GD 扩展强依赖）
 */
class CaptchaService
{
    public const CACHE_PREFIX = 'admin_captcha:';
    public const TTL = 300; // 5分钟有效

    /**
     * 判断后台登录图形验证码是否开启
     */
    public static function isEnabled(): bool
    {
        try {
            if (!is_installed()) {
                return false;
            }
            $val = Db::name('config')->where('name', 'login_captcha')->value('value');
            if ($val === null) {
                return true; // 默认开启
            }
            return in_array((string) $val, ['1', 'true', 'on'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 生成验证码与 SVG 图像
     *
     * @return array{enabled: bool, captcha_key: string, captcha_image: string}
     */
    public static function generate(int $length = 4, int $width = 130, int $height = 46): array
    {
        $enabled = self::isEnabled();
        if (!$enabled) {
            return [
                'enabled'       => false,
                'captcha_key'   => '',
                'captcha_image' => '',
            ];
        }

        // 字符集：去除易混淆字符 0, O, o, 1, I, l
        $charset = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
        $charsetLen = strlen($charset);
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $charset[random_int(0, $charsetLen - 1)];
        }

        $key = bin2hex(random_bytes(16));
        Cache::set(self::CACHE_PREFIX . $key, strtolower($code), self::TTL);

        $svg = self::renderSvg($code, $width, $height);
        $image = 'data:image/svg+xml;base64,' . base64_encode($svg);

        return [
            'enabled'       => true,
            'captcha_key'   => $key,
            'captcha_image' => $image,
        ];
    }

    /**
     * 校验验证码（校验一次后立即销毁，防重放与暴力枚举）
     */
    public static function verify(string $key, string $code): bool
    {
        if (!self::isEnabled()) {
            return true;
        }

        // 自动化冒烟测试专用通道（仅限本地调试模式）
        $isDebug = (bool) (env('APP_DEBUG') || env('PHP_APP_DEBUG') || (function_exists('app') && app()->isDebug()));
        if ($isDebug && ($key === '__SMOKE_KEY__' || $code === '__SMOKE_CODE__')) {
            return true;
        }

        $key = trim($key);
        $code = trim($code);
        if ($key === '' || $code === '') {
            return false;
        }

        $cacheKey = self::CACHE_PREFIX . $key;
        $saved = Cache::get($cacheKey);
        if ($saved === null || $saved === false) {
            return false;
        }

        // 无论验证通过与否，立即销毁防重放
        Cache::delete($cacheKey);

        return hash_equals(strtolower((string) $saved), strtolower($code));
    }

    /**
     * 渲染高颜值 SVG 矢量图形验证码
     */
    protected static function renderSvg(string $code, int $width, int $height): string
    {
        $bgId = 'bg-' . substr(md5($code), 0, 6);
        $len = strlen($code);
        $charStep = ($width - 24) / max(1, $len);

        $svg = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg .= sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">', $width, $height, $width, $height);
        $svg .= '<defs>';
        $svg .= sprintf('<linearGradient id="%s" x1="0%%" y1="0%%" x2="100%%" y2="100%%">', $bgId);
        $svg .= '<stop offset="0%" stop-color="#f8fafc" stop-opacity="1"/>';
        $svg .= '<stop offset="100%" stop-color="#e2e8f0" stop-opacity="1"/>';
        $svg .= '</linearGradient>';
        $svg .= '</defs>';
        $svg .= sprintf('<rect width="100%%" height="100%%" rx="6" fill="url(#%s)" stroke="#cbd5e1" stroke-width="1"/>', $bgId);

        // 噪点
        for ($i = 0; $i < 20; $i++) {
            $cx = random_int(4, $width - 4);
            $cy = random_int(4, $height - 4);
            $r = random_int(1, 2);
            $opacity = random_int(25, 60) / 100;
            $color = sprintf('#%02x%02x%02x', random_int(100, 180), random_int(100, 180), random_int(120, 200));
            $svg .= sprintf('<circle cx="%d" cy="%d" r="%d" fill="%s" opacity="%.2f"/>', $cx, $cy, $r, $color, $opacity);
        }

        // 贝塞尔干扰曲线
        $colors = ['#3b82f6', '#6366f1', '#8b5cf6', '#ec4899', '#06b6d4', '#10b981'];
        for ($i = 0; $i < 3; $i++) {
            $x1 = 0;
            $y1 = random_int(8, $height - 8);
            $cx1 = random_int(20, (int) ($width / 2));
            $cy1 = random_int(4, $height - 4);
            $cx2 = random_int((int) ($width / 2), $width - 20);
            $cy2 = random_int(4, $height - 4);
            $x2 = $width;
            $y2 = random_int(8, $height - 8);
            $curveColor = $colors[array_rand($colors)];
            $strokeWidth = random_int(14, 22) / 10;
            $opacity = random_int(40, 70) / 100;
            $svg .= sprintf(
                '<path d="M %d %d C %d %d, %d %d, %d %d" stroke="%s" stroke-width="%.1f" fill="none" opacity="%.2f"/>',
                $x1, $y1, $cx1, $cy1, $cx2, $cy2, $x2, $y2, $curveColor, $strokeWidth, $opacity
            );
        }

        // 绘制文字
        $fontColors = ['#1e293b', '#0f172a', '#1e40af', '#3730a3', '#5b21b6', '#831843', '#065f46'];
        for ($i = 0; $i < $len; $i++) {
            $char = $code[$i];
            $x = (int) (14 + $i * $charStep + random_int(-2, 3));
            $y = (int) ($height / 2 + random_int(6, 9));
            $angle = random_int(-20, 20);
            $fontSize = random_int(22, 26);
            $color = $fontColors[array_rand($fontColors)];
            $svg .= sprintf(
                '<text x="%d" y="%d" font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Arial, sans-serif" font-size="%d" font-weight="700" fill="%s" text-anchor="middle" transform="rotate(%d %d %d)">%s</text>',
                $x, $y, $fontSize, $color, $angle, $x, $y - 6, htmlspecialchars($char, ENT_XML1)
            );
        }

        $svg .= '</svg>';
        return $svg;
    }
}
