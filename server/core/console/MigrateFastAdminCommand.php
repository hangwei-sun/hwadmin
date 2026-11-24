<?php
declare(strict_types=1);

namespace core\console;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use Throwable;

/**
 * FastAdmin 一键平滑迁移 CLI 命令
 *
 * 用法：
 *   export FA_DB_PASS=123456
 *   php think hw:migrate-fa --host=127.0.0.1 --dbname=fastadmin --user=root --prefix=fa_
 *   php think hw:migrate-fa --sqlite=/tmp/fa.sqlite --prefix=fa_
 *   php think hw:migrate-fa --sqlite=/tmp/fa.sqlite --dry-run
 */
class MigrateFastAdminCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:migrate-fa')
            ->addOption('host', null, Option::VALUE_OPTIONAL, '源 FastAdmin 数据库主机', '127.0.0.1')
            ->addOption('port', null, Option::VALUE_OPTIONAL, '源 FastAdmin 数据库端口', '3306')
            ->addOption('dbname', null, Option::VALUE_OPTIONAL, '源 FastAdmin 数据库库名', 'fastadmin')
            ->addOption('user', null, Option::VALUE_OPTIONAL, '源 FastAdmin 数据库用户名', 'root')
            ->addOption('pass', null, Option::VALUE_OPTIONAL, '源 FastAdmin 数据库密码（建议设置 FA_DB_PASS 环境变量以避免进入 Shell 历史）', '')
            ->addOption('prefix', null, Option::VALUE_OPTIONAL, '源 FastAdmin 数据表前缀', 'fa_')
            ->addOption('sqlite', null, Option::VALUE_OPTIONAL, '直接使用 SQLite 源数据库文件进行迁移（离线/测试）')
            ->addOption('dry-run', null, Option::VALUE_NONE, '执行结构化迁移预检报告，不实际写入目标数据库')
            ->addOption('force', null, Option::VALUE_NONE, '若目标数据已存在，强制覆盖更新')
            ->setDescription('FastAdmin 1:1 数据库与权限节点平滑迁移 CLI 工具');
    }

    protected function execute(Input $input, Output $output): int
    {
        $output->writeln('<info>====================================================</info>');
        $output->writeln('<info>       HwAdmin FastAdmin 一键平滑迁移工具           </info>');
        $output->writeln('<info>====================================================</info>');

        $sqlite = (string) ($input->getOption('sqlite') ?? '');
        $prefix = (string) ($input->getOption('prefix') ?? 'fa_');
        $isDryRun = (bool) $input->getOption('dry-run');
        $isForce = (bool) $input->getOption('force');

        if ($sqlite !== '') {
            if (!is_file($sqlite)) {
                $output->error("SQLite 源数据库文件不存在：{$sqlite}");
                return 1;
            }
            $sourceConfig = [
                'type'            => 'sqlite',
                'hostname'        => $sqlite,
                'database'        => $sqlite,
                'prefix'          => $prefix,
                'charset'         => 'utf8mb4',
                'break_reconnect' => true,
            ];
            $output->writeln("源数据库连接：SQLite ({$sqlite})");
        } else {
            $host = (string) $input->getOption('host');
            $port = (int) $input->getOption('port');
            $dbname = (string) $input->getOption('dbname');
            $user = (string) $input->getOption('user');

            // 安全读取密码：优先读取命令行参数，未传入时从 FA_DB_PASS 环境变量获取
            $passOption = (string) ($input->getOption('pass') ?? '');
            $passEnv = (string) (getenv('FA_DB_PASS') ?: (function_exists('env') ? env('FA_DB_PASS', '') : ''));
            $pass = $passOption !== '' ? $passOption : $passEnv;

            $sourceConfig = [
                'type'            => 'mysql',
                'hostname'        => $host,
                'hostport'        => $port,
                'database'        => $dbname,
                'username'        => $user,
                'password'        => $pass,
                'prefix'          => $prefix,
                'charset'         => 'utf8mb4',
                'break_reconnect' => true,
            ];
            $output->writeln("源数据库连接：MySQL ({$host}:{$port}/{$dbname})");
        }

        try {
            $sourceDb = Db::connect($sourceConfig);
            $sourceDb->query('SELECT 1');
            $output->writeln('<info>[✔] 成功连接至源 FastAdmin 数据库</info>');
        } catch (Throwable $e) {
            $output->error('连接源 FastAdmin 数据库失败：' . $e->getMessage());
            return 1;
        }

        if ($isDryRun) {
            $output->writeln('<comment>[DRY-RUN 模式] 仅执行连通性与数据完整性预检，不会向目标库写入任何数据。</comment>');
            $targetDb = Db::connect();
            $this->dryRun($sourceDb, $targetDb, $prefix, $output);
            return 0;
        }

        $targetDb = Db::connect();

        $output->writeln('开始执行数据迁移...');
        $this->migrateAdmins($sourceDb, $targetDb, $isForce, $output);
        $this->migrateAuthGroups($sourceDb, $targetDb, $isForce, $output);
        $this->migrateAuthGroupAccess($sourceDb, $targetDb, $isForce, $output);
        $this->migrateAuthRules($sourceDb, $targetDb, $isForce, $output);
        $this->migrateUserGroups($sourceDb, $targetDb, $isForce, $output);
        $this->migrateUsers($sourceDb, $targetDb, $isForce, $output);
        $this->migrateAttachments($sourceDb, $targetDb, $isForce, $output);

        $output->writeln('<info>====================================================</info>');
        $output->writeln('<info>🎉 FastAdmin 数据迁移全部完成！</info>');
        $output->writeln('<info>💡 提示：管理员及会员密码无感兼容，原密码直接登录后自动升级为 bcrypt！</info>');
        $output->writeln('<info>====================================================</info>');

        return 0;
    }

    /**
     * 增强版 DRY-RUN 预检结构化报告
     */
    protected function dryRun(
        \think\db\ConnectionInterface $sourceDb,
        \think\db\ConnectionInterface $targetDb,
        string $prefix,
        Output $output
    ): void {
        $output->writeln('');
        $output->writeln('<info>----------------------------------------------------</info>');
        $output->writeln('<info>           FastAdmin 迁移预检结构化报告             </info>');
        $output->writeln('<info>----------------------------------------------------</info>');

        // 1. 核心表数据量统计
        $output->writeln('<comment>[1. 核心表数据量统计]</comment>');
        $tables = [
            'admin'             => '管理员表',
            'auth_group'        => '角色组表',
            'auth_group_access' => '管理员与角色组关联表',
            'auth_rule'         => '菜单与权限规则表',
            'user_group'        => '会员组表',
            'user'              => '会员用户表',
            'attachment'        => '附件多媒体表',
        ];

        foreach ($tables as $table => $label) {
            try {
                $count = $sourceDb->name($table)->count();
                $output->writeln(sprintf('  - %-20s (%s): <info>%d 条记录</info>', $table, $label, $count));
            } catch (Throwable $e) {
                $output->writeln(sprintf('  - %-20s (%s): <comment>未检测到该表或不可读 (%s)</comment>', $table, $label, $e->getMessage()));
            }
        }

        // 2. 用户名/主键冲突检测
        $output->writeln('');
        $output->writeln('<comment>[2. 管理员冲突检测 (fa_admin -> hw_admin)]</comment>');
        try {
            $sourceAdmins = $sourceDb->name('admin')->column('username', 'id');
            $targetAdmins = $targetDb->name('admin')->column('username', 'id');

            $conflictUsers = [];
            $conflictIds = [];
            foreach ($sourceAdmins as $sId => $sUser) {
                if (in_array($sUser, $targetAdmins, true)) {
                    $conflictUsers[] = (string) $sUser;
                }
                if (isset($targetAdmins[$sId])) {
                    $conflictIds[] = $sId;
                }
            }

            if (!empty($conflictUsers)) {
                $output->writeln(sprintf('  <error>[!] 发现同名管理员冲突 %d 个：</error>%s', count($conflictUsers), implode(', ', $conflictUsers)));
                $output->writeln('      提示：若继续执行且未加 --force，已存在的管理员将被跳过；若加 --force 将覆盖更新目标库数据。');
            } else {
                $output->writeln('  <info>[✔] 未发现用户名重名冲突</info>');
            }

            if (!empty($conflictIds)) {
                $output->writeln(sprintf('  <comment>[i] 发现主键 ID 重叠 %d 个（ID: %s）</comment>', count($conflictIds), implode(', ', array_slice($conflictIds, 0, 10)) . (count($conflictIds) > 10 ? '...' : '')));
            }
        } catch (Throwable $e) {
            $output->writeln('  <error>管理员冲突检测失败: ' . $e->getMessage() . '</error>');
        }

        // 3. 权限规则与角色组依赖检查
        $output->writeln('');
        $output->writeln('<comment>[3. 角色组与权限规则依赖检查 (auth_group -> auth_rule)]</comment>');
        try {
            $validRuleIds = array_map('strval', $sourceDb->name('auth_rule')->column('id'));
            $validRuleMap = array_flip($validRuleIds);

            $groups = $sourceDb->name('auth_group')->select()->toArray();
            $brokenGroupDeps = [];

            foreach ($groups as $group) {
                $gName = (string) ($group['name'] ?? 'ID:' . $group['id']);
                $rulesStr = (string) ($group['rules'] ?? '');
                if ($rulesStr === '' || $rulesStr === '*') {
                    continue;
                }

                $rules = explode(',', $rulesStr);
                $missingRules = [];
                foreach ($rules as $rId) {
                    $rId = trim($rId);
                    if ($rId !== '' && $rId !== '*' && !isset($validRuleMap[$rId])) {
                        $missingRules[] = $rId;
                    }
                }

                if (!empty($missingRules)) {
                    $brokenGroupDeps[$gName] = $missingRules;
                }
            }

            if (!empty($brokenGroupDeps)) {
                $output->writeln(sprintf('  <comment>[!] 发现 %d 个角色组包含不存在的孤儿权限规则 ID：</comment>', count($brokenGroupDeps)));
                foreach ($brokenGroupDeps as $gName => $mRules) {
                    $output->writeln(sprintf('      - 角色组「%s」引用了未定义的规则 ID: %s', $gName, implode(', ', $mRules)));
                }
                $output->writeln('      提示：迁移后系统将保留原始 ID 字符串，但不存在的规则将无法在菜单树中解析渲染。');
            } else {
                $output->writeln('  <info>[✔] 所有角色组关联的规则 ID 均在 auth_rule 中完整存在</info>');
            }
        } catch (Throwable $e) {
            $output->writeln('  <error>权限依赖检查失败: ' . $e->getMessage() . '</error>');
        }

        // 4. 附件物理文件抽样核验
        $output->writeln('');
        $output->writeln('<comment>[4. 附件物理文件抽样核验 (前 20 条本地附件)]</comment>');
        try {
            $attachments = $sourceDb->name('attachment')
                ->where('storage', 'local')
                ->whereOr('storage', '')
                ->whereOr('storage', null)
                ->limit(20)
                ->select()
                ->toArray();

            if (empty($attachments)) {
                $attachments = $sourceDb->name('attachment')->limit(20)->select()->toArray();
            }

            if (empty($attachments)) {
                $output->writeln('  <info>[✔] 附件表中暂无数据，无需校验物理文件</info>');
            } else {
                $publicPath = rtrim(function_exists('public_path') ? public_path() : (dirname(__DIR__, 2) . '/public/'), '/\\') . DIRECTORY_SEPARATOR;
                $sampleCount = count($attachments);
                $missingCount = 0;
                $missingSamples = [];

                foreach ($attachments as $att) {
                    $url = (string) ($att['url'] ?? '');
                    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                        continue;
                    }
                    $localFile = $publicPath . ltrim($url, '/\\');
                    if (!is_file($localFile)) {
                        $missingCount++;
                        if (count($missingSamples) < 5) {
                            $missingSamples[] = $url;
                        }
                    }
                }

                if ($missingCount > 0) {
                    $output->writeln(sprintf('  <comment>[!] 抽样 %d 条附件，发现 %d 个本地文件在 public/ 目录下缺失：</comment>', $sampleCount, $missingCount));
                    foreach ($missingSamples as $sampleUrl) {
                        $output->writeln("      - 缺失文件：{$sampleUrl}");
                    }
                    if ($missingCount > count($missingSamples)) {
                        $output->writeln('      ...');
                    }
                    $output->writeln('      建议：请确保将原 FastAdmin 项目 public/uploads 目录完整复制同步至 HwAdmin 的 public/uploads 目录。');
                } else {
                    $output->writeln(sprintf('  <info>[✔] 抽样 %d 条附件物理文件核验全部通过，对应文件均存在于 public/ 目录</info>', $sampleCount));
                }
            }
        } catch (Throwable $e) {
            $output->writeln('  <error>附件核验检查失败: ' . $e->getMessage() . '</error>');
        }

        // 5. 未映射自定义业务表扫描
        $output->writeln('');
        $output->writeln('<comment>[5. 未映射自定义业务表扫描 (除核心表外的业务表)]</comment>');
        try {
            $coreTableNames = [
                'admin',
                'auth_group',
                'auth_group_access',
                'auth_rule',
                'user_group',
                'user',
                'attachment',
                'config',
                'category',
                'ems',
                'sms',
                'version',
            ];
            $coreWithPrefix = array_map(static fn($t) => $prefix . $t, $coreTableNames);

            $allTables = [];
            $driverType = (string) ($sourceDb->getConfig('type') ?? 'mysql');

            if ($driverType === 'sqlite') {
                $rows = $sourceDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                foreach ($rows as $r) {
                    $allTables[] = (string) ($r['name'] ?? '');
                }
            } else {
                $rows = $sourceDb->query('SHOW TABLES');
                foreach ($rows as $r) {
                    $allTables[] = (string) reset($r);
                }
            }

            $customTables = [];
            foreach ($allTables as $tName) {
                if (in_array($tName, $coreWithPrefix, true)) {
                    continue;
                }
                if ($prefix === '' || str_starts_with($tName, $prefix)) {
                    $customTables[] = $tName;
                }
            }

            if (!empty($customTables)) {
                $output->writeln(sprintf('  <comment>[i] 扫描到源库存在 %d 个未自动映射的自定义业务表：</comment>', count($customTables)));
                foreach ($customTables as $ct) {
                    $output->writeln("      - {$ct}");
                }
                $output->writeln('      迁移方向指引：');
                $output->writeln('      1) 对于业务表结构与数据，可直接使用原生 mysqldump / 数据库管理工具迁移至目标库；');
                $output->writeln('      2) 在 HwAdmin 中通过 `php think hw:crud --table=<表名>` 快速生成现代化 API 控制器与前端页面；');
                $output->writeln('      3) 或编写专属插件置于 server/plugins/ 目录下实现完全解耦插拔。');
            } else {
                $output->writeln('  <info>[✔] 未发现额外的前缀自定义业务表，源库仅包含标准核心表</info>');
            }
        } catch (Throwable $e) {
            $output->writeln('  <error>业务表扫描失败: ' . $e->getMessage() . '</error>');
        }

        $output->writeln('<info>----------------------------------------------------</info>');
        $output->writeln('<info>[DRY-RUN 预检完毕] 正式迁移请移除 --dry-run 参数执行</info>');
        $output->writeln('<info>----------------------------------------------------</info>');
    }

    protected function migrateAdmins($sourceDb, $targetDb, bool $force, Output $output): void
    {
        try {
            $list = $sourceDb->name('admin')->select()->toArray();
        } catch (Throwable $e) {
            $output->writeln('<comment>[跳过] 未在源库找到 admin 表：' . $e->getMessage() . '</comment>');
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($list as $row) {
            $id = (int) $row['id'];
            $username = (string) ($row['username'] ?? '');

            $exist = $targetDb->name('admin')->where('id', $id)->whereOr('username', $username)->find();
            if ($exist && !$force) {
                $skipped++;
                continue;
            }

            $status = match ($row['status'] ?? 'normal') {
                'normal', 1, '1' => 1,
                'hidden', 0, '0' => 0,
                default => 1,
            };

            $data = [
                'id'              => $id,
                'username'        => $username,
                'nickname'        => (string) ($row['nickname'] ?? ''),
                'password'        => (string) ($row['password'] ?? ''),
                'salt'            => (string) ($row['salt'] ?? ''),
                'avatar'          => (string) ($row['avatar'] ?? ''),
                'email'           => (string) ($row['email'] ?? ''),
                'mobile'          => (string) ($row['mobile'] ?? ''),
                'login_failure'   => (int) ($row['loginfailure'] ?? $row['login_failure'] ?? 0),
                'last_login_time' => (int) ($row['logintime'] ?? $row['last_login_time'] ?? 0),
                'last_login_ip'   => (string) ($row['loginip'] ?? $row['last_login_ip'] ?? ''),
                'status'          => $status,
                'create_time'     => (int) ($row['createtime'] ?? $row['create_time'] ?? time()),
                'update_time'     => (int) ($row['updatetime'] ?? $row['update_time'] ?? time()),
            ];

            if ($exist) {
                $targetDb->name('admin')->where('id', $exist['id'])->update($data);
            } else {
                $targetDb->name('admin')->insert($data);
            }
            $imported++;
        }

        $output->writeln("  [✔] 管理员 (admin)：成功导入 {$imported} 条，跳过 {$skipped} 条");
    }

    protected function migrateAuthGroups($sourceDb, $targetDb, bool $force, Output $output): void
    {
        try {
            $list = $sourceDb->name('auth_group')->select()->toArray();
        } catch (Throwable $e) {
            $output->writeln('<comment>[跳过] 未在源库找到 auth_group 表：' . $e->getMessage() . '</comment>');
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($list as $row) {
            $id = (int) $row['id'];
            $exist = $targetDb->name('auth_group')->where('id', $id)->find();
            if ($exist && !$force) {
                $skipped++;
                continue;
            }

            $status = match ($row['status'] ?? 'normal') {
                'normal', 1, '1' => 1,
                'hidden', 0, '0' => 0,
                default => 1,
            };

            $data = [
                'id'          => $id,
                'pid'         => (int) ($row['pid'] ?? 0),
                'name'        => (string) ($row['name'] ?? ''),
                'rules'       => (string) ($row['rules'] ?? ''),
                'status'      => $status,
                'create_time' => (int) ($row['createtime'] ?? $row['create_time'] ?? time()),
                'update_time' => (int) ($row['updatetime'] ?? $row['update_time'] ?? time()),
            ];

            if ($exist) {
                $targetDb->name('auth_group')->where('id', $id)->update($data);
            } else {
                $targetDb->name('auth_group')->insert($data);
            }
            $imported++;
        }

        $output->writeln("  [✔] 角色组 (auth_group)：成功导入 {$imported} 条，跳过 {$skipped} 条");
    }

    protected function migrateAuthGroupAccess($sourceDb, $targetDb, bool $force, Output $output): void
    {
        try {
            $list = $sourceDb->name('auth_group_access')->select()->toArray();
        } catch (Throwable $e) {
            $output->writeln('<comment>[跳过] 未在源库找到 auth_group_access 表：' . $e->getMessage() . '</comment>');
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($list as $row) {
            $adminId = (int) ($row['uid'] ?? $row['admin_id'] ?? 0);
            $groupId = (int) ($row['group_id'] ?? 0);

            if ($adminId <= 0 || $groupId <= 0) {
                continue;
            }

            $exist = $targetDb->name('auth_group_access')
                ->where('admin_id', $adminId)
                ->where('group_id', $groupId)
                ->find();

            if ($exist && !$force) {
                $skipped++;
                continue;
            }

            if (!$exist) {
                $targetDb->name('auth_group_access')->insert([
                    'admin_id' => $adminId,
                    'group_id' => $groupId,
                ]);
            }
            $imported++;
        }

        $output->writeln("  [✔] 角色关联 (auth_group_access)：成功导入 {$imported} 条，跳过 {$skipped} 条");
    }

    protected function migrateAuthRules($sourceDb, $targetDb, bool $force, Output $output): void
    {
        try {
            $list = $sourceDb->name('auth_rule')->select()->toArray();
        } catch (Throwable $e) {
            $output->writeln('<comment>[跳过] 未在源库找到 auth_rule 表：' . $e->getMessage() . '</comment>');
            return;
        }

        $imported = 0;
        $skipped = 0;

        // 识别父节点集合
        $parentPids = [];
        foreach ($list as $r) {
            $pid = (int) ($r['pid'] ?? 0);
            if ($pid > 0) {
                $parentPids[$pid] = true;
            }
        }

        foreach ($list as $row) {
            $id = (int) $row['id'];
            $exist = $targetDb->name('auth_rule')->where('id', $id)->find();
            if ($exist && !$force) {
                $skipped++;
                continue;
            }

            $isMenu = (int) ($row['ismenu'] ?? 1);
            $hasChild = isset($parentPids[$id]);

            if ($isMenu === 0) {
                $type = 'button';
            } elseif ($hasChild) {
                $type = 'dir';
            } else {
                $type = 'menu';
            }

            $name = (string) ($row['name'] ?? '');
            $component = '';
            if ($type === 'menu') {
                $cleanPath = trim(str_replace('.', '/', $name), '/');
                $component = '@/views/' . $cleanPath . '/index.vue';
            }

            $status = match ($row['status'] ?? 'normal') {
                'normal', 1, '1' => 1,
                'hidden', 0, '0' => 0,
                default => 1,
            };

            $data = [
                'id'          => $id,
                'pid'         => (int) ($row['pid'] ?? 0),
                'type'        => $type,
                'name'        => $name,
                'title'       => (string) ($row['title'] ?? ''),
                'icon'        => (string) ($row['icon'] ?? ''),
                'path'        => $name,
                'component'   => $component,
                'hidden'      => $isMenu === 0 ? 1 : 0,
                'plugin'      => '',
                'weight'      => (int) ($row['weigh'] ?? $row['weight'] ?? 0),
                'status'      => $status,
                'create_time' => (int) ($row['createtime'] ?? $row['create_time'] ?? time()),
                'update_time' => (int) ($row['updatetime'] ?? $row['update_time'] ?? time()),
            ];

            if ($exist) {
                $targetDb->name('auth_rule')->where('id', $id)->update($data);
            } else {
                $targetDb->name('auth_rule')->insert($data);
            }
            $imported++;
        }

        $output->writeln("  [✔] 菜单规则 (auth_rule)：成功导入 {$imported} 条，跳过 {$skipped} 条");
    }

    protected function migrateUserGroups($sourceDb, $targetDb, bool $force, Output $output): void
    {
        try {
            $list = $sourceDb->name('user_group')->select()->toArray();
        } catch (Throwable $e) {
            $output->writeln('<comment>[跳过] 未在源库找到 user_group 表：' . $e->getMessage() . '</comment>');
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($list as $row) {
            $id = (int) $row['id'];
            $exist = $targetDb->name('user_group')->where('id', $id)->find();
            if ($exist && !$force) {
                $skipped++;
                continue;
            }

            $status = match ($row['status'] ?? 'normal') {
                'normal', 1, '1' => 1,
                'hidden', 0, '0' => 0,
                default => 1,
            };

            $data = [
                'id'          => $id,
                'name'        => (string) ($row['name'] ?? ''),
                'rules'       => (string) ($row['rules'] ?? ''),
                'status'      => $status,
                'create_time' => (int) ($row['createtime'] ?? $row['create_time'] ?? time()),
                'update_time' => (int) ($row['updatetime'] ?? $row['update_time'] ?? time()),
            ];

            if ($exist) {
                $targetDb->name('user_group')->where('id', $id)->update($data);
            } else {
                $targetDb->name('user_group')->insert($data);
            }
            $imported++;
        }

        $output->writeln("  [✔] 会员组 (user_group)：成功导入 {$imported} 条，跳过 {$skipped} 条");
    }

    protected function migrateUsers($sourceDb, $targetDb, bool $force, Output $output): void
    {
        try {
            $list = $sourceDb->name('user')->select()->toArray();
        } catch (Throwable $e) {
            $output->writeln('<comment>[跳过] 未在源库找到 user 表：' . $e->getMessage() . '</comment>');
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($list as $row) {
            $id = (int) $row['id'];
            $username = (string) ($row['username'] ?? '');

            $exist = $targetDb->name('user')->where('id', $id)->whereOr('username', $username)->find();
            if ($exist && !$force) {
                $skipped++;
                continue;
            }

            $status = match ($row['status'] ?? 'normal') {
                'normal', 1, '1' => 1,
                'hidden', 0, '0' => 0,
                default => 1,
            };

            $data = [
                'id'              => $id,
                'group_id'        => (int) ($row['group_id'] ?? 0),
                'username'        => $username,
                'nickname'        => (string) ($row['nickname'] ?? ''),
                'password'        => (string) ($row['password'] ?? ''),
                'salt'            => (string) ($row['salt'] ?? ''),
                'email'           => (string) ($row['email'] ?? ''),
                'mobile'          => (string) ($row['mobile'] ?? ''),
                'avatar'          => (string) ($row['avatar'] ?? ''),
                'gender'          => (int) ($row['gender'] ?? 0),
                'birthday'        => !empty($row['birthday']) ? (string) $row['birthday'] : null,
                'bio'             => (string) ($row['bio'] ?? ''),
                'level'           => (int) ($row['level'] ?? 0),
                'money'           => (float) ($row['money'] ?? 0),
                'score'           => (int) ($row['score'] ?? 0),
                'last_login_time' => (int) ($row['logintime'] ?? $row['last_login_time'] ?? 0),
                'last_login_ip'   => (string) ($row['loginip'] ?? $row['last_login_ip'] ?? ''),
                'join_ip'         => (string) ($row['joinip'] ?? $row['join_ip'] ?? ''),
                'status'          => $status,
                'create_time'     => (int) ($row['createtime'] ?? $row['create_time'] ?? time()),
                'update_time'     => (int) ($row['updatetime'] ?? $row['update_time'] ?? time()),
            ];

            if ($exist) {
                $targetDb->name('user')->where('id', $exist['id'])->update($data);
            } else {
                $targetDb->name('user')->insert($data);
            }
            $imported++;
        }

        $output->writeln("  [✔] 会员用户 (user)：成功导入 {$imported} 条，跳过 {$skipped} 条");
    }

    protected function migrateAttachments($sourceDb, $targetDb, bool $force, Output $output): void
    {
        try {
            $list = $sourceDb->name('attachment')->select()->toArray();
        } catch (Throwable $e) {
            $output->writeln('<comment>[跳过] 未在源库找到 attachment 表：' . $e->getMessage() . '</comment>');
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($list as $row) {
            $id = (int) $row['id'];
            $exist = $targetDb->name('attachment')->where('id', $id)->find();
            if ($exist && !$force) {
                $skipped++;
                continue;
            }

            $url = (string) ($row['url'] ?? '');
            $ext = (string) ($row['imagetype'] ?? pathinfo($url, PATHINFO_EXTENSION));
            $name = (string) ($row['filename'] ?? (basename($url) ?: 'file'));

            $data = [
                'id'          => $id,
                'admin_id'    => (int) ($row['admin_id'] ?? 0),
                'user_id'     => (int) ($row['user_id'] ?? 0),
                'storage'     => (string) ($row['storage'] ?? 'local'),
                'url'         => $url,
                'name'        => $name,
                'mime'        => (string) ($row['mimetype'] ?? ''),
                'ext'         => $ext,
                'size'        => (int) ($row['filesize'] ?? 0),
                'width'       => (int) ($row['imagewidth'] ?? 0),
                'height'      => (int) ($row['imageheight'] ?? 0),
                'sha1'        => (string) ($row['sha1'] ?? ''),
                'create_time' => (int) ($row['createtime'] ?? $row['create_time'] ?? time()),
            ];

            if ($exist) {
                $targetDb->name('attachment')->where('id', $id)->update($data);
            } else {
                $targetDb->name('attachment')->insert($data);
            }
            $imported++;
        }

        $output->writeln("  [✔] 附件数据 (attachment)：成功导入 {$imported} 条，跳过 {$skipped} 条");
    }
}
