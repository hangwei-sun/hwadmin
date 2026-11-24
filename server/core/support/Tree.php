<?php
declare(strict_types=1);

namespace core\support;

/**
 * 通用树工具（扁平数组 <-> 树）
 */
final class Tree
{
    /**
     * 扁平数组构建为树
     * @param array      $rows     每行至少包含 id、pid
     * @param int|string $rootPid  根节点的 pid
     */
    public static function build(array $rows, int|string $rootPid = 0, string $idKey = 'id', string $pidKey = 'pid', string $childrenKey = 'children'): array
    {
        $refs = [];
        foreach ($rows as $row) {
            $row[$childrenKey] = [];
            $refs[$row[$idKey]] = $row;
        }
        $tree = [];
        foreach ($refs as $id => &$node) {
            $pid = $node[$pidKey];
            if ($pid == $rootPid) {
                $tree[] = &$node;
            } elseif (isset($refs[$pid])) {
                $refs[$pid][$childrenKey][] = &$node;
            }
            // 父节点不可见（无权限/被禁用）的孤儿节点直接丢弃，避免菜单错乱
        }
        unset($node);
        return self::detach($tree, $childrenKey);
    }

    /**
     * 获取某节点的所有子孙 id（含自身可选）
     */
    public static function descendantIds(array $rows, int $id, bool $withSelf = false, string $idKey = 'id', string $pidKey = 'pid'): array
    {
        $children = [];
        foreach ($rows as $row) {
            $children[$row[$pidKey]][] = $row[$idKey];
        }
        $result = $withSelf ? [$id] : [];
        $stack = [$id];
        while ($stack) {
            $current = array_pop($stack);
            foreach ($children[$current] ?? [] as $child) {
                $result[] = $child;
                $stack[] = $child;
            }
        }
        return $result;
    }

    /**
     * 去除引用，返回纯数组
     */
    private static function detach(array $tree, string $childrenKey): array
    {
        $result = [];
        foreach ($tree as $node) {
            $copy = $node;
            $copy[$childrenKey] = self::detach($node[$childrenKey] ?? [], $childrenKey);
            $result[] = $copy;
        }
        return $result;
    }
}
