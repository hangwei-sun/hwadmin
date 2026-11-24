<?php
declare(strict_types=1);

namespace app\common\service;

use core\exception\ApiException;
use think\App;

/** 部署引导与短期无害探针。只操作本站固定路径；宝塔自动配置仅限显式请求本机回环面板接口。 */
final class DeploymentService
{
    private string $root;
    private string $state;

    public function __construct(App $app)
    {
        $this->root = rtrim($app->getRootPath(), '/\\');
        // 不使用多应用的 runtime 子目录，保证 install/adminapi 共用并可清理。
        $this->state = $this->root . '/runtime/deployment';
    }

    public function configuration(): array
    {
        $public = str_replace('\\', '/', $this->root) . '/public';
        if (preg_match('/[\x00-\x1f"$;{}]/', $public)) {
            throw new ApiException('站点路径含 Nginx 特殊字符，请由管理员配置');
        }
        $rewrite = file_get_contents($this->root . '/public/nginx.htaccess');
        if ($rewrite === false) {
            throw new ApiException('部署规则文件缺失，请重新上传完整发布包');
        }
        $command = $this->buildTerminalCommand($public, $rewrite);
        return [
            'public_path' => $public,
            'rewrite'     => $rewrite,
            'command'     => $command,
        ];
    }

    /**
     * 通过本机宝塔面板 API 自动备份并写入伪静态规则，必要时同步校正站点运行目录后重载 Nginx。
     * 仅允许连接本机回环地址（127.0.0.1 / localhost / ::1），凭据仅当次请求内存签名使用，不落盘。
     *
     * @param callable(string, array): array|null $httpClient 仅测试注入替身用
     */
    public function applyBaotaRewrite(string $panelUrl, string $apiKey, ?callable $httpClient = null): array
    {
        $baseUrl = $this->validateLoopbackPanelUrl($panelUrl);
        $key = trim($apiKey);
        if ($key === '' || strlen($key) < 16 || strlen($key) > 128 || preg_match('/[\s\x00-\x1f]/', $key)) {
            throw new ApiException('请填写有效的宝塔面板 API 密钥（16~128 位非空白字符）');
        }
        $config = $this->configuration();
        $rewrite = (string) $config['rewrite'];
        $public = (string) $config['public_path'];

        $request = $httpClient ?? fn (string $url, array $post): array => $this->postLoopbackForm($url, $post);
        $signed = static function () use ($key): array {
            $now = time();
            return [
                'request_time'  => (string) $now,
                'request_token' => md5((string) $now . md5($key)),
            ];
        };

        $sitesRes = $request($baseUrl . '/data?action=getData', $signed() + [
            'table' => 'sites',
            'limit' => '200',
            'p'     => '1',
        ]);
        $sites = is_array($sitesRes['data'] ?? null) ? $sitesRes['data'] : [];
        if ($sites === []) {
            $msg = is_string($sitesRes['msg'] ?? null) ? $sitesRes['msg'] : '未从宝塔获取到站点列表，请确认 API 已开启且已把 127.0.0.1 加入白名单';
            throw new ApiException($msg);
        }

        $matched = $this->matchBaotaSite($sites, $public);
        if ($matched === null) {
            throw new ApiException('未能自动匹配当前站点（运行路径：' . $public . '），请确认站点目录一致或改用复制粘贴伪静态');
        }
        $siteName = (string) ($matched['name'] ?? '');
        $siteId = (string) ($matched['id'] ?? '');
        $siteBasePath = rtrim(str_replace('\\', '/', (string) ($matched['path'] ?? '')), '/');
        if ($siteName === '' || !preg_match('/^[a-zA-Z0-9._-]+$/D', $siteName)) {
            throw new ApiException('匹配到的站点名称格式异常，无法安全定位伪静态文件');
        }

        $runPathUpdated = false;
        if ($siteId !== '' && $siteBasePath !== '' && str_starts_with($public, $siteBasePath)) {
            $expectedRunPath = substr($public, strlen($siteBasePath));
            if ($expectedRunPath !== '' && $expectedRunPath !== '/') {
                $runRes = $request($baseUrl . '/site?action=SetSiteRunPath', $signed() + [
                    'id'      => $siteId,
                    'runPath' => $expectedRunPath,
                ]);
                $runPathUpdated = (bool) ($runRes['status'] ?? false);
            }
        }

        $rewriteFile = '/www/server/panel/vhost/rewrite/' . $siteName . '.conf';
        $readRes = $request($baseUrl . '/files?action=GetFileBody', $signed() + ['path' => $rewriteFile]);
        if (!($readRes['status'] ?? false) && !isset($readRes['data'])) {
            $request($baseUrl . '/files?action=CreateFile', $signed() + ['path' => $rewriteFile]);
        }

        $saveRes = $request($baseUrl . '/files?action=SaveFileBody', $signed() + [
            'path'     => $rewriteFile,
            'data'     => $rewrite,
            'encoding' => 'utf-8',
            'force'    => '1',
        ]);
        if (!($saveRes['status'] ?? false)) {
            $errMsg = trim(strip_tags((string) ($saveRes['msg'] ?? '写入宝塔伪静态失败')));
            throw new ApiException($errMsg !== '' ? $errMsg : '写入宝塔伪静态失败');
        }

        $reloadRes = $request($baseUrl . '/system?action=ServiceAdmin', $signed() + [
            'name' => 'nginx',
            'type' => 'reload',
        ]);

        return [
            'site'             => $siteName,
            'rewrite_path'     => $rewriteFile,
            'run_path_updated' => $runPathUpdated,
            'reloaded'         => (bool) ($reloadRes['status'] ?? true),
        ];
    }

