<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

/**
 * 附件模型（自动附加 前端习惯的别名字段，保持 100% 兼容）
 */
class Attachment extends UnixTimeModel
{
    protected $name = 'attachment';

    protected $updateTime = false;

    protected $append = ['filename', 'filesize', 'mimetype', 'imagetype', 'image_width', 'image_height', 'fullurl'];

    public function getFullurlAttr(mixed $value, array $data): string
    {
        $url = (string) ($data['url'] ?? $value ?? '');
        if ($url === '') {
            return '';
        }
        if (preg_match('/^(?:https?:)?\/\//i', $url) || str_starts_with($url, 'data:')) {
            return $url;
        }
        try {
            $domain = request()->domain();
            return $domain . ($url[0] === '/' ? $url : '/' . $url);
        } catch (\Throwable) {
            return $url;
        }
    }

    public function getFilenameAttr(mixed $value, array $data): string
    {
        return (string) ($data['name'] ?? $value ?? '');
    }

    public function getFilesizeAttr(mixed $value, array $data): int
    {
        return (int) ($data['size'] ?? $value ?? 0);
    }

    public function getMimetypeAttr(mixed $value, array $data): string
    {
        return (string) ($data['mime'] ?? $value ?? '');
    }

    public function getImagetypeAttr(mixed $value, array $data): string
    {
        return (string) ($data['ext'] ?? $value ?? '');
    }

    public function getImageWidthAttr(mixed $value, array $data): int
    {
        return (int) ($data['width'] ?? $value ?? 0);
    }

    public function getImageHeightAttr(mixed $value, array $data): int
    {
        return (int) ($data['height'] ?? $value ?? 0);
    }
}
