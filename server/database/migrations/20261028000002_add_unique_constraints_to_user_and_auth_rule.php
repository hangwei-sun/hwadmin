<?php
declare(strict_types=1);

use think\migration\Migrator;

/**
 * 为会员表 (user.username) 与权限规则表 (auth_rule.name) 增加唯一性索引与重复数据安全平滑升级（S07）
 */
class AddUniqueConstraintsToUserAndAuthRule extends Migrator
{
    public function up(): void
    {
        $prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? '');
        $pdo = $this->getAdapter()->getConnection();

        // 1. 会员表 user: 检查并去重，建立 username 唯一索引（参数绑定防御，保留最早主键）
        if ($this->hasTable('user')) {
            $userTableName = $prefix . 'user';
            $countRow = $this->fetchRow("SELECT COUNT(*) AS cnt FROM {$userTableName}");
            if ((int) ($countRow['cnt'] ?? 0) > 0) {
                $dups = $this->fetchAll("SELECT username, COUNT(*) as cnt FROM {$userTableName} GROUP BY username HAVING cnt > 1");
                $selStmt = $pdo->prepare("SELECT id FROM {$userTableName} WHERE username = :username ORDER BY id ASC");
                $updStmt = $pdo->prepare("UPDATE {$userTableName} SET username = :newUsername WHERE id = :id");

                foreach ($dups as $dup) {
                    $rawUsername = (string) $dup['username'];
                    $selStmt->execute([':username' => $rawUsername]);
                    $rows = $selStmt->fetchAll(PDO::FETCH_ASSOC);
                    array_shift($rows); // 保留首条记录
                    foreach ($rows as $r) {
                        $id = (int) $r['id'];
                        $newUsername = $rawUsername . '_' . $id;
                        $updStmt->execute([':newUsername' => $newUsername, ':id' => $id]);
                    }
                }
            }

            $userTable = $this->table('user');
            if ($userTable->hasIndex(['username'])) {
                $userTable->removeIndex(['username'])->save();
            }
            $userTable->addIndex(['username'], ['unique' => true, 'name' => 'idx_user_username_unique'])->save();
        }

        // 2. 权限规则表 auth_rule: 检查并清理重复 name，先迁移角色关联与子节点引用再删除重复记录，确保真正唯一约束生效
        if ($this->hasTable('auth_rule')) {
            $ruleTableName = $prefix . 'auth_rule';
            $groupTableName = $prefix . 'auth_group';
            $hasGroupTable = $this->hasTable('auth_group');

            $countRow = $this->fetchRow("SELECT COUNT(*) AS cnt FROM {$ruleTableName}");
            if ((int) ($countRow['cnt'] ?? 0) > 0) {
                $dups = $this->fetchAll("SELECT name, COUNT(*) as cnt FROM {$ruleTableName} GROUP BY name HAVING cnt > 1");
                $selRuleStmt = $pdo->prepare("SELECT id FROM {$ruleTableName} WHERE name = :name ORDER BY id ASC");
                $rebindChildStmt = $pdo->prepare("UPDATE {$ruleTableName} SET pid = :primaryId WHERE pid = :dupId");
                $delRuleStmt = $pdo->prepare("DELETE FROM {$ruleTableName} WHERE id = :dupId");

                foreach ($dups as $dup) {
                    $rawName = (string) $dup['name'];
                    $selRuleStmt->execute([':name' => $rawName]);
                    $rows = $selRuleStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (count($rows) <= 1) {
                        continue;
                    }
                    $primaryId = (int) array_shift($rows)['id'];

                    foreach ($rows as $r) {
                        $dupId = (int) $r['id'];

                        // 迁移子节点父级引用至保留的主节点
                        $rebindChildStmt->execute([':primaryId' => $primaryId, ':dupId' => $dupId]);

                        // 迁移角色关联 rules 字段中可能存在的重复规则 ID
                        if ($hasGroupTable) {
                            $groups = $this->fetchAll("SELECT id, rules FROM {$groupTableName}");
                            $updGroupStmt = $pdo->prepare("UPDATE {$groupTableName} SET rules = :rules WHERE id = :id");
                            foreach ($groups as $g) {
                                $rulesStr = trim((string) ($g['rules'] ?? ''));
                                if ($rulesStr === '*' || $rulesStr === '') {
                                    continue;
                                }
                                $ruleIds = array_values(array_filter(array_map('trim', explode(',', $rulesStr)), static fn ($v) => $v !== ''));
                                $dupStr = (string) $dupId;
                                if (in_array($dupStr, $ruleIds, true)) {
                                    $newRuleIds = array_map(static fn ($id) => $id === $dupStr ? (string) $primaryId : $id, $ruleIds);
                                    $newRulesStr = implode(',', array_values(array_unique($newRuleIds)));
                                    $updGroupStmt->execute([':rules' => $newRulesStr, ':id' => (int) $g['id']]);
                                }
                            }
                        }

                        // 安全删除冗余权限节点
                        $delRuleStmt->execute([':dupId' => $dupId]);
                    }
                }
            }

            // 无论此前是否存在普通 name 索引，先清理后重建真正的唯一索引
            $ruleTable = $this->table('auth_rule');
            if ($ruleTable->hasIndex(['name'])) {
                $ruleTable->removeIndex(['name'])->save();
            }
            $ruleTable->addIndex(['name'], ['unique' => true, 'name' => 'idx_auth_rule_name_unique'])->save();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('user')) {
            $userTable = $this->table('user');
            if ($userTable->hasIndex(['username'])) {
                $userTable->removeIndex(['username'])->save();
                $userTable->addIndex(['username'])->save();
            }
        }

        if ($this->hasTable('auth_rule')) {
            $ruleTable = $this->table('auth_rule');
            if ($ruleTable->hasIndex(['name'])) {
                $ruleTable->removeIndex(['name'])->save();
            }
        }
    }
}
