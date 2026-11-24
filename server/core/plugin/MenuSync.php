<?php
declare(strict_types=1);

namespace core\plugin;

use core\exception\ApiException;
use PDO;
use Throwable;
use think\facade\Db;

/**
 * 菜单与权限规则幂等同步器（唯一数据源：core/install/data/menus.php 与 plugin.json）
 *
 * 彻底消除迁移脚本手写原生 SQL 字符串拼接与 MySQL/PgSQL/SQLite 方言差异：
 * - 日常运行时走 ThinkORM Db::name('auth_rule') 参数绑定；
 * - 在 Phinx 迁移中通过 MenuSync::withAdapter($this->getAdapter(), ...) 复用迁移同一 PDO 连接与绑定参数，
 *   避免 SQLite 迁移事务锁库冲突（database is locked），并保持 MySQL / PostgreSQL / SQLite 三库兼容。
 */
final class MenuSync
{
    private static ?PDO $scopedPdo = null;
    private static string $scopedPrefix = '';

    /**
     * 在 Phinx 迁移上下文中复用当前 Adapter 的 PDO 连接执行同步（参数化预处理，零 SQL 拼接）
     *
     * @template T
     * @param object   $adapter  Phinx AbstractAdapter / PdoAdapter 实例
     * @param callable(): T $callback
     * @return T
     */
    public static function withAdapter(object $adapter, callable $callback): mixed
    {
        $prevPdo = self::$scopedPdo;
        $prevPrefix = self::$scopedPrefix;

        $target = (!method_exists($adapter, 'getConnection') && method_exists($adapter, 'getAdapter'))
            ? $adapter->getAdapter()
            : $adapter;

        if (method_exists($target, 'getConnection')) {
            $conn = $target->getConnection();
            if ($conn instanceof PDO) {
                self::$scopedPdo = $conn;
            }
        }

        if (method_exists($target, 'getOption')) {
            self::$scopedPrefix = (string) ($target->getOption('table_prefix') ?? '');
        } elseif ($prevPrefix === '') {
            self::$scopedPrefix = (string) (Db::connect()->getConfig('prefix') ?? '');
        }

        try {
            return $callback();
        } finally {
            self::$scopedPdo = $prevPdo;
            self::$scopedPrefix = $prevPrefix;
        }
    }

    /**
     * 同步核心菜单（数据源：server/core/install/data/menus.php）
     *
     * @param bool $prune       true=清理未在 menus.php 中声明的核心节点；false=仅幂等新增/更新，保留自定义菜单
     * @param bool $forceLayout true=强制以 menus.php 覆盖现有节点归属(pid)/标题/图标/权重/显隐；false=保留用户在「权限-菜单规则」的布局调整
     * @return array{inserted: int, updated: int, pruned: int, total: int}
     */
    public static function syncCore(bool $prune = false, bool $forceLayout = false): array
    {
        $menusFile = root_path() . 'core' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'menus.php';
        if (!is_file($menusFile)) {
            throw new ApiException('核心菜单数据源不存在：' . $menusFile);
        }
        $menus = require $menusFile;
        if (!is_array($menus)) {
            throw new ApiException('核心菜单数据源格式无效');
        }

        return self::syncTree('', $menus, true, $prune, $forceLayout);
    }

