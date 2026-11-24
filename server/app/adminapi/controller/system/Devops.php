<?php
declare(strict_types=1);

namespace app\adminapi\controller\system;

use core\base\AdminController;
use core\exception\ApiException;
use ReflectionClass;
use ReflectionMethod;
use think\facade\App;
use think\facade\Db;

/**
 * 开发者运维中心控制器
 *
 * 节点：system/devops/{index|apiDocs|tables|backup}
 */
class Devops extends AdminController
{
    /**
     * 自动扫描并生成全栈 API 接口文档树
     * GET /adminapi/system.devops/apiDocs
     */
    public function apiDocs(): void
    {
        $rootPath = App::getRootPath();
        $scanDirs = [
            'adminapi' => $rootPath . 'app/adminapi/controller',
            'api'      => $rootPath . 'app/api/controller',
        ];

        $docs = [];

        foreach ($scanDirs as $appKey => $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $appTitle = $appKey === 'adminapi' ? '管理后台接口 (Admin API)' : '前台开放接口 (User API)';
            $appGroup = [
                'name'     => $appKey,
                'title'    => $appTitle,
                'children' => [],
            ];

            $this->scanControllerDir($dir, $appKey, '', $appGroup['children']);
            $docs[] = $appGroup;
        }

        $this->success($docs);
    }

