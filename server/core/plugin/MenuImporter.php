<?php
declare(strict_types=1);

namespace core\plugin;

use core\exception\ApiException;
use think\facade\Db;

/**
 * 菜单/权限规则导入器：把 plugin.json 的 menus 同步到 auth_rule（plugin 字段标记归属）。
 *
 * 菜单项字段：
 *   type       dir|menu|button（默认 menu；有 children 且未指定时为 dir）
 *   name       权限节点，插件内唯一；缺省时自动生成 plugin/{插件}/{path}
 *   title      标题（必填）
 *   path       前端路由路径片段
 *   component  前端组件（见 core\auth\MenuFormatter 约定）
 *   icon, weight, hidden, children
 *
 * 安全基线（2-P0-5 防权限串号）：
 *   1. 当 $plugin !== '' 时（即插件菜单），所有规则的 name 必须严格以 `plugin/{$plugin}/` 开头，
 *      否则直接抛出 ApiException 拒绝安装，防止恶意或错误插件注册 `auth/admin/edit` 等核心权限节点；
 *   2. 跨插件/核心唯一性检查：禁止注册已被核心或其他插件占用的同名规则。
 */
final class MenuImporter
{
    public static function import(string $plugin, array $menus, bool $enabled, bool $forceLayout = false): void
    {
        $rawExisting = Db::name('auth_rule')->where('plugin', $plugin)->column('*', 'name');
        $existing = is_array($rawExisting) ? array_change_key_case($rawExisting, CASE_LOWER) : [];
        $kept = [];
        self::walk($plugin, $menus, 0, $enabled ? 1 : 0, $existing, $kept, $forceLayout);
        $remove = array_diff(array_map(static fn (array $r) => (int) $r['id'], $existing), $kept);
        if ($remove) {
            self::rescueExternalChildren($plugin, $remove);
            Db::name('auth_rule')->whereIn('id', $remove)->delete();
        }
    }

    public static function remove(string $plugin): void
    {
        $ids = Db::name('auth_rule')->where('plugin', $plugin)->column('id');
        if ($ids) {
            self::rescueExternalChildren($plugin, array_map('intval', $ids));
        }
        Db::name('auth_rule')->where('plugin', $plugin)->delete();
    }

    public static function setStatus(string $plugin, int $status): void
    {
        Db::name('auth_rule')->where('plugin', $plugin)->update(['status' => $status, 'update_time' => time()]);
    }

    private static function walk(string $plugin, array $items, int $pid, int $status, array $existing, array &$kept, bool $forceLayout = false): void
    {
        $exactNamespace = $plugin !== '' ? ('plugin/' . strtolower($plugin)) : '';
        $requiredPrefix = $exactNamespace !== '' ? ($exactNamespace . '/') : '';

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

            // 2-P0-5：强制校验插件权限节点前缀，严禁插件声明非自身命名空间的权限节点（允许精确根节点如 plugin/crud 或 plugin/crud/... 子节点）
            if ($requiredPrefix !== '' && $name !== $exactNamespace && !str_starts_with($name, $requiredPrefix)) {
                throw new ApiException(sprintf(
                    '安全拦截：插件 [%s] 的权限节点 [%s] 必须以 "%s" 开头，禁止注册核心或其他插件权限节点',
                    $plugin,
                    $name,
                    $requiredPrefix
                ));
            }

            // 防止覆盖其他插件已存在的同名规则；若在插件自身命名空间下且原先由核心预置(plugin='')，直接复用并保留现有归属布局
            $conflict = Db::name('auth_rule')
                ->where('name', $name)
                ->where('plugin', '<>', $plugin)
                ->find();
            if ($conflict) {
                if ($plugin !== '' && (string) ($conflict['plugin'] ?? '') === '') {
                    $id = (int) $conflict['id'];
                    Db::name('auth_rule')->where('id', $id)->update([
                        'status'      => $status,
                        'update_time' => time(),
                    ]);
                    if ($children) {
                        self::walk($plugin, $children, $id, $status, $existing, $kept, $forceLayout);
                    }
                    continue;
                }
                throw new ApiException(sprintf('权限节点 [%s] 已被其他模块占用，无法导入', $name));
            }

            $resolvedPid = $pid;
            if ($resolvedPid <= 0 && !empty($item['parent'])) {
                $parentName = strtolower(trim((string) $item['parent'], '/'));
                $parentId = (int) (Db::name('auth_rule')->where('name', $parentName)->value('id') ?: 0);
                if ($parentId > 0) {
                    $resolvedPid = $parentId;
                }
            }

            $now = time();
            $data = [
                'pid'         => $resolvedPid,
                'type'        => (string) ($item['type'] ?? ($children ? 'dir' : 'menu')),
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
            if (isset($existing[$name])) {
                $existingRow = $existing[$name];
                $id = (int) $existingRow['id'];
                if (!$forceLayout) {
                    // 保留用户在「权限 -> 菜单规则」中手动调整过的归属目录(pid)、名称、图标、排序与显隐状态
                    $existingPid = (int) ($existingRow['pid'] ?? 0);
                    if ($existingPid === 0) {
                        $data['pid'] = 0;
                    } elseif ($existingPid > 0 && $existingPid !== $id && self::ruleExists($existingPid)) {
                        $data['pid'] = $existingPid;
                    }
                    if (trim((string) ($existingRow['title'] ?? '')) !== '') {
                        $data['title'] = (string) $existingRow['title'];
                    }
                    if (trim((string) ($existingRow['icon'] ?? '')) !== '') {
                        $data['icon'] = (string) $existingRow['icon'];
                    }
                    $data['weight'] = (int) ($existingRow['weight'] ?? $data['weight']);
                    $data['hidden'] = (int) ($existingRow['hidden'] ?? $data['hidden']);
                }
                Db::name('auth_rule')->where('id', $id)->update($data);
            } else {
                $data['create_time'] = $now;
                $id = (int) Db::name('auth_rule')->insertGetId($data);
            }
            $kept[] = $id;
            if ($children) {
                self::walk($plugin, $children, $id, $status, $existing, $kept, $forceLayout);
            }
        }
    }

    private static function ruleExists(int $id): bool
    {
        return $id > 0 && (bool) Db::name('auth_rule')->where('id', $id)->value('id');
    }

    /**
     * 卸载或清理插件规则时，若其他模块/用户自定义规则曾挂在该插件目录下，将其提升至顶级(pid=0)避免孤儿失踪
     */
    private static function rescueExternalChildren(string $plugin, array $deletedIds): void
    {
        if (!$deletedIds) {
            return;
        }
        Db::name('auth_rule')
            ->whereIn('pid', $deletedIds)
            ->where('plugin', '<>', $plugin)
            ->update(['pid' => 0, 'update_time' => time()]);
    }
}
