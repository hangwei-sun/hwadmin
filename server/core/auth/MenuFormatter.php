<?php
declare(strict_types=1);

namespace core\auth;

/**
 * 将 auth_rule 菜单树转换为前端路由格式（Vab authentication=all 模式）
 *
 * 输出节点：
 * {
 *   path: '/auth', name: 'Auth', component: 'Layout' | '@/views/auth/admin/index.vue' | 'schema' | 'plugin:cms/Article',
 *   redirect?: string,
 *   meta: { title, icon, hidden, ruleId, plugin },
 *   children: []
 * }
 *
 * component 约定（见 docs/前端开发规范.md）：
 * - 'Layout'               顶级目录布局
 * - '@/views/xxx.vue'      主工程内页面
 * - 'schema'               通用 Schema 驱动 CRUD 页面（后端提供表格/表单 schema）
 * - 'plugin:{name}/{Page}' 插件运行时加载的页面（阶段 3 实现）
 */
final class MenuFormatter
{
    public static function format(array $tree, bool $isRoot = true): array
    {
        $routes = [];
        $usedPaths = [];
        foreach ($tree as $node) {
            $rawPath = (string) ($node['path'] ?: $node['name'] ?: 'rule' . $node['id']);
            $isExt = str_starts_with($rawPath, 'http://') || str_starts_with($rawPath, 'https://');
            if ($isExt) {
                $finalPath = $rawPath;
            } else {
                $segment = trim($rawPath, '/');
                if ($segment === '') {
                    $segment = 'rule' . $node['id'];
                }
                // 防止用户把多个同 path 菜单（如不同插件的 setting）调到同一父目录下时路由冲突
                $dedupSegment = $segment;
                $lowerSeg = strtolower($dedupSegment);
                if (isset($usedPaths[$lowerSeg])) {
                    $dedupSegment = $segment . '-' . (int) $node['id'];
                    $lowerSeg = strtolower($dedupSegment);
                }
                $usedPaths[$lowerSeg] = true;
                $finalPath = $isRoot ? '/' . $dedupSegment : $dedupSegment;
            }

            $route = [
                'path'      => $finalPath,
                'name'      => self::routeName($node),
                'component' => $node['component'] ?: ($node['type'] === 'dir' ? 'Layout' : ''),
                'meta'      => [
                    'title'  => (string) $node['title'],
                    'icon'   => (string) $node['icon'],
                    'hidden' => (int) ($node['hidden'] ?? 0) === 1,
                    'ruleId' => (int) $node['id'],
                    'plugin' => (string) ($node['plugin'] ?? ''),
                ],
            ];
            // 非顶级目录不能使用 Layout，避免布局嵌套
            if (!$isRoot && $route['component'] === 'Layout') {
                $route['component'] = '';
            }
            if (!empty($node['children'])) {
                $route['children'] = self::format($node['children'], false);
            }
            $routes[] = $route;
        }
        return $routes;
    }

    /**
     * 生成唯一路由名：优先由权限节点/路径转大驼峰，并追加 id 保证唯一
     */
    protected static function routeName(array $node): string
    {
        $source = (string) ($node['name'] ?: $node['path']);
        $studly = str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z0-9]+/', ' ', $source) ?? ''));
        return ($studly !== '' ? $studly : 'Rule') . $node['id'];
    }
}