    /**
     * 同步指定树形菜单列表到 auth_rule
     *
     * @return array{inserted: int, updated: int, pruned: int, total: int}
     */
    public static function syncTree(string $plugin, array $menus, bool $enabled = true, bool $prune = false, bool $forceLayout = false): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'pruned' => 0, 'total' => 0];
        $status = $enabled ? 1 : 0;

        $runner = static function () use ($plugin, $menus, $status, $prune, $forceLayout, &$stats): void {
            $kept = [];
            self::walkTree($plugin, $menus, 0, $status, $kept, $stats, $forceLayout, false);

            if ($prune) {
                $existingIds = self::getRuleIdsByPlugin($plugin);
                $removeIds = array_values(array_diff(array_map('intval', $existingIds), $kept));
                if ($removeIds) {
                    $stats['pruned'] = self::deleteRulesByIds($removeIds);
                }
            }
        };

        if (self::$scopedPdo !== null) {
            $runner();
        } else {
            Db::transaction($runner);
        }

        return $stats;
    }

    /**
     * 幂等确保单个菜单/目录/按钮节点及其 children 存在
     *
     * 支持通过 $node['parent'] 指定父节点标识（如 'system'、'auth'）自动解析 pid。
     */
    public static function ensureNode(array $node, int $pid = 0, string $plugin = '', int $status = 1): int
    {
        if ($pid <= 0 && !empty($node['pid'])) {
            $pid = (int) $node['pid'];
        }
        if ($pid <= 0 && !empty($node['parent'])) {
            $parentName = strtolower(trim((string) $node['parent'], '/'));
            $pid = self::resolveParentId($parentName);
        }

        $stats = ['inserted' => 0, 'updated' => 0, 'pruned' => 0, 'total' => 0];
        $kept = [];
        self::walkTree($plugin, [$node], $pid, $status, $kept, $stats, false, true);

        return $kept[0] ?? 0;
    }

    /**
     * 按节点标识批量安全删除规则（供迁移 down() 使用）
     */
    public static function removeNodes(array $names): int
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($n) => strtolower(trim((string) $n, '/')),
            $names
        ))));
        if (!$normalized) {
            return 0;
        }

        return self::deleteRulesByNames($normalized);
    }

    private static function walkTree(
        string $plugin,
        array $items,
        int $pid,
        int $status,
        array &$kept,
        array &$stats,
        bool $forceLayout = false,
        bool $isEnsureNode = false
    ): void {
        $requiredPrefix = $plugin !== '' ? ('plugin/' . strtolower($plugin) . '/') : '';
        $now = time();

        foreach ($items as $index => $item) {
            if (!is_array($item) || empty($item['title'])) {
                continue;
            }

            $children = (array) ($item['children'] ?? []);
            $path = trim((string) ($item['path'] ?? ''), '/');
            $name = strtolower(trim((string) ($item['name'] ?? ''), '/'));
            if ($name === '') {
                $name = ($plugin !== '' ? 'plugin/' . $plugin . '/' : '') . ($path !== '' ? $path : 'menu' . $pid . '_' . $index);
            }

            if ($requiredPrefix !== '' && !str_starts_with($name, $requiredPrefix)) {
                throw new ApiException(sprintf(
                    '安全拦截：插件 [%s] 的权限节点 [%s] 必须以 "%s" 开头，禁止注册核心或其他插件权限节点',
                    $plugin,
                    $name,
                    $requiredPrefix
                ));
            }

            $existingRow = self::findRuleByName($name);
            if ($existingRow && (string) ($existingRow['plugin'] ?? '') !== $plugin) {
                throw new ApiException(sprintf('权限节点 [%s] 已被其他模块占用，无法同步', $name));
            }

            $type = (string) ($item['type'] ?? ($children ? 'dir' : 'menu'));
            $data = [
                'pid'         => $pid,
                'type'        => $type,
                'name'        => $name,
                'title'       => (string) $item['title'],
                'icon'        => (string) ($item['icon'] ?? ''),
                'path'        => $path,
                'component'   => (string) ($item['component'] ?? ''),
                'weight'      => (int) ($item['weight'] ?? 0),
                'hidden'      => !empty($item['hidden']) ? 1 : 0,
                'plugin'      => $plugin,
                'status'      => $status,
                'update_time' => $now,
            ];

            if ($existingRow) {
                $id = (int) $existingRow['id'];
                if (!$forceLayout) {
                    // 保留用户在「权限 -> 菜单规则」调整后的父级归属(pid)
                    $existingPid = (int) ($existingRow['pid'] ?? 0);
                    if ($existingPid === 0) {
                        $data['pid'] = 0;
                    } elseif ($existingPid > 0 && $existingPid !== $id && self::ruleExistsById($existingPid)) {
                        $data['pid'] = $existingPid;
                    }
                    // 非单节点 ensureNode 同步时，一并保留自定义标题/图标/权重/显隐
                    if (!$isEnsureNode) {
                        if (trim((string) ($existingRow['title'] ?? '')) !== '') {
                            $data['title'] = (string) $existingRow['title'];
                        }
                        if (trim((string) ($existingRow['icon'] ?? '')) !== '') {
                            $data['icon'] = (string) $existingRow['icon'];
                        }
                        $data['weight'] = (int) ($existingRow['weight'] ?? $data['weight']);
                        $data['hidden'] = (int) ($existingRow['hidden'] ?? $data['hidden']);
                    } elseif (!array_key_exists('weight', $item) && (int) ($existingRow['weight'] ?? 0) !== 0) {
                        $data['weight'] = (int) $existingRow['weight'];
                    }
                }
                self::updateRule($id, $data);
                $stats['updated']++;
            } else {
                $data['create_time'] = $now;
                $id = self::insertRule($data);
                $stats['inserted']++;
            }

            $stats['total']++;
            $kept[] = $id;

            if ($children) {
                self::walkTree($plugin, $children, $id, $status, $kept, $stats, $forceLayout, $isEnsureNode);
            }
        }
    }

    private static function ruleExistsById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        if (self::$scopedPdo === null) {
            return (bool) Db::name('auth_rule')->where('id', $id)->value('id');
        }
        $stmt = self::$scopedPdo->prepare(sprintf('SELECT id FROM %s WHERE id = ? LIMIT 1', self::scopedTable()));
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    private static function scopedTable(): string
    {
        $table = self::$scopedPrefix . 'auth_rule';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new ApiException('非法的数据表名：' . $table);
        }
        return $table;
    }

    private static function findRuleByName(string $name): ?array
    {
        if (self::$scopedPdo === null) {
            $row = Db::name('auth_rule')->where('name', $name)->find();
            return is_array($row) ? $row : null;
        }

        $stmt = self::$scopedPdo->prepare(sprintf('SELECT * FROM %s WHERE name = ? LIMIT 1', self::scopedTable()));
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function resolveParentId(string $parentName): int
    {
        if (self::$scopedPdo === null) {
            return (int) (Db::name('auth_rule')->where('name', $parentName)->value('id') ?: 0);
        }

        $stmt = self::$scopedPdo->prepare(sprintf('SELECT id FROM %s WHERE name = ? LIMIT 1', self::scopedTable()));
        $stmt->execute([$parentName]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : 0;
    }

    private static function insertRule(array $data): int
    {
        if (self::$scopedPdo === null) {
            return (int) Db::name('auth_rule')->insertGetId($data);
        }

        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::scopedTable(),
            implode(', ', $columns),
            $placeholders
        );
        $stmt = self::$scopedPdo->prepare($sql);
        $stmt->execute(array_values($data));

        $row = self::findRuleByName((string) $data['name']);
        if ($row && isset($row['id'])) {
            return (int) $row['id'];
        }

        try {
            return (int) self::$scopedPdo->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function updateRule(int $id, array $data): void
    {
        if (self::$scopedPdo === null) {
            Db::name('auth_rule')->where('id', $id)->update($data);
            return;
        }

        $assignments = [];
        $values = [];
        foreach ($data as $col => $val) {
            $assignments[] = $col . ' = ?';
            $values[] = $val;
        }
        $values[] = $id;

        $sql = sprintf(
            'UPDATE %s SET %s WHERE id = ?',
            self::scopedTable(),
            implode(', ', $assignments)
        );
        $stmt = self::$scopedPdo->prepare($sql);
        $stmt->execute($values);
    }

    private static function getRuleIdsByPlugin(string $plugin): array
    {
        if (self::$scopedPdo === null) {
            return Db::name('auth_rule')->where('plugin', $plugin)->column('id');
        }

        $stmt = self::$scopedPdo->prepare(sprintf('SELECT id FROM %s WHERE plugin = ?', self::scopedTable()));
        $stmt->execute([$plugin]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private static function deleteRulesByIds(array $ids): int
    {
        if (self::$scopedPdo === null) {
            return (int) Db::name('auth_rule')->whereIn('id', $ids)->delete();
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = self::$scopedPdo->prepare(sprintf('DELETE FROM %s WHERE id IN (%s)', self::scopedTable(), $placeholders));
        $stmt->execute(array_values(array_map('intval', $ids)));
        return $stmt->rowCount();
    }

    private static function deleteRulesByNames(array $names): int
    {
        if (self::$scopedPdo === null) {
            return (int) Db::name('auth_rule')->whereIn('name', $names)->delete();
        }

        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $stmt = self::$scopedPdo->prepare(sprintf('DELETE FROM %s WHERE name IN (%s)', self::scopedTable(), $placeholders));
        $stmt->execute(array_values($names));
        return $stmt->rowCount();
    }
}
