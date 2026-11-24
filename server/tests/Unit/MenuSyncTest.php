<?php
declare(strict_types=1);

namespace tests\Unit;

use core\plugin\MenuSync;
use tests\TestCase;
use think\facade\Db;

final class MenuSyncTest extends TestCase
{
    public function testEnsureNodeIsIdempotentAndSupportsParentLookup(): void
    {
        $nodeName = 'system/phpunit_probe';
        MenuSync::removeNodes([$nodeName, "{$nodeName}/index", "{$nodeName}/add"]);

        $id1 = MenuSync::ensureNode([
            'parent'    => 'system',
            'type'      => 'menu',
            'name'      => $nodeName,
            'title'     => '自检菜单',
            'path'      => 'phpunitProbe',
            'component' => '@/views/system/phpunitProbe/index.vue',
            'icon'      => 'tools-line',
            'children'  => [
                ['type' => 'button', 'name' => "{$nodeName}/index", 'title' => '查看'],
                ['type' => 'button', 'name' => "{$nodeName}/add", 'title' => '添加'],
            ],
        ]);
        $this->assertGreaterThan(0, $id1);

        // 二次同步应幂等更新且保持 ID 不变
        $id2 = MenuSync::ensureNode([
            'parent'    => 'system',
            'type'      => 'menu',
            'name'      => $nodeName,
            'title'     => '自检菜单-已更新',
            'path'      => 'phpunitProbe',
            'component' => '@/views/system/phpunitProbe/index.vue',
            'children'  => [
                ['type' => 'button', 'name' => "{$nodeName}/index", 'title' => '查看'],
                ['type' => 'button', 'name' => "{$nodeName}/add", 'title' => '添加'],
            ],
        ]);
        $this->assertSame($id1, $id2);

        $row = Db::name('auth_rule')->where('id', $id1)->find();
        $this->assertSame('自检菜单-已更新', $row['title']);

        $deleted = MenuSync::removeNodes([$nodeName, "{$nodeName}/index", "{$nodeName}/add"]);
        $this->assertSame(3, $deleted);
    }

    public function testSyncCoreRunsIdempotentlyWithoutDuplicatingRules(): void
    {
        $beforeCount = (int) Db::name('auth_rule')->where('plugin', '')->count();
        $stats = MenuSync::syncCore(false);
        $afterCount = (int) Db::name('auth_rule')->where('plugin', '')->count();

        $this->assertGreaterThan(0, $stats['total']);
        $this->assertSame($beforeCount, $afterCount);
    }
}
