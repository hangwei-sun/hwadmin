<?php
declare(strict_types=1);

namespace core\support;

use core\exception\ApiException;

/**
 * 出站 HTTP 安全守卫（SSRF 防护、协议收敛与同源校验）
 */
class OutboundHttpGuard
{
    /** 明确拦截的危险主机名 */
    private const BLOCKED_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
        'metadata.google.internal',
        'metadata.goog',
        '169.254.169.254',
        '100.100.100.200',
        'fd00:ec2::254',
    ];

    /**
     * 校验出站 URL 安全性，拦截非 HTTP(S) 协议及内网/回环/云元数据地址
     */
    public static function assertSafeUrl(string $url, bool $allowPrivate = false): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\r\n\0]/', $url)) {
            throw new ApiException('非法的请求 URL');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ApiException("非法的 URL 格式：{$url}");
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new ApiException("无法解析 URL：{$url}");
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ApiException("禁止使用非 HTTP(S) 协议：{$scheme}");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiException('URL 不允许包含内嵌认证凭据');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '') {
            throw new ApiException('URL 缺少有效主机名');
        }

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                throw new ApiException('URL 端口号超出合法范围');
            }
        }

        if (!$allowPrivate) {
            self::assertPublicHost($host);
        }

        return $url;
    }

    /**
     * 校验主机名与解析后的 IP 均为公网地址
     */
    public static function assertPublicHost(string $host): void
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new ApiException("安全拦截：禁止访问受限地址 [{$host}]");
        }

        foreach (['.local', '.internal', '.localhost', '.arpa'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new ApiException("安全拦截：禁止访问内网域名 [{$host}]");
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($host)) {
                throw new ApiException("安全拦截：禁止访问内网/回环/保留 IP 地址 [{$host}]");
            }
            return;
        }

        // 域名解析检查：若当前环境可解析到 IP，必须全部为公网 IP
        foreach (self::resolveHostIps($host) as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new ApiException("安全拦截：域名 [{$host}] 解析到内网或受限 IP [{$ip}]");
            }
        }
    }

    /**
     * 判断 IP 是否为公网可路由地址（拦截回环、私网、链路本地/云元数据、CGNAT、组播与保留段）
     */
    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // 处理 IPv4-mapped IPv6 地址（如 ::ffff:127.0.0.1 / ::ffff:169.254.169.254）
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            if (substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
                $mappedV4 = @inet_ntop(substr($packed, 12, 4));
                if ($mappedV4 !== false) {
                    $ip = $mappedV4;
                }
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return false;
            }
            // 100.64.0.0/10 (CGNAT)
            if (($long & 0xFFC00000) === 0x64400000) {
                return false;
            }
            // 169.254.0.0/16 (Link-Local / Cloud Metadata)
            if (($long & 0xFFFF0000) === 0xA9FE0000) {
                return false;
            }
            // 127.0.0.0/8 (Loopback)
            if (($long & 0xFF000000) === 0x7F000000) {
                return false;
            }
            // 0.0.0.0/8
            if (($long & 0xFF000000) === 0x00000000) {
                return false;
            }
            // 224.0.0.0/4 (Multicast / Reserved)
            if (($long & 0xE0000000) === 0xE0000000) {
                return false;
            }
        }

        return true;
    }

    /**
     * 为 cURL 句柄统一注入安全协议限制与 TLS 校验
     *
     * @param \CurlHandle|resource $ch
     */
    public static function applyCurlOptions($ch, bool $verifyTls = true): void
    {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
    }

    /**
     * 判断目标 URL 是否与任一受信任基地址同源（协议 + 主机 + 端口）
     *
     * @param string[] $trustedBaseUrls
     */
    public static function isSameOrigin(string $url, array $trustedBaseUrls): bool
    {
        $target = self::extractOrigin($url);
        if ($target === null) {
            return false;
        }
        foreach ($trustedBaseUrls as $base) {
            $origin = self::extractOrigin((string) $base);
            if ($origin !== null && $origin === $target) {
                return true;
            }
        }
        return false;
    }

    /**
     * 安全执行带重定向校检的 HTTP GET 下载到文件
     *
     * @return array{ok: bool, http_code: int, content_type: string, error: string}
     */
    public static function downloadToFile(
        string $url,
        string $destFile,
        array $headers = [],
        int $timeout = 30,
        int $connectTimeout = 5,
        int $maxRedirects = 3,
        bool $verifyTls = true
    ): array {
        $currentUrl = self::assertSafeUrl($url);
        $lastError = '';
        $httpCode = 0;
        $contentType = '';

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $fp = @fopen($destFile, 'wb');
            if ($fp === false) {
                throw new ApiException('无法写入本地临时下载文件');
            }

            $redirectUrl = null;
            $ch = curl_init($currentUrl);
            self::applyCurlOptions($ch, $verifyTls);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, false);
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $headerLine) use (&$redirectUrl): int {
                $len = strlen($headerLine);
                if (stripos($headerLine, 'Location:') === 0) {
                    $redirectUrl = trim(substr($headerLine, 9));
                }
                return $len;
            });

            $ok = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $lastError = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
                @curl_close($ch);
            }
            @fclose($fp);

            if ($ok && $httpCode >= 300 && $httpCode < 400 && $redirectUrl !== null && $redirectUrl !== '') {
                @unlink($destFile);
                $currentUrl = self::resolveRedirectUrl($currentUrl, $redirectUrl);
                $currentUrl = self::assertSafeUrl($currentUrl);
                continue;
            }

            return [
                'ok'           => (bool) $ok,
                'http_code'    => $httpCode,
                'content_type' => $contentType,
                'error'        => $lastError,
            ];
        }

        @unlink($destFile);
        return [
            'ok'           => false,
            'http_code'    => $httpCode,
            'content_type' => $contentType,
            'error'        => '重定向次数超过上限',
        ];
    }

    /**
     * 安全执行 HTTP 请求（校验每个重定向目标防止 302 SSRF）
     *
     * @return array{ok: bool, http_code: int, body: string, error: string}
     */
    public static function request(
        string $url,
        string $method = 'GET',
        string $postBody = '',
        array $headers = [],
        int $timeout = 10,
        int $connectTimeout = 5,
        int $maxRedirects = 3,
        string $userAgent = 'HwAdmin-Http-Client/1.0',
        bool $verifyTls = true
    ): array {
        $currentUrl = self::assertSafeUrl($url);
        $method = strtoupper($method);
        $lastError = '';
        $httpCode = 0;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $redirectUrl = null;
            $ch = curl_init($currentUrl);
            self::applyCurlOptions($ch, $verifyTls);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            if ($method === 'POST' && $hop === 0) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
            }
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $headerLine) use (&$redirectUrl): int {
                $len = strlen($headerLine);
                if (stripos($headerLine, 'Location:') === 0) {
                    $redirectUrl = trim(substr($headerLine, 9));
                }
                return $len;
            });

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastError = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
                @curl_close($ch);
            }

            if ($body !== false && $httpCode >= 300 && $httpCode < 400 && $redirectUrl !== null && $redirectUrl !== '') {
                $currentUrl = self::resolveRedirectUrl($currentUrl, $redirectUrl);
                $currentUrl = self::assertSafeUrl($currentUrl);
                continue;
            }

            return [
                'ok'        => $body !== false,
                'http_code' => $httpCode,
                'body'      => $body === false ? '' : (string) $body,
                'error'     => $lastError,
            ];
        }

        return [
            'ok'        => false,
            'http_code' => $httpCode,
            'body'      => '',
            'error'     => '重定向次数超过上限',
        ];
    }

    private static function extractOrigin(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        return "{$scheme}://{$host}:{$port}";
    }

    private static function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            throw new ApiException('无效的重定向目标地址');
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $basePath = (string) ($base['path'] ?? '/');
        $dir = rtrim(substr($basePath, 0, (int) strrpos($basePath, '/') + 1), '/');
        return $origin . $dir . '/' . $location;
    }

    /**
     * @return string[]
     */
    private static function resolveHostIps(string $host): array
    {
        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $rec) {
                    if (!empty($rec['ip'])) {
                        $ips[] = (string) $rec['ip'];
                    } elseif (!empty($rec['ipv6'])) {
                        $ips[] = (string) $rec['ipv6'];
                    }
                }
            }
        }
        if (empty($ips) && function_exists('gethostbynamel')) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
        }
        return array_values(array_unique($ips));
    }
}
