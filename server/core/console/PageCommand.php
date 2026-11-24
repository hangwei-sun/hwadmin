<?php
declare(strict_types=1);

namespace core\console;

use core\support\CrudScaffoldBuilder;
use core\support\ProjectSchemaService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use Throwable;

/**
 * 前台页面与公共接口快速脚手架命令
 *
 * 用法：
 *   php think hw:page --name=guestbook --table=guestbook --title="在线留言板" --theme=light
 *   php think hw:page --name=about --title="关于我们" --theme=dark
 *   php think hw:page --name=guestbook --table=guestbook --plan --json
 */
class PageCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hw:page')
            ->addOption('name', 'N', Option::VALUE_OPTIONAL, '前台页面访问标识（对应 URL /{name}，如 guestbook）', '')
            ->addOption('table', 't', Option::VALUE_OPTIONAL, '可选绑定数据表名（不含前缀，自动生成白名单 ApiController 并联调列表/表单）', '')
            ->addOption('from-project', null, Option::VALUE_OPTIONAL, '可选从 hw.project.json 读取声明式实体定义', '')
            ->addOption('fields', null, Option::VALUE_OPTIONAL, '可选内联字段定义（无需先建表，如 nickname:string(64),contact:?string(100),content:text?）', '')
            ->addOption('public-fields', null, Option::VALUE_OPTIONAL, '显式指定前台公开返回字段白名单（逗号分隔，可放行只展不收列）', '')
            ->addOption('submit-fields', null, Option::VALUE_OPTIONAL, '显式指定前台访客提交字段白名单（逗号分隔，可放行只收不展列）', '')
            ->addOption('with-nav', null, Option::VALUE_NONE, '将生成的独立页面入口链接自动挂载到默认门户首页（/）')
            ->addOption('title', null, Option::VALUE_OPTIONAL, '页面显示标题', '')
            ->addOption('theme', null, Option::VALUE_OPTIONAL, '骨架默认主题（light|dark）', 'light')
            ->addOption('without-api', null, Option::VALUE_NONE, '即使指定了 --table 也不生成 app/api 控制器')
            ->addOption('force', 'f', Option::VALUE_NONE, '覆盖已存在的文件')
            ->addOption('incremental', null, Option::VALUE_NONE, '增量更新模式，保留 @hw-custom-start/end 保护区内容')
            ->addOption('plan', null, Option::VALUE_NONE, '仅输出生成计划预览，不写入文件')
            ->addOption('dry-run', null, Option::VALUE_NONE, '同 --plan')
            ->addOption('json', 'j', Option::VALUE_NONE, '以纯 JSON 格式输出结果')
            ->setDescription('一键生成前台页面控制器、响应式骨架模板、路由与白名单公开 ApiController');
    }

    protected function execute(Input $input, Output $output): int
    {
        $name = trim((string) $input->getOption('name'));
        $table = trim((string) $input->getOption('table'));
        $fromProject = trim((string) $input->getOption('from-project'));
        $inlineFieldsSpec = trim((string) $input->getOption('fields'));
        $publicFieldsOpt = trim((string) $input->getOption('public-fields'));
        $submitFieldsOpt = trim((string) $input->getOption('submit-fields'));
        $withNav = (bool) $input->getOption('with-nav');
        $customTitle = trim((string) $input->getOption('title'));
        $theme = strtolower(trim((string) $input->getOption('theme'))) === 'dark' ? 'dark' : 'light';
        $withoutApi = (bool) $input->getOption('without-api');
        $force = (bool) $input->getOption('force');
        $incremental = (bool) $input->getOption('incremental');
        $plan = (bool) $input->getOption('plan') || (bool) $input->getOption('dry-run');
        $asJson = (bool) $input->getOption('json');

        $slug = $this->normalizeSlug($name !== '' ? $name : ($table !== '' ? $table : $fromProject));
        if ($slug === '' || !preg_match('/^[a-z][a-z0-9_-]*$/', $slug)) {
            return $this->failResult($output, $asJson, '请通过 --name（如 guestbook）或 --table 指定有效的前台页面标识');
        }
        if (in_array($slug, ['admin', 'adminapi', 'api', 'install', 'index', 'uploads', 'plugins'], true)) {
            return $this->failResult($output, $asJson, "页面标识 [{$slug}] 为系统保留路径，请更换其他名称");
        }

        $scaffold = new CrudScaffoldBuilder();
        $ctrlClass = $this->studly($slug);
        $title = $customTitle !== '' ? $customTitle : $ctrlClass;

        $bindEntity = $fromProject !== '' ? $fromProject : ($table !== '' ? $table : ($inlineFieldsSpec !== '' ? $slug : ''));
        $hasApi = !$withoutApi && ($bindEntity !== '');
        $pk = 'id';
        $publicReadFields = [];
        $submitFields = [];
        $searchFields = [];
        $fieldDetails = [];
        $hasStatus = false;
        $modelClass = '';
        $validateClass = '';
        $advisories = [];
        $resolvedTable = '';

        if ($hasApi) {
            $declExplicitPublic = null;
            $declExplicitSubmit = null;
            if ($inlineFieldsSpec !== '' && $fromProject === '') {
                try {
                    $parsedInline = $scaffold->parseInlineFields($inlineFieldsSpec, 'id', true);
                } catch (Throwable $e) {
                    return $this->failResult($output, $asJson, $e->getMessage());
                }
                $resolvedTable = $table !== '' ? $table : $slug;
                $resolvedTitle = '';
                $pk = $parsedInline['pk'];
                $fields = $parsedInline['fields'];
                $fieldDetails = $parsedInline['field_details'];
            } else {
                [$resolvedTable, $resolvedTitle, $pk, $fields, $fieldDetails, $declMeta] = $this->resolveEntityMeta($fromProject, $table);
                if (is_array($declMeta['public_fields'] ?? null)) {
                    $declExplicitPublic = array_values(array_map('strval', $declMeta['public_fields']));
                }
                if (is_array($declMeta['submit_fields'] ?? null)) {
                    $declExplicitSubmit = array_values(array_map('strval', $declMeta['submit_fields']));
                }
            }
            if ($resolvedTable === '' || empty($fields)) {
                return $this->failResult($output, $asJson, "未能从数据表或 hw.project.json 读取到实体 [{$bindEntity}] 的字段结构，可通过 --fields 内联定义");
            }
            if ($customTitle === '' && $resolvedTitle !== '') {
                $title = $resolvedTitle;
            }

            $secretPattern = '/(password|pass|pwd|salt|token|secret|access_token|refresh_token|remember_token|auth_key|private_key|api_key)/i';
            $ownershipPattern = '/(tenant_id|admin_id|user_id)/i';
            $privilegePattern = '/(rules|permissions|role_id|role_ids|group_id|group_ids|is_admin|is_super|money|balance|score|credit|quota)/i';
            $systemCols = [$pk, 'create_time', 'update_time', 'delete_time'];

            $allowFields = [];
            foreach ($fields as $f) {
                if (in_array($f, $systemCols, true)) {
                    continue;
                }
                if (preg_match($secretPattern, $f) || preg_match($ownershipPattern, $f) || preg_match($privilegePattern, $f)) {
                    continue;
                }
                $allowFields[] = $f;
            }

            $explicitPublicFields = $scaffold->parseFieldListOption($publicFieldsOpt) ?? $declExplicitPublic;
            $explicitSubmitFields = $scaffold->parseFieldListOption($submitFieldsOpt) ?? $declExplicitSubmit;

            $hasStatus = in_array('status', $fields, true);
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
            $advisories[] = '前台接口白名单：公开只读 $publicFields=[' . implode(', ', $publicReadFields) . ']；访客提交 $submitFields=[' . implode(', ', $submitFields) . ']（可用 --public-fields / --submit-fields 分别配置只展不收与只收不展字段）';

            foreach (['title', 'name', 'nickname', 'content', 'summary'] as $candidate) {
                if (in_array($candidate, $publicReadFields, true)) {
                    $searchFields[] = $candidate;
                }
            }
            if (!$searchFields) {
                $nonPkPublic = array_values(array_diff($publicReadFields, [$pk, 'create_time']));
                if ($nonPkPublic) {
                    $searchFields = [$nonPkPublic[0]];
                }
            }

            $studlyTable = $this->studly($resolvedTable);
            $modelClass = 'app\\common\\model\\' . $studlyTable;
            $validateClass = 'app\\common\\validate\\' . $studlyTable;
        }

        $root = rtrim(root_path(), DIRECTORY_SEPARATOR);
        $pageCtrlFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . $ctrlClass . '.php';
        $pageViewFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'index.html';
        $indexRouteFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR . 'app.php';
        $portalViewFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'portal' . DIRECTORY_SEPARATOR . 'index.html';
        $publicApiFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . $ctrlClass . '.php';

        $planned = [$pageCtrlFile, $pageViewFile];
        if ($hasApi) {
            $planned[] = $publicApiFile;
        }

        $filesToCreate = [];
        $filesToUpdate = [];
        $conflicts = [];
        foreach ($planned as $f) {
            if (is_file($f)) {
                if ($incremental || $force) {
                    $filesToUpdate[] = $f;
                } else {
                    $conflicts[] = $f;
                }
            } else {
                $filesToCreate[] = $f;
            }
        }
        $filesToUpdate[] = $indexRouteFile;
        if ($withNav && is_file($portalViewFile)) {
            $filesToUpdate[] = $portalViewFile;
        }

        if ($withNav) {
            $advisories[] = "已启用 --with-nav：将同步把「{$title}」(/{$slug}) 入口链接挂载至默认门户首页（app/index/view/portal/index.html）";
        } else {
            $advisories[] = "已注册独立前台路由 /{$slug}（默认门户首页 / 不会自动挂载导航入口；如需挂载可追加 --with-nav，或按需在 app/index/view/portal/index.html / public/site/index.html 添加链接）";
        }

        if ($plan) {
            $payload = [
                'status'          => empty($conflicts) ? 'ready' : 'conflict',
                'slug'            => $slug,
                'url'             => '/' . $slug,
                'api_base'        => $hasApi ? "/api/{$slug}" : null,
                'theme'           => $theme,
                'files_to_create' => $filesToCreate,
                'files_to_update' => $filesToUpdate,
                'conflicts'       => $conflicts,
                'advisories'      => $advisories,
            ];
            if ($asJson) {
                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $output->info("=== [Plan] 前台页面生成计划 (/{$slug}) ===");
                foreach ($filesToCreate as $f) {
                    $output->writeln("  + {$f}");
                }
                foreach ($filesToUpdate as $f) {
                    $output->writeln("  ~ {$f}");
                }
                foreach ($advisories as $adv) {
                    $output->warning("[页面建议] {$adv}");
                }
            }
            return empty($conflicts) ? 0 : 1;
        }

        if (!$force && !$incremental && !empty($conflicts)) {
            return $this->failResult(
                $output,
                $asJson,
                '目标文件已存在（追加 --incremental 保留手写保护区或 --force 覆盖）：' . implode(', ', $conflicts),
                ['conflicts' => $conflicts]
            );
        }

        $created = [];
        $updated = [];

        $pageCtrlCode = $scaffold->renderForegroundPageController($ctrlClass, $slug, $title, $theme);
        $pageViewCode = $scaffold->renderForegroundPageView(
            $slug,
            $title,
            $publicReadFields,
            $submitFields,
            $fieldDetails,
            $pk,
            $theme,
            $hasApi
        );

        $this->writeManagedFile($pageCtrlFile, $pageCtrlCode, $incremental, $scaffold, $created, $updated);
        $this->writeManagedFile($pageViewFile, $pageViewCode, $incremental, $scaffold, $created, $updated);

        if ($hasApi) {
            // 若绑定模型尚未生成，兜底提示用户或生成最小基础模型以便接口立即可跑通
            $modelFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . $this->studly($resolvedTable) . '.php';
            if (!is_file($modelFile)) {
                $hasCreate = in_array('create_time', array_keys($fieldDetails), true);
                $hasUpdate = in_array('update_time', array_keys($fieldDetails), true);
                $modelCode = $this->renderMinimalModel($this->studly($resolvedTable), $resolvedTable, $pk, $hasCreate, $hasUpdate);
                $this->writeManagedFile($modelFile, $modelCode, false, $scaffold, $created, $updated);
            }

            $publicApiCode = $scaffold->renderPublicApiController(
                $ctrlClass,
                $modelClass,
                $validateClass,
                $publicReadFields,
                $submitFields,
                $searchFields,
                $pk,
                $hasStatus
            );
            $this->writeManagedFile($publicApiFile, $publicApiCode, $incremental, $scaffold, $created, $updated);
        }

        $existingRoute = is_file($indexRouteFile)
            ? (string) file_get_contents($indexRouteFile)
            : "<?php\ndeclare(strict_types=1);\n\nuse think\\facade\\Route;\n\nRoute::get('/', 'Index/index');\n// @hw-page-routes-start\n// @hw-page-routes-end\nRoute::any(':entry', 'Index/entry')->pattern(['entry' => '^[a-zA-Z0-9_-]+$']);\n";
        $updatedRoute = $scaffold->buildIndexRouteUpdate($existingRoute, $slug, $ctrlClass);
        if ($updatedRoute !== $existingRoute || !is_file($indexRouteFile)) {
            $this->writeManagedFile($indexRouteFile, $updatedRoute, false, $scaffold, $created, $updated);
        }

        if ($withNav && is_file($portalViewFile)) {
            $existingPortal = (string) file_get_contents($portalViewFile);
            $updatedPortal = $scaffold->buildPortalNavUpdate($existingPortal, $slug, $title);
            if ($updatedPortal !== $existingPortal) {
                $this->writeManagedFile($portalViewFile, $updatedPortal, false, $scaffold, $created, $updated);
            }
        }

        $result = [
            'status'        => 'success',
            'slug'          => $slug,
            'url'           => '/' . $slug,
            'api_base'      => $hasApi ? "/api/{$slug}" : null,
            'theme'         => $theme,
            'created_files' => $created,
            'updated_files' => $updated,
            'advisories'    => $advisories,
        ];

        if ($asJson) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $output->info("✔ 前台页面 /{$slug} 脚手架生成完成！");
            foreach ($created as $f) {
                $output->writeln("  + {$f}");
            }
            foreach ($updated as $f) {
                $output->writeln("  ~ {$f}");
            }
            foreach ($advisories as $adv) {
                $output->warning("[页面建议] {$adv}");
            }
        }
        return 0;
    }

    private function writeManagedFile(
        string $path,
        string $content,
        bool $incremental,
        CrudScaffoldBuilder $scaffold,
        array &$created,
        array &$updated
    ): void {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_file($path)) {
            $final = $content;
            if ($incremental) {
                $sections = $scaffold->extractCustomSections((string) file_get_contents($path));
                $final = $scaffold->mergeCustomSections($content, $sections);
            }
            file_put_contents($path, $final);
            $updated[] = $path;
        } else {
            file_put_contents($path, $content);
            $created[] = $path;
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string[], 4: array<string, array<string, mixed>>, 5: array<string, mixed>}
     */
    private function resolveEntityMeta(string $fromProject, string $table): array
    {
        $schemaService = new ProjectSchemaService($this->app);
        if ($fromProject !== '') {
            $projectData = $schemaService->load();
            $decl = $projectData['entities'][$fromProject] ?? null;
            if (is_array($decl)) {
                return $this->extractFromDecl((string) ($decl['table'] ?? $fromProject), $decl);
            }
        }

        if ($table !== '') {
            $decl = null;
            $projectData = $schemaService->load();
            foreach ($projectData['entities'] ?? [] as $k => $ent) {
                if ($k === $table || ($ent['table'] ?? '') === $table) {
                    $decl = $ent;
                    break;
                }
            }
            try {
                $fieldDetails = Db::name($table)->getFields();
                if (!empty($fieldDetails)) {
                    $rawPk = Db::name($table)->getPk();
                    $pk = is_array($rawPk) ? (string) ($rawPk[0] ?? 'id') : (string) ($rawPk ?: 'id');
                    return [$table, is_array($decl) ? (string) ($decl['title'] ?? '') : '', $pk, array_keys($fieldDetails), $fieldDetails, is_array($decl) ? $decl : []];
                }
            } catch (Throwable) {
            }

            if (is_array($decl)) {
                return $this->extractFromDecl((string) ($decl['table'] ?? $table), $decl);
            }
        }

        return ['', '', 'id', [], [], []];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string[], 4: array<string, array<string, mixed>>, 5: array<string, mixed>}
     */
    private function extractFromDecl(string $table, array $decl): array
    {
        $pk = (string) ($decl['primary_key'] ?? 'id');
        $title = (string) ($decl['title'] ?? '');
        $fields = [];
        $details = [];
        foreach ((array) ($decl['fields'] ?? []) as $f) {
            $fName = (string) ($f['name'] ?? '');
            if ($fName === '') {
                continue;
            }
            $fields[] = $fName;
            $details[$fName] = [
                'name'    => $fName,
                'type'    => $f['type'] ?? 'string',
                'length'  => $f['length'] ?? null,
                'notnull' => empty($f['nullable']),
                'default' => $f['default'] ?? null,
                'comment' => $f['title'] ?? '',
                'primary' => !empty($f['primary']) || $fName === $pk,
            ];
        }
        if (!in_array($pk, $fields, true)) {
            array_unshift($fields, $pk);
        }
        return [$table, $title, $pk, $fields, $details, $decl];
    }

    private function renderMinimalModel(string $class, string $table, string $pk, bool $hasCreate, bool $hasUpdate): string
    {
        $autoTs = ($hasCreate || $hasUpdate) ? "protected \$autoWriteTimestamp = 'int';" : 'protected $autoWriteTimestamp = false;';
        $createAttr = $hasCreate ? "'create_time'" : 'false';
        $updateAttr = $hasUpdate ? "'update_time'" : 'false';
        $pkLine = $pk !== 'id' ? "\n    protected \$pk = '{$pk}';" : '';

        return <<<PHP
<?php
declare(strict_types=1);

namespace app\common\model;

use core\base\UnixTimeModel;

class {$class} extends UnixTimeModel
{
    protected \$name = '{$table}';{$pkLine}
    {$autoTs}
    protected \$createTime = {$createAttr};
    protected \$updateTime = {$updateAttr};
}
PHP;
    }

    private function normalizeSlug(string $raw): string
    {
        $clean = strtolower(trim($raw, " \t\n\r\0\x0B/"));
        return (string) preg_replace('/[^a-z0-9_-]+/', '_', $clean);
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private function failResult(Output $output, bool $asJson, string $msg, array $extra = []): int
    {
        if ($asJson) {
            $output->writeln((string) json_encode(['error' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $output->error($msg);
        }
        return 1;
    }
}
