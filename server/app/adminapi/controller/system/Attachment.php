<?php
declare(strict_types=1);

namespace app\adminapi\controller\system;

use app\common\model\Attachment as AttachmentModel;
use app\common\service\ConfigService;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;
use think\facade\Db;

/**
 * 附件管理与上传控制器
 *
 * 节点：system/attachment/{index|read|upload|del}
 * 安全基线（P1-2 & 2-P0-3）：
 *   1. 使用 `add as protected crudAdd; edit as protected crudEdit;` 彻底屏蔽直接新增/编辑路由；
 *   2. upload 纳入 RBAC 权限控制；
 *   3. 严格拦截双扩展名（如 evil.php.jpg）与空字节；
 *   4. 基于 finfo_file 校验真实二进制 MIME 类型并对照扩展名白名单；
 *   5. 图片扩展名强制要求 getimagesize 探测为合法 IMAGETYPE_*；
 *   6. 秒传复用物理文件但为当前上传管理员独立写入归属记录，避免跨管理员数据泄露；
 *   7. 删除物理文件前通过 realpath() 严格校验路径必须位于 public/uploads/ 目录内，杜绝路径穿越任意文件删除。
 */
class Attachment extends AdminController
{
    use CrudTrait {
        add as protected crudAdd;
        edit as protected crudEdit;
        del as protected crudDel;
    }

    protected string $dataScope = 'self';
    protected string $dataScopeField = 'admin_id';
    protected array $searchFields = ['name', 'mime', 'ext'];
    protected array $filterFields = ['id', 'admin_id', 'user_id', 'storage', 'mime', 'ext', 'create_time'];
    protected array $sortableFields = ['id', 'size', 'create_time'];
    protected array $noNeedRight = ['download'];

    /** 待清理的物理文件相对路径列表（由 del 通过 buildIndexQuery 收集并在 afterDelete 中处理） */
    protected array $pendingDeleteFiles = [];

    /** 扩展名到合法真实 MIME 的映射表 */
    private const EXT_MIME_MAP = [
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png', 'image/x-png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon'],
        'mp4'  => ['video/mp4', 'video/quicktime', 'video/x-m4v'],
        'webm' => ['video/webm', 'audio/webm'],
        'mov'  => ['video/quicktime', 'video/mp4'],
        'm4v'  => ['video/x-m4v', 'video/mp4'],
        'mp3'  => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg-3'],
        'wav'  => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        'ogg'  => ['audio/ogg', 'video/ogg', 'application/ogg'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/CDFV2'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'rar'  => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'],
        '7z'   => ['application/x-7z-compressed'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'md'   => ['text/plain', 'text/markdown'],
    ];

    /** 图片类扩展名集合（需强制通过 getimagesize 结构校验） */
    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new AttachmentModel();
    }

    protected function getFullUrl(string $url): string
    {
        if ($url === '' || preg_match('/^(?:https?:)?\/\//i', $url) || str_starts_with($url, 'data:')) {
            return $url;
        }
        return $this->request->domain() . ($url[0] === '/' ? $url : '/' . $url);
    }

