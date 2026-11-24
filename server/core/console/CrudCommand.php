<?php
declare(strict_types=1);

namespace core\console;

use core\plugin\MenuSync;
use core\support\CrudScaffoldBuilder;
use core\support\ProjectSchemaService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use Throwable;

/**
 * HwAdmin 敏捷 CRUD 一键代码生成器（正向建模/反向工程/生成计划/增量保护/全栈生成）
 *
 * 用法：
 *   php think hw:crud --table=article                  # 反向工程模式
 *   php think hw:crud --from-project=demo_item --all   # 正向建模模式 (读取 hw.project.json)
 *   php think hw:crud --table=article --plan --json    # 生成计划预览 (dry-run，不写文件不改库)
 *   php think hw:crud --table=article --all --incremental # 增量保护模式 (保留手写扩展区)
 *   php think hw:crud --rollback-manifest=manifest.json # 依据清单安全撤销生成
 */
class CrudCommand extends Command
{
    protected string $customRootPath = '';

    public function setCustomRootPath(string $root): void
    {
        $this->customRootPath = $root !== '' ? (rtrim($root, '/\\') . DIRECTORY_SEPARATOR) : '';
    }

    protected function resolveRootPath(): string
    {
        if ($this->customRootPath !== '') {
            return $this->customRootPath;
        }
        return rtrim(($this->app ? $this->app->getRootPath() : '') ?: root_path(), '/\\') . DIRECTORY_SEPARATOR;
    }

    protected function configure(): void
    {
        $this->setName('hw:crud')
            ->addOption('table', 't', Option::VALUE_OPTIONAL, '数据表名（不含表前缀）', '')
            ->addOption('from-project', null, Option::VALUE_OPTIONAL, '从 hw.project.json 声明式实体正向生成', '')
            ->addOption('fields', null, Option::VALUE_OPTIONAL, '轻量内联字段定义（无需先建表，如 nickname:string(64),contact:?string(100),content:text?）', '')
            ->addOption('plugin', 'p', Option::VALUE_OPTIONAL, '目标插件标识（留空则生成到主应用 app/）', '')
            ->addOption('controller', 'c', Option::VALUE_OPTIONAL, '自定义控制器相对类名（如 content/Article）', '')
            ->addOption('title', null, Option::VALUE_OPTIONAL, '模块菜单标题（默认自动推断）', '')
            ->addOption('with-ui', null, Option::VALUE_NONE, '同时生成前端 API TS 与 Vue 3 强类型管理页面')
            ->addOption('with-menu', null, Option::VALUE_NONE, '自动幂等同步菜单与权限按钮到 auth_rule（插件同步到 plugin.json）')
            ->addOption('with-migration', null, Option::VALUE_NONE, '同时生成 Phinx 数据表与菜单同步迁移文件')
            ->addOption('with-test', null, Option::VALUE_NONE, '同时生成 PHPUnit CRUD 特征测试骨架')
            ->addOption('with-api', null, Option::VALUE_NONE, '同时生成仅返回白名单安全字段的公开 ApiController 与响应式前台骨架页')
            ->addOption('with-mobile', null, Option::VALUE_NONE, '同时生成移动端 API 封装与 uni-app + Wot UI V2 页面骨架（admin-ui/mobile/）')
            ->addOption('public-fields', null, Option::VALUE_OPTIONAL, '配合 --with-api 指定前台公开返回字段白名单（逗号分隔，可放行只展不收列）', '')
            ->addOption('submit-fields', null, Option::VALUE_OPTIONAL, '配合 --with-api 指定前台访客提交字段白名单（逗号分隔，可放行只收不展列）', '')
            ->addOption('with-nav', null, Option::VALUE_NONE, '配合 --with-api 将生成的前台页面链接挂载到默认门户首页（/）')
            ->addOption('theme', null, Option::VALUE_OPTIONAL, '前台骨架页默认主题（light|dark）', 'light')
            ->addOption('all', 'a', Option::VALUE_NONE, '全栈模式（等同于 --with-ui --with-menu --with-migration，并在存在 tests/bootstrap.php 时附带 --with-test）')
            ->addOption('force', 'f', Option::VALUE_NONE, '覆盖已存在的目标文件')
            ->addOption('plan', null, Option::VALUE_NONE, '生成计划预览（dry-run，不写文件不改库）')
            ->addOption('dry-run', null, Option::VALUE_NONE, '同 --plan')
            ->addOption('incremental', null, Option::VALUE_NONE, '增量更新模式，保留手写保护区代码')
            ->addOption('rollback-manifest', null, Option::VALUE_OPTIONAL, '依据变更清单撤销本次生成的文件', '')
            ->addOption('json', 'j', Option::VALUE_NONE, '以纯 JSON 格式输出结果')
            ->setDescription('根据数据表、内联 --fields 或 hw.project.json 实体一键生成 HwAdmin 全栈 CRUD 骨架');
    }