    private function buildTerminalCommand(string $public, string $rewrite): string
    {
        $siteRoot = dirname($public);
        $parentRoot = dirname($siteRoot);
        $b64Rewrite = base64_encode($rewrite);
        $b64Public = base64_encode($public);
        $b64Roots = base64_encode(json_encode([$public, $siteRoot, $parentRoot], JSON_UNESCAPED_SLASHES) ?: '[]');
        return "python3 -c 'import base64,glob,json,os,shutil,subprocess,time; "
            . "pub=base64.b64decode(\"{$b64Public}\").decode(); "
            . "rew=base64.b64decode(\"{$b64Rewrite}\").decode(); "
            . "roots=json.loads(base64.b64decode(\"{$b64Roots}\").decode()); "
            . "confs=sorted(glob.glob(\"/www/server/panel/vhost/nginx/*.conf\")); "
            . "hit=next((c for c in confs if any(r and r in open(c,errors=\"ignore\").read() for r in roots)),None); "
            . "assert hit, \"未找到对应站点 nginx conf\"; "
            . "site=os.path.basename(hit)[:-5]; "
            . "rf=f\"/www/server/panel/vhost/rewrite/{site}.conf\"; "
            . "bak=f\"{rf}.hwadmin.bak.{int(time.time())}\"; "
            . "shutil.copy2(rf,bak) if os.path.exists(rf) else None; "
            . "open(rf,\"w\",encoding=\"utf-8\").write(rew); "
            . "r=subprocess.run([\"nginx\",\"-t\"],capture_output=True,text=True); "
            . "((shutil.copy2(bak,rf) if os.path.exists(bak) else None), (_ for _ in ()).throw(RuntimeError(r.stderr or r.stdout))) if r.returncode!=0 else None; "
            . "subprocess.check_call([\"/etc/init.d/nginx\",\"reload\"]); "
            . "print(\"OK: updated\", rf)'";
    }

