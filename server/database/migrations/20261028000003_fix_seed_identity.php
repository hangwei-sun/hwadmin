<?php
declare(strict_types=1);

use think\migration\Migrator;

class FixSeedIdentity extends Migrator
{
    public function up(): void
    {
        if ($this->getAdapter()->getOption('adapter') !== 'pgsql') {
            return;
        }
        foreach (['auth_group', 'user_group'] as $name) {
            if (!$this->hasTable($name)) continue;
            $table = $this->table($name);
            $column = $table->getColumn('id');
            if ($column && $column->isIdentity() && $column->getGenerated() === 'ALWAYS') {
                $column->setGenerated('BY DEFAULT');
                $table->changeColumn('id', $column)->update();
            }
        }
    }
    // Keep BY DEFAULT on rollback: reverting to ALWAYS would break installer
    // retries and explicit seed IDs in databases that retain their data.
    public function down(): void {}
}
