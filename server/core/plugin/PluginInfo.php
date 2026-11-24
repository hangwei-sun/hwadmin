<?php
declare(strict_types=1);

namespace core\plugin;

use core\exception\ApiException;

/**
 * 插件清单（plugins/{name}/plugin.json）的只读模型
 *
 * {
 *   "name": "demo",                       // 唯一标识：小写字母开头，仅含小写字母/数字/下划线，与目录名一致
 *   "title": "示例插件",
 *   "version": "1.0.0",                   // 语义化版本
 *   "description": "",
 *   "author": "",
 *   "require": { "hwadmin": ">=0.1.0", "php": ">=8.2", "plugins": { "other": ">=1.0.0" } },
 *   "menus": [ { "type": "dir", "title": "示例", "path": "demo", "icon": "apps-line", "children": [...] } ],
 *   "config": [ { "name": "key", "title": "标题", "type": "string", "value": "" } ]
 * }
 */
final class PluginInfo
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    private function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author,
        public readonly array $require,
        public readonly array $menus,
        public readonly array $config,
        public readonly string $path,
        public readonly array $raw,
    ) {
    }

    public static function fromDirectory(string $dir): self
    {
        $dir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        $file = $dir . 'plugin.json';
        if (!is_file($file)) {
            throw new ApiException('插件清单不存在：' . $file);
        }
        $raw = json_decode((string) file_get_contents($file), true);
        if (!is_array($raw)) {
            throw new ApiException('插件清单不是合法 JSON：' . $file);
        }
        $name = (string) ($raw['name'] ?? '');
        if (!preg_match(self::NAME_PATTERN, $name) || $name !== basename(rtrim($dir, '/\\'))) {
            throw new ApiException("插件标识不合法或与目录名不一致：{$name}");
        }
        if (!preg_match('/^\d+\.\d+\.\d+([\-+].+)?$/', (string) ($raw['version'] ?? ''))) {
            throw new ApiException("插件 {$name} 版本号必须为语义化版本，如 1.0.0");
        }
        return new self(
            $name,
            (string) ($raw['title'] ?? $name),
            (string) $raw['version'],
            (string) ($raw['description'] ?? ''),
            (string) ($raw['author'] ?? ''),
            (array) ($raw['require'] ?? []),
            (array) ($raw['menus'] ?? []),
            (array) ($raw['config'] ?? []),
            $dir,
            $raw,
        );
    }

    /**
     * 插件主类全名
     */
    public function className(): string
    {
        return 'plugins\\' . $this->name . '\\Plugin';
    }

    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'title'       => $this->title,
            'version'     => $this->version,
            'description' => $this->description,
            'author'      => $this->author,
            'require'     => $this->require,
        ];
    }
}