    /**
     * 递归扫描控制器目录
     */
    private function scanControllerDir(string $dir, string $appKey, string $subNamespace, array &$result): void
    {
        $files = scandir($dir);
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fullPath = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($fullPath)) {
                $sub = ($subNamespace === '' ? '' : $subNamespace . '\\') . $file;
                $group = [
                    'name'     => $file,
                    'title'    => ucfirst($file) . ' 模块',
                    'children' => [],
                ];
                $this->scanControllerDir($fullPath, $appKey, $sub, $group['children']);
                if (!empty($group['children'])) {
                    $result[] = $group;
                }
            } elseif (str_ends_with($file, '.php')) {
                $className = substr($file, 0, -4);
                $classNamespace = "app\\{$appKey}\\controller" . ($subNamespace === '' ? '' : "\\{$subNamespace}");
                $fullClass = $classNamespace . "\\{$className}";

                if (!class_exists($fullClass)) {
                    continue;
                }

                $controllerDoc = $this->parseControllerDoc($fullClass, $appKey, $subNamespace, $className);
                if ($controllerDoc && !empty($controllerDoc['apis'])) {
                    $result[] = $controllerDoc;
                }
            }
        }
    }

    /**
     * 解析单个控制器的反射与注释
     */
    private function parseControllerDoc(string $className, string $appKey, string $subNamespace, string $shortName): ?array
    {
        try {
            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || $ref->isInterface()) {
                return null;
            }

            $classDoc = (string) $ref->getDocComment();
            $title = $this->extractDocTitle($classDoc, $shortName . ' 控制器');

            // 检查登录与权限豁免
            $noNeedLogin = [];
            $noNeedRight = [];
            if ($ref->hasProperty('noNeedLogin')) {
                $prop = $ref->getProperty('noNeedLogin');
                $prop->setAccessible(true);
                $noNeedLogin = (array) $prop->getValue($ref->newInstanceWithoutConstructor());
            }
            if ($ref->hasProperty('noNeedRight')) {
                $prop = $ref->getProperty('noNeedRight');
                $prop->setAccessible(true);
                $noNeedRight = (array) $prop->getValue($ref->newInstanceWithoutConstructor());
            }

            // 路由前缀转换，如 system/Dict => system.dict
            $routePrefix = '';
            if ($subNamespace !== '') {
                $routePrefix = strtolower(str_replace('\\', '.', $subNamespace)) . '.';
            }
            $routePrefix .= strtolower($shortName);

            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
            $apis = [];

            // 排除通用底层方法
            $excludeMethods = ['__construct', 'initialize', 'validate', 'renderException', 'batch', 'rule'];

            foreach ($methods as $method) {
                $methodName = $method->getName();
                if (in_array($methodName, $excludeMethods, true) || str_starts_with($methodName, '__')) {
                    continue;
                }
                if ($method->getDeclaringClass()->getName() === \think\App::class) {
                    continue;
                }

                $methodDoc = (string) $method->getDocComment();
                $methodTitle = $this->extractDocTitle($methodDoc, $methodName);
                $desc = $this->extractDocDescription($methodDoc);

                // 判断默认 HTTP 动词
                $httpMethod = 'GET';
                if (preg_match('/(add|edit|del|save|update|upload|import|export|post|run|clear|optimize|restore|backup)/i', $methodName)) {
                    $httpMethod = 'POST';
                }

                $path = "/{$appKey}/{$routePrefix}/{$methodName}";
                $isLoginRequired = !in_array('*', $noNeedLogin, true) && !in_array($methodName, $noNeedLogin, true);
                $isRightRequired = $isLoginRequired && !in_array('*', $noNeedRight, true) && !in_array($methodName, $noNeedRight, true);

                $apis[] = [
                    'name'            => $methodName,
                    'title'           => $methodTitle,
                    'description'     => $desc,
                    'method'          => $httpMethod,
                    'path'            => $path,
                    'need_login'      => $isLoginRequired,
                    'need_right'      => $isRightRequired,
                    'permission_node' => str_replace('.', '/', $routePrefix) . "/{$methodName}",
                ];
            }

            return [
                'name'        => $shortName,
                'title'       => $title,
                'class'       => $className,
                'routePrefix' => $routePrefix,
                'apis'        => $apis,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 提取文档注释第一行作为标题
     */
    private function extractDocTitle(string $doc, string $default): string
    {
        if ($doc === '') {
            return $default;
        }

        $lines = explode("\n", $doc);
        foreach ($lines as $line) {
            $line = trim($line, "/* \t\r\n");
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            return $line;
        }

        return $default;
    }

    /**
     * 提取完整文档描述
     */
    private function extractDocDescription(string $doc): string
    {
        if ($doc === '') {
            return '';
        }

        $cleaned = [];
        $lines = explode("\n", $doc);
        foreach ($lines as $line) {
            $line = trim($line, "/* \t\r\n");
            if ($line !== '') {
                $cleaned[] = $line;
            }
        }

        return implode("\n", $cleaned);
    }

    /**
     * 数据库表空间列表与体检
     * GET /adminapi/system.devops/tables
     */
    public function tables(): void
    {
        $driver = $this->getDbDriver();
        $tables = [];

        try {
            if ($driver === 'mysql') {
                $dbName = (string) config('database.connections.mysql.database');
                $rows = Db::query("
                    SELECT TABLE_NAME as name, 
                           ENGINE as engine, 
                           TABLE_ROWS as rows, 
                           DATA_LENGTH as data_length, 
                           INDEX_LENGTH as index_length, 
                           TABLE_COMMENT as comment, 
                           CREATE_TIME as create_time
                    FROM information_schema.tables 
                    WHERE table_schema = ?
                    ORDER BY TABLE_NAME ASC
                ", [$dbName]);

                foreach ($rows as $r) {
                    $dataBytes = (int) ($r['data_length'] ?? 0);
                    $indexBytes = (int) ($r['index_length'] ?? 0);
                    $tables[] = [
                        'name'        => (string) $r['name'],
                        'comment'     => (string) ($r['comment'] ?: $r['name']),
                        'engine'      => (string) ($r['engine'] ?: 'InnoDB'),
                        'rows'        => (int) ($r['rows'] ?? 0),
                        'data_size'   => $this->formatBytes($dataBytes),
                        'index_size'  => $this->formatBytes($indexBytes),
                        'total_size'  => $this->formatBytes($dataBytes + $indexBytes),
                        'create_time' => (string) ($r['create_time'] ?? '-'),
                    ];
                }
            } elseif ($driver === 'pgsql') {
                $rows = Db::query("
                    SELECT tablename as name,
                           schemaname,
                           pg_total_relation_size(quote_ident(schemaname) || '.' || quote_ident(tablename)) as total_bytes,
                           pg_relation_size(quote_ident(schemaname) || '.' || quote_ident(tablename)) as data_bytes
                    FROM pg_tables 
                    WHERE schemaname = 'public'
                    ORDER BY tablename ASC
                ");

                foreach ($rows as $r) {
                    $tableName = (string) $r['name'];
                    $total = (int) ($r['total_bytes'] ?? 0);
                    $data = (int) ($r['data_bytes'] ?? 0);
                    $rowCount = (int) Db::table($tableName)->count();

                    $tables[] = [
                        'name'        => $tableName,
                        'comment'     => $tableName,
                        'engine'      => 'PostgreSQL',
                        'rows'        => $rowCount,
                        'data_size'   => $this->formatBytes($data),
                        'index_size'  => $this->formatBytes($total - $data),
                        'total_size'  => $this->formatBytes($total),
                        'create_time' => '-',
                    ];
                }
            } else {
                // SQLite 或其他
                $rows = Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC");
                foreach ($rows as $r) {
                    $tableName = (string) $r['name'];
                    $count = 0;
                    try {
                        $count = (int) Db::table($tableName)->count();
                    } catch (\Throwable) {
                    }
                    $tables[] = [
                        'name'        => $tableName,
                        'comment'     => $tableName,
                        'engine'      => 'SQLite',
                        'rows'        => $count,
                        'data_size'   => '-',
                        'index_size'  => '-',
                        'total_size'  => '-',
                        'create_time' => '-',
                    ];
                }
            }
        } catch (\Throwable $e) {
            throw new ApiException('获取数据库表空间失败: ' . $e->getMessage());
        }

        $this->success([
            'driver' => $driver,
            'tables' => $tables,
            'total'  => count($tables),
        ]);
    }

    /**
     * 创建数据库快照备份
     * POST /adminapi/system.devops/backup
     */
    public function backup(): void
    {
        $this->ensurePost();
        $backupDir = App::getRuntimePath() . 'backups/db/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $driver = $this->getDbDriver();
        $nowStr = date('Ymd_His');
        $random = substr(md5(uniqid('', true)), 0, 6);
        $fileName = "hwadmin_backup_{$nowStr}_{$random}.json";
        $filePath = $backupDir . $fileName;

        // 获取要备份的表名列表
        $tableList = $this->getAllTableNames();
        $snapshot = [
            'version'     => '0.2.0',
            'created_at'  => date('Y-m-d H:i:s'),
            'timestamp'   => time(),
            'driver'      => $driver,
            'tables_count'=> count($tableList),
            'data'        => [],
        ];

        foreach ($tableList as $table) {
            try {
                $rows = Db::table($table)->select()->toArray();
                $snapshot['data'][$table] = $rows;
            } catch (\Throwable) {
                $snapshot['data'][$table] = [];
            }
        }

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new ApiException('备份数据序列化失败');
        }

        if (file_put_contents($filePath, $json) === false) {
            throw new ApiException('写入备份文件失败，请检查 runtime 目录写权限');
        }

        $fileSize = filesize($filePath);
        $this->success([
            'filename'   => $fileName,
            'size'       => $this->formatBytes($fileSize),
            'created_at' => date('Y-m-d H:i:s'),
            'tables'     => count($tableList),
        ], '数据库备份生成成功');
    }

    /**
     * 获取备份文件列表
     * GET /adminapi/system.devops/backupList
     */
    public function backupList(): void
    {
        $backupDir = App::getRuntimePath() . 'backups/db/';
        if (!is_dir($backupDir)) {
            $this->success([]);
            return;
        }

        $files = scandir($backupDir);
        $list = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
                continue;
            }
            $fullPath = $backupDir . $file;
            $mtime = filemtime($fullPath);
            $size = filesize($fullPath);

            $list[] = [
                'filename'   => $file,
                'size'       => $this->formatBytes($size),
                'size_bytes' => $size,
                'created_at' => date('Y-m-d H:i:s', $mtime),
                'timestamp'  => $mtime,
            ];
        }

        // 按时间倒序
        usort($list, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
        $this->success($list);
    }

    /**
     * 删除备份文件
     * POST /adminapi/system.devops/delBackup
     */
    public function delBackup(): void
    {
        $this->ensurePost();
        $filename = basename((string) $this->request->post('filename', ''));
        if ($filename === '' || !str_ends_with($filename, '.json')) {
            throw new ApiException('非法的备份文件名');
        }

        $backupDir = App::getRuntimePath() . 'backups/db/';
        $filePath = $backupDir . $filename;
        if (!is_file($filePath)) {
            throw new ApiException('备份文件不存在');
        }

        unlink($filePath);
        $this->success(null, '删除备份成功');
    }

    /**
     * 从备份快照还原数据
     * POST /adminapi/system.devops/restore
     */
    public function restore(): void
    {
        $this->ensurePost();
        $filename = basename((string) $this->request->post('filename', ''));
        if ($filename === '' || !str_ends_with($filename, '.json')) {
            throw new ApiException('非法的备份文件名');
        }

        $backupDir = App::getRuntimePath() . 'backups/db/';
        $filePath = $backupDir . $filename;
        if (!is_file($filePath)) {
            throw new ApiException('备份文件不存在');
        }

        $content = file_get_contents($filePath);
        $snapshot = json_decode((string) $content, true);
        if (!is_array($snapshot) || empty($snapshot['data'])) {
            throw new ApiException('备份文件内容已损坏或格式不正确');
        }

        $restoredTables = 0;
        $restoredRows = 0;

        Db::transaction(function () use ($snapshot, &$restoredTables, &$restoredRows) {
            foreach ($snapshot['data'] as $table => $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                // 清空原表并重新灌入
                try {
                    Db::table($table)->where('1=1')->delete();
                    if (!empty($rows)) {
                        // 分批灌入以防超大 SQL
                        $chunks = array_chunk($rows, 100);
                        foreach ($chunks as $chunk) {
                            Db::table($table)->insertAll($chunk);
                        }
                    }
                    $restoredTables++;
                    $restoredRows += count($rows);
                } catch (\Throwable) {
                    // 忽略不存在的表
                }
            }
        });

        $this->success([
            'tables' => $restoredTables,
            'rows'   => $restoredRows,
        ], "数据还原成功！已恢复 {$restoredTables} 张表，共 {$restoredRows} 行数据。");
    }

    /**
     * 数据库表空间优化
     * POST /adminapi/system.devops/optimize
     */
    public function optimize(): void
    {
        $this->ensurePost();
        $tableName = trim((string) $this->request->post('table', ''));
        $driver = $this->getDbDriver();
        $allTables = $this->getAllTableNames();

        if ($tableName !== '') {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !in_array($tableName, $allTables, true)) {
                throw new ApiException('非法或不存在的数据表名');
            }
        }

        try {
            if ($driver === 'mysql') {
                if ($tableName !== '') {
                    Db::execute("OPTIMIZE TABLE `{$tableName}`");
                    $msg = "数据表 [{$tableName}] 优化完成";
                } else {
                    foreach ($allTables as $t) {
                        if (preg_match('/^[a-zA-Z0-9_]+$/', $t)) {
                            Db::execute("OPTIMIZE TABLE `{$t}`");
                        }
                    }
                    $msg = '全库表空间优化整理完成';
                }
            } elseif ($driver === 'sqlite') {
                Db::execute("VACUUM");
                $msg = 'SQLite VACUUM 空间碎片整理完成';
            } elseif ($driver === 'pgsql') {
                if ($tableName !== '') {
                    Db::execute("VACUUM \"{$tableName}\"");
                    $msg = "PostgreSQL 表 [{$tableName}] VACUUM 完成";
                } else {
                    Db::execute("VACUUM");
                    $msg = 'PostgreSQL 全库 VACUUM 整理完成';
                }
            } else {
                $msg = '当前数据库类型无需额外空间碎片整理';
            }
        } catch (\Throwable $e) {
            throw new ApiException('优化失败: ' . $e->getMessage());
        }

        $this->success(null, $msg);
    }

    /**
     * 仅允许 POST 请求
     */
    protected function ensurePost(): void
    {
        if (!$this->request->isPost()) {
            throw new ApiException('请求方式错误', 0, null, 405);
        }
    }

    /**
     * 获取数据库驱动
     */
    private function getDbDriver(): string
    {
        $defaultConn = (string) config('database.default', 'mysql');
        $type = (string) config("database.connections.{$defaultConn}.type", 'mysql');
        return strtolower($type);
    }

    /**
     * 获取所有表名
     */
    private function getAllTableNames(): array
    {
        $driver = $this->getDbDriver();
        $tableList = [];

        if ($driver === 'mysql') {
            $dbName = (string) config('database.connections.mysql.database');
            $rows = Db::query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = ?", [$dbName]);
            foreach ($rows as $r) {
                $tableList[] = (string) $r['TABLE_NAME'];
            }
        } elseif ($driver === 'pgsql') {
            $rows = Db::query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            foreach ($rows as $r) {
                $tableList[] = (string) $r['tablename'];
            }
        } else {
            $rows = Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            foreach ($rows as $r) {
                $tableList[] = (string) $r['name'];
            }
        }

        return $tableList;
    }

    /**
     * 字节转换格式化
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
