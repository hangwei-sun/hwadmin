<?php
declare(strict_types=1);

namespace core\auth;

use Closure;
use Throwable;
use think\App;

/**
 * 密码恢复真实通知渠道分发器（T07）
 *
 * 可挂载到 ThinkPHP `UserPasswordResetRequested` 事件：
 * - 支持 SMTP 邮件 / 签名 Webhook / 短信网关三种适配器配置；
 * - 未配置有效真实渠道或缺少收件目标时严格返回 false，触发 CONFIGURE_PASSWORD_RECOVERY_DELIVERY；
 * - 配置有效渠道并通过送达校验时返回严格 true，并写入脱敏审计日志（绝不落盘明文凭证或密钥）。
 */
class PasswordRecoveryChannelService
{
    /** @var callable|Closure|null 仅允许在 APP_ENV=testing 中注入的离线传输器 */
    protected static $testTransport = null;

    public function __construct(protected ?App $app = null)
    {
        $this->app = $app ?: app();
    }

    /**
     * 将本服务挂载到应用事件总线
     */
    public static function register(App $app): void
    {
        $app->event->listen('UserPasswordResetRequested', self::class);
    }

    /**
     * 注入测试传输适配器（仅 APP_ENV=testing 可用）
     * 签名: fn(string $channel, array $envelope): array{ok: bool, status_code?: int, message?: string, provider_id?: string}
     */
    public static function setTestTransport(?callable $transport): void
    {
        self::$testTransport = $transport;
    }

    public static function resetTestState(): void
    {
        self::$testTransport = null;
    }

    /**
     * ThinkPHP 事件监听器标准入口
     */
    public function handle(array $payload): bool
    {
        return $this->dispatch($payload);
    }

    public function __invoke(array $payload): bool
    {
        return $this->dispatch($payload);
    }

