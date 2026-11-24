<?php
declare(strict_types=1);

use think\migration\Migrator;

/**
 * 核心业务表与热路径高频查询复合索引补齐（MySQL 8.0+ / PostgreSQL 16+ / SQLite 幂等兼容）
 */
class AddPerformanceIndexes extends Migrator
{
    /**
     * @var array<int, array{table: string, columns: string[], name: string}>
     */
    private const INDEXES = [
        ['table' => 'token', 'columns' => ['expire_time'], 'name' => 'idx_token_expire_time'],
        ['table' => 'attachment', 'columns' => ['admin_id'], 'name' => 'idx_attachment_admin_id'],
        ['table' => 'attachment', 'columns' => ['sha1', 'storage'], 'name' => 'idx_attachment_sha1_storage'],
        ['table' => 'admin_log', 'columns' => ['admin_id', 'create_time'], 'name' => 'idx_admin_log_admin_create'],
        ['table' => 'dict_item', 'columns' => ['dict_id', 'status', 'sort'], 'name' => 'idx_dict_item_dict_status_sort'],
        ['table' => 'crontab_log', 'columns' => ['crontab_id', 'id'], 'name' => 'idx_crontab_log_crontab_id_id'],
        ['table' => 'auth_group', 'columns' => ['pid', 'status'], 'name' => 'idx_auth_group_pid_status'],
        ['table' => 'auth_rule', 'columns' => ['status', 'weight'], 'name' => 'idx_auth_rule_status_weight'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $def) {
            $tableName = $def['table'];
            $columns = $def['columns'];
            $indexName = $def['name'];

            if (!$this->hasTable($tableName)) {
                continue;
            }

            $table = $this->table($tableName);
            foreach ($columns as $col) {
                if ($col !== 'id' && !$table->hasColumn($col)) {
                    continue 2;
                }
            }

            if (!$table->hasIndex($columns) && !$table->hasIndexByName($indexName)) {
                $table->addIndex($columns, ['name' => $indexName])->save();
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEXES) as $def) {
            $tableName = $def['table'];
            $columns = $def['columns'];
            $indexName = $def['name'];

            if (!$this->hasTable($tableName)) {
                continue;
            }

            $table = $this->table($tableName);
            if ($table->hasIndexByName($indexName)) {
                $table->removeIndexByName($indexName)->save();
            } elseif ($table->hasIndex($columns)) {
                $table->removeIndex($columns)->save();
            }
        }
    }
}
