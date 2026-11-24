<?php
declare(strict_types=1);

use think\migration\Migrator;

/**
 * 同步核心顶级节点标题与个人中心菜单规则（版本化迁移，支持新库与旧库平滑升级）
 */
class SyncCoreRules extends Migrator
{
    public function up(): void
    {
        if (!$this->hasTable('auth_rule')) {
            return;
        }

        $prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? '');
        $tableName = $prefix . 'auth_rule';

        // 仅当表中已有数据时（即旧库升级场景）才执行同步；新装库在 seed() 阶段会由 menus.php 原生写入
        $countRow = $this->fetchRow("SELECT COUNT(*) AS cnt FROM {$tableName}");
        $count = (int) ($countRow['cnt'] ?? 0);
        if ($count === 0) {
            return;
        }

        $now = time();

        // 1. 同步四大顶级节点标题为：首页、权限、会员、系统
        $titleUpdates = [
            'dashboard' => '首页',
            'auth'      => '权限',
            'user'      => '会员',
            'system'    => '系统',
        ];
        foreach ($titleUpdates as $name => $title) {
            $this->execute(sprintf(
                "UPDATE %s SET title = '%s', update_time = %d WHERE name = '%s' AND (title <> '%s' OR title IS NULL)",
                $tableName,
                $title,
                $now,
                $name,
                $title
            ));
        }

        // 2. 确保 system/personal_center 规则存在
        $row = $this->fetchRow(sprintf("SELECT id FROM %s WHERE name = 'system/personal_center' LIMIT 1", $tableName));
        if (!$row) {
            $systemRow = $this->fetchRow(sprintf("SELECT id FROM %s WHERE name = 'system' LIMIT 1", $tableName));
            $pid = $systemRow ? (int) $systemRow['id'] : 0;

            $this->execute(sprintf(
                "INSERT INTO %s (pid, type, name, title, icon, path, component, weight, hidden, plugin, status, create_time, update_time) " .
                "VALUES (%d, 'menu', 'system/personal_center', '个人中心', 'user-follow-line', 'personalCenter', '@/views/setting/personalCenter/index.vue', 600, 0, '', 1, %d, %d)",
                $tableName,
                $pid,
                $now,
                $now
            ));

            $centerRow = $this->fetchRow(sprintf("SELECT id FROM %s WHERE name = 'system/personal_center' LIMIT 1", $tableName));
            $centerId = $centerRow ? (int) $centerRow['id'] : 0;

            $buttons = [
                ['name' => 'system/personal_center/index', 'title' => '查看'],
                ['name' => 'system/personal_center/edit', 'title' => '修改'],
            ];
            foreach ($buttons as $btn) {
                $exists = $this->fetchRow(sprintf("SELECT id FROM %s WHERE name = '%s' LIMIT 1", $tableName, $btn['name']));
                if (!$exists) {
                    $this->execute(sprintf(
                        "INSERT INTO %s (pid, type, name, title, icon, path, component, weight, hidden, plugin, status, create_time, update_time) " .
                        "VALUES (%d, 'button', '%s', '%s', '', '', '', 0, 0, '', 1, %d, %d)",
                        $tableName,
                        $centerId,
                        $btn['name'],
                        $btn['title'],
                        $now,
                        $now
                    ));
                }
            }
        } else {
            $this->execute(sprintf(
                "UPDATE %s SET title = '个人中心', component = '@/views/setting/personalCenter/index.vue', path = 'personalCenter', update_time = %d WHERE name = 'system/personal_center'",
                $tableName,
                $now
            ));
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('auth_rule')) {
            return;
        }

        $prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? '');
        $tableName = $prefix . 'auth_rule';

        $row = $this->fetchRow(sprintf("SELECT id FROM %s WHERE name = 'system/personal_center' LIMIT 1", $tableName));
        if ($row) {
            $id = (int) $row['id'];
            $this->execute(sprintf("DELETE FROM %s WHERE pid = %d", $tableName, $id));
            $this->execute(sprintf("DELETE FROM %s WHERE id = %d", $tableName, $id));
        }
    }
}
