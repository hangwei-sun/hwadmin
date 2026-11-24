<?php
declare(strict_types=1);

namespace core\support;

use core\plugin\PluginManager;
use ReflectionClass;
use ReflectionMethod;
use think\App;
use think\facade\Db;
use Throwable;

/**
 * 项目结构与路由自省器（供 php think hw:inspect、OpenApiExporter 与 AI Agent 共用）
 */
final class RouteInspector
{
    public const SCHEMA_VERSION = '2.0.0';

    public const VALID_SECTIONS = ['framework', 'database', 'routes', 'auth_rules', 'plugins'];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * 采集完整的项目自省快照
     *
     * @param string[]             $sections 限定返回区块（framework|database|routes|auth_rules|plugins），空表示全部
     * @param string               $table    仅输出指定数据表结构（不含或含前缀均可）
     * @param array<string, mixed> $filters  扩展过滤（module, controller, plugin, with_counts 等）
     * @return array<string, mixed>
     */
    public function inspect(array $sections = [], string $table = '', array $filters = []): array
    {
        $all = empty($sections);
        $want = static fn (string $key): bool => $all || in_array($key, $sections, true);

        $errors = [];
        $warnings = [];
        $sources = [
            'framework' => 'config:hwadmin + runtime:php',
            'database'  => 'pdo:connection',
            'routes'    => 'reflection:filesystem',
            'plugins'   => 'filesystem:plugin.json + db:plugin',
        ];

        $withCounts = (bool) ($filters['with_counts'] ?? false);
        $moduleFilter = (string) ($filters['module'] ?? '');
        $controllerFilter = (string) ($filters['controller'] ?? '');
        $pluginFilter = (string) ($filters['plugin'] ?? '');
        $tableFilter = $table !== '' ? $table : (string) ($filters['table'] ?? '');

        $result = [];

        if ($want('framework')) {
            $result['framework'] = $this->inspectFramework();
        }

        if ($want('database')) {
            $dbInfo = $this->inspectDatabase($tableFilter, $withCounts);
            $result['database'] = $dbInfo;
            if (!$dbInfo['connected']) {
                $warnings[] = '数据库未连接或尚未安装，已跳过表结构物理校验';
            } elseif ($dbInfo['status'] === 'query_failed') {
                $errors[] = '数据库表结构扫描异常: ' . ($dbInfo['error'] ?? 'unknown error');
            }
        }

        if ($want('routes')) {
            try {
                $result['routes'] = $this->inspectRoutes($moduleFilter, $controllerFilter, $pluginFilter);
            } catch (Throwable $e) {
                $errors[] = '路由反射扫描失败: ' . $e->getMessage();
                $result['routes'] = ['adminapi' => [], 'api' => [], 'index' => [], 'plugins' => []];
            }
        }

        if ($want('auth_rules')) {
            $authData = $this->inspectAuthRules();
            $result['auth_rules'] = $authData['rules'];
            if ($authData['status'] === 'not_connected') {
                $warnings[] = '数据表 auth_rule 无法连接或尚未初始化';
            }
        }

        if ($want('plugins')) {
            try {
                $result['plugins'] = $this->inspectPlugins($pluginFilter);
            } catch (Throwable $e) {
                $warnings[] = '插件清单扫描警告: ' . $e->getMessage();
                $result['plugins'] = [];
            }
        }

        $status = 'complete';
        if (!empty($errors)) {
            $status = 'degraded';
        } elseif (!empty($warnings)) {
            $status = 'partial';
        }

        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status'         => $status,
            'errors'         => $errors,
            'warnings'       => $warnings,
            'sources'        => $sources,
        ], $result);
    }

    public function inspectFramework(): array
    {
        return [
            'name'              => 'HwAdmin',
            'app_id'            => (string) config('hwadmin.app_id', 'hwadmin-app'),
            'app_name'          => (string) config('hwadmin.app_name', 'HwAdmin Application'),
            'app_version'       => (string) config('hwadmin.app_version', '1.0.0'),
            'version'           => (string) config('hwadmin.version', '1.6.1'),
            'framework_version' => (string) config('hwadmin.version', '1.6.1'),
            'php_version'       => PHP_VERSION,
            'app_env'           => (string) env('APP_ENV', ''),
            'app_debug'         => $this->app->isDebug(),
            'installed'         => is_installed(),
            'root_path'         => $this->app->getRootPath(),
        ];
    }

    public function inspectDatabase(string $onlyTable = '', bool $withCounts = false): array
    {
        $driver = strtolower((string) (Db::connect()->getConfig('type') ?: env('DB_DRIVER', 'mysql')));
        $prefix = (string) (Db::connect()->getConfig('prefix') ?: 'hw_');
        $database = (string) (Db::connect()->getConfig('database') ?: '');

        $info = [
            'connected'   => false,
            'status'      => 'not_connected',
            'driver'      => $driver,
            'database'    => $database,
            'prefix'      => $prefix,
            'with_counts' => $withCounts,
            'tables'      => [],
        ];

        try {
            Db::connect()->getPdo();
            $info['connected'] = true;

            $tableNames = $this->listTableNames($driver);
            if (empty($tableNames)) {
                $info['status'] = 'empty';
                return $info;
            }

            $info['status'] = 'connected';

            $filterClean = '';
            if ($onlyTable !== '') {
                $filterClean = ($prefix !== '' && str_starts_with($onlyTable, $prefix))
                    ? substr($onlyTable, strlen($prefix))
                    : $onlyTable;
            }

            foreach ($tableNames as $fullTable) {
                $clean = ($prefix !== '' && str_starts_with($fullTable, $prefix))
                    ? substr($fullTable, strlen($prefix))
                    : $fullTable;

                if ($filterClean !== '' && $clean !== $filterClean && $fullTable !== $onlyTable) {
                    continue;
                }

                $fields = [];
                $pk = 'id';
                $rowCount = null;
                try {
                    $rawFields = Db::table($fullTable)->getFields();
                    foreach ($rawFields as $colName => $meta) {
                        $isPk = !empty($meta['primary']);
                        if ($isPk) {
                            $pk = (string) $colName;
                        }
                        $fields[] = [
                            'name'    => (string) $colName,
                            'type'    => (string) ($meta['type'] ?? ''),
                            'notnull' => (bool) ($meta['notnull'] ?? false),
                            'default' => $meta['default'] ?? null,
                            'primary' => $isPk,
                        ];
                    }

                    if ($withCounts) {
                        $rowCount = (int) Db::table($fullTable)->count();
                    }
                } catch (Throwable) {
                }

                $info['tables'][] = [
                    'name'       => $fullTable,
                    'clean_name' => $clean,
                    'pk'         => $pk,
                    'rows'       => $rowCount,
                    'columns'    => $fields,
                ];
            }
        } catch (Throwable $e) {
            $info['status'] = $info['connected'] ? 'query_failed' : 'not_connected';
            $info['error'] = $e->getMessage();
        }

        return $info;
    }

    public function inspectRoutes(string $moduleFilter = '', string $controllerFilter = '', string $pluginFilter = ''): array
    {
        $root = $this->app->getRootPath();
        $routes = [
            'adminapi' => [],
            'api'      => [],
            'index'    => [],
            'plugins'  => [],
        ];

        if ($moduleFilter === '' || $moduleFilter === 'adminapi') {
            $routes['adminapi'] = $this->scanControllerTree(
                $root . 'app/adminapi/controller',
                'app\\adminapi\\controller',
                'adminapi',
                '',
                $controllerFilter
            );
        }

        if ($moduleFilter === '' || $moduleFilter === 'api') {
            $routes['api'] = $this->scanControllerTree(
                $root . 'app/api/controller',
                'app\\api\\controller',
                'api',
                '',
                $controllerFilter
            );
        }

        if ($moduleFilter === '' || $moduleFilter === 'index') {
            $routes['index'] = $this->scanIndexRoutes($root, $controllerFilter);
        }

        if ($moduleFilter === '' || $moduleFilter === 'plugins') {
            $pluginsDir = plugin_path();
            if (is_dir($pluginsDir)) {
                $manager = $this->app->make(PluginManager::class);
                $enabledSet = array_flip($manager->enabledNames());

                foreach (scandir($pluginsDir) ?: [] as $pluginName) {
                    if ($pluginName === '.' || $pluginName === '..' || !is_dir($pluginsDir . $pluginName)) {
                        continue;
                    }
                    if ($pluginFilter !== '' && $pluginName !== $pluginFilter) {
                        continue;
                    }
                    if (!isset($enabledSet[$pluginName])) {
                        continue;
                    }

                    $adminDir = $pluginsDir . $pluginName . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'admin';
                    $apiDir = $pluginsDir . $pluginName . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'api';
                    $pluginRoutes = [
                        'adminapi' => $this->scanControllerTree($adminDir, "plugins\\{$pluginName}\\controller\\admin", 'plugin_admin', $pluginName, $controllerFilter),
                        'api'      => $this->scanControllerTree($apiDir, "plugins\\{$pluginName}\\controller\\api", 'plugin_api', $pluginName, $controllerFilter),
                    ];
                    if (!empty($pluginRoutes['adminapi']) || !empty($pluginRoutes['api'])) {
                        $routes['plugins'][$pluginName] = $pluginRoutes;
                    }
                }
            }
        }

        return $routes;
    }

    /**
     * 采集 app/index/route/*.php 与自定义前台控制器单页路由
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanIndexRoutes(string $root, string $controllerFilter = ''): array
    {
        $items = [];
        $seen = [];
        $routeDir = $root . 'app' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'route';
        $routeFiles = is_dir($routeDir) ? (glob($routeDir . DIRECTORY_SEPARATOR . '*.php') ?: []) : [];
        sort($routeFiles);

        foreach ($routeFiles as $file) {
            $content = (string) @file_get_contents($file);
            if ($content === '') {
                continue;
            }
            $relSource = 'app/index/route/' . basename($file);
            if (preg_match_all('/Route::(get|post|put|delete|patch|any|rule)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $httpMethod = strtoupper($m[1]);
                    $rawUri = trim($m[2]);
                    $target = trim($m[3]);
                    if ($controllerFilter !== '' && !str_contains($target, $controllerFilter) && !str_contains($rawUri, $controllerFilter)) {
                        continue;
                    }
                    $url = $rawUri === '/' || $rawUri === '' ? '/' : ('/' . ltrim($rawUri, '/'));
                    $key = $httpMethod . ' ' . $url . ' ' . $target;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $parts = explode('/', $target, 2);
                    $items[] = [
                        'url'        => $url,
                        'methods'    => [$httpMethod === 'ANY' || $httpMethod === 'RULE' ? 'GET/POST' : $httpMethod],
                        'controller' => 'app\\index\\controller\\' . ($parts[0] ?? 'Index'),
                        'action'     => $parts[1] ?? 'index',
                        'target'     => $target,
                        'dynamic'    => str_contains($rawUri, ':') || str_contains($rawUri, '<'),
                        'source'     => $relSource,
                    ];
                }
            }
        }

        return $items;
    }

    public function inspectAuthRules(): array
    {
        try {
            $rows = Db::name('auth_rule')
                ->field('id, pid, type, name, title, path, component, icon, weight, hidden, plugin, status')
                ->order('weight', 'desc')
                ->order('id', 'asc')
                ->select()
                ->toArray();

            return [
                'status' => empty($rows) ? 'empty' : 'connected',
                'rules'  => $rows,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'not_connected',
                'error'  => $e->getMessage(),
                'rules'  => [],
            ];
        }
    }

    public function inspectPlugins(string $pluginFilter = ''): array
    {
        try {
            /** @var PluginManager $manager */
            $manager = $this->app->make(PluginManager::class);
            $all = $manager->all();
            if ($pluginFilter !== '') {
                return array_values(array_filter($all, static fn ($p) => ($p['name'] ?? '') === $pluginFilter));
            }
            return $all;
        } catch (Throwable) {
            return $this->scanStaticPlugins($pluginFilter);
        }
    }

    private function scanStaticPlugins(string $pluginFilter = ''): array
    {
        $pluginsDir = plugin_path();
        if (!is_dir($pluginsDir)) {
            return [];
        }
        $list = [];
        foreach (scandir($pluginsDir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($pluginsDir . $name)) {
                continue;
            }
            if ($pluginFilter !== '' && $name !== $pluginFilter) {
                continue;
            }
            $jsonFile = $pluginsDir . $name . DIRECTORY_SEPARATOR . 'plugin.json';
            if (is_file($jsonFile)) {
                $meta = json_decode((string) file_get_contents($jsonFile), true) ?: [];
                $list[] = [
                    'name'        => $name,
                    'title'       => $meta['title'] ?? $name,
                    'version'     => $meta['version'] ?? '1.0.0',
                    'description' => $meta['description'] ?? '',
                    'installed'   => false,
                    'enabled'     => false,
                ];
            }
        }
        return $list;
    }

    /**
     * @return string[]
     */
    private function listTableNames(string $driver): array
    {
        if ($driver === 'sqlite') {
            $rows = Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC");
            return array_values(array_filter(array_map(static fn ($r) => (string) ($r['name'] ?? ''), $rows)));
        }
        if ($driver === 'pgsql') {
            $rows = Db::query("SELECT tablename AS name FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename ASC");
            return array_values(array_filter(array_map(static fn ($r) => (string) ($r['name'] ?? ''), $rows)));
        }
        $rows = Db::query('SHOW TABLES');
        $names = [];
        foreach ($rows as $row) {
            $val = reset($row);
            if (is_string($val) && $val !== '') {
                $names[] = $val;
            }
        }
        sort($names);
        return $names;
    }

    private function scanControllerTree(string $dir, string $baseNamespace, string $kind, string $pluginName = '', string $controllerFilter = ''): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $items = [];
        $this->walkControllers($dir, $baseNamespace, '', $kind, $pluginName, $controllerFilter, $items);
        return $items;
    }

    private function walkControllers(
        string $dir,
        string $baseNamespace,
        string $subNs,
        string $kind,
        string $pluginName,
        string $controllerFilter,
        array &$items
    ): void {
        $entries = scandir($dir);
        if (!$entries) {
            return;
        }
        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($fullPath)) {
                $nextSub = $subNs === '' ? $entry : ($subNs . '\\' . $entry);
                $this->walkControllers($fullPath, $baseNamespace, $nextSub, $kind, $pluginName, $controllerFilter, $items);
                continue;
            }
            if (!str_ends_with($entry, '.php')) {
                continue;
            }

            $shortClass = substr($entry, 0, -4);
            $fqcn = $baseNamespace . ($subNs === '' ? '' : '\\' . $subNs) . '\\' . $shortClass;

            if ($controllerFilter !== '' && !str_contains($fqcn, $controllerFilter) && !str_contains($shortClass, $controllerFilter)) {
                continue;
            }

            if (!class_exists($fqcn)) {
                continue;
            }

            $parsed = $this->reflectController($fqcn, $subNs, $shortClass, $kind, $pluginName);
            if ($parsed !== null) {
                $items[] = $parsed;
            }
        }
    }

    private function reflectController(
        string $fqcn,
        string $subNs,
        string $shortClass,
        string $kind,
        string $pluginName
    ): ?array {
        try {
            $ref = new ReflectionClass($fqcn);
            if ($ref->isAbstract() || $ref->isInterface()) {
                return null;
            }

            $noNeedLogin = $this->readArrayProperty($ref, 'noNeedLogin');
            $noNeedRight = $this->readArrayProperty($ref, 'noNeedRight');
            $allowFields = $this->readArrayProperty($ref, 'allowFields');
            $addAllowFields = $this->readArrayProperty($ref, 'addAllowFields');
            $editAllowFields = $this->readArrayProperty($ref, 'editAllowFields');
            $searchFields = $this->readArrayProperty($ref, 'searchFields');
            $filterFields = $this->readArrayProperty($ref, 'filterFields');
            $sortableFields = $this->readArrayProperty($ref, 'sortableFields');
            $validateClass = $this->readStringProperty($ref, 'validateClass');
            $validatorRules = $this->extractValidatorRules($validateClass);

            $mergedAllowFields = $allowFields ?: array_values(array_unique(array_merge($addAllowFields, $editAllowFields)));
            $classDoc = (string) $ref->getDocComment();

            $snakeParts = [];
            if ($subNs !== '') {
                foreach (explode('\\', $subNs) as $seg) {
                    $snakeParts[] = $this->snake($seg);
                }
            }
            $snakeParts[] = $this->snake($shortClass);

            $dotCtrl = implode('.', $snakeParts);
            $slashCtrl = implode('/', $snakeParts);

            $isAdmin = in_array($kind, ['adminapi', 'plugin_admin'], true);
            $isPlugin = str_starts_with($kind, 'plugin_');

            $urlPrefix = $isPlugin
                ? ($isAdmin ? "/adminapi/plugin/{$pluginName}/{$dotCtrl}" : "/api/plugin/{$pluginName}/{$dotCtrl}")
                : ($isAdmin ? "/adminapi/{$dotCtrl}" : "/api/{$dotCtrl}");

            $nodePrefix = $isAdmin
                ? ($isPlugin ? "plugin/{$pluginName}/{$slashCtrl}" : $slashCtrl)
                : '';

            $exclude = ['__construct', 'initialize', 'validate', 'renderException', 'batch', 'rule'];
            $endpoints = [];
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $action = $method->getName();
                if ($method->isStatic() || in_array($action, $exclude, true) || str_starts_with($action, '_')) {
                    continue;
                }
                if (str_starts_with($method->getDeclaringClass()->getName(), 'core\\')) {
                    continue;
                }

                $lowerAction = strtolower($action);

                // 显式免登录判定 vs 约定
                $explicitNoLogin = in_array($action, $noNeedLogin, true) || in_array($lowerAction, array_map('strtolower', $noNeedLogin), true);
                $wildcardNoLogin = in_array('*', $noNeedLogin, true);
                $needLogin = !$wildcardNoLogin && !$explicitNoLogin;
                $loginSource = ($explicitNoLogin || $wildcardNoLogin) ? 'explicit' : 'convention';

                // 显式免权限判定 vs 约定
                $explicitNoRight = in_array($action, $noNeedRight, true) || in_array($lowerAction, array_map('strtolower', $noNeedRight), true);
                $wildcardNoRight = in_array('*', $noNeedRight, true);
                $needRight = $isAdmin && $needLogin && !$wildcardNoRight && !$explicitNoRight;
                $rightSource = ($explicitNoRight || $wildcardNoRight) ? 'explicit' : 'convention';

                $doc = (string) $method->getDocComment();
                $bodyCode = $this->readMethodBodyCode($method);

                // HTTP 动词推导（优先方法注解 -> 方法/类文档路由表 -> 方法体内真实谓词 -> 动作命名约定）
                [$httpMethod, $methodSource] = $this->resolveHttpMethod($action, $doc, $classDoc, $bodyCode);

                // 提取请求参数与验证规则契约
                $requestParams = $this->extractEndpointParams(
                    $action,
                    $httpMethod,
                    $bodyCode,
                    $mergedAllowFields,
                    $addAllowFields,
                    $editAllowFields,
                    $validatorRules
                );

                $ep = [
                    'action'         => $action,
                    'method'         => $httpMethod,
                    'source'         => $methodSource,
                    'method_source'  => $methodSource,
                    'url'            => "{$urlPrefix}/{$action}",
                    'need_login'     => $needLogin,
                    'login_source'   => $loginSource,
                    'title'          => $this->docTitle($doc, $action),
                    'request_params' => $requestParams,
                ];
                if ($isAdmin) {
                    $ep['need_right'] = $needRight;
                    $ep['right_source'] = $rightSource;
                    $ep['permission_node'] = "{$nodePrefix}/{$lowerAction}";
                }
                $endpoints[] = $ep;
            }

            if (!$endpoints) {
                return null;
            }

            return [
                'class'           => $fqcn,
                'title'           => $this->docTitle($classDoc, $shortClass),
                'url_prefix'      => $urlPrefix,
                'node_prefix'     => $nodePrefix,
                'allow_fields'    => $mergedAllowFields,
                'search_fields'   => $searchFields,
                'filter_fields'   => $filterFields,
                'sortable_fields' => $sortableFields,
                'validate_class'  => $validateClass,
                'endpoints'       => $endpoints,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 解析方法真实 HTTP 动词与来源 (explicit | convention)
     *
     * @return array{0: string, 1: string}
     */
    private function resolveHttpMethod(string $action, string $methodDoc, string $classDoc, string $bodyCode): array
    {
        // 1. 方法级 @method 注解
        if (preg_match('/@method\s+(GET|POST|PUT|DELETE|PATCH)/i', $methodDoc, $mMatch)) {
            return [strtoupper($mMatch[1]), 'explicit'];
        }

        // 2. 方法级文档注释中的 "POST /xxx/action" 声明
        if (preg_match('/(?:^|\s|\*)(GET|POST|PUT|DELETE|PATCH)\s+\/\S+/im', $methodDoc, $mMatch)) {
            return [strtoupper($mMatch[1]), 'explicit'];
        }

        // 3. 方法体内真实 HTTP 动词守卫 ($this->request->isPost() / $this->ensurePost() 等)
        if ($bodyCode !== '') {
            if (preg_match('/\$this->ensurePost\s*\(|\$this->request->isPost\s*\(/i', $bodyCode)) {
                return ['POST', 'explicit'];
            }
            if (preg_match('/\$this->request->isPut\s*\(/i', $bodyCode)) {
                return ['PUT', 'explicit'];
            }
            if (preg_match('/\$this->request->isDelete\s*\(/i', $bodyCode)) {
                return ['DELETE', 'explicit'];
            }
            if (preg_match('/\$this->request->isPatch\s*\(/i', $bodyCode)) {
                return ['PATCH', 'explicit'];
            }
            if (preg_match('/\$this->request->isGet\s*\(/i', $bodyCode)) {
                return ['GET', 'explicit'];
            }
        }

        // 4. 控制器类级别文档注释路由表中的 "POST /.../action" 声明
        if ($classDoc !== '' && preg_match('/(?:^|\s|\*)(GET|POST|PUT|DELETE|PATCH)\s+\/\S+\/' . preg_quote($action, '/') . '\b/im', $classDoc, $cMatch)) {
            return [strtoupper($cMatch[1]), 'explicit'];
        }

        // 5. 动作命名约定回退
        if (
            preg_match('/^(add|edit|del|delete|remove|multi|save|update|upload|import|export|post|run|execute|submit|apply|cancel|confirm|audit|approve|reject|bind|unbind|send|request|verify|sync|publish|clear|optimize|restore|backup|install|uninstall|enable|disable|upgrade|login|logout|register|reset)/i', $action)
            || preg_match('/(Login|Logout|Register|Reset|Code|Upload|Submit|Save|Update|Delete|Create|Add|Edit|Send|Sync|Run|Apply|Cancel)$/i', $action)
        ) {
            return ['POST', 'convention'];
        }

        return ['GET', 'convention'];
    }

    private function readMethodBodyCode(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if (!is_string($file) || !is_file($file) || !is_int($start) || !is_int($end) || $end < $start) {
            return '';
        }
        $lines = @file($file);
        if (!is_array($lines)) {
            return '';
        }
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * 提取接口请求参数与校验规则
     *
     * @param string[]             $mergedAllowFields
     * @param string[]             $addAllowFields
     * @param string[]             $editAllowFields
     * @param array<string, mixed> $validatorRules
     * @return array<int, array<string, mixed>>
     */
    private function extractEndpointParams(
        string $action,
        string $httpMethod,
        string $bodyCode,
        array $mergedAllowFields,
        array $addAllowFields,
        array $editAllowFields,
        array $validatorRules
    ): array {
        $params = [];

        // 1. 解析方法体内的 $this->validate($data, [ 'field|标题' => 'require|...' ])
        if ($bodyCode !== '' && preg_match('/\$this->validate\s*\([^,]+,\s*\[(.*?)\]/s', $bodyCode, $vMatch)) {
            $ruleBlock = $vMatch[1];
            if (preg_match_all('/[\'"]([a-zA-Z0-9_]+)(?:\|([^\'"]+))?[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $ruleBlock, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $name = $m[1];
                    $label = $m[2] !== '' ? $m[2] : $name;
                    $ruleStr = $m[3];
                    $params[$name] = [
                        'name'     => $name,
                        'title'    => $label,
                        'in'       => $httpMethod === 'GET' ? 'query' : 'body',
                        'type'     => $this->inferTypeFromRule($name, $ruleStr),
                        'required' => str_contains($ruleStr, 'require'),
                        'rule'     => $ruleStr,
                    ];
                }
            }
        }

        // 2. 解析方法体内的 $this->request->only(['a', 'b'])
        if ($bodyCode !== '' && preg_match('/\$this->request->only\s*\(\s*\[(.*?)\]\s*\)/s', $bodyCode, $oMatch)) {
            if (preg_match_all('/[\'"]([a-zA-Z0-9_]+)[\'"]/', $oMatch[1], $fMatches)) {
                foreach ($fMatches[1] as $fieldName) {
                    if (!isset($params[$fieldName])) {
                        $params[$fieldName] = [
                            'name'     => $fieldName,
                            'title'    => $fieldName,
                            'in'       => $httpMethod === 'GET' ? 'query' : 'body',
                            'type'     => $this->inferTypeFromRule($fieldName, ''),
                            'required' => false,
                            'rule'     => '',
                        ];
                    }
                }
            }
        }

        // 3. 解析方法体内的 $this->request->post('x') / get('x') / param('x') / file('x')
        if ($bodyCode !== '') {
            if (preg_match_all('/\$this->request->(post|get|param|file)\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $bodyCode, $pMatches, PREG_SET_ORDER)) {
                foreach ($pMatches as $pm) {
                    $reqType = strtolower($pm[1]);
                    $fieldName = $pm[2];
                    if ($fieldName === 'token') {
                        continue;
                    }
                    if (!isset($params[$fieldName])) {
                        $isFile = $reqType === 'file';
                        $params[$fieldName] = [
                            'name'     => $fieldName,
                            'title'    => $fieldName,
                            'in'       => $isFile ? 'formData' : ($httpMethod === 'GET' || $reqType === 'get' ? 'query' : 'body'),
                            'type'     => $isFile ? 'file' : $this->inferTypeFromRule($fieldName, ''),
                            'required' => $isFile || $fieldName === 'code' || $fieldName === 'id',
                            'rule'     => '',
                        ];
                    }
                }
            }
        }

        // 4. CRUD 标准动作参数推导
        $lowerAction = strtolower($action);
        if ($lowerAction === 'index' && $httpMethod === 'GET') {
            foreach ([
                ['name' => 'page', 'title' => '当前页码', 'type' => 'integer', 'required' => false],
                ['name' => 'limit', 'title' => '每页数量', 'type' => 'integer', 'required' => false],
                ['name' => 'search', 'title' => '关键词快速检索', 'type' => 'string', 'required' => false],
                ['name' => 'sort', 'title' => '排序字段', 'type' => 'string', 'required' => false],
                ['name' => 'order', 'title' => '排序方向(asc/desc)', 'type' => 'string', 'required' => false],
            ] as $qp) {
                $params[$qp['name']] = array_merge($qp, ['in' => 'query', 'rule' => '']);
            }
        } elseif (in_array($lowerAction, ['read', 'detail'], true) && $httpMethod === 'GET') {
            $params['id'] = [
                'name'     => 'id',
                'title'    => '主键ID',
                'in'       => 'query',
                'type'     => 'integer',
                'required' => true,
                'rule'     => 'require|integer',
            ];
        } elseif (in_array($lowerAction, ['add', 'edit'], true) && $httpMethod === 'POST') {
            $fields = $lowerAction === 'add'
                ? ($addAllowFields ?: $mergedAllowFields)
                : ($editAllowFields ?: $mergedAllowFields);
            if ($lowerAction === 'edit' && !isset($params['id'])) {
                $params['id'] = [
                    'name'     => 'id',
                    'title'    => '主键ID',
                    'in'       => 'body',
                    'type'     => 'integer',
                    'required' => true,
                    'rule'     => 'require|integer',
                ];
            }
            foreach ($fields as $f) {
                if (!isset($params[$f])) {
                    $vMeta = $validatorRules[$f] ?? null;
                    $ruleStr = is_array($vMeta) ? (string) ($vMeta['rule'] ?? '') : '';
                    $label = is_array($vMeta) ? (string) ($vMeta['title'] ?? $f) : $f;
                    $params[$f] = [
                        'name'     => $f,
                        'title'    => $label,
                        'in'       => 'body',
                        'type'     => $this->inferTypeFromRule($f, $ruleStr),
                        'required' => $lowerAction === 'add' && str_contains($ruleStr, 'require'),
                        'rule'     => $ruleStr,
                    ];
                }
            }
        } elseif ($lowerAction === 'del' && $httpMethod === 'POST') {
            if (!isset($params['ids'])) {
                $params['ids'] = [
                    'name'     => 'ids',
                    'title'    => '待删除主键ID列表',
                    'in'       => 'body',
                    'type'     => 'string',
                    'required' => true,
                    'rule'     => 'require',
                ];
            }
        } elseif ($lowerAction === 'multi' && $httpMethod === 'POST') {
            foreach ([
                ['name' => 'ids', 'title' => '主键ID列表', 'type' => 'string', 'required' => true],
                ['name' => 'field', 'title' => '更新字段名', 'type' => 'string', 'required' => true],
                ['name' => 'value', 'title' => '更新目标值', 'type' => 'string', 'required' => true],
            ] as $mp) {
                $params[$mp['name']] = array_merge($mp, ['in' => 'body', 'rule' => 'require']);
            }
        }

        return array_values($params);
    }

    /**
     * @return array<string, array{title: string, rule: string}>
     */
    private function extractValidatorRules(?string $validateClass): array
    {
        if ($validateClass === null || $validateClass === '' || !class_exists($validateClass)) {
            return [];
        }
        try {
            $vRef = new ReflectionClass($validateClass);
            $defaults = $vRef->getDefaultProperties();
            $rawRules = is_array($defaults['rule'] ?? null) ? $defaults['rule'] : [];
            $parsed = [];
            foreach ($rawRules as $k => $r) {
                if (!is_string($k)) {
                    continue;
                }
                $parts = explode('|', $k, 2);
                $field = $parts[0];
                $title = $parts[1] ?? $field;
                $ruleStr = is_string($r) ? $r : (is_array($r) ? implode('|', array_map('strval', $r)) : '');
                $parsed[$field] = [
                    'title' => $title,
                    'rule'  => $ruleStr,
                ];
            }
            return $parsed;
        } catch (Throwable) {
            return [];
        }
    }

    private function inferTypeFromRule(string $fieldName, string $ruleStr): string
    {
        $lowerRule = strtolower($ruleStr);
        if (str_contains($lowerRule, 'integer') || preg_match('/(?:^|_)(id|status|sort|weight|pid|level|score|gender|page|limit|count|time)$/i', $fieldName)) {
            return 'integer';
        }
        if (str_contains($lowerRule, 'float') || str_contains($lowerRule, 'number') || preg_match('/(?:^|_)(amount|price|money|rate|total)$/i', $fieldName)) {
            return 'number';
        }
        if (str_contains($lowerRule, 'boolean') || str_contains($lowerRule, 'bool')) {
            return 'boolean';
        }
        if (str_contains($lowerRule, 'array')) {
            return 'array';
        }
        return 'string';
    }

    private function readArrayProperty(ReflectionClass $ref, string $name): array
    {
        if (!$ref->hasProperty($name)) {
            return [];
        }
        $defaults = $ref->getDefaultProperties();
        return is_array($defaults[$name] ?? null) ? $defaults[$name] : [];
    }

    private function readStringProperty(ReflectionClass $ref, string $name): ?string
    {
        if (!$ref->hasProperty($name)) {
            return null;
        }
        $defaults = $ref->getDefaultProperties();
        return is_string($defaults[$name] ?? null) && $defaults[$name] !== '' ? $defaults[$name] : null;
    }

    private function docTitle(string $doc, string $fallback): string
    {
        if ($doc === '') {
            return $fallback;
        }
        foreach (explode("\n", $doc) as $line) {
            $clean = trim($line, "/* \t\r\n");
            if ($clean !== '' && !str_starts_with($clean, '@') && !preg_match('/^(GET|POST|PUT|DELETE|PATCH)\s+\//i', $clean)) {
                return $clean;
            }
        }
        return $fallback;
    }

    private function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