    private function validateLoopbackPanelUrl(string $panelUrl): string
    {
        $raw = trim($panelUrl);
        if ($raw === '' || preg_match('/[\x00-\x1f\s]/', $raw)) {
            throw new ApiException('请填写正确的宝塔面板地址，例如 http://127.0.0.1:8888');
        }
        $parts = parse_url($raw);
        if (!is_array($parts)) {
            throw new ApiException('宝塔面板地址格式无效');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 8888);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ApiException('宝塔面板地址仅支持 http:// 或 https://');
        }
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
            throw new ApiException('出于安全隔离，仅支持连接本机回环地址（127.0.0.1 / localhost）的宝塔面板');
        }
        if ($port < 1 || $port > 65535 || isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiException('宝塔面板端口或地址参数不合法');
        }
        $base = $scheme . '://' . $host . ':' . $port;
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '' && !preg_match('~^/[a-zA-Z0-9_/-]*$~D', $path)) {
            throw new ApiException('宝塔面板路径含非法字符');
        }
        return $base . $path;
    }

    private function matchBaotaSite(array $sites, string $public): ?array
    {
        $normPublic = rtrim(str_replace('\\', '/', $public), '/');
        $siteRoot = dirname($normPublic);
        $parentRoot = dirname($siteRoot);
        $hostHeader = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
        $hostOnly = preg_replace('/:\d+$/', '', $hostHeader) ?: '';

        foreach ([$normPublic, $siteRoot, $parentRoot] as $candidatePath) {
            foreach ($sites as $site) {
                if (!is_array($site)) {
                    continue;
                }
                $sp = rtrim(str_replace('\\', '/', (string) ($site['path'] ?? '')), '/');
                if ($sp !== '' && $sp === $candidatePath) {
                    return $site;
                }
            }
        }
        if ($hostOnly !== '') {
            foreach ($sites as $site) {
                if (!is_array($site)) {
                    continue;
                }
                if (strtolower((string) ($site['name'] ?? '')) === $hostOnly) {
                    return $site;
                }
            }
        }
        return null;
    }

    private function postLoopbackForm(string $url, array $post): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('当前 PHP 未启用 cURL 扩展，无法调用本机宝塔面板 API');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException('初始化本机面板请求失败');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || !is_string($raw)) {
            throw new ApiException('连接本机宝塔面板失败：' . ($err !== '' ? $err : 'HTTP ' . $status));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiException('宝塔面板返回了非 JSON 响应（HTTP ' . $status . '），请检查面板地址与安全入口设置');
        }
        return $decoded;
    }

    /** 安装完成页专用，30 分钟有效，不能解锁安装或取得管理员权限。 */
    public function issueGrant(): string
    {
        $this->ensureState();
        $token = bin2hex(random_bytes(32));
        $this->writePrivate($this->state . '/grant.json', ['hash' => hash('sha256', $token), 'expires' => time() + 1800]);
        return $token;
    }

    public function acceptsGrant(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return false;
        }
        $grant = $this->readState($this->state . '/grant.json');
        return ($grant['expires'] ?? 0) >= time() && hash_equals((string) ($grant['hash'] ?? ''), hash('sha256', $token));
    }

    public function prepare(): array
    {
        $this->ensureState();
        $this->removeExpired();
        if (count(glob($this->state . '/probe-*.json') ?: []) >= 8) {
            throw new ApiException('检查任务过多，请稍后重试（临时任务 5 分钟过期）');
        }
        $id = bin2hex(random_bytes(16));
        $marker = 'hw-deployment-' . bin2hex(random_bytes(24));
        $paths = $this->paths($id);
        // 先记录路径，任何写入中断都能在下一轮检查时清理。
        $this->writePrivate($this->state . '/probe-' . $id . '.json', ['expires' => time() + 300]);
        try {
            foreach ($paths as $key => $path) {
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
                    throw new \RuntimeException('无法创建探针目录');
                }
                $content = str_ends_with($path, '.txt') ? $marker : "<?php /* HwAdmin temporary inert deployment probe */ echo '" . $marker . "';";
                if (file_put_contents($path, $content, LOCK_EX) === false || !chmod($path, 0644)) {
                    throw new \RuntimeException('无法写入探针');
                }
            }
        } catch (\Throwable $e) {
            $this->cleanup($id);
            throw new ApiException('无法创建安全检查探针，请检查 public、uploads、plugins 目录权限');
        }
        $urls = [];
        foreach ($paths as $key => $path) {
            $urls[$key] = substr($path, strlen($this->root . '/public'));
        }
        return ['id' => $id, 'marker' => $marker, 'urls' => $urls];
    }

    public function cleanup(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new ApiException('检查任务编号不合法');
        }
        $failed = false;
        foreach ($this->paths($id) as $path) {
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                $failed = true;
            }
        }
        if ($failed) {
            throw new ApiException('临时探针清理失败，请检查目录权限后重新检查');
        }
        $record = $this->state . '/probe-' . $id . '.json';
        if (is_file($record) && !unlink($record)) {
            throw new ApiException('检查记录清理失败');
        }
    }

    private function paths(string $id): array
    {
        $base = $this->root . '/public';
        $name = '/hw-deploy-' . $id;
        return [
            'rootControl' => $base . $name . '.txt',
            'rootPhp' => $base . $name . '.php',
            'uploadControl' => $base . '/uploads' . $name . '.txt',
            'uploadPhp' => $base . '/uploads' . $name . '.php',
            'uploadPhtml' => $base . '/uploads' . $name . '.phtml',
            'pluginControl' => $base . '/plugins' . $name . '.txt',
            'pluginPhp' => $base . '/plugins' . $name . '.php',
        ];
    }

    private function ensureState(): void
    {
        if (!is_dir($this->state) && !mkdir($this->state, 0700, true) && !is_dir($this->state)) {
            throw new ApiException('无法创建部署检查记录目录');
        }
    }

    private function writePrivate(string $path, array $data): void
    {
        $mask = umask(0077);
        try {
            if (file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false || !chmod($path, 0600)) {
                throw new ApiException('无法保存部署检查记录');
            }
        } finally {
            umask($mask);
        }
    }

    private function readState(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function removeExpired(): void
    {
        foreach (glob($this->state . '/probe-*.json') ?: [] as $file) {
            if (($this->readState($file)['expires'] ?? 0) < time()) {
                $this->cleanup(substr(basename($file), 6, 32));
            }
        }
    }
}