    public function upload(): void
    {
        $this->ensurePost();

        // 0. 检查 PHP 系统层上传错误
        if (empty($_FILES) && empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0) {
            $postLimit = ini_get('post_max_size') ?: '未知';
            throw new ApiException('上传数据超出服务器 post_max_size 限制（当前限制为 ' . $postLimit . '）');
        }

        if (isset($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errCode = (int) $_FILES['file']['error'];
            $iniLimit = ini_get('upload_max_filesize') ?: '未知';
            $errMsg = match ($errCode) {
                UPLOAD_ERR_INI_SIZE   => '上传文件大小超出服务器 php.ini 的 upload_max_filesize 限制（当前限制为 ' . $iniLimit . '）',
                UPLOAD_ERR_FORM_SIZE  => '上传文件大小超出 HTML 表单 MAX_FILE_SIZE 限制',
                UPLOAD_ERR_PARTIAL    => '文件仅部分被上传，请重新上传',
                UPLOAD_ERR_NO_FILE    => '未找到上传的文件',
                UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时上传目录 (upload_tmp_dir)',
                UPLOAD_ERR_CANT_WRITE => '文件写入临时目录失败，请检查服务器磁盘空间与权限',
                UPLOAD_ERR_EXTENSION  => '文件上传被服务器 PHP 扩展模块中断',
                default               => '文件上传失败，错误代码：' . $errCode,
            };
            throw new ApiException($errMsg);
        }

        $file = $this->request->file('file');
        if (!$file) {
            throw new ApiException('请选择要上传的文件（未接收到文件数据）');
        }

        $origName = (string) $file->getOriginalName();
        if ($origName === '' || str_contains($origName, "\0")) {
            throw new ApiException('非法的文件名');
        }

        // 1. 拦截高危双扩展名（例如 shell.php.jpg、xss.html.png、payload.svg.jpg）
        if (preg_match('/\.(php\d*|phtml|phar|sh|bash|bat|cmd|exe|dll|jsp|asp|aspx|cgi|pl|py|rb|js|mjs|html?|htm|xhtml|svg|xml|htaccess|user\.ini)\b/i', $origName)) {
            throw new ApiException('安全拦截：文件名包含可执行脚本或危险扩展名片段');
        }

        // 读取单文件最大大小配置（<= 1024 视为 MB，自动乘以 1024 * 1024；> 1024 视为字节）
        $maxSizeCfg = ConfigService::get('upload_max_size');
        $maxSizeNum = is_numeric($maxSizeCfg) ? (float) $maxSizeCfg : 50.0;
        $maxSize = $maxSizeNum <= 1024 ? (int) ($maxSizeNum * 1024 * 1024) : (int) $maxSizeNum;
        if ($maxSize <= 0) {
            $maxSize = 52428800; // 默认 50MB
        }

        $allowExtsStr = (string) (ConfigService::get('upload_allow_ext')
            ?: 'jpg,jpeg,png,gif,webp,bmp,ico,mp4,webm,mov,m4v,mp3,wav,ogg,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z,txt,csv,md');
        $allowExts = array_values(array_filter(array_map(
            static fn ($s) => strtolower(trim($s)),
            explode(',', $allowExtsStr)
        )));

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new ApiException('上传文件内容为空');
        }
        if ($size > $maxSize) {
            throw new ApiException('文件大小超出限制（最大 ' . round($maxSize / 1024 / 1024, 2) . 'MB）');
        }

        $ext = strtolower((string) $file->extension());
        if ($ext === '' || !in_array($ext, $allowExts, true)) {
            throw new ApiException('不支持的文件扩展名：' . $ext);
        }

        // 高危黑名单严格拦截
        $dangerExts = [
            'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
            'sh', 'bash', 'bat', 'cmd', 'com', 'exe', 'dll', 'bin',
            'jsp', 'jspx', 'asp', 'aspx', 'asa', 'asax', 'cgi', 'pl',
            'py', 'pyc', 'rb', 'js', 'mjs', 'html', 'htm', 'xhtml',
            'svg', 'xml', 'htaccess', 'user.ini', 'ini'
        ];
        if (in_array($ext, $dangerExts, true)) {
            throw new ApiException('安全拦截：禁止上传危险扩展名格式文件');
        }

        $tmpPath = $file->getPathname();

        // 2. 基于文件内容（finfo_file）探测真实 MIME 类型，拒绝伪造 Content-Type
        $realMime = $this->detectRealMime($tmpPath);
        // 空字节文本文件（如极简测试文本）在某些 finfo 库下可能返回 application/x-empty，做兼容归一化
        if ($realMime === 'application/x-empty') {
            $realMime = 'text/plain';
        }

        if (isset(self::EXT_MIME_MAP[$ext])) {
            if (!in_array($realMime, self::EXT_MIME_MAP[$ext], true)) {
                throw new ApiException(sprintf('文件内容类型 (%s) 与扩展名 (.%s) 不匹配', $realMime, $ext));
            }
        } else {
            // 用户自定义扩展名不在内置静态映射表中：只要真实 MIME 不是可执行/脚本/HTML 类型即可放行
            $forbiddenMimes = [
                'text/html', 'text/javascript', 'application/javascript',
                'application/x-php', 'application/x-httpd-php',
                'application/x-executable', 'application/x-sh',
                'application/x-msdownload', 'application/x-msdos-program'
            ];
            if (in_array($realMime, $forbiddenMimes, true)) {
                throw new ApiException(sprintf('安全拦截：文件真实内容类型 (%s) 存在安全隐患', $realMime));
            }
        }

        // 3. 图片类型强制校验真实图像头与像素尺寸，防止伪造图片木马
        $imgWidth = 0;
        $imgHeight = 0;
        if (in_array($ext, self::IMAGE_EXTS, true)) {
            $imgInfo = @getimagesize($tmpPath);
            if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1]) || empty($imgInfo[2])) {
                throw new ApiException('图片文件已损坏或内容非合法图像格式');
            }
            $imgWidth = (int) $imgInfo[0];
            $imgHeight = (int) $imgInfo[1];
        }

        $safeOrigName = mb_substr(preg_replace('/[^\w\-\.\x{4e00}-\x{9fa5} ]/u', '_', $origName) ?: ('file.' . $ext), 0, 200);
        $now = time();

        $dateDir = date('Ymd');
        $rawStorage = $this->request->post('storage');
        if ($rawStorage === null || $rawStorage === '' || $rawStorage === 'default') {
            $storage = (string) ConfigService::get('upload_storage', '');
        } else {
            $storage = (string) $rawStorage;
        }

        $allowedStorages = ['local', 'private', 'cos', 'oss', 'qiniu'];
        if (!in_array($storage, $allowedStorages, true)) {
            $storage = 'local';
        }

        // 检查云存储驱动扩展可用性
        $isCloudStorage = in_array($storage, ['cos', 'oss', 'qiniu'], true);
        if ($isCloudStorage) {
            $pluginEnabled = false;
            try {
                $pluginEnabled = (int) Db::name('plugin')->where('name', 'oss')->value('status') === 1;
            } catch (\Throwable) {
            }
            if (!$pluginEnabled || !class_exists(\plugins\oss\service\CloudStorageService::class)) {
                $storage = 'local';
                $isCloudStorage = false;
            }
        }

        $sha1 = (string) ($file->hash('sha1') ?: @sha1_file($tmpPath) ?: '');
        if ($sha1 !== '') {
            // S08: 秒传必须严格区分存储域（storage 相同），禁止跨域秒传导致可见性降级或越权
            $exist = Db::name('attachment')->where('sha1', $sha1)->where('storage', $storage)->find();
            if ($exist) {
                $canFast = false;
                if ($storage === 'private') {
                    $fullExist = $this->resolveSafePrivatePath((string) $exist['url']);
                    $canFast = ($fullExist !== null && is_file($fullExist));
                } elseif ($storage === 'local') {
                    $fullExist = $this->resolveSafeUploadPath((string) $exist['url']);
                    $canFast = ($fullExist !== null && is_file($fullExist));
                } else {
                    // 云存储直接复用 url
                    $canFast = !empty($exist['url']);
                }

                if ($canFast) {
                    // 若当前管理员本人已有该 sha1 记录则直接返回；否则复用物理文件并新建归属当前管理员的记录
                    if ((int) $exist['admin_id'] === $this->auth->id()) {
                        $exist['fast_upload'] = true;
                        $exist['filename'] = $exist['name'];
                        $exist['filesize'] = $exist['size'];
                        $exist['mimetype'] = $exist['mime'];
                        $exist['imagetype'] = $exist['ext'];
                        $exist['fullurl'] = $storage === 'private'
                            ? '/adminapi/system.attachment/download?id=' . $exist['id']
                            : $this->getFullUrl((string) $exist['url']);
                        $this->success($exist, '秒传成功');
                    }
                    $newRecord = [
                        'admin_id'    => $this->auth->id(),
                        'user_id'     => 0,
                        'storage'     => $storage,
                        'url'         => (string) $exist['url'],
                        'name'        => $safeOrigName,
                        'mime'        => $realMime,
                        'ext'         => $ext,
                        'size'        => $size,
                        'width'       => $imgWidth,
                        'height'      => $imgHeight,
                        'sha1'        => $sha1,
                        'create_time' => $now,
                    ];
                    $newId = (int) Db::name('attachment')->insertGetId($newRecord);
                    $newRecord['id'] = $newId;
                    $newRecord['fast_upload'] = true;
                    $newRecord['filename'] = $safeOrigName;
                    $newRecord['filesize'] = $size;
                    $newRecord['mimetype'] = $realMime;
                    $newRecord['imagetype'] = $ext;
                    $newRecord['fullurl'] = $storage === 'private'
                        ? '/adminapi/system.attachment/download?id=' . $newId
                        : $this->getFullUrl((string) $newRecord['url']);
                    $this->success($newRecord, '秒传成功');
                }
            }
        }

        if ($storage === 'private') {
            $relDir = '/private/' . $dateDir;
            $targetDir = $this->app->getRuntimePath() . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . $dateDir;
        } else {
            $relDir = '/uploads/' . $dateDir;
            $targetDir = $this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $dateDir;
        }
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $saveName = bin2hex(random_bytes(16)) . '.' . $ext;
        $file->move($targetDir, $saveName);
        $savedFilePath = $targetDir . DIRECTORY_SEPARATOR . $saveName;

        $url = $relDir . '/' . $saveName;

        if ($isCloudStorage) {
            $objectKey = 'uploads/' . $dateDir . '/' . $saveName;
            $cloudRes = \plugins\oss\service\CloudStorageService::upload(
                $storage,
                $savedFilePath,
                $objectKey,
                $safeOrigName,
                $realMime,
                $size,
                $sha1,
                (int) $this->auth->id()
            );
            $url = (string) ($cloudRes['url'] ?? $url);
        }

        $record = [
            'admin_id'    => $this->auth->id(),
            'user_id'     => 0,
            'storage'     => $storage,
            'url'         => $url,
            'name'        => $safeOrigName,
            'mime'        => $realMime,
            'ext'         => $ext,
            'size'        => $size,
            'width'       => $imgWidth,
            'height'      => $imgHeight,
            'sha1'        => $sha1,
            'create_time' => $now,
        ];

        $id = (int) Db::name('attachment')->insertGetId($record);
        $record['id'] = $id;
        $record['fast_upload'] = false;
        $record['filename'] = $safeOrigName;
        $record['filesize'] = $size;
        $record['mimetype'] = $realMime;
        $record['imagetype'] = $ext;
        $record['fullurl'] = $storage === 'private'
            ? ($this->request->domain() . '/adminapi/system.attachment/download?id=' . $id)
            : $this->getFullUrl((string) $record['url']);

        $this->success($record, '上传成功');
    }

    /**
     * 附件受控/私有下载接口（S08）
     * GET /adminapi/system.attachment/download?id=123
     */
    public function download(): void
    {
        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            throw new ApiException('参数错误：缺少附件ID');
        }

        // 遵循数据范围与权限隔离检查
        $attachment = $this->buildIndexQuery()->where('id', $id)->find();
        if (!$attachment) {
            throw new ApiException('附件不存在或无权访问', 0, null, 403);
        }

        $url = (string) $attachment['url'];
        $storage = (string) ($attachment['storage'] ?? 'local');

        // 私有附件强制校验所有权（非超管只能访问自己上传的私有附件）
        if ($storage === 'private' && !$this->auth->isSuper() && (int) ($attachment['admin_id'] ?? 0) !== $this->auth->id()) {
            throw new ApiException('无权访问私有附件', 0, null, 403);
        }

        $filePath = $storage === 'private'
            ? $this->resolveSafePrivatePath($url)
            : $this->resolveSafeUploadPath($url);

        if ($filePath === null || !is_file($filePath)) {
            throw new ApiException('物理文件不存在或已被移除', 0, null, 404);
        }

        $fileName = (string) ($attachment['name'] ?: basename($filePath));
        $mime = (string) ($attachment['mime'] ?: 'application/octet-stream');
        $fileSize = filesize($filePath);

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . $fileSize);
        header('X-Content-Type-Options: nosniff');

        readfile($filePath);
        exit;
    }

    public function del(): void
    {
        $this->ensurePost();
        $ids = $this->request->post('ids', $this->request->param('id', ''));
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            throw new ApiException('请选择要删除的记录');
        }

        // 通过 buildIndexQuery() 查询（遵循数据范围），并在 afterDelete 中校验 realpath 后物理删除
        $rows = $this->buildIndexQuery()->whereIn('id', $ids)->select()->toArray();
        $this->pendingDeleteFiles = [];
        foreach ($rows as $row) {
            if (!empty($row['url'])) {
                $this->pendingDeleteFiles[(int) $row['id']] = [
                    'url'     => (string) $row['url'],
                    'storage' => (string) ($row['storage'] ?? 'local'),
                ];
            }
        }

        $this->crudDel();
    }

    protected function afterDelete(array $ids): void
    {
        foreach ($ids as $id) {
            $item = $this->pendingDeleteFiles[(int) $id] ?? null;
            if (!$item || empty($item['url'])) {
                continue;
            }
            $url = (string) $item['url'];
            $storage = (string) ($item['storage'] ?? 'local');

            // 若存在秒传复用同一物理文件的其他记录，则保留物理文件
            $stillUsed = Db::name('attachment')->where('url', $url)->count();
            if ($stillUsed > 0) {
                continue;
            }

            // 若为云存储驱动，联动清理云存储服务/日志
            if (in_array($storage, ['cos', 'oss', 'qiniu'], true)) {
                if (class_exists(\plugins\oss\service\CloudStorageService::class)) {
                    \plugins\oss\service\CloudStorageService::delete($storage, $url);
                }
            }

            $safeFullPath = $storage === 'private'
                ? $this->resolveSafePrivatePath($url)
                : $this->resolveSafeUploadPath($url);

            if ($safeFullPath !== null && is_file($safeFullPath)) {
                if (!@unlink($safeFullPath)) {
                    $this->app->log->warning('[attachment] 物理文件删除失败: ' . $safeFullPath);
                }
            }
        }
        $this->pendingDeleteFiles = [];
    }

    /**
     * 解析并校验私有附件物理路径必须严格位于 runtime/storage/private/ 目录内（S08 防路径穿越）
     */
    private function resolveSafePrivatePath(string $url): ?string
    {
        if (!str_starts_with($url, '/private/') || str_contains($url, '..') || str_contains($url, "\0")) {
            return null;
        }
        $baseDir = $this->app->getRuntimePath() . 'storage' . DIRECTORY_SEPARATOR . 'private';
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0755, true);
        }
        $privateRoot = realpath($baseDir);
        if ($privateRoot === false) {
            return null;
        }
        $candidate = $this->app->getRuntimePath() . 'storage' . str_replace('/', DIRECTORY_SEPARATOR, $url);
        $realCandidate = realpath($candidate);
        if ($realCandidate === false) {
            return null;
        }
        $normRoot = rtrim(str_replace('\\', '/', $privateRoot), '/') . '/';
        $normCand = str_replace('\\', '/', $realCandidate);
        if (!str_starts_with($normCand, $normRoot)) {
            return null;
        }
        return $realCandidate;
    }

    /**
     * 解析并校验附件物理路径必须严格位于 public/uploads/ 目录内（2-P0-3 防路径穿越删除任意文件）
     */
    private function resolveSafeUploadPath(string $url): ?string
    {
        if (!str_starts_with($url, '/uploads/') || str_contains($url, '..') || str_contains($url, "\0")) {
            return null;
        }
        $uploadsRoot = realpath($this->app->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'uploads');
        if ($uploadsRoot === false) {
            return null;
        }
        $candidate = $this->app->getRootPath() . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $url);
        $realCandidate = realpath($candidate);
        if ($realCandidate === false) {
            return null;
        }
        $normRoot = rtrim(str_replace('\\', '/', $uploadsRoot), '/') . '/';
        $normCand = str_replace('\\', '/', $realCandidate);
        if (!str_starts_with($normCand, $normRoot)) {
            return null;
        }
        return $realCandidate;
    }

    private function detectRealMime(string $filePath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $filePath);
                if (PHP_VERSION_ID < 80500 && function_exists('finfo_close')) {
                    @finfo_close($finfo);
                }
                if (is_string($mime) && $mime !== '') {
                    return strtolower(trim($mime));
                }
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($filePath);
            if (is_string($mime) && $mime !== '') {
                return strtolower(trim($mime));
            }
        }
        return 'application/octet-stream';
    }
}