    /**
     * 校验 Webhook / 网关 HMAC-SHA256 请求签名（防篡改、防重放窗口）
     */
    public static function verifyWebhookSignature(
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $signature,
        string $secret,
        int $toleranceSeconds = 300
    ): bool {
        if ($secret === '' || $nonce === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }
        $ts = (int) $timestamp;
        if ($toleranceSeconds > 0 && abs(time() - $ts) > $toleranceSeconds) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $rawBody, $secret);
        return hash_equals($expected, trim($signature));
    }

    /**
     * 诊断所有恢复发送渠道的配置就绪状态
     *
     * @return array<string, array<string, mixed>>
     */
    public function diagnoseChannels(): array
    {
        $smtpHost = $this->readSetting('HW_RECOVERY_SMTP_HOST', 'hwadmin.recovery.smtp.host');
        $smtpPort = (int) ($this->readSetting('HW_RECOVERY_SMTP_PORT', 'hwadmin.recovery.smtp.port') ?: '587');
        $smtpUser = $this->readSetting('HW_RECOVERY_SMTP_USER', 'hwadmin.recovery.smtp.user');
        $smtpPass = $this->readSetting('HW_RECOVERY_SMTP_PASS', 'hwadmin.recovery.smtp.pass');
        $smtpFrom = $this->readSetting('HW_RECOVERY_SMTP_FROM', 'hwadmin.recovery.smtp.from');
        $smtpEnc = strtolower($this->readSetting('HW_RECOVERY_SMTP_ENCRYPTION', 'hwadmin.recovery.smtp.encryption') ?: 'tls');
        if (!in_array($smtpEnc, ['tls', 'ssl', 'none'], true)) {
            $smtpEnc = 'tls';
        }
        $smtpMissing = [];
        if ($this->isPlaceholder($smtpHost)) {
            $smtpMissing[] = 'HW_RECOVERY_SMTP_HOST';
        }
        if ($smtpPort <= 0 || $smtpPort > 65535) {
            $smtpMissing[] = 'HW_RECOVERY_SMTP_PORT';
        }
        if ($smtpEnc === 'none' && !$this->isTestingEnvironment()) {
            $smtpMissing[] = 'HW_RECOVERY_SMTP_ENCRYPTION';
        }
        if (!filter_var($smtpFrom, FILTER_VALIDATE_EMAIL) || $this->isPlaceholder($smtpFrom)) {
            $smtpMissing[] = 'HW_RECOVERY_SMTP_FROM';
        }

        $webhookUrl = $this->readSetting('HW_RECOVERY_WEBHOOK_URL', 'hwadmin.recovery.webhook.url');
        $webhookSecret = $this->readSetting('HW_RECOVERY_WEBHOOK_SECRET', 'hwadmin.recovery.webhook.secret');
        $webhookMissing = [];
        if (!$this->isValidHttpUrl($webhookUrl)) {
            $webhookMissing[] = 'HW_RECOVERY_WEBHOOK_URL';
        }
        if ($this->isPlaceholder($webhookSecret)) {
            $webhookMissing[] = 'HW_RECOVERY_WEBHOOK_SECRET';
        }

        $smsEndpoint = $this->readSetting('HW_RECOVERY_SMS_ENDPOINT', 'hwadmin.recovery.sms.endpoint');
        $smsApiKey = $this->readSetting('HW_RECOVERY_SMS_API_KEY', 'hwadmin.recovery.sms.api_key');
        $smsSecret = $this->readSetting('HW_RECOVERY_SMS_SECRET', 'hwadmin.recovery.sms.secret');
        $smsSign = $this->readSetting('HW_RECOVERY_SMS_SIGN_NAME', 'hwadmin.recovery.sms.sign_name') ?: 'HwAdmin';
        $smsMissing = [];
        if (!$this->isValidHttpUrl($smsEndpoint)) {
            $smsMissing[] = 'HW_RECOVERY_SMS_ENDPOINT';
        }
        if ($this->isPlaceholder($smsApiKey)) {
            $smsMissing[] = 'HW_RECOVERY_SMS_API_KEY';
        }
        if ($this->isPlaceholder($smsSecret)) {
            $smsMissing[] = 'HW_RECOVERY_SMS_SECRET';
        }

        return [
            'smtp' => [
                'ready'       => empty($smtpMissing),
                'missing_env' => $smtpMissing,
                'config'      => [
                    'host'       => $smtpHost,
                    'port'       => $smtpPort,
                    'user'       => $smtpUser,
                    'pass'       => $smtpPass,
                    'from'       => $smtpFrom,
                    'encryption' => $smtpEnc,
                ],
            ],
            'webhook' => [
                'ready'       => empty($webhookMissing),
                'missing_env' => $webhookMissing,
                'config'      => [
                    'url'    => $webhookUrl,
                    'secret' => $webhookSecret,
                ],
            ],
            'sms' => [
                'ready'       => empty($smsMissing),
                'missing_env' => $smsMissing,
                'config'      => [
                    'endpoint'  => $smsEndpoint,
                    'api_key'   => $smsApiKey,
                    'secret'    => $smsSecret,
                    'sign_name' => $smsSign,
                ],
            ],
        ];
    }

    /**
     * 执行密码恢复通知分发（必须严格验证渠道配置与真实投递结果）
     */
    public function dispatch(array $payload): bool
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $resetToken = trim((string) ($payload['reset_token'] ?? ''));
        $account = trim((string) ($payload['account'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $mobile = trim((string) ($payload['mobile'] ?? ''));
        $expiresAt = (int) ($payload['expires_at'] ?? (time() + 600));

        if ($userId <= 0 || $resetToken === '') {
            $this->writeAuditLog('none', $userId, $account, false, 'invalid_payload');
            return false;
        }

        if (self::$testTransport !== null && !$this->isTestingEnvironment()) {
            $this->writeAuditLog('none', $userId, $account, false, 'test_transport_rejected_outside_testing');
            return false;
        }

        $channels = $this->diagnoseChannels();
        $preferredRaw = strtolower(trim($this->readSetting('HW_RECOVERY_CHANNEL', 'hwadmin.recovery.channel')));
        $candidates = ($preferredRaw !== '' && $preferredRaw !== 'auto')
            ? array_values(array_filter(array_map('trim', explode(',', $preferredRaw))))
            : ['smtp', 'sms', 'webhook'];

        $attempted = false;
        foreach ($candidates as $channel) {
            if (!isset($channels[$channel]) || !$channels[$channel]['ready']) {
                continue;
            }

            if ($channel === 'smtp') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $attempted = true;
                $ok = $this->sendViaSmtp(
                    $channels['smtp']['config'],
                    $userId,
                    $account,
                    $email,
                    $resetToken,
                    $expiresAt
                );
                if ($ok) {
                    return true;
                }
            } elseif ($channel === 'sms') {
                if (!preg_match('/^\+?[1-9]\d{6,14}$/', $mobile)) {
                    continue;
                }
                $attempted = true;
                $ok = $this->sendViaSms(
                    $channels['sms']['config'],
                    $userId,
                    $account,
                    $mobile,
                    $resetToken,
                    $expiresAt
                );
                if ($ok) {
                    return true;
                }
            } elseif ($channel === 'webhook') {
                $attempted = true;
                $ok = $this->sendViaWebhook(
                    $channels['webhook']['config'],
                    $userId,
                    $account,
                    $email,
                    $mobile,
                    $resetToken,
                    $expiresAt
                );
                if ($ok) {
                    return true;
                }
            }
        }

        if (!$attempted) {
            $this->writeAuditLog($preferredRaw !== '' ? $preferredRaw : 'none', $userId, $account, false, 'no_ready_channel_or_target');
        }
        return false;
    }

    public function getAuditLogPath(): string
    {
        $runtimePath = $this->app
            ? ($this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR)
            : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
        return $runtimePath . 'logs' . DIRECTORY_SEPARATOR . 'password_recovery_audit.log';
    }

    public function maskTarget(string $value, string $type = 'account'): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($type === 'email' || str_contains($value, '@')) {
            $parts = explode('@', $value, 2);
            $local = $parts[0] ?? '';
            $domain = $parts[1] ?? '';
            $prefix = mb_substr($local, 0, min(2, mb_strlen($local)));
            return $prefix . '***@' . $domain;
        }
        if ($type === 'mobile' || preg_match('/^\+?\d{7,15}$/', $value)) {
            $len = strlen($value);
            if ($len <= 4) {
                return str_repeat('*', $len);
            }
            return substr($value, 0, 3) . str_repeat('*', max(2, $len - 7)) . substr($value, -4);
        }
        if ($type === 'url') {
            $parsed = parse_url($value);
            if (is_array($parsed) && !empty($parsed['host'])) {
                $scheme = $parsed['scheme'] ?? 'https';
                $port = isset($parsed['port']) ? (':' . $parsed['port']) : '';
                return $scheme . '://' . $parsed['host'] . $port . '/***';
            }
            return '***';
        }
        $len = mb_strlen($value);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        return mb_substr($value, 0, 1) . str_repeat('*', min(6, max(2, $len - 2))) . mb_substr($value, -1);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function sendViaWebhook(
        array $config,
        int $userId,
        string $account,
        string $email,
        string $mobile,
        string $resetToken,
        int $expiresAt
    ): bool {
        $url = (string) $config['url'];
        $secret = (string) $config['secret'];
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));

        $payload = [
            'event'       => 'user.password_reset_requested',
            'user_id'     => $userId,
            'account'     => $account,
            'email'       => $email,
            'mobile'      => $mobile,
            'reset_token' => $resetToken,
            'expires_at'  => $expiresAt,
            'issued_at'   => (int) $timestamp,
        ];
        $body = (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Hw-Event: user.password_reset_requested',
            'X-Hw-Timestamp: ' . $timestamp,
            'X-Hw-Nonce: ' . $nonce,
            'X-Hw-Signature: ' . $signature,
        ];

        $result = $this->performHttpPost($url, $headers, $body, 'webhook');
        $ok = (bool) ($result['ok'] ?? false);
        $this->writeAuditLog('webhook', $userId, $account, $ok, (string) ($result['message'] ?? ''), [
            'endpoint'    => $this->maskTarget($url, 'url'),
            'status_code' => (int) ($result['status_code'] ?? 0),
        ]);
        return $ok;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function sendViaSms(
        array $config,
        int $userId,
        string $account,
        string $mobile,
        string $resetToken,
        int $expiresAt
    ): bool {
        $endpoint = (string) $config['endpoint'];
        $apiKey = (string) $config['api_key'];
        $secret = (string) $config['secret'];
        $signName = (string) $config['sign_name'];
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $ttlMinutes = max(1, (int) ceil(max(60, $expiresAt - time()) / 60));

        $payload = [
            'event'      => 'user.password_reset_sms',
            'api_key'    => $apiKey,
            'sign_name'  => $signName,
            'mobile'     => $mobile,
            'template'   => 'password_reset',
            'params'     => [
                'reset_token'     => $resetToken,
                'expires_minutes' => $ttlMinutes,
            ],
            'timestamp'  => (int) $timestamp,
            'nonce'      => $nonce,
        ];
        $body = (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Hw-Api-Key: ' . $apiKey,
            'X-Hw-Timestamp: ' . $timestamp,
            'X-Hw-Nonce: ' . $nonce,
            'X-Hw-Signature: ' . $signature,
        ];

        $result = $this->performHttpPost($endpoint, $headers, $body, 'sms');
        $ok = (bool) ($result['ok'] ?? false);
        $this->writeAuditLog('sms', $userId, $mobile, $ok, (string) ($result['message'] ?? ''), [
            'endpoint'    => $this->maskTarget($endpoint, 'url'),
            'status_code' => (int) ($result['status_code'] ?? 0),
        ]);
        return $ok;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function sendViaSmtp(
        array $config,
        int $userId,
        string $account,
        string $email,
        string $resetToken,
        int $expiresAt
    ): bool {
        $host = (string) $config['host'];
        $port = (int) $config['port'];
        $user = (string) $config['user'];
        $pass = (string) $config['pass'];
        $from = (string) $config['from'];
        $enc = (string) $config['encryption'];
        $ttlMinutes = max(1, (int) ceil(max(60, $expiresAt - time()) / 60));

        $subject = 'HwAdmin 密码恢复验证码';
        $textBody = "您好，您正在申请重置账号 [{$account}] 的登录密码。\n"
            . "一次性恢复凭证：{$resetToken}\n"
            . "有效期：{$ttlMinutes} 分钟。如非本人操作请忽略本邮件。";

        if ($this->isTestingEnvironment() && self::$testTransport !== null) {
            $res = (self::$testTransport)('smtp', [
                'host'       => $host,
                'port'       => $port,
                'encryption' => $enc,
                'from'       => $from,
                'to'         => $email,
                'subject'    => $subject,
                'body'       => $textBody,
                'user_id'    => $userId,
            ]);
            $ok = is_array($res) && (($res['ok'] ?? false) === true);
            $this->writeAuditLog('smtp', $userId, $email, $ok, (string) ($res['message'] ?? ($ok ? 'delivered' : 'rejected')), [
                'endpoint' => 'smtp://' . $host . ':' . $port,
            ]);
            return $ok;
        }

        $result = $this->deliverSmtpSocket($host, $port, $enc, $user, $pass, $from, $email, $subject, $textBody);
        $ok = (bool) ($result['ok'] ?? false);
        $this->writeAuditLog('smtp', $userId, $email, $ok, (string) ($result['message'] ?? ''), [
            'endpoint' => 'smtp://' . $host . ':' . $port,
        ]);
        return $ok;
    }

    /**
     * @param string[] $headers
     * @return array{ok: bool, status_code: int, message: string}
     */
    protected function performHttpPost(string $url, array $headers, string $body, string $channel): array
    {
        if ($this->isTestingEnvironment() && self::$testTransport !== null) {
            $res = (self::$testTransport)($channel, [
                'url'     => $url,
                'headers' => $headers,
                'body'    => $body,
            ]);
            if (!is_array($res)) {
                return ['ok' => false, 'status_code' => 0, 'message' => 'invalid_test_transport_result'];
            }
            return [
                'ok'          => ($res['ok'] ?? false) === true,
                'status_code' => (int) ($res['status_code'] ?? (($res['ok'] ?? false) ? 200 : 500)),
                'message'     => (string) ($res['message'] ?? (($res['ok'] ?? false) ? 'delivered' : 'failed')),
            ];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status_code' => 0, 'message' => 'curl_extension_missing'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status_code' => 0, 'message' => 'curl_init_failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $responseBody = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false || $curlErrno !== 0) {
            return [
                'ok'          => false,
                'status_code' => $httpCode,
                'message'     => 'network_error:' . ($curlError ?: (string) $curlErrno),
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'ok'          => false,
                'status_code' => $httpCode,
                'message'     => 'http_status_' . $httpCode,
            ];
        }

        $trimmed = trim((string) $responseBody);
        if ($trimmed !== '' && str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                if (array_key_exists('ok', $decoded) && $decoded['ok'] !== true) {
                    return ['ok' => false, 'status_code' => $httpCode, 'message' => 'remote_rejected_ok_false'];
                }
                if (array_key_exists('delivered', $decoded) && $decoded['delivered'] !== true) {
                    return ['ok' => false, 'status_code' => $httpCode, 'message' => 'remote_rejected_delivered_false'];
                }
                if (array_key_exists('code', $decoded) && (int) $decoded['code'] === 0) {
                    return ['ok' => false, 'status_code' => $httpCode, 'message' => 'remote_rejected_code_0'];
                }
            }
        }

        return ['ok' => true, 'status_code' => $httpCode, 'message' => 'delivered'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function deliverSmtpSocket(
        string $host,
        int $port,
        string $encryption,
        string $user,
        string $pass,
        string $from,
        string $to,
        string $subject,
        string $textBody
    ): array {
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!is_resource($fp)) {
            return ['ok' => false, 'message' => 'smtp_connect_failed:' . $errstr];
        }
        stream_set_timeout($fp, 8);

        $readCode = static function ($stream): int {
            $lastLine = '';
            while (($line = fgets($stream, 1024)) !== false) {
                $lastLine = $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return (int) substr($lastLine, 0, 3);
        };
        $sendCmd = static function ($stream, string $cmd) use ($readCode): int {
            fwrite($stream, $cmd . "\r\n");
            return $readCode($stream);
        };

        try {
            if ($readCode($fp) !== 220) {
                return ['ok' => false, 'message' => 'smtp_greeting_rejected'];
            }
            if ($sendCmd($fp, 'EHLO hwadmin.local') !== 250) {
                return ['ok' => false, 'message' => 'smtp_ehlo_rejected'];
            }
            if ($encryption === 'tls') {
                if ($sendCmd($fp, 'STARTTLS') !== 220) {
                    return ['ok' => false, 'message' => 'smtp_starttls_rejected'];
                }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return ['ok' => false, 'message' => 'smtp_tls_handshake_failed'];
                }
                if ($sendCmd($fp, 'EHLO hwadmin.local') !== 250) {
                    return ['ok' => false, 'message' => 'smtp_ehlo_after_tls_rejected'];
                }
            }
            if ($user !== '' || $pass !== '') {
                if ($sendCmd($fp, 'AUTH LOGIN') !== 334
                    || $sendCmd($fp, base64_encode($user)) !== 334
                    || $sendCmd($fp, base64_encode($pass)) !== 235) {
                    return ['ok' => false, 'message' => 'smtp_auth_failed'];
                }
            }
            if ($sendCmd($fp, 'MAIL FROM:<' . $from . '>') !== 250) {
                return ['ok' => false, 'message' => 'smtp_mail_from_rejected'];
            }
            if ($sendCmd($fp, 'RCPT TO:<' . $to . '>') !== 250) {
                return ['ok' => false, 'message' => 'smtp_rcpt_to_rejected'];
            }
            if ($sendCmd($fp, 'DATA') !== 354) {
                return ['ok' => false, 'message' => 'smtp_data_rejected'];
            }

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $safeBody = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], str_replace(["\r\n", "\r"], "\n", $textBody));
            $safeBody = str_replace("\n", "\r\n", $safeBody);
            $rawMessage = "From: <{$from}>\r\n"
                . "To: <{$to}>\r\n"
                . "Subject: {$encodedSubject}\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n"
                . "\r\n"
                . $safeBody
                . "\r\n.";

            if ($sendCmd($fp, $rawMessage) !== 250) {
                return ['ok' => false, 'message' => 'smtp_body_rejected'];
            }
            $sendCmd($fp, 'QUIT');
            return ['ok' => true, 'message' => 'delivered'];
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function writeAuditLog(string $channel, int $userId, string $target, bool $ok, string $reason, array $extra = []): void
    {
        $record = array_merge([
            'timestamp'     => gmdate('c'),
            'channel'       => $channel,
            'user_id'       => $userId,
            'target_masked' => $this->maskTarget($target, $channel === 'smtp' ? 'email' : ($channel === 'sms' ? 'mobile' : 'account')),
            'delivered'     => $ok,
            'reason'        => $reason,
        ], $extra);

        $line = (string) json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $logFile = $this->getAuditLogPath();
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $isNew = !is_file($logFile);
        @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($isNew && is_file($logFile)) {
            @chmod($logFile, 0600);
        }

        try {
            if ($this->app !== null) {
                $this->app->log->info('[password_recovery_audit] ' . $line);
            }
        } catch (Throwable) {
        }
    }

    protected function isTestingEnvironment(): bool
    {
        if ($this->app !== null && (string) $this->app->env->get('APP_ENV', '') === 'testing') {
            return true;
        }
        return $this->readEnv('APP_ENV') === 'testing';
    }

    protected function isValidHttpUrl(string $url): bool
    {
        if ($url === '' || $this->isPlaceholder($url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        return ($scheme === 'https' || ($scheme === 'http' && $this->isTestingEnvironment()))
            && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    protected function isPlaceholder(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return true;
        }
        $lower = strtolower($trimmed);
        return str_contains($lower, 'your-')
            || str_contains($lower, 'changeme')
            || str_contains($lower, 'example.invalid');
    }

    protected function readSetting(string $envKey, string $configKey): string
    {
        $envVal = $this->readEnv($envKey);
        if ($envVal !== '') {
            return $envVal;
        }
        if ($this->app !== null) {
            $cfgVal = $this->app->config->get($configKey, '');
            if ($cfgVal !== null && $cfgVal !== '') {
                return (string) $cfgVal;
            }
        }
        return '';
    }

    protected function readEnv(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        $fromGetenv = getenv($key);
        if ($fromGetenv !== false && $fromGetenv !== '') {
            return (string) $fromGetenv;
        }
        if ($this->app !== null) {
            $fromApp = $this->app->env->get($key, '');
            if ($fromApp !== '' && $fromApp !== null) {
                return (string) $fromApp;
            }
        }
        return $default;
    }
}
