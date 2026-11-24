<?php
declare(strict_types=1);

namespace core\console;

use core\plugin\MenuSync;
use core\support\CrudScaffoldBuilder;
use core\support\ProjectSchemaService;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\TablePrefixAdapter;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use Throwable;

/**
 * 项目应用描述、需求建模、多端契约与模板管理命令行工具
 *
 * 用法：
 *   php think hw:project validate --json
 *   php think hw:project status --json
 *   php think hw:project export-types --json
 *   php think hw:project external-check --json
 *   php think hw:project apply-template corporate-site [--dry-run] [--force] [--with-migrate] [--json]
 *   php think hw:project rollback-template corporate-site [--force] [--json]
 */
class ProjectCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:project')
            ->addArgument('action', Argument::REQUIRED, '执行动作：validate|status|export-types|external-check|apply-template|rollback-template')
            ->addArgument('target', Argument::OPTIONAL, '目标参数（如模板标识名称）', '')
            ->addOption('json', 'j', Option::VALUE_NONE, '以纯 JSON 格式输出，便于 AI Agent 消费')
            ->addOption('dry-run', null, Option::VALUE_NONE, '试运行（不写文件不改库）')
            ->addOption('force', 'f', Option::VALUE_NONE, '强制覆盖已存在且被修改的目标文件（自动保留手写保护区）')
            ->addOption('with-migrate', null, Option::VALUE_NONE, '应用模板时立即执行数据库迁移与菜单同步')
            ->setDescription('管理 hw.project.json 应用描述文件、需求建模验证、全端类型导出与业务模板安装');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = trim((string) $input->getArgument('action'));
        $target = trim((string) $input->getArgument('target'));
        $asJson = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $withMigrate = (bool) $input->getOption('with-migrate');

        $service = new ProjectSchemaService($this->app);

        switch ($action) {
            case 'validate':
                $res = $service->validate();
                if ($asJson) {
                    $output->writeln((string) json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                } else {
                    if ($res['valid']) {
                        $output->info('[✔] hw.project.json 描述结构校验通过！');
                    } else {
                        $output->error('[✘] hw.project.json 校验未通过：');
                        foreach ($res['errors'] as $err) {
                            $output->writeln("  - {$err}");
                        }
                    }
                    if (!empty($res['warnings'])) {
                        foreach ($res['warnings'] as $w) {
                            $output->warning("  [!] {$w}");
                        }
                    }
                }
                return $res['valid'] ? 0 : 1;

            case 'status':
                $status = $service->getStatus();
                if ($asJson) {
                    $output->writeln((string) json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                } else {
                    $output->info("=== 项目状态: {$status['app_name']} ({$status['app_id']} v{$status['app_version']}) ===");
                    $output->writeln("框架版本: {$status['framework_version']}");
                    $output->writeln("启用客户端: " . implode(', ', $status['clients']));
                    $output->writeln("模块数量: {$status['modules_count']} | 实体数量: {$status['entities_count']}");
                    foreach ($status['entities'] as $entKey => $ent) {
                        $output->writeln(sprintf('  - %-20s 表: %-20s 字段数: %d', $ent['title'], $ent['table'], $ent['field_count']));
                    }
                }
                return 0;

            case 'export-types':
                $res = $service->exportSharedTypes();
                if ($asJson) {
                    $output->writeln((string) json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                } else {
                    $output->info('[✔] 全端共享 TypeScript 类型契约导出成功！');
                    foreach ($res['exported_files'] as $f) {
                        $output->writeln("  - {$f}");
                    }
                }
                return 0;

            case 'external-check':
                $res = $service->checkExternal();
                if ($asJson) {
                    $output->writeln((string) json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                } else {
                    $output->info("=== 外部依赖与凭据配置诊断 (共 {$res['total_items']} 项) ===");
                    foreach ($res['requirements'] as $req) {
                        $stateStr = $req['configured'] ? "[已配置: {$req['value_mask']}]" : ($req['required'] ? '[缺失必需项]' : '[可选未配置]');
                        $output->writeln(sprintf('  - %-24s %-16s %s', $req['title'], $req['env_mapping'], $stateStr));
                    }
                    if (!$res['all_required_met']) {
                        $output->error('存在未配置的必需外部凭据！');
                    }
                }
                return $res['all_required_met'] ? 0 : 1;

            case 'apply-template':
                return $this->executeApplyTemplate($target, $dryRun, $asJson, $output, $service, $force, $withMigrate);

            case 'rollback-template':
                return $this->executeRollbackTemplate($target, $force, $asJson, $output, $service);

            default:
                $err = "未知动作 [{$action}]。可选动作: validate, status, export-types, external-check, apply-template, rollback-template";
                if ($asJson) {
                    $output->writeln((string) json_encode(['error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                } else {
                    $output->error($err);
                }
                return 1;
        }
    }

    /**
     * 执行业务起步模板安装（含源文件严格预检、冲突保护、迁移复制、描述合并、菜单同步与状态清单记录）
     */
    public function executeApplyTemplate(
        string $templateName,
        bool $dryRun = false,
        bool $asJson = false,
        ?Output $output = null,
        ?ProjectSchemaService $service = null,
        bool $force = false,
        bool $withMigrate = false
    ): int {
        $output ??= new Output('buffer');
        $service ??= new ProjectSchemaService($this->app);

        if ($templateName === '') {
            $err = '请指定要应用的模板名称，可选：corporate-site, booking-system, enterprise-admin';
            if ($asJson) {
                $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }

        $effectiveRoot = $this->resolveEffectiveProjectRoot($service);
        $templateDir = $effectiveRoot . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $templateName;
        $manifestFile = $templateDir . DIRECTORY_SEPARATOR . 'template.json';
        if (!is_file($manifestFile)) {
            $err = "模板 [{$templateName}] 不存在或缺少 template.json (路径: {$templateDir})";
            if ($asJson) {
                $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            $err = "模板 [{$templateName}] 的 template.json 格式非法";
            if ($asJson) {
                $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }

        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $migrations = is_array($manifest['migrations'] ?? null) ? $manifest['migrations'] : [];
        $menus = is_array($manifest['menus'] ?? null) ? $manifest['menus'] : [];
        $entities = is_array($manifest['entities'] ?? null) ? $manifest['entities'] : [];
        $entitiesSchema = is_array($manifest['entities_schema'] ?? null) ? $manifest['entities_schema'] : [];

        if (empty($files)) {
            $err = "模板 [{$templateName}] 的 files 清单不能为空";
            if ($asJson) {
                $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }

        // 1. 严格预检所有 files 与 migrations 源文件是否存在
        $missingSources = [];
        $resolvedCopyPlan = [];

        foreach ($files as $idx => $f) {
            $srcRel = trim((string) ($f['source'] ?? ''));
            $dstRel = trim((string) ($f['target'] ?? ''));
            if ($srcRel === '' || $dstRel === '') {
                $missingSources[] = "files[{$idx}] 缺少 source 或 target 定义";
                continue;
            }
            $srcPath = $templateDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $srcRel);
            if (!is_file($srcPath)) {
                $missingSources[] = $srcRel;
                continue;
            }
            $dstPath = $this->resolveTargetFilePath($effectiveRoot, $dstRel);
            $resolvedCopyPlan[] = [
                'kind'   => 'file',
                'source' => $srcRel,
                'target' => $dstRel,
                'src'    => $srcPath,
                'dst'    => $dstPath,
            ];
        }

        $resolvedMigrationsPlan = [];
        foreach ($migrations as $idx => $migEntry) {
            $migName = is_array($migEntry)
                ? trim((string) ($migEntry['source'] ?? ''))
                : trim((string) $migEntry);
            if ($migName === '') {
                $missingSources[] = "migrations[{$idx}] 为空";
                continue;
            }
            $candidateA = $templateDir . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . basename($migName);
            $candidateB = $templateDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $migName);
            $migSrc = is_file($candidateA) ? $candidateA : (is_file($candidateB) ? $candidateB : '');
            if ($migSrc === '') {
                $missingSources[] = "migrations/{$migName}";
                continue;
            }
            $migTargetRel = is_array($migEntry) && !empty($migEntry['target'])
                ? trim((string) $migEntry['target'])
                : ('server/database/migrations/' . basename($migSrc));
            $migDst = $this->resolveTargetFilePath($effectiveRoot, $migTargetRel);
            $resolvedMigrationsPlan[] = [
                'kind'   => 'migration',
                'name'   => basename($migSrc),
                'source' => 'migrations/' . basename($migSrc),
                'target' => $migTargetRel,
                'src'    => $migSrc,
                'dst'    => $migDst,
            ];
        }

        if (!empty($missingSources)) {
            $err = "模板 [{$templateName}] 源文件预检失败，缺失以下源文件: " . implode(', ', $missingSources);
            if ($asJson) {
                $output->writeln((string) json_encode([
                    'status'          => 'error',
                    'error'           => $err,
                    'missing_sources' => $missingSources,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->error($err);
            }
            return 1;
        }

        // 2. 冲突保护预检（若目标文件已存在且内容与模板不一致，非 --force 拒绝覆盖）
        $conflicts = [];
        foreach (array_merge($resolvedCopyPlan, $resolvedMigrationsPlan) as $item) {
            if (is_file($item['dst'])) {
                $srcHash = (string) hash_file('sha256', $item['src']);
                $dstHash = (string) hash_file('sha256', $item['dst']);
                if ($srcHash !== $dstHash) {
                    $conflicts[] = $item['target'];
                }
            }
        }

        $plan = [
            'status'          => (empty($conflicts) || $force) ? 'ready' : 'conflict',
            'template'        => $templateName,
            'title'           => $manifest['title'] ?? $templateName,
            'version'         => $manifest['version'] ?? '1.0.0',
            'dry_run'         => $dryRun,
            'files_to_copy'   => $files,
            'migrations'      => array_map(static fn (array $m) => $m['target'], $resolvedMigrationsPlan),
            'menus'           => $menus,
            'entities'        => $entities,
            'conflicts'       => $conflicts,
            'applied'         => !$dryRun && (empty($conflicts) || $force),
        ];

        if ($dryRun) {
            if ($asJson) {
                $output->writeln((string) json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->info("=== [Dry Run] 模板 [{$templateName}] 安装计划 ===");
                $output->writeln("拟写入业务文件数量: " . count($resolvedCopyPlan));
                $output->writeln("拟写入迁移文件数量: " . count($resolvedMigrationsPlan));
                foreach ($resolvedCopyPlan as $f) {
                    $output->writeln("  - {$f['target']}");
                }
                foreach ($resolvedMigrationsPlan as $m) {
                    $output->writeln("  - [migration] {$m['target']}");
                }
                if (!empty($conflicts)) {
                    $output->warning("检测到文件冲突: " . implode(', ', $conflicts));
                }
            }
            return 0;
        }

        if (!empty($conflicts) && !$force) {
            $err = "目标文件已存在且内容与模板不一致（用户已修改），拒绝覆盖: " . implode(', ', $conflicts) . "（如需强制覆盖请追加 --force）";
            if ($asJson) {
                $output->writeln((string) json_encode([
                    'status'    => 'conflict',
                    'error'     => $err,
                    'conflicts' => $conflicts,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->error($err);
            }
            return 1;
        }

        // 3. 执行复制（含业务文件与 migrations 迁移脚本，--force 更新时保留 @hw-custom 保护区）
        $scaffold = new CrudScaffoldBuilder();
        $appliedFiles = [];
        $appliedMigrations = [];
        $appliedFileHashes = [];
        $modifiedFileBackups = [];

        try {
            foreach (array_merge($resolvedCopyPlan, $resolvedMigrationsPlan) as $item) {
                $srcContent = (string) file_get_contents($item['src']);
                $dstPath = $item['dst'];
                $dir = dirname($dstPath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }

                $finalContent = $srcContent;
                if (is_file($dstPath)) {
                    $existingContent = (string) file_get_contents($dstPath);
                    $modifiedFileBackups[$item['target']] = [
                        'path'             => $dstPath,
                        'previous_content' => $existingContent,
                        'previous_sha256'  => hash('sha256', $existingContent),
                    ];
                    $customSections = $scaffold->extractCustomSections($existingContent);
                    if (!empty($customSections)) {
                        $finalContent = $scaffold->mergeCustomSections($srcContent, $customSections);
                    }
                }

                file_put_contents($dstPath, $finalContent);
                $appliedFiles[] = $item['target'];
                $appliedFileHashes[$item['target']] = [
                    'path'   => $dstPath,
                    'sha256' => hash('sha256', $finalContent),
                ];

                if ($item['kind'] === 'migration') {
                    $appliedMigrations[] = $item['target'];
                }
            }

            // 4. 合并模板实体与模块定义到 hw.project.json
            $projectJsonPath = $effectiveRoot . DIRECTORY_SEPARATOR . 'hw.project.json';
            $previousProjectJson = null;
            $mergedEntities = [];
            $mergedModules = [];
            $templateModules = is_array($manifest['modules'] ?? null) ? $manifest['modules'] : [];
            if (empty($templateModules)) {
                $templateModules = [[
                    'id'          => $templateName,
                    'title'       => (string) ($manifest['title'] ?? $templateName),
                    'description' => (string) ($manifest['description'] ?? ''),
                ]];
            }
            if (is_file($projectJsonPath) && (!empty($entitiesSchema) || !empty($templateModules))) {
                $previousProjectJson = (string) file_get_contents($projectJsonPath);
                $projectData = json_decode($previousProjectJson, true);
                if (is_array($projectData)) {
                    if (!isset($projectData['entities']) || !is_array($projectData['entities'])) {
                        $projectData['entities'] = [];
                    }
                    foreach ($entitiesSchema as $entKey => $entDef) {
                        if (!isset($projectData['entities'][$entKey]) || $force) {
                            $projectData['entities'][$entKey] = $entDef;
                            $mergedEntities[] = (string) $entKey;
                        }
                    }

                    if (!isset($projectData['modules']) || !is_array($projectData['modules'])) {
                        $projectData['modules'] = [];
                    }
                    $moduleIndexById = [];
                    foreach ($projectData['modules'] as $mIdx => $existingMod) {
                        if (is_array($existingMod) && !empty($existingMod['id'])) {
                            $moduleIndexById[(string) $existingMod['id']] = $mIdx;
                        }
                    }
                    foreach ($templateModules as $modDef) {
                        if (!is_array($modDef) || empty($modDef['id'])) {
                            continue;
                        }
                        $modId = (string) $modDef['id'];
                        if (!isset($moduleIndexById[$modId])) {
                            $projectData['modules'][] = $modDef;
                            $moduleIndexById[$modId] = count($projectData['modules']) - 1;
                            $mergedModules[] = $modId;
                        } elseif ($force) {
                            $projectData['modules'][$moduleIndexById[$modId]] = $modDef;
                            $mergedModules[] = $modId;
                        }
                    }

                    file_put_contents(
                        $projectJsonPath,
                        json_encode($projectData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
                    );
                }
            }

            // 5. 若指定 --with-migrate，立即执行模板迁移建表
            $executedMigrations = [];
            if ($withMigrate) {
                foreach ($resolvedMigrationsPlan as $migItem) {
                    $this->executeSingleMigrationFile($migItem['dst']);
                    $executedMigrations[] = $migItem['name'];
                }
            }

            // 6. 同步菜单节点到 auth_rule（若数据库已连接且存在 auth_rule 表）
            $syncedMenus = [];
            if (!empty($menus)) {
                try {
                    foreach ($menus as $menuNode) {
                        if (is_array($menuNode) && !empty($menuNode['name'])) {
                            MenuSync::ensureNode($menuNode);
                            $this->collectMenuNodeNames($menuNode, $syncedMenus);
                        }
                    }
                } catch (Throwable) {
                    // 若数据库尚未初始化，迁移脚本中的 MenuSync 将在后续 migrate:run 时完成同步
                }
            }

            // 7. 记录模板安装清单到 .hw-state/template-manifests/
            $stateDir = $effectiveRoot . DIRECTORY_SEPARATOR . '.hw-state' . DIRECTORY_SEPARATOR . 'template-manifests';
            if (!is_dir($stateDir)) {
                @mkdir($stateDir, 0755, true);
            }
            $manifestRecord = [
                'applied_at'            => time(),
                'template'              => $templateName,
                'version'               => $manifest['version'] ?? '1.0.0',
                'files'                 => $appliedFiles,
                'applied_files'         => $appliedFiles,
                'applied_migrations'    => $appliedMigrations,
                'executed_migrations'   => $executedMigrations,
                'applied_file_hashes'   => $appliedFileHashes,
                'modified_file_backups' => $modifiedFileBackups,
                'synced_menus'          => array_values(array_unique($syncedMenus)),
                'merged_entities'       => $mergedEntities,
                'merged_modules'        => $mergedModules,
                'previous_project_json' => $previousProjectJson,
            ];
            $manifestSavePath = $stateDir . DIRECTORY_SEPARATOR . "{$templateName}.json";
            file_put_contents($manifestSavePath, json_encode($manifestRecord, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $service->saveState("template-{$templateName}", $manifestRecord);

            $resultPayload = [
                'status'              => 'success',
                'template'            => $templateName,
                'applied_files'       => $appliedFiles,
                'applied_migrations'  => $appliedMigrations,
                'executed_migrations' => $executedMigrations,
                'synced_menus'        => $manifestRecord['synced_menus'],
                'merged_entities'     => $mergedEntities,
                'merged_modules'      => $mergedModules,
                'manifest'            => $manifestSavePath,
            ];

            if ($asJson) {
                $output->writeln((string) json_encode($resultPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->info("[✔] 模板 [{$templateName}] 应用完成！共复制 " . count($appliedFiles) . " 个文件（含 " . count($appliedMigrations) . " 个迁移脚本）。");
            }
            return 0;
        } catch (Throwable $e) {
            $err = "应用模板失败: " . $e->getMessage();
            if ($asJson) {
                $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }
    }

    /**
     * 安全卸载/回滚已安装的业务模板
     */
    public function executeRollbackTemplate(
        string $templateName,
        bool $force = false,
        bool $asJson = false,
        ?Output $output = null,
        ?ProjectSchemaService $service = null
    ): int {
        $output ??= new Output('buffer');
        $service ??= new ProjectSchemaService($this->app);

        if (trim($templateName) === '') {
            $err = '请指定要回滚的模板名称';
            if ($asJson) {
                $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }

        $effectiveRoot = $this->resolveEffectiveProjectRoot($service);
        $manifestPath = $effectiveRoot . DIRECTORY_SEPARATOR . '.hw-state' . DIRECTORY_SEPARATOR . 'template-manifests' . DIRECTORY_SEPARATOR . "{$templateName}.json";
        if (!is_file($manifestPath)) {
            $err = "未找到模板 [{$templateName}] 的安装清单: {$manifestPath}";
            if ($asJson) {
                $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }

        $record = json_decode((string) file_get_contents($manifestPath), true) ?: [];
        $fileHashes = is_array($record['applied_file_hashes'] ?? null) ? $record['applied_file_hashes'] : [];
        $backups = is_array($record['modified_file_backups'] ?? null) ? $record['modified_file_backups'] : [];

        if (!$force) {
            $conflicts = [];
            foreach ($fileHashes as $rel => $info) {
                $path = (string) ($info['path'] ?? '');
                $expected = (string) ($info['sha256'] ?? '');
                if ($path !== '' && is_file($path) && $expected !== '' && hash_file('sha256', $path) !== $expected) {
                    $conflicts[] = $rel;
                }
            }
            if (!empty($conflicts)) {
                $err = '模板文件已被二次修改，拒绝直接删除: ' . implode(', ', $conflicts);
                if ($asJson) {
                    $output->writeln((string) json_encode(['status' => 'conflict', 'error' => $err, 'conflicts' => $conflicts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } else {
                    $output->error($err);
                }
                return 1;
            }
        }

        $deleted = [];
        $restored = [];
        foreach ($fileHashes as $rel => $info) {
            $path = (string) ($info['path'] ?? '');
            if (isset($backups[$rel]['previous_content'])) {
                file_put_contents($path, (string) $backups[$rel]['previous_content']);
                $restored[] = $rel;
            } elseif ($path !== '' && is_file($path)) {
                @unlink($path);
                $deleted[] = $rel;
            }
        }

        if (isset($record['previous_project_json']) && is_string($record['previous_project_json'])) {
            $projectJsonPath = $effectiveRoot . DIRECTORY_SEPARATOR . 'hw.project.json';
            file_put_contents($projectJsonPath, $record['previous_project_json']);
        }

        if (!empty($record['synced_menus']) && is_array($record['synced_menus'])) {
            try {
                MenuSync::removeNodes($record['synced_menus']);
            } catch (Throwable) {
            }
        }

        @unlink($manifestPath);
        @unlink($effectiveRoot . DIRECTORY_SEPARATOR . '.hw-state' . DIRECTORY_SEPARATOR . "template-{$templateName}.json");

        if ($asJson) {
            $output->writeln((string) json_encode([
                'status'         => 'success',
                'template'       => $templateName,
                'deleted_files'  => $deleted,
                'restored_files' => $restored,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $output->info("[✔] 模板 [{$templateName}] 已安全回滚。");
        }
        return 0;
    }

    /**
     * 执行单个 Phinx 迁移文件的 up() 方法（基于当前 ThinkORM 数据库连接）
     */
    public function executeSingleMigrationFile(string $migrationFile): void
    {
        if (!is_file($migrationFile)) {
            throw new \RuntimeException("迁移文件不存在: {$migrationFile}");
        }

        $beforeClasses = get_declared_classes();
        require_once $migrationFile;
        $afterClasses = get_declared_classes();
        $newClasses = array_diff($afterClasses, $beforeClasses);

        $migClass = null;
        foreach ($newClasses as $cls) {
            if (is_subclass_of($cls, \Phinx\Migration\AbstractMigration::class)) {
                $migClass = $cls;
                break;
            }
        }
        if ($migClass === null) {
            // 如果此前已 require 过，根据文件名推导类名
            $base = basename($migrationFile, '.php');
            $parts = explode('_', $base);
            array_shift($parts); // 移除时间戳前缀
            $guessed = implode('', array_map('ucfirst', $parts));
            if (class_exists($guessed)) {
                $migClass = $guessed;
            }
        }
        if ($migClass === null) {
            throw new \RuntimeException("无法解析迁移类名: {$migrationFile}");
        }

        $adapter = $this->createPhinxAdapterFromCurrentDb();
        /** @var \Phinx\Migration\AbstractMigration $migration */
        $migration = new $migClass('default', (int) substr(basename($migrationFile), 0, 14));
        $migration->setAdapter($adapter);
        if (method_exists($migration, 'up')) {
            $migration->up();
        } elseif (method_exists($migration, 'change')) {
            $migration->change();
        }
    }

    private function createPhinxAdapterFromCurrentDb(): \Phinx\Db\Adapter\AdapterInterface
    {
        $conn = Db::connect();
        $pdo = $conn->getPdo();
        if (!$pdo instanceof \PDO) {
            $pdo = $conn->connect();
        }
        $config = $conn->getConfig();
        $type = strtolower((string) ($config['type'] ?? 'sqlite'));
        $prefix = (string) ($config['prefix'] ?? 'hw_');

        if ($type === 'sqlite') {
            $dbFile = (string) ($config['database'] ?? '');
            $options = [
                'adapter'      => 'sqlite',
                'name'         => $dbFile,
                'table_prefix' => $prefix,
                'connection'   => $pdo,
            ];
        } else {
            $adapterType = ($type === 'pgsql' || $type === 'postgres') ? 'pgsql' : 'mysql';
            $options = [
                'adapter'      => $adapterType,
                'host'         => $config['hostname'] ?? '127.0.0.1',
                'name'         => $config['database'] ?? '',
                'user'         => $config['username'] ?? '',
                'pass'         => $config['password'] ?? '',
                'port'         => $config['hostport'] ?? 3306,
                'charset'      => $config['charset'] ?? 'utf8mb4',
                'table_prefix' => $prefix,
                'connection'   => $pdo,
            ];
        }

        $rawAdapter = AdapterFactory::instance()->getAdapter($options['adapter'], $options);
        return new TablePrefixAdapter($rawAdapter);
    }

    private function resolveEffectiveProjectRoot(ProjectSchemaService $service): string
    {
        $root = rtrim($service->getProjectRoot(), '/\\');
        if (is_dir($root . DIRECTORY_SEPARATOR . 'templates')) {
            return $root;
        }
        if ($this->app) {
            $appRoot = rtrim($this->app->getRootPath(), '/\\');
            if (is_dir($appRoot . DIRECTORY_SEPARATOR . 'templates')) {
                return $appRoot;
            }
        }
        return $root;
    }

    private function resolveTargetFilePath(string $effectiveRoot, string $targetRel): string
    {
        $normalized = ltrim(str_replace('\\', '/', $targetRel), '/');

        // 兼容发布包布局（根目录即后端根目录，前端在 sources/admin-ui/）
        if (str_starts_with($normalized, 'server/') && !is_dir($effectiveRoot . DIRECTORY_SEPARATOR . 'server') && is_dir($effectiveRoot . DIRECTORY_SEPARATOR . 'app')) {
            $normalized = substr($normalized, strlen('server/'));
        } elseif (str_starts_with($normalized, 'admin-ui/') && !is_dir($effectiveRoot . DIRECTORY_SEPARATOR . 'admin-ui') && is_dir($effectiveRoot . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR . 'admin-ui')) {
            $normalized = 'sources/' . $normalized;
        }

        return $effectiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }

    private function collectMenuNodeNames(array $node, array &$names): void
    {
        if (!empty($node['name'])) {
            $names[] = (string) $node['name'];
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectMenuNodeNames($child, $names);
            }
        }
    }
}
