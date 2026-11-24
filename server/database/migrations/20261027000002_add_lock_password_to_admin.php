<?php
declare(strict_types=1);

use think\migration\Migrator;

/**
 * 为 admin 表增加 lock_password 个性锁屏解锁密码哈希字段
 */
class AddLockPasswordToAdmin extends Migrator
{
    public function up(): void
    {
        $table = $this->table('admin');
        if (!$table->hasColumn('lock_password')) {
            $table->addColumn('lock_password', 'string', [
                'limit'   => 255,
                'default' => '',
                'comment' => '个性锁屏解锁密码哈希',
            ])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('admin');
        if ($table->hasColumn('lock_password')) {
            $table->removeColumn('lock_password')->update();
        }
    }
}