    protected function execute(Input $input, Output $output): int
    {
        $rollbackManifest = trim((string) $input->getOption('rollback-manifest'));
        $asJson = (bool) $input->getOption('json');
        $force = (bool) $input->getOption('force');

        if ($rollbackManifest !== '') {
            return $this->handleRollback($rollbackManifest, $force, $asJson, $output);
        }

        $table = trim((string) $input->getOption('table'));
        $fromProject = trim((string) $input->getOption('from-project'));
        $inlineFieldsSpec = trim((string) $input->getOption('fields'));
        $plugin = trim((string) $input->getOption('plugin'));
        $customCtrl = trim((string) $input->getOption('controller'));
        $customTitle = trim((string) $input->getOption('title'));
        $all = (bool) $input->getOption('all');
        $withUi = $all || (bool) $input->getOption('with-ui');
        $withMenu = $all || (bool) $input->getOption('with-menu');
        $withMigration = $all || (bool) $input->getOption('with-migration');
        $explicitWithTest = (bool) $input->getOption('with-test');
        $appRootPath = $this->resolveRootPath();
        $hasTestRunner = is_file($appRootPath . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php')
            && is_file($appRootPath . 'phpunit.xml');
        $withTest = $explicitWithTest || ($all && $hasTestRunner);
        $withApi = (bool) $input->getOption('with-api');
        $withMobile = (bool) $input->getOption('with-mobile');
        $publicFieldsOpt = trim((string) $input->getOption('public-fields'));
        $submitFieldsOpt = trim((string) $input->getOption('submit-fields'));
        $withNav = (bool) $input->getOption('with-nav');
        $theme = strtolower(trim((string) $input->getOption('theme'))) === 'dark' ? 'dark' : 'light';
        $plan = (bool) $input->getOption('plan') || (bool) $input->getOption('dry-run');
        $incremental = (bool) $input->getOption('incremental');

        $scaffold = new CrudScaffoldBuilder();
        $schemaService = new ProjectSchemaService($this->app);

        $fields = [];
        $fieldDetails = [];
        $pk = 'id';
        $entityTitle = '';
        $isTakeover = false;
        $entityDecl = null;

        try {
            $prefix = (string) (Db::connect()->getConfig('prefix') ?: 'hw_');
        } catch (Throwable) {
            $prefix = 'hw_';
        }

        if ($fromProject !== '') {
            // 正向建模模式：从 hw.project.json 读取
            $projectData = $schemaService->load();
            $entities = $projectData['entities'] ?? [];
            if (!isset($entities[$fromProject])) {
                $err = "hw.project.json 中未找到实体 [{$fromProject}]";
                if ($asJson) {
                    $output->writeln((string) json_encode(['error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } else {
                    $output->error($err);
                }
                return 1;
            }

            $entityDecl = $entities[$fromProject];
            $table = $entityDecl['table'] ?? $fromProject;
            $entityTitle = $entityDecl['title'] ?? $fromProject;
            $rawFields = is_array($entityDecl['fields'] ?? null) ? $entityDecl['fields'] : [];

            try {
                $pk = $scaffold->validateEntityDefinition((string) $fromProject, $entityDecl['primary_key'] ?? 'id', $rawFields);
            } catch (Throwable $e) {
                $err = $e->getMessage();
                if ($asJson) {
                    $output->writeln((string) json_encode(['error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } else {
                    $output->error($err);
                }
                return 1;
            }

            foreach ($rawFields as $f) {
                $fName = $f['name'];
                $fields[] = $fName;
                $fieldDetails[$fName] = [
                    'name'      => $fName,
                    'type'      => $f['type'] ?? 'string',
                    'length'    => $f['length'] ?? null,
                    'notnull'   => empty($f['nullable']),
                    'default'   => $f['default'] ?? null,
                    'comment'   => $f['title'] ?? '',
                    'precision' => $f['precision'] ?? null,
                    'scale'     => $f['scale'] ?? null,
                    'primary'   => !empty($f['primary']) || $fName === $pk,
                ];
            }
            if (!in_array($pk, $fields, true)) {
                array_unshift($fields, $pk);
                $fieldDetails[$pk] = [
                    'name'    => $pk,
                    'type'    => $entityDecl['primary_key_type'] ?? ($pk !== 'id' ? 'string' : 'integer'),
                    'length'  => 64,
                    'notnull' => true,
                    'default' => null,
                    'comment' => '主键',
                    'primary' => true,
                ];
            }

            // 检查数据库中是否已存在该表以区分新建 vs 接管
            try {
                $dbTables = Db::connect()->getTables();
                $fullTableName = $prefix . $table;
                if (in_array($fullTableName, $dbTables, true) || in_array($table, $dbTables, true)) {
                    $isTakeover = true;
                }
            } catch (Throwable) {
                $isTakeover = false;
            }
        } else {
            // 反向工程模式：从已有数据表读取（若数据表尚未创建，自动回退到 hw.project.json 实体建模）
            if ($table === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $table)) {
                $err = '请通过 --table 指定数据表名，或通过 --from-project 指定声明式实体';
                if ($asJson) {
                    $output->writeln((string) json_encode(['error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } else {
                    $output->error($err);
                }
                return 1;
            }

            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                $table = substr($table, strlen($prefix));
            }

            if ($inlineFieldsSpec !== '') {
                try {
                    $parsedInline = $scaffold->parseInlineFields($inlineFieldsSpec, 'id', true);
                } catch (Throwable $e) {
                    $err = $e->getMessage();
                    if ($asJson) {
                        $output->writeln((string) json_encode(['error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    } else {
                        $output->error($err);
                    }
                    return 1;
                }
                $pk = $parsedInline['pk'];
                $fields = $parsedInline['fields'];
                $fieldDetails = $parsedInline['field_details'];
                try {
                    $dbTables = Db::connect()->getTables();
                    $fullTableName = $prefix . $table;
                    if (in_array($fullTableName, $dbTables, true) || in_array($table, $dbTables, true)) {
                        $isTakeover = true;
                    }
                } catch (Throwable) {
                    $isTakeover = false;
                }
            } else {
                $tableFoundInDb = false;
                try {
                    $fields = Db::name($table)->getTableFields();
                    if (!empty($fields)) {
                        $tableFoundInDb = true;
                    }
                } catch (Throwable) {
                    $tableFoundInDb = false;
                }

                if (!$tableFoundInDb) {
                // 自动回退探测 hw.project.json 中匹配的实体
                $projectData = $schemaService->load();
                $matchedEntityKey = null;
                foreach ($projectData['entities'] ?? [] as $k => $ent) {
                    if ($k === $table || ($ent['table'] ?? '') === $table) {
                        $matchedEntityKey = $k;
                        break;
                    }
                }

                if ($matchedEntityKey !== null) {
                    $entityDecl = $projectData['entities'][$matchedEntityKey];
                    $entityTitle = $entityDecl['title'] ?? $table;
                    $fromProject = $matchedEntityKey;
                    $rawFields = is_array($entityDecl['fields'] ?? null) ? $entityDecl['fields'] : [];

                    try {
                        $pk = $scaffold->validateEntityDefinition((string) $matchedEntityKey, $entityDecl['primary_key'] ?? 'id', $rawFields);
                    } catch (Throwable $e) {
                        $err = $e->getMessage();
                        if ($asJson) {
                            $output->writeln((string) json_encode(['error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        } else {
                            $output->error($err);
                        }
                        return 1;
                    }

                    foreach ($rawFields as $f) {
                        $fName = $f['name'];
                        $fields[] = $fName;
                        $fieldDetails[$fName] = [
                            'name'      => $fName,
                            'type'      => $f['type'] ?? 'string',
                            'length'    => $f['length'] ?? null,
                            'notnull'   => empty($f['nullable']),
                            'default'   => $f['default'] ?? null,
                            'comment'   => $f['title'] ?? '',
                            'precision' => $f['precision'] ?? null,
                            'scale'     => $f['scale'] ?? null,
                            'primary'   => !empty($f['primary']) || $fName === $pk,
                        ];
                    }
                    if (!in_array($pk, $fields, true)) {
                        array_unshift($fields, $pk);
                        $fieldDetails[$pk] = [
                            'name'    => $pk,
                            'type'    => $entityDecl['primary_key_type'] ?? ($pk !== 'id' ? 'string' : 'integer'),
                            'length'  => 64,
                            'notnull' => true,
                            'default' => null,
                            'comment' => '主键',
                            'primary' => true,
                        ];
                    }
                    $isTakeover = false;
                } else {
                    $err = "数据表 [{$prefix}{$table}] 不存在且在 hw.project.json 中未找到匹配的实体建模";
                    if ($asJson) {
                        $output->writeln((string) json_encode(['error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    } else {
                        $output->error($err);
                    }
                    return 1;
                }
            } else {
                try {
                    $rawPk = Db::name($table)->getPk();
                    if (is_array($rawPk) && count($rawPk) > 1) {
                        $err = "数据表 [{$prefix}{$table}] 使用了复合主键（" . implode(', ', $rawPk) . "），不支持复合主键";
                        if ($asJson) {
                            $output->writeln((string) json_encode(['error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        } else {
                            $output->error($err);
                        }
                        return 1;
                    }
                    $pk = is_array($rawPk) ? ($rawPk[0] ?? 'id') : (string) ($rawPk ?: 'id');
                } catch (Throwable) {
                    $pk = 'id';
                }

                try {
                    $fieldDetails = Db::name($table)->getFields();
                } catch (Throwable) {
                }

                // 已有表生成迁移时默认为接管迁移
                $isTakeover = true;
            }
            }
        }

        // 核心系统列与机密过滤
        $systemCols = [$pk, 'create_time', 'update_time', 'delete_time'];
        $secretPattern = '/(password|pass|pwd|salt|token|secret|access_token|refresh_token|remember_token|auth_key|private_key|api_key)/i';
        $ownershipPattern = '/(tenant_id|admin_id|user_id)/i';
        $privilegePattern = '/(rules|permissions|role_id|role_ids|group_id|group_ids|is_admin|is_super|money|balance|score|credit|quota)/i';

        $blockedCols = $systemCols;
        $secretCols = [];
        foreach ($fields as $f) {
            if (in_array($f, $systemCols, true)) {
                continue;
            }
            if (preg_match($secretPattern, $f)) {
                $secretCols[] = $f;
                $blockedCols[] = $f;
                continue;
            }
            if (preg_match($ownershipPattern, $f) || preg_match($privilegePattern, $f)) {
                $blockedCols[] = $f;
            }
        }
        $allowFields = array_values(array_diff($fields, $blockedCols));
        $hiddenFields = array_values(array_unique($secretCols));

        $passwordCols = [];
        foreach ($fields as $f) {
            if (preg_match('/^(password|pwd|pass)$/i', $f)) {
                $passwordCols[] = $f;
            }
        }
        $passwordField = $passwordCols ? $passwordCols[0] : '';

        $dataScopeField = in_array('admin_id', $fields, true) ? 'admin_id' : '';

        $searchCandidates = ['title', 'name', 'username', 'nickname', 'slug', 'sn', 'code', 'mobile', 'email', 'summary'];
        $searchFields = array_values(array_intersect($fields, $searchCandidates));
        if (!$searchFields) {
            foreach ($allowFields as $f) {
                if (!str_ends_with($f, '_id') && !str_ends_with($f, '_time') && $f !== 'status' && $f !== 'weight') {
                    $searchFields[] = $f;
                    if (count($searchFields) >= 2) {
                        break;
                    }
                }
            }
        }

        $studlyTable = $this->studly($table);
        if ($plugin !== '' && str_starts_with(strtolower($table), strtolower($plugin) . '_')) {
            $shortTable = substr($table, strlen($plugin) + 1);
            $studlyShort = $this->studly($shortTable ?: $table);
        } else {
            $studlyShort = $studlyTable;
        }

        $ctrlRel = $customCtrl !== '' ? str_replace('\\', '/', $customCtrl) : $studlyShort;
        $ctrlSegments = array_map([$this, 'studly'], explode('/', $ctrlRel));
        $ctrlClass = array_pop($ctrlSegments);
        $ctrlSubNs = $ctrlSegments ? ('\\' . strtolower(implode('\\', $ctrlSegments))) : '';
        $ctrlSubPath = $ctrlSegments ? (strtolower(implode(DIRECTORY_SEPARATOR, $ctrlSegments)) . DIRECTORY_SEPARATOR) : '';

        if ($plugin !== '') {
            $baseDir = plugin_path($plugin);
            $modelNs = "plugins\\{$plugin}\\model";
            $modelClass = $studlyShort;
            $modelFile = $baseDir . 'model' . DIRECTORY_SEPARATOR . $modelClass . '.php';
            $validateNs = "plugins\\{$plugin}\\validate";
            $validateFile = $baseDir . 'validate' . DIRECTORY_SEPARATOR . $modelClass . '.php';
            $ctrlNs = "plugins\\{$plugin}\\controller\\admin" . $ctrlSubNs;
            $ctrlFile = $baseDir . 'controller' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . $ctrlSubPath . $ctrlClass . '.php';
            $nodePrefix = "plugin/{$plugin}/" . strtolower(implode('/', array_merge($ctrlSegments, [$this->snake($ctrlClass)])));
        } else {
            $baseDir = $appRootPath . 'app' . DIRECTORY_SEPARATOR;
            $modelNs = 'app\\common\\model';
            $modelClass = $studlyTable;
            $modelFile = $baseDir . 'common' . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . $modelClass . '.php';
            $validateNs = 'app\\common\\validate';
            $validateFile = $baseDir . 'common' . DIRECTORY_SEPARATOR . 'validate' . DIRECTORY_SEPARATOR . $modelClass . '.php';
            $ctrlNs = 'app\\adminapi\\controller' . $ctrlSubNs;
            $ctrlFile = $baseDir . 'adminapi' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . $ctrlSubPath . $ctrlClass . '.php';
            $nodePrefix = strtolower(implode('/', array_merge($ctrlSegments, [$this->snake($ctrlClass)])));
        }

        $snakeSegs = array_map([$this, 'snake'], $ctrlSegments);
        $snakeCtrl = $this->snake($ctrlClass);
        $routePath = $snakeCtrl;
        $dotCtrl = implode('.', array_merge($snakeSegs, [$snakeCtrl]));
        $slashCtrl = implode('/', array_merge($snakeSegs, [$snakeCtrl]));
        $moduleTitle = $customTitle !== '' ? $customTitle : ($entityTitle ?: $ctrlClass);
        $viewRel = $plugin !== '' ? "plugin/{$plugin}/{$slashCtrl}" : $slashCtrl;
        $apiUrlPrefix = $plugin !== '' ? "/plugin/{$plugin}/{$dotCtrl}" : "/{$dotCtrl}";

        $menuNode = [
            'type'      => 'menu',
            'name'      => $nodePrefix,
            'title'     => $moduleTitle,
            'path'      => $routePath,
            'component' => "@/views/{$viewRel}/index.vue",
            'icon'      => 'file-list-3-line',
            'weight'    => 100,
            'children'  => [
                ['type' => 'button', 'name' => "{$nodePrefix}/index", 'title' => '查看'],
                ['type' => 'button', 'name' => "{$nodePrefix}/read", 'title' => '详情'],
                ['type' => 'button', 'name' => "{$nodePrefix}/add", 'title' => '添加'],
                ['type' => 'button', 'name' => "{$nodePrefix}/edit", 'title' => '编辑'],
                ['type' => 'button', 'name' => "{$nodePrefix}/del", 'title' => '删除'],
                ['type' => 'button', 'name' => "{$nodePrefix}/multi", 'title' => '批量更新'],
            ],
        ];

        $frontendSrc = $scaffold->detectFrontendSrcDir($appRootPath);
        $apiRelPath = $plugin !== '' ? "plugin/{$plugin}/{$slashCtrl}.ts" : "{$slashCtrl}.ts";
        $apiFile = $frontendSrc ? ($frontendSrc . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $apiRelPath)) : '';
        $viewFile = $frontendSrc ? ($frontendSrc . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $viewRel) . DIRECTORY_SEPARATOR . 'index.vue') : '';
        $apiImportPath = '/@/api/' . substr($apiRelPath, 0, -3);

        $migClass = 'Create' . $studlyTable . 'Table';
        $migFile = $appRootPath . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR
            . date('YmdHis') . '_create_' . $table . '_table.php';

        $testPrefix = $plugin !== '' ? "/adminapi/plugin/{$plugin}/{$dotCtrl}" : "/adminapi/{$dotCtrl}";
        $testFile = $appRootPath . 'tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . $ctrlClass . 'CrudTest.php';

        $pageSlug = $snakeCtrl;
        $publicApiFile = $appRootPath . 'app' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . $ctrlClass . '.php';
        $pageCtrlFile = $appRootPath . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . $ctrlClass . '.php';
        $pageViewFile = $appRootPath . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . $pageSlug . DIRECTORY_SEPARATOR . 'index.html';
        $indexRouteFile = $appRootPath . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR . 'app.php';
        $portalViewFile = $appRootPath . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'portal' . DIRECTORY_SEPARATOR . 'index.html';

        $mobileSrc = $withMobile ? $scaffold->detectMobileSrcDir($appRootPath) : null;
        $mobileApiFile = $mobileSrc ? ($mobileSrc . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . $pageSlug . '.ts') : '';
        $mobilePageFile = $mobileSrc ? ($mobileSrc . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . $pageSlug . DIRECTORY_SEPARATOR . 'index.vue') : '';

        $explicitPublicFields = $scaffold->parseFieldListOption($publicFieldsOpt);
        if ($explicitPublicFields === null && is_array($entityDecl['public_fields'] ?? null)) {
            $explicitPublicFields = array_values(array_map('strval', $entityDecl['public_fields']));
        }
        $explicitSubmitFields = $scaffold->parseFieldListOption($submitFieldsOpt);
        if ($explicitSubmitFields === null && is_array($entityDecl['submit_fields'] ?? null)) {
            $explicitSubmitFields = array_values(array_map('strval', $entityDecl['submit_fields']));
        }

        // 收集生成清单
        $allPlannedFiles = [
            'model'      => $modelFile,
            'validate'   => $validateFile,
            'controller' => $ctrlFile,
        ];
        if ($withUi && $frontendSrc) {
            $allPlannedFiles['frontend_api'] = $apiFile;
            $allPlannedFiles['frontend_view'] = $viewFile;
        }
        if ($withMigration) {
            $allPlannedFiles['migration'] = $migFile;
        }
        if ($withTest) {
            $allPlannedFiles['feature_test'] = $testFile;
        }
        if ($withApi) {
            $allPlannedFiles['public_api'] = $publicApiFile;
            $allPlannedFiles['page_controller'] = $pageCtrlFile;
            $allPlannedFiles['page_view'] = $pageViewFile;
        } elseif ($withMobile) {
            $allPlannedFiles['public_api'] = $publicApiFile;
        }
        if ($withMobile && $mobileSrc) {
            $allPlannedFiles['mobile_api'] = $mobileApiFile;
            $allPlannedFiles['mobile_page'] = $mobilePageFile;
        }

        $filesToCreate = [];
        $filesToUpdate = [];
        $conflicts = [];

        foreach ($allPlannedFiles as $k => $f) {
            if ($f === '') continue;
            if (is_file($f)) {
                if ($incremental) {
                    $filesToUpdate[] = $f;
                } elseif ($force) {
                    $filesToUpdate[] = $f;
                } else {
                    $conflicts[] = $f;
                }
            } else {
                $filesToCreate[] = $f;
            }
        }
        if ($withApi) {
            $filesToUpdate[] = $indexRouteFile;
            if ($withNav && is_file($portalViewFile)) {
                $filesToUpdate[] = $portalViewFile;
            }
        }

        $pendingRules = [
            "需验证 {$moduleTitle} 的业务字段校验与权限按钮边界",
            "如涉及特定状态流转，建议在模型与服务层补充状态机方法",
        ];

        $advisories = [];
        $titleChars = mb_strlen(trim((string) ($menuNode['title'] ?? '')), 'UTF-8');
        if ($titleChars > 6) {
            $advisories[] = "菜单标题「{$menuNode['title']}」长度为 {$titleChars} 字，分栏次级侧边栏建议控制在 6 个汉字以内以免遮挡，请通过 --title 指定更精简名称";
        }
        if ($plugin === '' && !empty($snakeSegs)) {
            $dirTitleGuess = ucfirst($snakeSegs[0]);
            if (mb_strlen($dirTitleGuess, 'UTF-8') > 6) {
                $advisories[] = "自动目录名「{$dirTitleGuess}」偏长，建议在后台「菜单规则」中调整为 3 个汉字以内并归集相关菜单";
            }
        }
        if ($all && !$explicitWithTest && !$hasTestRunner) {
            $advisories[] = '当前目录缺少 tests/bootstrap.php 或 phpunit.xml（精简部署包默认不含测试基座），--all 已自动跳过 Feature 测试骨架生成；若确需生成可显式传入 --with-test';
        } elseif ($explicitWithTest && !$hasTestRunner) {
            $advisories[] = '已显式生成 Feature 测试骨架，但当前目录缺少 tests/bootstrap.php 或 phpunit.xml，运行 php think hw:test 请使用完整开发包 (hwadmin-dev)';
        }
        if ($inlineFieldsSpec !== '' && !$isTakeover && !$withMigration) {
            $advisories[] = '检测到通过 --fields 离线定义了新表结构但未启用 --with-migration，建议追加 --with-migration 或 --all 自动生成建表迁移';
        }

        $publicReadFields = [];
        $submitFields = [];
        if ($withApi || $withMobile) {
            $partitionWarnings = [];
            $partitioned = $scaffold->partitionPublicApiFields(
                $fields,
                $allowFields,
                $pk,
                $explicitPublicFields,
                $explicitSubmitFields,
                $partitionWarnings
            );
            $publicReadFields = $partitioned['public_read_fields'];
            $submitFields = $partitioned['submit_fields'];
            foreach ($partitionWarnings as $pw) {
                $advisories[] = $pw;
            }
            $advisories[] = '前台接口白名单：公开只读 $publicFields=[' . implode(', ', $publicReadFields) . ']；访客提交 $submitFields=[' . implode(', ', $submitFields) . ']（如需放行只收不展如 contact 或只展不收如 reply/is_top，可用 --public-fields / --submit-fields 或 hw.project.json 声明）';
            if ($withApi) {
                if ($withNav) {
                    $advisories[] = "已启用 --with-nav：将同步把「{$moduleTitle}」(/{$pageSlug}) 入口链接挂载至默认门户首页（app/index/view/portal/index.html）";
                } else {
                    $advisories[] = "已注册独立前台路由 /{$pageSlug}（默认门户首页 / 不会自动挂载导航入口；如需挂载可追加 --with-nav，或按需在 app/index/view/portal/index.html / public/site/index.html 添加链接）";
                }
            }
            if ($withMobile) {
                if ($mobileSrc) {
                    $advisories[] = "已启用 --with-mobile：将生成移动端 API (src/api/{$pageSlug}.ts) 与页面 (src/pages/{$pageSlug}/index.vue)，pages.json 由 uni-pages 自动生成；校验请执行 pnpm --dir admin-ui mobile:check";
                } else {
                    $advisories[] = '已指定 --with-mobile，但未检测到 admin-ui/mobile/src 目录，已跳过移动端文件生成';
                }
            }
        }

        // 如果是 --plan / --dry-run 模式：纯输出计划，不写文件不改库
        if ($plan) {
            $planResult = [
                'status'                 => empty($conflicts) ? 'ready' : 'conflict',
                'table'                  => $table,
                'entity'                 => $fromProject ?: $table,
                'is_takeover'            => $isTakeover,
                'files_to_create'        => $filesToCreate,
                'files_to_update'        => $filesToUpdate,
                'conflicts'              => $conflicts,
                'migrations'             => $withMigration ? [$migFile] : [],
                'menus'                  => $withMenu ? [$menuNode] : [],
                'advisories'             => $advisories,
                'pending_business_rules' => $pendingRules,
            ];

            if ($asJson) {
                $output->writeln((string) json_encode($planResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->info("=== [Plan] CRUD 生成计划 (Table: {$table}) ===");
                $output->writeln("模式: " . ($isTakeover ? "已有表接管" : "新建表"));
                $output->writeln("拟新建文件 (" . count($filesToCreate) . "):");
                foreach ($filesToCreate as $f) {
                    $output->writeln("  + {$f}");
                }
                if (!empty($filesToUpdate)) {
                    $output->writeln("拟更新文件 (" . count($filesToUpdate) . "):");
                    foreach ($filesToUpdate as $f) {
                        $output->writeln("  ~ {$f}");
                    }
                }
                foreach ($advisories as $adv) {
                    $output->warning("[UI 建议] {$adv}");
                }
                if (!empty($conflicts)) {
                    $output->error("检测到存在冲突的文件 (" . count($conflicts) . ")，追加 --force 覆盖或 --incremental 增量更新：");
                    foreach ($conflicts as $f) {
                        $output->writeln("  ! {$f}");
                    }
                }
            }
            return empty($conflicts) ? 0 : 1;
        }

        // 检查非 force / 非 incremental 冲突
        if (!$force && !$incremental && !empty($conflicts)) {
            $err = "目标文件已存在（如需增量保留请追加 --incremental，强制覆盖请追加 --force）：\n  - " . implode("\n  - ", $conflicts);
            if ($asJson) {
                $output->writeln((string) json_encode(['error' => $err, 'conflicts' => $conflicts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }

        $createdFiles = [];
        $modifiedFiles = [];
        $createdFileHashes = [];
        $modifiedFileBackups = [];
        $createdMenuNodes = [];

        try {
            $hasCreateTime = in_array('create_time', $fields, true);
            $hasUpdateTime = in_array('update_time', $fields, true);
            $pkRawType = strtolower(trim((string) ($fieldDetails[$pk]['type'] ?? 'integer')));
            $pkBaseType = (string) preg_replace('/\(.*\)$/', '', $pkRawType);
            $isStringPk = in_array($pkBaseType, ['string', 'varchar', 'char'], true);

            $modelCode = $this->renderModel($modelNs, $modelClass, $table, $hasCreateTime, $hasUpdateTime, $pk, $fieldDetails);
            $validateCode = $this->renderValidate($validateNs, $modelClass, $allowFields, $passwordField, $fieldDetails);
            $ctrlCode = $this->renderController(
                $ctrlNs,
                $ctrlClass,
                "{$modelNs}\\{$modelClass}",
                "{$validateNs}\\{$modelClass}",
                $searchFields,
                $allowFields,
                $hiddenFields,
                $fields,
                $nodePrefix,
                $passwordField,
                $dataScopeField,
                $pk,
                $isStringPk
            );

            $this->writeFileSafe($modelFile, $modelCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
            $this->writeFileSafe($validateFile, $validateCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
            $this->writeFileSafe($ctrlFile, $ctrlCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);

            if ($withUi && $frontendSrc) {
                $apiCode = $scaffold->renderFrontendApi($ctrlClass, $apiUrlPrefix, $pk, $fields, $allowFields, $hiddenFields, $fieldDetails);
                $viewCode = $scaffold->renderFrontendView($ctrlClass, $moduleTitle, $apiImportPath, $pk, $fields, $allowFields, $hiddenFields, $fieldDetails, $nodePrefix);
                $this->writeFileSafe($apiFile, $apiCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
                $this->writeFileSafe($viewFile, $viewCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
            }

            if ($withMigration) {
                if ($entityDecl !== null) {
                    $migCode = $scaffold->renderMigrationFromEntity($migClass, $entityDecl, $menuNode, $isTakeover);
                } else {
                    $migCode = $scaffold->renderMigration($migClass, $table, $pk, $fieldDetails, $menuNode, $isTakeover);
                }
                $this->writeFileSafe($migFile, $migCode, false, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
            }

            if ($withTest) {
                $testCode = $scaffold->renderFeatureTest($ctrlClass, $table, $testPrefix, $allowFields, $fieldDetails, $pk, $isStringPk);
                $this->writeFileSafe($testFile, $testCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
            }

            if ($withApi || $withMobile) {
                $publicSearchFields = array_values(array_intersect($searchFields, $publicReadFields));
                if (!$publicSearchFields) {
                    foreach (['title', 'name', 'nickname', 'content', 'summary'] as $cand) {
                        if (in_array($cand, $publicReadFields, true)) {
                            $publicSearchFields[] = $cand;
                        }
                    }
                }
                $hasStatus = in_array('status', $fields, true);

                $publicApiCode = $scaffold->renderPublicApiController(
                    $ctrlClass,
                    "{$modelNs}\\{$modelClass}",
                    "{$validateNs}\\{$modelClass}",
                    $publicReadFields,
                    $submitFields,
                    $publicSearchFields,
                    $pk,
                    $hasStatus
                );
                $this->writeFileSafe($publicApiFile, $publicApiCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
            }

            if ($withApi) {
                $pageCtrlCode = $scaffold->renderForegroundPageController($ctrlClass, $pageSlug, $moduleTitle, $theme);
                $pageViewCode = $scaffold->renderForegroundPageView(
                    $pageSlug,
                    $moduleTitle,
                    $publicReadFields,
                    $submitFields,
                    $fieldDetails,
                    $pk,
                    $theme,
                    true
                );

                $this->writeFileSafe($pageCtrlFile, $pageCtrlCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
                $this->writeFileSafe($pageViewFile, $pageViewCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);

                $existingRoute = is_file($indexRouteFile) ? (string) file_get_contents($indexRouteFile) : "<?php\ndeclare(strict_types=1);\n\nuse think\\facade\\Route;\n\nRoute::get('/', 'Index/index');\n// @hw-page-routes-start\n// @hw-page-routes-end\nRoute::any(':entry', 'Index/entry')->pattern(['entry' => '^[a-zA-Z0-9_-]+$']);\n";
                $updatedRoute = $scaffold->buildIndexRouteUpdate($existingRoute, $pageSlug, $ctrlClass);
                if ($updatedRoute !== $existingRoute) {
                    $this->writeFileSafe($indexRouteFile, $updatedRoute, false, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
                }

                if ($withNav && is_file($portalViewFile)) {
                    $existingPortal = (string) file_get_contents($portalViewFile);
                    $updatedPortal = $scaffold->buildPortalNavUpdate($existingPortal, $pageSlug, $moduleTitle);
                    if ($updatedPortal !== $existingPortal) {
                        $this->writeFileSafe($portalViewFile, $updatedPortal, false, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
                    }
                }
            }

            if ($withMobile && $mobileSrc) {
                $mobileApiCode = $scaffold->renderMobileApi(
                    $ctrlClass,
                    $pageSlug,
                    $pk,
                    $publicReadFields,
                    $submitFields,
                    $fieldDetails
                );
                $mobilePageCode = $scaffold->renderMobilePage(
                    $ctrlClass,
                    $moduleTitle,
                    $pageSlug,
                    $pk,
                    $publicReadFields,
                    $submitFields,
                    $fieldDetails
                );
                $this->writeFileSafe($mobileApiFile, $mobileApiCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
                $this->writeFileSafe($mobilePageFile, $mobilePageCode, $incremental, $scaffold, $createdFiles, $modifiedFiles, $createdFileHashes, $modifiedFileBackups);
            }

            if ($withMenu) {
                $pid = 0;
                if ($plugin !== '') {
                    $pid = MenuSync::ensureNode([
                        'type'   => 'dir',
                        'name'   => 'plugin',
                        'title'  => '插件',
                        'path'   => 'plugin',
                        'icon'   => 'apps-2-line',
                        'weight' => 650,
                    ]);
                    $this->appendPluginJsonMenu($plugin, $menuNode, $output, $modifiedFiles, $modifiedFileBackups);
                } elseif (!empty($snakeSegs)) {
                    $dirName = $snakeSegs[0];
                    $existingDir = null;
                    try {
                        $existingDir = Db::name('auth_rule')->where('name', $dirName)->find();
                    } catch (Throwable) {
                    }
                    $pid = MenuSync::ensureNode([
                        'type'   => 'dir',
                        'name'   => $dirName,
                        'title'  => ucfirst($dirName),
                        'path'   => $dirName,
                        'icon'   => 'folder-line',
                        'weight' => 500,
                    ]);
                    if (empty($existingDir)) {
                        $createdMenuNodes[] = $dirName;
                    }
                }
                MenuSync::ensureNode($menuNode, $pid, $plugin);
                $createdMenuNodes[] = $menuNode['name'];
                foreach ($menuNode['children'] ?? [] as $child) {
                    if (!empty($child['name'])) {
                        $createdMenuNodes[] = (string) $child['name'];
                    }
                }
            }

            // 保存变更清单到 runtime/crud-manifests/
            $manifestDir = $appRootPath . 'runtime' . DIRECTORY_SEPARATOR . 'crud-manifests';
            if (!is_dir($manifestDir)) {
                @mkdir($manifestDir, 0755, true);
            }
            $manifestFileName = "manifest-{$table}-" . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.json';
            $manifestFilePath = $manifestDir . DIRECTORY_SEPARATOR . $manifestFileName;
            $manifestData = [
                'generated_at'          => time(),
                'table'                 => $table,
                'plugin'                => $plugin,
                'is_takeover'           => $isTakeover,
                'created_files'         => $createdFiles,
                'created_file_hashes'   => $createdFileHashes,
                'modified_files'        => $modifiedFiles,
                'modified_file_backups' => $modifiedFileBackups,
                'created_menu_nodes'    => array_values(array_unique($createdMenuNodes)),
            ];
            file_put_contents($manifestFilePath, json_encode($manifestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            if ($asJson) {
                $output->writeln((string) json_encode([
                    'status'              => 'success',
                    'table'               => $table,
                    'manifest'            => $manifestFilePath,
                    'created_files'       => $createdFiles,
                    'created_file_hashes' => $createdFileHashes,
                    'modified_files'      => $modifiedFiles,
                    'created_menu_nodes'  => $manifestData['created_menu_nodes'],
                    'advisories'          => $advisories,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->info("✔ CRUD 全栈骨架生成完成！变更清单: {$manifestFilePath}");
                foreach ($createdFiles as $f) {
                    $output->writeln("  + {$f}");
                }
                foreach ($modifiedFiles as $f) {
                    $output->writeln("  ~ {$f} (增量保留手写逻辑)");
                }
                foreach ($advisories as $adv) {
                    $output->warning("[UI 建议] {$adv}");
                }
            }
            return 0;
        } catch (Throwable $e) {
            $err = '生成失败: ' . $e->getMessage();
            if ($asJson) {
                $output->writeln((string) json_encode(['error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }
    }

    private function writeFileSafe(
        string $file,
        string $newContent,
        bool $incremental,
        CrudScaffoldBuilder $scaffold,
        array &$createdFiles,
        array &$modifiedFiles,
        array &$createdFileHashes = [],
        array &$modifiedFileBackups = []
    ): void {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (is_file($file)) {
            $existing = (string) file_get_contents($file);
            $finalContent = $newContent;
            if ($incremental) {
                $customSections = $scaffold->extractCustomSections($existing);
                $finalContent = $scaffold->mergeCustomSections($newContent, $customSections);
            }
            file_put_contents($file, $finalContent);
            $modifiedFiles[] = $file;
            $modifiedFileBackups[$file] = [
                'previous_content' => $existing,
                'previous_sha256'  => hash('sha256', $existing),
                'new_sha256'       => hash('sha256', $finalContent),
            ];
            return;
        }

        file_put_contents($file, $newContent);
        $createdFiles[] = $file;
        $createdFileHashes[$file] = hash('sha256', $newContent);
    }

    private function handleRollback(string $manifestPath, bool $force, bool $asJson, Output $output): int
    {
        $realFile = $manifestPath;
        if (!is_file($realFile)) {
            $fallback = root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'crud-manifests' . DIRECTORY_SEPARATOR . $manifestPath;
            if (is_file($fallback)) {
                $realFile = $fallback;
            }
        }

        if (!is_file($realFile)) {
            $err = "未找到变更清单文件: {$manifestPath}";
            if ($asJson) {
                $output->writeln((string) json_encode(['status' => 'error', 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->error($err);
            }
            return 1;
        }

        $manifest = json_decode((string) file_get_contents($realFile), true) ?: [];
        $createdFiles = $manifest['created_files'] ?? [];
        $createdFileHashes = is_array($manifest['created_file_hashes'] ?? null) ? $manifest['created_file_hashes'] : [];
        $modifiedFiles = $manifest['modified_files'] ?? [];
        $modifiedFileBackups = is_array($manifest['modified_file_backups'] ?? null) ? $manifest['modified_file_backups'] : [];
        $createdMenuNodes = is_array($manifest['created_menu_nodes'] ?? null) ? $manifest['created_menu_nodes'] : [];

        // 1. 预检冲突：检查用户是否在生成后手写修改了待撤回的文件
        $conflicts = [];
        if (!$force) {
            foreach ($createdFiles as $k => $entry) {
                $filePath = is_array($entry) ? (string) ($entry['path'] ?? '') : (string) $entry;
                $expectedHash = is_array($entry)
                    ? (string) ($entry['sha256'] ?? '')
                    : (string) ($createdFileHashes[$filePath] ?? '');
                if ($filePath !== '' && is_file($filePath) && $expectedHash !== '') {
                    $actualHash = (string) hash_file('sha256', $filePath);
                    if ($actualHash !== $expectedHash) {
                        $conflicts[] = $filePath;
                    }
                }
            }

            foreach ($modifiedFiles as $k => $entry) {
                $filePath = is_array($entry) ? (string) ($entry['path'] ?? '') : (string) $entry;
                $expectedNewHash = is_array($entry)
                    ? (string) ($entry['new_sha256'] ?? '')
                    : (string) ($modifiedFileBackups[$filePath]['new_sha256'] ?? '');
                if ($filePath !== '' && is_file($filePath) && $expectedNewHash !== '') {
                    $actualHash = (string) hash_file('sha256', $filePath);
                    if ($actualHash !== $expectedNewHash) {
                        $conflicts[] = $filePath;
                    }
                }
            }
        }

        if (!empty($conflicts)) {
            $err = '检测到以下文件在生成后已被手动修改，安全撤回已拦截（如需强制撤回请追加 --force）：' . implode(', ', $conflicts);
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

        // 2. 删除新建的文件
        $deleted = [];
        foreach ($createdFiles as $entry) {
            $filePath = is_array($entry) ? (string) ($entry['path'] ?? '') : (string) $entry;
            if ($filePath !== '' && is_file($filePath)) {
                @unlink($filePath);
                $deleted[] = $filePath;
            }
        }

        // 3. 恢复被增量修改的文件原内容
        $restored = [];
        foreach ($modifiedFileBackups as $filePath => $backup) {
            if (is_string($filePath) && $filePath !== '' && isset($backup['previous_content'])) {
                $dir = dirname($filePath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                file_put_contents($filePath, (string) $backup['previous_content']);
                $restored[] = $filePath;
            }
        }
        foreach ($modifiedFiles as $entry) {
            if (is_array($entry) && !empty($entry['path']) && isset($entry['previous_content']) && !in_array($entry['path'], $restored, true)) {
                file_put_contents((string) $entry['path'], (string) $entry['previous_content']);
                $restored[] = (string) $entry['path'];
            }
        }

        // 4. 撤销新增的菜单与按钮权限节点
        $removedMenus = [];
        if (!empty($createdMenuNodes)) {
            try {
                MenuSync::removeNodes($createdMenuNodes);
                $removedMenus = $createdMenuNodes;
            } catch (Throwable) {
            }
        }

        @unlink($realFile);

        if ($asJson) {
            $output->writeln((string) json_encode([
                'status'         => 'success',
                'rollback_from'  => $realFile,
                'deleted_files'  => $deleted,
                'restored_files' => $restored,
                'removed_menus'  => $removedMenus,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $output->info("[✔] 清单撤销完成！共删除 " . count($deleted) . " 个文件，恢复 " . count($restored) . " 个文件。");
            foreach ($deleted as $d) {
                $output->writeln("  - {$d}");
            }
            foreach ($restored as $r) {
                $output->writeln("  ~ {$r} (已恢复修改前内容)");
            }
        }
        return 0;
    }

    private function appendPluginJsonMenu(
        string $plugin,
        array $menuNode,
        Output $output,
        array &$modifiedFiles = [],
        array &$modifiedFileBackups = []
    ): void {
        $manifestFile = plugin_path($plugin) . 'plugin.json';
        if (!is_file($manifestFile)) {
            return;
        }
        $existingRaw = (string) file_get_contents($manifestFile);
        $decoded = json_decode($existingRaw, true);
        if (!is_array($decoded)) {
            return;
        }
        $menus = is_array($decoded['menus'] ?? null) ? $decoded['menus'] : [];
        foreach ($menus as $existing) {
            if (($existing['name'] ?? '') === $menuNode['name']) {
                return;
            }
        }
        $menus[] = $menuNode;
        $decoded['menus'] = $menus;
        $newRaw = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        file_put_contents($manifestFile, $newRaw);
        $modifiedFiles[] = $manifestFile;
        $modifiedFileBackups[$manifestFile] = [
            'previous_content' => $existingRaw,
            'previous_sha256'  => hash('sha256', $existingRaw),
            'new_sha256'       => hash('sha256', $newRaw),
        ];
        $output->info("✔ 已将菜单写入插件清单：{$manifestFile}");
    }

    private function renderModel(
        string $ns,
        string $class,
        string $table,
        bool $hasCreate,
        bool $hasUpdate,
        string $pk = 'id',
        array $fieldDetails = []
    ): string {
        $autoTs = ($hasCreate || $hasUpdate) ? "protected \$autoWriteTimestamp = 'int';" : 'protected $autoWriteTimestamp = false;';
        $createAttr = $hasCreate ? "'create_time'" : 'false';
        $updateAttr = $hasUpdate ? "'update_time'" : 'false';
        $pkLine = $pk !== 'id' ? "\n    protected \$pk = '{$pk}';" : '';

        $typeCasts = [];
        foreach ($fieldDetails as $fName => $detail) {
            $rawType = strtolower(trim((string) ($detail['type'] ?? '')));
            $baseType = (string) preg_replace('/\(.*\)$/', '', $rawType);
            if ($fName === $pk && !in_array($baseType, ['string', 'varchar', 'char'], true)) {
                $typeCasts[$fName] = 'integer';
            } elseif (in_array($baseType, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint'], true)) {
                $typeCasts[$fName] = 'integer';
            } elseif (in_array($baseType, ['decimal', 'float', 'double', 'numeric'], true)) {
                $typeCasts[$fName] = 'float';
            } elseif (in_array($baseType, ['bool', 'boolean'], true)) {
                $typeCasts[$fName] = 'boolean';
            } elseif (str_ends_with((string) $fName, '_time') && !in_array($baseType, ['datetime', 'date', 'string', 'varchar', 'char', 'text'], true)) {
                $typeCasts[$fName] = 'integer';
            }
        }
        if ($pk !== '' && !isset($typeCasts[$pk]) && !isset($fieldDetails[$pk])) {
            $typeCasts[$pk] = 'integer';
        }
        if ($hasCreate && !isset($typeCasts['create_time'])) {
            $typeCasts['create_time'] = 'integer';
        }
        if ($hasUpdate && !isset($typeCasts['update_time'])) {
            $typeCasts['update_time'] = 'integer';
        }
        $typeBlock = $typeCasts
            ? "\n\n    /** 字段读取强类型转换（消除 SQLite PDO 字符串与 MySQL 类型差异） */\n    protected \$type = " . $this->exportAssocArray($typeCasts) . ';'
            : '';

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

use core\base\UnixTimeModel;

/**
 * {$class} 模型（由 php think hw:crud 自动生成）
 */
class {$class} extends UnixTimeModel
{
    protected \$name = '{$table}';{$pkLine}

    {$autoTs}
    protected \$createTime = {$createAttr};
    protected \$updateTime = {$updateAttr};{$typeBlock}

    /* @hw-custom-start: model-relations */
    // 模型关联与自定义获取器写在此处，增量更新时自动保留
    /* @hw-custom-end: model-relations */
}

PHP;
    }

    private function renderValidate(string $ns, string $class, array $allowFields, string $passwordField = '', array $fieldDetails = []): string
    {
        $rules = [];
        $messages = [];
        $addScene = [];
        $editScene = [];

        foreach ($allowFields as $field) {
            $detail = $fieldDetails[$field] ?? null;
            $notnull = $detail ? (bool) ($detail['notnull'] ?? false) : false;
            $default = $detail ? ($detail['default'] ?? null) : null;
            $type = strtolower((string) ($detail['type'] ?? ''));

            $fieldRules = [];
            $isRequiredOnAdd = ($notnull && $default === null);

            if (str_contains($type, 'int') || str_contains($type, 'tinyint')) {
                $fieldRules[] = 'number';
                $messages["{$field}.number"] = "{$field} 必须为数字";
            } elseif (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
                $fieldRules[] = 'float';
                $messages["{$field}.float"] = "{$field} 必须为浮点数值";
            }

            if ($field === 'mobile') {
                $fieldRules[] = 'mobile';
                $messages["{$field}.mobile"] = '手机号格式不正确';
            } elseif ($field === 'email') {
                $fieldRules[] = 'email';
                $messages["{$field}.email"] = '邮箱格式不正确';
            }

            if (preg_match('/(?:varchar|char)\((\d+)\)/', $type, $m)) {
                $maxLen = (int) $m[1];
                if ($maxLen > 0) {
                    $fieldRules[] = 'max:' . $maxLen;
                    $messages["{$field}.max"] = "{$field} 最大长度不能超过 {$maxLen} 个字符";
                }
            }

            if ($fieldRules) {
                $rules[$field] = implode('|', $fieldRules);
            }

            if ($isRequiredOnAdd) {
                $addRules = array_merge(['require'], $fieldRules);
                $addScene[$field] = implode('|', $addRules);
                $messages["{$field}.require"] = "{$field} 不能为空";
            } else {
                $addScene[] = $field;
            }

            $editScene[] = $field;
        }

        if ($passwordField !== '') {
            $rules[$passwordField] = 'min:6';
            $messages["{$passwordField}.require"] = '密码不能为空';
            $messages["{$passwordField}.min"] = '密码长度不能少于6位';
            $addScene[$passwordField] = 'require|min:6';
            $editScene[$passwordField] = 'min:6';
        }

        $rulesExport = $this->exportAssocArray($rules);
        $messagesExport = $this->exportAssocArray($messages);
        $addSceneExport = $this->exportSceneArray($addScene);
        $editSceneExport = $this->exportSceneArray($editScene);

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

use think\Validate;

/**
 * {$class} 数据验证器（由 php think hw:crud 自动生成）
 */
class {$class} extends Validate
{
    protected \$rule = {$rulesExport};

    protected \$message = {$messagesExport};

    protected \$scene = [
        'add'  => {$addSceneExport},
        'edit' => {$editSceneExport},
    ];

    /* @hw-custom-start: validate-rules */
    // 自定义验证规则与场景写在此处，增量更新时自动保留
    /* @hw-custom-end: validate-rules */
}

PHP;
    }

    private function exportSceneArray(array $items): string
    {
        if (!$items) {
            return '[]';
        }
        $parts = [];
        foreach ($items as $k => $v) {
            if (is_int($k)) {
                $parts[] = "\n        '" . addslashes((string) $v) . "'";
            } else {
                $parts[] = "\n        '" . addslashes((string) $k) . "' => '" . addslashes((string) $v) . "'";
            }
        }
        return '[' . implode(',', $parts) . "\n    ]";
    }

    private function exportAssocArray(array $items): string
    {
        if (!$items) {
            return '[]';
        }
        $parts = [];
        foreach ($items as $k => $v) {
            $parts[] = "\n        '" . addslashes((string) $k) . "' => '" . addslashes((string) $v) . "'";
        }
        return '[' . implode(',', $parts) . "\n    ]";
    }

    private function renderController(
        string $ns,
        string $class,
        string $modelFqn,
        string $validateFqn,
        array $searchFields,
        array $allowFields,
        array $hiddenFields,
        array $allFields,
        string $nodePrefix,
        string $passwordField = '',
        string $dataScopeField = '',
        string $pk = 'id',
        bool $isStringPk = false
    ): string {
        $searchStr = $this->exportStringArray($searchFields);
        $allowStr = $this->exportStringArray($allowFields);
        $hiddenLine = $hiddenFields ? "\n    protected array \$hiddenFields = " . $this->exportStringArray($hiddenFields) . ";" : '';
        $defaultSort = in_array('weight', $allFields, true)
            ? "['weight' => 'desc', '{$pk}' => 'desc']"
            : "'{$pk}'";

        $dataScopeLines = '';
        if ($dataScopeField !== '') {
            $dataScopeLines = "\n    protected string \$dataScope = 'self';\n    protected string \$dataScopeField = '{$dataScopeField}';";
        }

        $allowFieldsBlock = "protected array \$allowFields = {$allowStr};";
        $beforeWriteStatements = [];
        if ($isStringPk) {
            $beforeWriteStatements[] = <<<PHP
        if (\$row === null && empty(\$data['{$pk}'])) {
            \$rawPk = trim((string) \$this->request->post('{$pk}', ''));
            \$data['{$pk}'] = \$rawPk !== '' ? \$rawPk : ('rk_' . bin2hex(random_bytes(6)));
        }
PHP;
        }
        if ($passwordField !== '') {
            $addAllow = array_values(array_unique(array_merge($allowFields, [$passwordField])));
            $editAllow = array_values(array_unique(array_merge($allowFields, [$passwordField])));
            $addAllowStr = $this->exportStringArray($addAllow);
            $editAllowStr = $this->exportStringArray($editAllow);
            $allowFieldsBlock = "protected array \$addAllowFields = {$addAllowStr};\n    protected array \$editAllowFields = {$editAllowStr};";
            $beforeWriteStatements[] = <<<PHP
        if (isset(\$data['{$passwordField}'])) {
            \$raw = trim((string) \$data['{$passwordField}']);
            if (\$raw !== '') {
                \$data['{$passwordField}'] = \\core\\auth\\PasswordHasher::hash(\$raw);
            } else {
                unset(\$data['{$passwordField}']);
            }
        }
PHP;
        }

        $beforeWriteBlock = '';
        if (!empty($beforeWriteStatements)) {
            $stmts = implode("\n", $beforeWriteStatements);
            $beforeWriteBlock = <<<PHP


    protected function beforeWrite(array \$data, ?\\think\\Model \$row): array
    {
{$stmts}
        return \$data;
    }
PHP;
        }

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

use {$modelFqn} as TargetModel;
use {$validateFqn} as TargetValidate;
use core\base\AdminController;
use core\crud\CrudTrait;

/**
 * {$class} 管理控制器（由 php think hw:crud 自动生成）
 *
 * 权限节点：{$nodePrefix}/{index|read|add|edit|del|multi|select}
 */
class {$class} extends AdminController
{
    use CrudTrait;

    protected array \$searchFields = {$searchStr};
    {$allowFieldsBlock}{$hiddenLine}{$dataScopeLines}
    protected string|array \$defaultSort = {$defaultSort};
    protected ?string \$validateClass = TargetValidate::class;

    protected function initialize(): void
    {
        parent::initialize();
        \$this->model = new TargetModel();
    }{$beforeWriteBlock}

    /* @hw-custom-start: controller-actions */
    // 自定义控制器业务方法与生命周期钩子写在此处，增量更新时自动保留
    // CrudTrait 钩子精确签名示例（重写时请保持参数与返回类型一致）：
    // protected function beforeWrite(array \$data, ?\\think\\Model \$row = null): array { return \$data; }
    // protected function afterWrite(\\think\\Model \$row, array \$data, bool \$isAdd): void {}
    /* @hw-custom-end: controller-actions */
}

PHP;
    }

    private function exportStringArray(array $items): string
    {
        if (!$items) {
            return '[]';
        }
        return '[' . implode(', ', array_map(static fn ($s) => "'" . addslashes((string) $s) . "'", $items)) . ']';
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
