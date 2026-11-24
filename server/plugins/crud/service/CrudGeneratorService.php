<?php
declare(strict_types=1);

namespace plugins\crud\service;

use core\exception\ApiException;
use think\facade\Db;
use Throwable;

/**
 * HwAdmin 官方旗舰级可视化 CRUD 生成器全栈核心服务
 */
class CrudGeneratorService
{
    /**
     * 系统受保护核心表（严禁覆盖生成，保障系统底层安全）
     */
    protected const SYSTEM_PROTECTED_TABLES = [
        'admin',
        'admin_log',
        'auth_group',
        'auth_group_access',
        'auth_rule',
        'config',
        'system_config',
        'plugin',
        'token',
        'attachment',
        'crontab',
        'crontab_log',
        'dict',
        'dict_item',
        'user',
        'user_group',
        'user_token',
        'user_money_log',
        'user_score_log',
        'user_profile',
        'phinxlog',
        'migrations',
    ];

    /**
     * 建表允许的字段类型白名单（杜绝 DDL SQL 注入）
     */
    protected const ALLOWED_COLUMN_TYPES = [
        'varchar',
        'char',
        'int',
        'tinyint',
        'smallint',
        'mediumint',
        'bigint',
        'decimal',
        'float',
        'double',
        'text',
        'mediumtext',
        'longtext',
        'datetime',
        'timestamp',
        'date',
        'time',
        'json',
        'boolean',
    ];

    /** 标识符正则：仅允许英文字母开头及字母、数字、下划线 */
    protected const IDENTIFIER_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    /**
     * 常见常用字段中文语义翻译字典
     */
    protected const FIELD_NAME_LABELS = [
        'id'          => 'ID',
        'title'       => '标题',
        'name'        => '名称',
        'username'    => '用户名',
        'nickname'    => '昵称',
        'password'    => '密码',
        'mobile'      => '手机号',
        'phone'       => '联系电话',
        'email'       => '电子邮箱',
        'avatar'      => '头像',
        'cover'       => '封面图',
        'image'       => '图片',
        'images'      => '图片画廊',
        'pic'         => '展示图',
        'photo'       => '照片',
        'logo'        => 'Logo标志',
        'icon'        => '图标',
        'summary'     => '摘要',
        'description' => '描述说明',
        'intro'       => '简介',
        'remark'      => '备注',
        'note'        => '便签备注',
        'content'     => '内容详情',
        'detail'      => '详细介绍',
        'body'        => '正文',
        'status'      => '状态',
        'state'       => '状态值',
        'type'        => '类型',
        'category_id' => '分类ID',
        'pid'         => '上级ID',
        'parent_id'   => '父级ID',
        'user_id'     => '会员ID',
        'admin_id'    => '归属管理员ID',
        'price'       => '价格',
        'money'       => '金额',
        'amount'      => '数值金额',
        'cost'        => '成本价',
        'market_price'=> '市场价',
        'score'       => '积分值',
        'stock'       => '库存数量',
        'count'       => '统计计数',
        'num'         => '数量',
        'weight'      => '权重排序',
        'sort'        => '排序',
        'views'       => '浏览次数',
        'likes'       => '点赞次数',
        'sales'       => '销量',
        'url'         => '链接地址',
        'link'        => '外链地址',
        'tags'        => '标签',
        'author'      => '作者',
        'source'      => '来源',
        'code'        => '编码标识',
        'slug'        => '别名/Slug',
        'sn'          => '序列号/单号',
        'ip'          => 'IP地址',
        'create_time' => '创建时间',
        'update_time' => '更新时间',
        'delete_time' => '删除时间',
        'created_at'  => '创建时间',
        'updated_at'  => '更新时间',
        'deleted_at'  => '删除时间',
    ];

    /**
     * 获取数据库驱动与前缀
     */
    protected function getDbConfig(): array
    {
        $driver = strtolower((string) (Db::connect()->getConfig('type') ?: 'mysql'));
        $prefix = (string) (Db::connect()->getConfig('prefix') ?: 'hw_');
        return [$driver, $prefix];
    }

    /**
     * 获取全部数据表列表（双库与 SQLite 完美自适应）
     */
    public function getTables(): array
    {
        [$driver, $prefix] = $this->getDbConfig();
        $tables = [];

        try {
            if ($driver === 'sqlite') {
                $rawList = Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                foreach ($rawList as $row) {
                    $tblName = (string) ($row['name'] ?? '');
                    if ($tblName === '') {
                        continue;
                    }
                    $colInfo = Db::query("PRAGMA table_info('{$tblName}')");
                    $colsCount = is_array($colInfo) ? count($colInfo) : 0;
                    $rowCount = 0;
                    try {
                        $rowCount = (int) Db::table($tblName)->count();
                    } catch (Throwable) {
                    }
                    $tables[] = [
                        'name'          => $tblName,
                        'clean_name'    => $this->stripPrefix($tblName, $prefix),
                        'comment'       => $this->guessTableComment($tblName),
                        'engine'        => 'SQLite',
                        'rows'          => $rowCount,
                        'columns_count' => $colsCount,
                        'is_system'     => $this->isSystemTable($tblName, $prefix),
                        'is_plugin'     => $this->isPluginTable($tblName, $prefix),
                    ];
                }
            } elseif ($driver === 'pgsql') {
                $sql = "SELECT t.table_name AS name,
                               obj_description((quote_ident(t.table_schema) || '.' || quote_ident(t.table_name))::regclass, 'pg_class') AS comment
                        FROM information_schema.tables t
                        WHERE t.table_schema = 'public' AND t.table_type = 'BASE TABLE'
                        ORDER BY t.table_name ASC";
                $rawList = Db::query($sql);
                foreach ($rawList as $row) {
                    $tblName = (string) ($row['name'] ?? '');
                    if ($tblName === '') {
                        continue;
                    }
                    $colCountRes = Db::query("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_name = '{$tblName}'");
                    $colsCount = (int) ($colCountRes[0]['c'] ?? 0);
                    $rowCount = 0;
                    try {
                        $rowCount = (int) Db::table($tblName)->count();
                    } catch (Throwable) {
                    }
                    $comment = (string) ($row['comment'] ?? '');
                    if ($comment === '') {
                        $comment = $this->guessTableComment($tblName);
                    }
                    $tables[] = [
                        'name'          => $tblName,
                        'clean_name'    => $this->stripPrefix($tblName, $prefix),
                        'comment'       => $comment,
                        'engine'        => 'PostgreSQL',
                        'rows'          => $rowCount,
                        'columns_count' => $colsCount,
                        'is_system'     => $this->isSystemTable($tblName, $prefix),
                        'is_plugin'     => $this->isPluginTable($tblName, $prefix),
                    ];
                }
            } else {
                // MySQL 8.0+
                $sql = "SELECT table_name AS name, table_comment AS comment, engine, table_rows AS rows_cnt
                        FROM information_schema.tables
                        WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                        ORDER BY table_name ASC";
                $rawList = Db::query($sql);
                foreach ($rawList as $row) {
                    $tblName = (string) ($row['name'] ?? '');
                    if ($tblName === '') {
                        continue;
                    }
                    $colCountRes = Db::query("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$tblName}'");
                    $colsCount = (int) ($colCountRes[0]['c'] ?? 0);
                    $comment = (string) ($row['comment'] ?? '');
                    if ($comment === '') {
                        $comment = $this->guessTableComment($tblName);
                    }
                    $tables[] = [
                        'name'          => $tblName,
                        'clean_name'    => $this->stripPrefix($tblName, $prefix),
                        'comment'       => $comment,
                        'engine'        => (string) ($row['engine'] ?? 'InnoDB'),
                        'rows'          => (int) ($row['rows_cnt'] ?? 0),
                        'columns_count' => $colsCount,
                        'is_system'     => $this->isSystemTable($tblName, $prefix),
                        'is_plugin'     => $this->isPluginTable($tblName, $prefix),
                    ];
                }
            }
        } catch (Throwable $e) {
            throw new ApiException('读取数据表列表失败：' . $e->getMessage());
        }

        return $tables;
    }

    /**
     * 读取指定数据表的全部字段结构并智能推导配置
     */
    public function getTableColumns(string $tableName): array
    {
        [$driver, $prefix] = $this->getDbConfig();
        $tableName = trim($tableName);
        if ($tableName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new ApiException('非法的数据表名');
        }

        // 智能兼容表前缀：若传入未带前缀的表名，自动探测是否带有系统表前缀
        if ($prefix !== '' && !str_starts_with($tableName, $prefix)) {
            try {
                if ($driver === 'sqlite') {
                    $check = Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$prefix . $tableName]);
                    if (!empty($check)) {
                        $tableName = $prefix . $tableName;
                    }
                } elseif ($driver === 'pgsql') {
                    $check = Db::query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = ?", [$prefix . $tableName]);
                    if (!empty($check)) {
                        $tableName = $prefix . $tableName;
                    }
                } else {
                    $check = Db::query("SHOW TABLES LIKE ?", [$prefix . $tableName]);
                    if (!empty($check)) {
                        $tableName = $prefix . $tableName;
                    }
                }
            } catch (Throwable) {
            }
        }

        $rawColumns = [];
        try {
            if ($driver === 'sqlite') {
                $infoList = Db::query("PRAGMA table_info('{$tableName}')");
                foreach ($infoList as $c) {
                    $rawColumns[] = [
                        'name'    => (string) ($c['name'] ?? ''),
                        'type'    => (string) ($c['type'] ?? 'text'),
                        'notnull' => (bool) ($c['notnull'] ?? false),
                        'default' => $c['dflt_value'] ?? null,
                        'comment' => '',
                        'is_pk'   => !empty($c['pk']),
                    ];
                }
            } elseif ($driver === 'pgsql') {
                $sql = "SELECT c.column_name AS name,
                               c.data_type AS type,
                               c.character_maximum_length AS length,
                               c.is_nullable AS is_nullable,
                               c.column_default AS default_val,
                               col_description((quote_ident(c.table_schema) || '.' || quote_ident(c.table_name))::regclass, c.ordinal_position) AS comment
                        FROM information_schema.columns c
                        WHERE c.table_schema = 'public' AND c.table_name = '{$tableName}'
                        ORDER BY c.ordinal_position ASC";
                $res = Db::query($sql);
                $pkRes = Db::query("SELECT a.attname
                                    FROM pg_index i
                                    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                                    WHERE i.indrelid = '\"{$tableName}\"'::regclass AND i.indisprimary");
                $pkCols = array_column($pkRes, 'attname');
                foreach ($res as $c) {
                    $colName = (string) ($c['name'] ?? '');
                    $rawColumns[] = [
                        'name'    => $colName,
                        'type'    => (string) ($c['type'] ?? 'varchar'),
                        'notnull' => strtoupper((string) ($c['is_nullable'] ?? '')) === 'NO',
                        'default' => $c['default_val'] ?? null,
                        'comment' => (string) ($c['comment'] ?? ''),
                        'is_pk'   => in_array($colName, $pkCols, true),
                    ];
                }
            } else {
                // MySQL 8.0+
                $res = Db::query("SHOW FULL COLUMNS FROM `{$tableName}`");
                foreach ($res as $c) {
                    $rawColumns[] = [
                        'name'    => (string) ($c['Field'] ?? ''),
                        'type'    => (string) ($c['Type'] ?? 'varchar(255)'),
                        'notnull' => strtoupper((string) ($c['Null'] ?? '')) === 'NO',
                        'default' => $c['Default'] ?? null,
                        'comment' => (string) ($c['Comment'] ?? ''),
                        'is_pk'   => strtoupper((string) ($c['Key'] ?? '')) === 'PRI',
                    ];
                }
            }
        } catch (Throwable $e) {
            throw new ApiException("无法解析表 [{$tableName}] 字段结构：" . $e->getMessage());
        }

        if (empty($rawColumns)) {
            throw new ApiException("数据表 [{$tableName}] 不存在或未包含任何有效字段");
        }

        // 进行智能推导
        $inferredColumns = [];
        foreach ($rawColumns as $col) {
            $inferredColumns[] = $this->inferColumnConfiguration($col);
        }

        return $inferredColumns;
    }

    /**
     * 智能推导单字段配置
     */
    protected function inferColumnConfiguration(array $col): array
    {
        $name = strtolower($col['name']);
        $type = strtolower((string) ($col['type'] ?? ''));
        $rawComment = trim((string) ($col['comment'] ?? ''));
        $isPk = !empty($col['is_pk']) || $name === 'id';

        // 1. 中文显示标签推导
        $title = $rawComment;
        if ($title !== '') {
            // 清理诸如 "状态:1=正常,0=禁用" 中的枚举提示，提取前缀作为简洁标题
            if (preg_match('/^([^:：,\s]+)/u', $title, $m)) {
                $title = trim($m[1]);
            }
        } else {
            $title = self::FIELD_NAME_LABELS[$name] ?? '';
            if ($title === '') {
                // 探测后缀或部分匹配
                if (str_ends_with($name, '_id')) {
                    $sub = substr($name, 0, -3);
                    $title = (self::FIELD_NAME_LABELS[$sub] ?? ucfirst($sub)) . 'ID';
                } elseif (str_ends_with($name, '_time')) {
                    $sub = substr($name, 0, -5);
                    $title = (self::FIELD_NAME_LABELS[$sub] ?? ucfirst($sub)) . '时间';
                } else {
                    $title = ucwords(str_replace('_', ' ', $name));
                }
            }
        }

        // 2. 表单录入控件推导 (control_type)
        $controlType = 'input';
        if ($isPk) {
            $controlType = 'input';
        } elseif (preg_match('/^(password|pwd|pass)$/i', $name)) {
            $controlType = 'password';
        } elseif (preg_match('/^(avatar|cover|image|img|pic|photo|logo)$/i', $name) || str_ends_with($name, '_image') || str_ends_with($name, '_cover')) {
            $controlType = 'image';
        } elseif (preg_match('/^(content|detail|body|html)$/i', $name) || str_contains($type, 'longtext') || str_contains($type, 'mediumtext')) {
            $controlType = 'editor';
        } elseif (preg_match('/^(remark|description|summary|intro|note|reason)$/i', $name) || str_contains($type, 'text')) {
            $controlType = 'textarea';
        } elseif (preg_match('/^(status|state|is_|switch)/i', $name) || str_ends_with($name, '_switch')) {
            $controlType = 'switch';
        } elseif (str_ends_with($name, '_id') || preg_match('/^(category|type|level|gender)$/i', $name)) {
            $controlType = 'select';
        } elseif (preg_match('/(_time|_at)$/i', $name) || str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
            $controlType = 'datetime';
        } elseif (preg_match('/(_date|^date)$/i', $name) || str_contains($type, 'date')) {
            $controlType = 'date';
        } elseif (preg_match('/(price|amount|money|cost|score|stock|num|count|sort|weight)/i', $name)
            || str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float')) {
            $controlType = 'number';
        }

        // 3. 列表是否显示 (show_in_list)
        $showInList = true;
        if (preg_match('/(password|salt|token|secret|private|delete_time|deleted_at)/i', $name)
            || in_array($controlType, ['editor'], true)) {
            $showInList = false;
        }

        // 4. 表单是否展示 (show_in_form)
        $showInForm = true;
        if ($isPk || in_array($name, ['create_time', 'update_time', 'delete_time', 'created_at', 'updated_at', 'deleted_at'], true)) {
            $showInForm = false;
        }

        // 5. 是否必填 (required)
        $required = false;
        if ($showInForm && !empty($col['notnull']) && $col['default'] === null) {
            $required = true;
        }

        // 6. 搜索查询方式 (query_type: NONE, =, LIKE, BETWEEN)
        $queryType = 'NONE';
        if (in_array($name, ['title', 'name', 'username', 'nickname', 'mobile', 'email', 'sn', 'code', 'slug'], true)) {
            $queryType = 'LIKE';
        } elseif (in_array($name, ['status', 'type', 'category_id', 'is_recommend', 'is_hot'], true) || str_ends_with($name, '_id')) {
            $queryType = '=';
        } elseif (in_array($name, ['create_time', 'created_at', 'date'], true)) {
            $queryType = 'BETWEEN';
        }

        // 7. 字典预设标识
        $dictCode = '';
        if ($name === 'status') {
            $dictCode = 'system_status';
        }

        return [
            'name'         => $col['name'],
            'type'         => $col['type'],
            'title'        => $title,
            'comment'      => $rawComment,
            'is_pk'        => $isPk,
            'notnull'      => !empty($col['notnull']),
            'default'      => $col['default'],
            'control_type' => $controlType,
            'show_in_list' => $showInList,
            'show_in_form' => $showInForm,
            'required'     => $required,
            'query_type'   => $queryType,
            'dict_code'    => $dictCode,
        ];
    }

    /**
     * 在线可视化零 SQL 建表
     */
    public function createTable(array $params): array
    {
        [$driver, $prefix] = $this->getDbConfig();

        $rawTableName = trim((string) ($params['table_name'] ?? ''));
        $tableComment = trim((string) ($params['table_comment'] ?? ''));
        $columns = (array) ($params['columns'] ?? []);

        if ($rawTableName === '' || !preg_match(self::IDENTIFIER_PATTERN, $rawTableName)) {
            throw new ApiException('数据表名必须以英文字母开头，仅支持字母、数字与下划线');
        }

        // 统一表全名
        $fullTable = str_starts_with($rawTableName, $prefix) ? $rawTableName : ($prefix . $rawTableName);

        if ($this->isSystemTable($fullTable, $prefix)) {
            throw new ApiException("安全警告：数据表 [{$fullTable}] 属于系统受保护核心表，禁止新建或覆盖！");
        }

        // 检查表是否已存在
        if ($this->tableExists($fullTable)) {
            throw new ApiException("数据表 [{$fullTable}] 已存在，请更换表名或直接选择该表生成 CRUD");
        }

        if (empty($columns)) {
            throw new ApiException('建表字段列表不能为空，请至少添加一个业务字段');
        }

        // 自动标准化与补齐字段
        $parsedColumns = [];
        $hasCreateTime = false;
        $hasUpdateTime = false;

        foreach ($columns as $idx => $c) {
            if (!is_array($c)) {
                throw new ApiException("第 " . ($idx + 1) . " 个字段配置格式非法");
            }
            $colName = strtolower(trim((string) ($c['name'] ?? '')));
            if ($colName === '' || !preg_match(self::IDENTIFIER_PATTERN, $colName)) {
                throw new ApiException("第 " . ($idx + 1) . " 个字段名称非法，必须以字母开头且仅含字母数字下划线");
            }
            if ($colName === 'id') {
                continue; // 自增主键由框架自动统一生成
            }
            if ($colName === 'create_time' || $colName === 'created_at') {
                $hasCreateTime = true;
            }
            if ($colName === 'update_time' || $colName === 'updated_at') {
                $hasUpdateTime = true;
            }

            $colType = strtolower(trim((string) ($c['type'] ?? 'varchar')));
            if (!in_array($colType, self::ALLOWED_COLUMN_TYPES, true)) {
                throw new ApiException("第 " . ($idx + 1) . " 个字段类型 [{$colType}] 不在允许的白名单内");
            }

            $colLength = $this->validateColumnLength($colType, trim((string) ($c['length'] ?? '')), $idx);

            $rawDefault = $c['default'] ?? null;
            if ($rawDefault !== null && !is_scalar($rawDefault)) {
                throw new ApiException("第 " . ($idx + 1) . " 个字段默认值格式非法");
            }

            $parsedColumns[] = [
                'name'    => $colName,
                'type'    => $colType,
                'length'  => $colLength,
                'notnull' => !empty($c['notnull']),
                'default' => $rawDefault,
                'comment' => str_replace("\0", '', trim((string) ($c['comment'] ?? ''))),
            ];
        }

        // 自动补充标准 Unix 时间戳字段
        if (!$hasCreateTime) {
            $parsedColumns[] = [
                'name'    => 'create_time',
                'type'    => 'bigint',
                'length'  => '20',
                'notnull' => true,
                'default' => 0,
                'comment' => '创建时间',
            ];
        }
        if (!$hasUpdateTime) {
            $parsedColumns[] = [
                'name'    => 'update_time',
                'type'    => 'bigint',
                'length'  => '20',
                'notnull' => true,
                'default' => 0,
                'comment' => '更新时间',
            ];
        }

        $tableComment = str_replace("\0", '', $tableComment);

        // 根据数据库驱动生成并执行 DDL
        try {
            if ($driver === 'sqlite') {
                $colDefs = ['"id" INTEGER PRIMARY KEY AUTOINCREMENT'];
                foreach ($parsedColumns as $pc) {
                    $sqliteType = match ($pc['type']) {
                        'int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'boolean' => 'INTEGER',
                        'decimal', 'float', 'double' => 'REAL',
                        default => 'TEXT',
                    };
                    $nn = $pc['notnull'] ? ' NOT NULL' : '';
                    $dflt = '';
                    if ($pc['default'] !== null && $pc['default'] !== '') {
                        $val = $this->formatSqlDefault($pc['default'], $driver);
                        $dflt = " DEFAULT {$val}";
                    }
                    $colDefs[] = "\"{$pc['name']}\" {$sqliteType}{$nn}{$dflt}";
                }
                $sql = "CREATE TABLE \"{$fullTable}\" (\n  " . implode(",\n  ", $colDefs) . "\n)";
                Db::execute($sql);
            } elseif ($driver === 'pgsql') {
                $colDefs = ['"id" SERIAL PRIMARY KEY'];
                $comments = [];
                if ($tableComment !== '') {
                    $comments[] = "COMMENT ON TABLE \"{$fullTable}\" IS " . $this->quoteSqlLiteral($tableComment, $driver);
                }
                foreach ($parsedColumns as $pc) {
                    $pgType = match ($pc['type']) {
                        'tinyint', 'smallint' => 'SMALLINT',
                        'int', 'mediumint' => 'INTEGER',
                        'bigint' => 'BIGINT',
                        'decimal' => 'NUMERIC(' . ($pc['length'] ?: '10,2') . ')',
                        'float', 'double' => 'DOUBLE PRECISION',
                        'text', 'mediumtext', 'longtext' => 'TEXT',
                        'datetime', 'timestamp' => 'TIMESTAMP',
                        'date' => 'DATE',
                        'time' => 'TIME',
                        'json' => 'JSONB',
                        'boolean' => 'BOOLEAN',
                        'char' => 'CHAR(' . ($pc['length'] ?: '32') . ')',
                        default => 'VARCHAR(' . ($pc['length'] ?: '255') . ')',
                    };
                    $nn = $pc['notnull'] ? ' NOT NULL' : '';
                    $dflt = '';
                    if ($pc['default'] !== null && $pc['default'] !== '') {
                        $val = $this->formatSqlDefault($pc['default'], $driver);
                        $dflt = " DEFAULT {$val}";
                    }
                    $colDefs[] = "\"{$pc['name']}\" {$pgType}{$nn}{$dflt}";
                    if ($pc['comment'] !== '') {
                        $comments[] = "COMMENT ON COLUMN \"{$fullTable}\".\"{$pc['name']}\" IS " . $this->quoteSqlLiteral($pc['comment'], $driver);
                    }
                }
                $sql = "CREATE TABLE \"{$fullTable}\" (\n  " . implode(",\n  ", $colDefs) . "\n)";
                Db::execute($sql);
                foreach ($comments as $cSql) {
                    try {
                        Db::execute($cSql);
                    } catch (Throwable) {
                    }
                }
            } else {
                // MySQL 8.0+
                $colDefs = ['`id` INT AUTO_INCREMENT PRIMARY KEY'];
                foreach ($parsedColumns as $pc) {
                    $typeStr = match ($pc['type']) {
                        'varchar' => 'VARCHAR(' . ($pc['length'] ?: '255') . ')',
                        'char' => 'CHAR(' . ($pc['length'] ?: '32') . ')',
                        'int' => 'INT' . ($pc['length'] !== '' ? "({$pc['length']})" : ''),
                        'tinyint' => 'TINYINT(' . ($pc['length'] ?: '1') . ')',
                        'smallint' => 'SMALLINT' . ($pc['length'] !== '' ? "({$pc['length']})" : ''),
                        'mediumint' => 'MEDIUMINT' . ($pc['length'] !== '' ? "({$pc['length']})" : ''),
                        'bigint' => 'BIGINT(' . ($pc['length'] ?: '20') . ')',
                        'decimal' => 'DECIMAL(' . ($pc['length'] ?: '10,2') . ')',
                        'float' => 'FLOAT' . ($pc['length'] !== '' ? "({$pc['length']})" : ''),
                        'double' => 'DOUBLE' . ($pc['length'] !== '' ? "({$pc['length']})" : ''),
                        'text' => 'TEXT',
                        'mediumtext' => 'MEDIUMTEXT',
                        'longtext' => 'LONGTEXT',
                        'datetime' => 'DATETIME',
                        'timestamp' => 'TIMESTAMP',
                        'date' => 'DATE',
                        'time' => 'TIME',
                        'json' => 'JSON',
                        'boolean' => 'TINYINT(1)',
                    };
                    $nn = $pc['notnull'] ? ' NOT NULL' : ' NULL';
                    $dflt = '';
                    if ($pc['default'] !== null && $pc['default'] !== '') {
                        $val = $this->formatSqlDefault($pc['default'], $driver);
                        $dflt = " DEFAULT {$val}";
                    } elseif (!$pc['notnull']) {
                        $dflt = " DEFAULT NULL";
                    }
                    $cmt = $pc['comment'] !== '' ? ' COMMENT ' . $this->quoteSqlLiteral($pc['comment'], $driver) : '';
                    $colDefs[] = "`{$pc['name']}` {$typeStr}{$nn}{$dflt}{$cmt}";
                }
                $tableCmtStr = $tableComment !== '' ? ' COMMENT=' . $this->quoteSqlLiteral($tableComment, $driver) : '';
                $sql = "CREATE TABLE `{$fullTable}` (\n  " . implode(",\n  ", $colDefs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4{$tableCmtStr}";
                Db::execute($sql);
            }
        } catch (Throwable $e) {
            throw new ApiException("创建数据表执行失败: " . $e->getMessage());
        }

        return [
            'table_name'    => $fullTable,
            'clean_name'    => $this->stripPrefix($fullTable, $prefix),
            'table_comment' => $tableComment,
            'columns_count' => count($parsedColumns) + 1,
            'message'       => "数据表 [{$fullTable}] 创建成功，已自动建立自增主键与标准审计时间戳",
        ];
    }

    /**
     * 实时预览全栈代码
     */
    public function preview(array $config): array
    {
        $resolved = $this->resolveConfig($config);

        return [
            'controller_code' => $this->renderController($resolved),
            'model_code'      => $this->renderModel($resolved),
            'validate_code'   => $this->renderValidate($resolved),
            'vue_code'        => $this->renderVue($resolved),
            'paths'           => [
                'controller_file' => $resolved['controller_file'],
                'model_file'      => $resolved['model_file'],
                'validate_file'   => $resolved['validate_file'],
                'vue_file'        => $resolved['vue_file'],
            ],
            'meta'            => [
                'table'       => $resolved['table'],
                'controller'  => $resolved['controller_class'],
                'node_prefix' => $resolved['node_prefix'],
            ],
        ];
    }

    /**
     * 执行全栈代码落盘与权限菜单自动注册
     */
    public function generate(array $config): array
    {
        $resolved = $this->resolveConfig($config);
        $force = !empty($config['force']);

        // 1. 系统核心安全保护检查
        if ($this->isSystemTable($resolved['table'], $resolved['prefix'])) {
            throw new ApiException("安全警告：数据表 [{$resolved['table']}] 属于系统核心认证与配置表，禁止通过 CRUD 生成器覆盖！");
        }

        // 2. 检查文件覆盖冲突
        $files = [
            'Controller' => $resolved['controller_file'],
            'Model'      => $resolved['model_file'],
            'Validate'   => $resolved['validate_file'],
            'Vue'        => $resolved['vue_file'],
        ];

        if (!$force) {
            $existing = [];
            foreach ($files as $name => $path) {
                if (is_file($path)) {
                    $existing[] = "{$name}: {$path}";
                }
            }
            if ($existing) {
                throw new ApiException("检测到目标代码文件已存在，为防意外覆盖已中断（如需强制覆盖请勾选覆盖选项）：\n" . implode("\n", $existing));
            }
        }

        // 3. 渲染代码内容
        $controllerCode = $this->renderController($resolved);
        $modelCode      = $this->renderModel($resolved);
        $validateCode   = $this->renderValidate($resolved);
        $vueCode        = $this->renderVue($resolved);

        // 4. 安全落盘写入
        $this->safeWriteFile($resolved['controller_file'], $controllerCode);
        $this->safeWriteFile($resolved['model_file'], $modelCode);
        $this->safeWriteFile($resolved['validate_file'], $validateCode);
        $this->safeWriteFile($resolved['vue_file'], $vueCode);

        // 5. 权限菜单注册
        $createdRuleIds = [];
        if (!empty($config['auto_menu'])) {
            $createdRuleIds = $this->registerAuthMenus($resolved);
        }

        // 6. 保存生成历史记录
        $historyId = date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $record = [
            'id'            => $historyId,
            'time'          => date('Y-m-d H:i:s'),
            'timestamp'     => time(),
            'table'         => $resolved['table'],
            'menu_title'    => $resolved['menu_title'],
            'controller'    => $resolved['controller_class'],
            'files'         => array_values($files),
            'rule_ids'      => $createdRuleIds,
            'status'        => 'active',
        ];
        $this->appendHistory($record);

        return [
            'history_id'  => $historyId,
            'files'       => $files,
            'rule_ids'    => $createdRuleIds,
            'menu_title'  => $resolved['menu_title'],
            'node_prefix' => $resolved['node_prefix'],
            'message'     => "全栈 CRUD 代码生成成功！已写入后端控制器、模型、验证器以及前端 Vue 页面，并自动注入权限规则。",
        ];
    }

    /**
     * 查询生成历史记录
     */
    public function getHistory(): array
    {
        $historyFile = runtime_path() . 'crud' . DIRECTORY_SEPARATOR . 'history.json';
        if (!is_file($historyFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($historyFile), true);
        return is_array($data) ? array_reverse($data) : [];
    }

    /**
     * 一键撤回已生成的全栈文件与权限菜单节点
     */
    public function rollback(string $historyId): array
    {
        $historyFile = runtime_path() . 'crud' . DIRECTORY_SEPARATOR . 'history.json';
        if (!is_file($historyFile)) {
            throw new ApiException('历史记录不存在');
        }

        $list = json_decode((string) file_get_contents($historyFile), true);
        if (!is_array($list)) {
            throw new ApiException('历史记录损坏');
        }

        $targetIndex = null;
        $targetRecord = null;
        foreach ($list as $idx => $item) {
            if (($item['id'] ?? '') === $historyId) {
                $targetIndex = $idx;
                $targetRecord = $item;
                break;
            }
        }

        if ($targetRecord === null) {
            throw new ApiException("未找到 ID 为 [{$historyId}] 的生成记录");
        }

        if (($targetRecord['status'] ?? '') === 'rolled_back') {
            throw new ApiException('该次生成已被撤回，无需重复操作');
        }

        $deletedFiles = [];
        $failedFiles = [];
        $allowedBaseDirs = [
            root_path() . 'app' . DIRECTORY_SEPARATOR,
            root_path() . 'plugins' . DIRECTORY_SEPARATOR,
            dirname(root_path()) . DIRECTORY_SEPARATOR . 'admin-ui' . DIRECTORY_SEPARATOR,
        ];

        // 1. 安全删除落盘文件
        foreach ((array) ($targetRecord['files'] ?? []) as $file) {
            $realFile = realpath($file) ?: $file;
            $isSafePath = false;
            foreach ($allowedBaseDirs as $baseDir) {
                if (str_starts_with($realFile, $baseDir)) {
                    $isSafePath = true;
                    break;
                }
            }
            if ($isSafePath && is_file($realFile)) {
                if (@unlink($realFile)) {
                    $deletedFiles[] = $file;
                } else {
                    $failedFiles[] = $file;
                }
            }
        }

        // 2. 清理权限菜单节点
        $ruleIds = (array) ($targetRecord['rule_ids'] ?? []);
        if ($ruleIds) {
            try {
                Db::name('auth_rule')->whereIn('id', $ruleIds)->delete();
            } catch (Throwable) {
            }
        }

        // 3. 更新历史状态
        $list[$targetIndex]['status'] = 'rolled_back';
        $list[$targetIndex]['rollback_time'] = date('Y-m-d H:i:s');
        file_put_contents($historyFile, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [
            'history_id'    => $historyId,
            'deleted_files' => $deletedFiles,
            'failed_files'  => $failedFiles,
            'deleted_rules' => count($ruleIds),
            'message'       => '成功撤回代码与权限节点！已清理本次生成的全部文件与菜单数据。',
        ];
    }

    /**
     * 自动向 auth_rule 注册权限菜单与按钮操作
     */
    protected function registerAuthMenus(array $resolved): array
    {
        $menuTitle   = $resolved['menu_title'];
        $nodePrefix  = $resolved['node_prefix'];
        $parentId    = (int) ($resolved['parent_menu_id'] ?? 0);
        $vueRelPath  = $resolved['vue_rel_path'];
        $cleanCtrl   = strtolower(str_replace('.', '/', $resolved['controller_rel']));
        $routePath   = $cleanCtrl;
        $pluginName  = $resolved['plugin'] ?? '';
        $now = time();

        $ruleIds = [];

        // 1. 查找或创建主菜单节点
        $menuRule = Db::name('auth_rule')->where('name', $nodePrefix)->find();
        if (!$menuRule) {
            $menuId = (int) Db::name('auth_rule')->insertGetId([
                'pid'         => $parentId,
                'type'        => 'menu',
                'name'        => $nodePrefix,
                'title'       => $menuTitle,
                'icon'        => 'file-list-3-line',
                'path'        => $routePath,
                'component'   => $vueRelPath,
                'hidden'      => 0,
                'plugin'      => $pluginName,
                'weight'      => 80,
                'status'      => 1,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $ruleIds[] = $menuId;
        } else {
            $menuId = (int) $menuRule['id'];
        }

        // 2. 注册标准 CRUD 按钮权限节点
        $buttons = [
            ['title' => "查看{$menuTitle}", 'action' => 'index'],
            ['title' => "查看{$menuTitle}详情", 'action' => 'read'],
            ['title' => "新增{$menuTitle}", 'action' => 'add'],
            ['title' => "编辑{$menuTitle}", 'action' => 'edit'],
            ['title' => "删除{$menuTitle}", 'action' => 'del'],
            ['title' => "批量修改{$menuTitle}", 'action' => 'multi'],
        ];

        foreach ($buttons as $btn) {
            $btnNode = "{$nodePrefix}/{$btn['action']}";
            $hasBtn = Db::name('auth_rule')->where('name', $btnNode)->find();
            if (!$hasBtn) {
                $bId = (int) Db::name('auth_rule')->insertGetId([
                    'pid'         => $menuId,
                    'type'        => 'button',
                    'name'        => $btnNode,
                    'title'       => $btn['title'],
                    'icon'        => '',
                    'path'        => '',
                    'component'   => '',
                    'hidden'      => 0,
                    'plugin'      => $pluginName,
                    'weight'      => 50,
                    'status'      => 1,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                $ruleIds[] = $bId;
            }
        }

        return $ruleIds;
    }

    /**
     * 解析生成器参数与绝对路径
     */
    protected function resolveConfig(array $config): array
    {
        [$driver, $prefix] = $this->getDbConfig();

        $table = trim((string) ($config['table'] ?? ''));
        if ($table === '' || !preg_match(self::IDENTIFIER_PATTERN, $table)) {
            throw new ApiException('数据表名非法，必须以英文字母开头且仅含字母、数字与下划线');
        }

        $cleanTable = $this->stripPrefix($table, $prefix);
        $plugin = trim((string) ($config['plugin'] ?? ''));
        $module = trim((string) ($config['module'] ?? 'adminapi'));
        if ($module !== 'adminapi' && $plugin === '') {
            $plugin = $module;
        }
        if ($plugin !== '' && !preg_match(self::IDENTIFIER_PATTERN, $plugin)) {
            throw new ApiException('插件标识非法，必须以英文字母开头且仅含字母、数字与下划线');
        }

        $customCtrl = trim((string) ($config['controller'] ?? ''));
        $studlyShort = $this->studly($cleanTable);
        if ($customCtrl !== '') {
            if (str_contains($customCtrl, '\\') || str_contains($customCtrl, '..') || str_starts_with($customCtrl, '/') || str_ends_with($customCtrl, '/')) {
                throw new ApiException('控制器名称非法，禁止包含反斜杠或路径穿越字符');
            }
            $rawSegments = explode('/', $customCtrl);
            if (count($rawSegments) > 3) {
                throw new ApiException('控制器目录层级不能超过 3 层');
            }
            foreach ($rawSegments as $seg) {
                if (!preg_match(self::IDENTIFIER_PATTERN, $seg)) {
                    throw new ApiException('控制器名称非法，每段必须以英文字母开头且仅含字母、数字与下划线');
                }
            }
            $ctrlRel = $customCtrl;
        } else {
            $ctrlRel = $studlyShort;
        }

        $ctrlSegments = array_map([$this, 'studly'], explode('/', $ctrlRel));
        $ctrlClass = array_pop($ctrlSegments);
        if ($ctrlClass === null || !preg_match(self::IDENTIFIER_PATTERN, $ctrlClass)) {
            throw new ApiException('控制器类名非法');
        }
        $ctrlSubNs = $ctrlSegments ? ('\\' . strtolower(implode('\\', $ctrlSegments))) : '';
        $ctrlSubPath = $ctrlSegments ? (strtolower(implode(DIRECTORY_SEPARATOR, $ctrlSegments)) . DIRECTORY_SEPARATOR) : '';

        // 模型名称
        $customModel = trim((string) ($config['model'] ?? ''));
        if ($customModel !== '' && !preg_match(self::IDENTIFIER_PATTERN, $customModel)) {
            throw new ApiException('模型名称非法，必须以英文字母开头且仅含字母、数字与下划线');
        }
        $modelClass = $customModel !== '' ? $this->studly($customModel) : $studlyShort;
        if (!preg_match(self::IDENTIFIER_PATTERN, $modelClass)) {
            throw new ApiException('模型类名非法');
        }

        // 菜单名称（剔除 PHPDoc 闭合符与换行防止注释注入）
        $menuTitle = trim((string) ($config['menu_title'] ?? '')) ?: $this->guessTableComment($table);
        $menuTitle = str_replace(['*/', '/*', "\r", "\n", "\0"], ['', '', ' ', ' ', ''], $menuTitle);

        // 前端项目根目录识别
        $frontBase = dirname(root_path()) . DIRECTORY_SEPARATOR . 'admin-ui' . DIRECTORY_SEPARATOR;

        if ($plugin !== '') {
            $baseDir = plugin_path($plugin);
            $modelNs = "plugins\\{$plugin}\\model";
            $modelFile = $baseDir . 'model' . DIRECTORY_SEPARATOR . $modelClass . '.php';
            $validateNs = "plugins\\{$plugin}\\validate";
            $validateFile = $baseDir . 'validate' . DIRECTORY_SEPARATOR . $modelClass . '.php';
            $ctrlNs = "plugins\\{$plugin}\\controller\\admin" . $ctrlSubNs;
            $ctrlFile = $baseDir . 'controller' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . $ctrlSubPath . $ctrlClass . '.php';
            $nodePrefix = "plugin/{$plugin}/" . strtolower(implode('/', array_merge($ctrlSegments, [$this->snake($ctrlClass)])));
            $vueRelPath = "@/views/plugin/{$plugin}/" . strtolower(implode('/', array_merge($ctrlSegments, [$this->snake($ctrlClass)]))) . "/index.vue";
            $vueFile = $frontBase . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . strtolower(implode(DIRECTORY_SEPARATOR, array_merge($ctrlSegments, [$this->snake($ctrlClass)]))) . DIRECTORY_SEPARATOR . 'index.vue';
        } else {
            $baseDir = root_path() . 'app' . DIRECTORY_SEPARATOR;
            $modelNs = 'app\\common\\model';
            $modelFile = $baseDir . 'common' . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . $modelClass . '.php';
            $validateNs = 'app\\common\\validate';
            $validateFile = $baseDir . 'common' . DIRECTORY_SEPARATOR . 'validate' . DIRECTORY_SEPARATOR . $modelClass . '.php';
            $ctrlNs = 'app\\adminapi\\controller' . $ctrlSubNs;
            $ctrlFile = $baseDir . 'adminapi' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . $ctrlSubPath . $ctrlClass . '.php';
            $nodePrefix = strtolower(implode('/', array_merge($ctrlSegments, [$this->snake($ctrlClass)])));
            $vueRelPath = "@/views/" . strtolower(implode('/', array_merge($ctrlSegments, [$this->snake($ctrlClass)]))) . "/index.vue";
            $vueFile = $frontBase . 'src' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . strtolower(implode(DIRECTORY_SEPARATOR, array_merge($ctrlSegments, [$this->snake($ctrlClass)]))) . DIRECTORY_SEPARATOR . 'index.vue';
        }

        // 解析 columns 配置
        $columns = (array) ($config['columns'] ?? []);
        if (empty($columns)) {
            $columns = $this->getTableColumns($table);
        } else {
            foreach ($columns as $idx => $col) {
                $colName = (string) (is_array($col) ? ($col['name'] ?? '') : '');
                if ($colName === '' || !preg_match(self::IDENTIFIER_PATTERN, $colName)) {
                    throw new ApiException("第 " . ($idx + 1) . " 个字段名非法，仅允许字母开头及字母数字下划线");
                }
            }
        }

        return [
            'prefix'           => $prefix,
            'driver'           => $driver,
            'table'            => $table,
            'clean_table'      => $cleanTable,
            'plugin'           => $plugin,
            'controller_rel'   => $ctrlRel,
            'controller_class' => $ctrlClass,
            'controller_ns'    => $ctrlNs,
            'controller_file'  => $ctrlFile,
            'model_class'      => $modelClass,
            'model_ns'         => $modelNs,
            'model_file'       => $modelFile,
            'validate_class'   => $modelClass,
            'validate_ns'      => $validateNs,
            'validate_file'    => $validateFile,
            'node_prefix'      => $nodePrefix,
            'menu_title'       => $menuTitle,
            'parent_menu_id'   => (int) ($config['parent_menu_id'] ?? 0),
            'vue_rel_path'     => $vueRelPath,
            'vue_file'         => $vueFile,
            'columns'          => $columns,
            'data_scope'       => !empty($config['data_scope']),
        ];
    }

    /**
     * 渲染控制器 PHP 源码
     */
    protected function renderController(array $r): string
    {
        $columns = $r['columns'];
        $searchFields = [];
        $allowFields = [];
        $hiddenFields = [];
        $hasPassword = false;
        $passwordField = '';
        $dataScopeLines = '';

        foreach ($columns as $c) {
            $name = $c['name'];
            if (!empty($c['show_in_form'])) {
                if (preg_match('/^(password|pwd|pass)$/i', $name)) {
                    $hasPassword = true;
                    $passwordField = $name;
                } else {
                    $allowFields[] = $name;
                }
            }
            if (($c['query_type'] ?? 'NONE') === 'LIKE' || ($c['query_type'] ?? 'NONE') === '=') {
                $searchFields[] = $name;
            }
            if (preg_match('/(password|salt|token|secret|private)/i', $name)) {
                $hiddenFields[] = $name;
            }
        }

        $searchStr = $this->exportStringArray(array_unique($searchFields));
        $allowStr  = $this->exportStringArray(array_unique($allowFields));
        $hiddenStr = $hiddenFields ? ("\n    protected array \$hiddenFields = " . $this->exportStringArray(array_unique($hiddenFields)) . ";") : '';

        // 数据隔离
        if (!empty($r['data_scope'])) {
            $dataScopeLines = "\n    protected string \$dataScope = 'self';\n    protected string \$dataScopeField = 'admin_id';";
        }

        $beforeWriteBlock = '';
        if ($hasPassword && $passwordField !== '') {
            $addAllow = array_values(array_unique(array_merge($allowFields, [$passwordField])));
            $editAllow = array_values(array_unique(array_merge($allowFields, [$passwordField])));
            $addAllowStr = $this->exportStringArray($addAllow);
            $editAllowStr = $this->exportStringArray($editAllow);
            $allowFieldsBlock = "protected array \$addAllowFields = {$addAllowStr};\n    protected array \$editAllowFields = {$editAllowStr};";
            $beforeWriteBlock = <<<PHP


    /**
     * 写入前数据清洗与密码加密
     */
    protected function beforeWrite(array \$data, ?\\think\\Model \$row): array
    {
        if (isset(\$data['{$passwordField}'])) {
            \$raw = trim((string) \$data['{$passwordField}']);
            if (\$raw !== '') {
                \$data['{$passwordField}'] = \\core\\auth\\PasswordHasher::hash(\$raw);
            } else {
                unset(\$data['{$passwordField}']);
            }
        }
        return \$data;
    }
PHP;
        } else {
            $allowFieldsBlock = "protected array \$allowFields = {$allowStr};";
        }

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$r['controller_ns']};

use {$r['model_ns']}\\{$r['model_class']} as TargetModel;
use {$r['validate_ns']}\\{$r['validate_class']} as TargetValidate;
use core\base\AdminController;
use core\crud\CrudTrait;

/**
 * {$r['menu_title']}管理控制器（由 HwAdmin 可视化 CRUD 生成器自动生成）
 *
 * 权限规则节点：{$r['node_prefix']}/{index|read|add|edit|del|multi}
 */
class {$r['controller_class']} extends AdminController
{
    use CrudTrait;

    protected array \$searchFields = {$searchStr};
    {$allowFieldsBlock}{$hiddenStr}{$dataScopeLines}
    protected string|array \$defaultSort = 'id';
    protected ?string \$validateClass = TargetValidate::class;

    protected function initialize(): void
    {
        parent::initialize();
        \$this->model = new TargetModel();
    }{$beforeWriteBlock}
}

PHP;
    }

    /**
     * 渲染模型 PHP 源码
     */
    protected function renderModel(array $r): string
    {
        $columns = $r['columns'];
        $colNames = array_column($columns, 'name');
        $hasCreate = in_array('create_time', $colNames, true);
        $hasUpdate = in_array('update_time', $colNames, true);

        $autoTs = ($hasCreate || $hasUpdate) ? "protected \$autoWriteTimestamp = 'int';" : 'protected $autoWriteTimestamp = false;';
        $createAttr = $hasCreate ? "'create_time'" : 'false';
        $updateAttr = $hasUpdate ? "'update_time'" : 'false';

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$r['model_ns']};

use core\base\UnixTimeModel;

/**
 * {$r['menu_title']}数据模型（由 HwAdmin 可视化 CRUD 生成器自动生成）
 */
class {$r['model_class']} extends UnixTimeModel
{
    protected \$name = '{$r['clean_table']}';

    {$autoTs}
    protected \$createTime = {$createAttr};
    protected \$updateTime = {$updateAttr};
}

PHP;
    }

    /**
     * 渲染验证器 PHP 源码
     */
    protected function renderValidate(array $r): string
    {
        $columns = $r['columns'];
        $rules = [];
        $messages = [];
        $addScene = [];
        $editScene = [];

        foreach ($columns as $c) {
            $name = $c['name'];
            if (empty($c['show_in_form'])) {
                continue;
            }

            $type = strtolower((string) ($c['type'] ?? ''));
            $fieldRules = [];
            $title = $c['title'] ?: $name;

            if (str_contains($type, 'int')) {
                $fieldRules[] = 'number';
                $messages["{$name}.number"] = "{$title}必须为整数数值";
            } elseif (str_contains($type, 'decimal') || str_contains($type, 'float')) {
                $fieldRules[] = 'float';
                $messages["{$name}.float"] = "{$title}必须为合法浮点数";
            }

            if ($name === 'mobile' || $name === 'phone') {
                $fieldRules[] = 'mobile';
                $messages["{$name}.mobile"] = '手机号格式不正确';
            } elseif ($name === 'email') {
                $fieldRules[] = 'email';
                $messages["{$name}.email"] = '电子邮箱格式不正确';
            }

            if (!empty($c['required'])) {
                $rules[$name] = implode('|', array_merge(['require'], $fieldRules));
                $messages["{$name}.require"] = "{$title}不能为空";
                $addScene[$name] = $rules[$name];
                $editScene[$name] = $rules[$name];
            } else {
                if ($fieldRules) {
                    $rules[$name] = implode('|', $fieldRules);
                }
                $addScene[] = $name;
                $editScene[] = $name;
            }
        }

        $rulesExport    = $this->exportAssocArray($rules);
        $messagesExport = $this->exportAssocArray($messages);
        $addExport      = $this->exportSceneArray($addScene);
        $editExport     = $this->exportSceneArray($editScene);

        return <<<PHP
<?php
declare(strict_types=1);

namespace {$r['validate_ns']};

use think\Validate;

/**
 * {$r['menu_title']}数据验证器（由 HwAdmin 可视化 CRUD 生成器自动生成）
 */
class {$r['validate_class']} extends Validate
{
    protected \$rule = {$rulesExport};

    protected \$message = {$messagesExport};

    protected \$scene = [
        'add'  => {$addExport},
        'edit' => {$editExport},
    ];
}

PHP;
    }

    /**
     * 渲染高颜值 Vue 3 + TypeScript CRUD 管理页面源码
     */
    protected function renderVue(array $r): string
    {
        $columns = $r['columns'];
        $menuTitle = $r['menu_title'];
        $apiPrefix = $r['plugin'] !== ''
            ? "/adminapi/plugin/{$r['plugin']}/" . strtolower(str_replace('.', '/', $r['controller_rel']))
            : "/adminapi/" . strtolower(str_replace('.', '/', $r['controller_rel']));

        // 提取搜索字段
        $searchInputs = [];
        foreach ($columns as $c) {
            $q = $c['query_type'] ?? 'NONE';
            if ($q !== 'NONE') {
                $searchInputs[] = $c;
            }
        }

        // 提取列表展示字段
        $listCols = array_filter($columns, static fn ($c) => !empty($c['show_in_list']));

        // 提取表单字段
        $formCols = array_filter($columns, static fn ($c) => !empty($c['show_in_form']));

        // 构建搜索表单 HTML
        $searchFormHtml = '';
        foreach ($searchInputs as $sc) {
            $name = $sc['name'];
            $label = $sc['title'];
            $q = $sc['query_type'];
            if ($q === 'LIKE') {
                $searchFormHtml .= <<<HTML

        <el-form-item label="{$label}">
          <el-input
            v-model="queryForm.{$name}"
            placeholder="搜索{$label}"
            clearable
            @keyup.enter="fetchList"
          />
        </el-form-item>
HTML;
            } elseif ($q === '=') {
                if (($sc['control_type'] ?? '') === 'switch' || $name === 'status') {
                    $searchFormHtml .= <<<HTML

        <el-form-item label="{$label}">
          <el-select v-model="queryForm.{$name}" placeholder="全部状态" clearable style="width: 130px">
            <el-option label="正常" :value="1" />
            <el-option label="禁用" :value="0" />
          </el-select>
        </el-form-item>
HTML;
                } else {
                    $searchFormHtml .= <<<HTML

        <el-form-item label="{$label}">
          <el-input
            v-model="queryForm.{$name}"
            placeholder="精确匹配{$label}"
            clearable
            @keyup.enter="fetchList"
          />
        </el-form-item>
HTML;
                }
            } elseif ($q === 'BETWEEN') {
                $searchFormHtml .= <<<HTML

        <el-form-item label="{$label}">
          <el-date-picker
            v-model="queryForm.{$name}"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
          />
        </el-form-item>
HTML;
            }
        }

        // 构建表格列 HTML
        $tableColsHtml = '';
        foreach ($listCols as $lc) {
            $name = $lc['name'];
            $label = $lc['title'];
            $ctrl = $lc['control_type'] ?? 'input';

            if ($name === 'id') {
                $tableColsHtml .= <<<HTML

        <el-table-column prop="id" label="ID" width="80" align="center" sortable />
HTML;
            } elseif ($ctrl === 'image') {
                $tableColsHtml .= <<<HTML

        <el-table-column prop="{$name}" label="{$label}" width="100" align="center">
          <template #default="{ row }">
            <el-image
              v-if="row.{$name}"
              :src="row.{$name}"
              :preview-src-list="[row.{$name}]"
              preview-teleported
              fit="cover"
              style="width: 48px; height: 48px; border-radius: 6px;"
            />
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
HTML;
            } elseif ($ctrl === 'switch') {
                $tableColsHtml .= <<<HTML

        <el-table-column prop="{$name}" label="{$label}" width="100" align="center">
          <template #default="{ row }">
            <el-switch
              v-model="row.{$name}"
              :active-value="1"
              :inactive-value="0"
              @change="handleSwitchChange(row, '{$name}')"
            />
          </template>
        </el-table-column>
HTML;
            } elseif ($ctrl === 'datetime') {
                $tableColsHtml .= <<<HTML

        <el-table-column prop="{$name}" label="{$label}" min-width="165" align="center">
          <template #default="{ row }">
            {{ formatTime(row.{$name}) }}
          </template>
        </el-table-column>
HTML;
            } else {
                $tableColsHtml .= <<<HTML

        <el-table-column prop="{$name}" label="{$label}" min-width="120" show-overflow-tooltip />
HTML;
            }
        }

        // 构建表单控件 HTML
        $formItemsHtml = '';
        foreach ($formCols as $fc) {
            $name = $fc['name'];
            $label = $fc['title'];
            $ctrl = $fc['control_type'] ?? 'input';
            $req = !empty($fc['required']);
            $propAttr = $req ? " prop=\"{$name}\"" : '';

            if ($ctrl === 'number') {
                $formItemsHtml .= <<<HTML

        <el-form-item label="{$label}"{$propAttr}>
          <el-input-number v-model="form.{$name}" :min="0" style="width: 100%" />
        </el-form-item>
HTML;
            } elseif ($ctrl === 'switch') {
                $formItemsHtml .= <<<HTML

        <el-form-item label="{$label}"{$propAttr}>
          <el-switch v-model="form.{$name}" :active-value="1" :inactive-value="0" />
        </el-form-item>
HTML;
            } elseif ($ctrl === 'textarea') {
                $formItemsHtml .= <<<HTML

        <el-form-item label="{$label}"{$propAttr}>
          <el-input v-model="form.{$name}" type="textarea" :rows="3" placeholder="请输入{$label}" />
        </el-form-item>
HTML;
            } elseif ($ctrl === 'image') {
                $formItemsHtml .= <<<HTML

        <el-form-item label="{$label}"{$propAttr}>
          <el-input v-model="form.{$name}" placeholder="请输入图片URL或上传地址" clearable />
        </el-form-item>
HTML;
            } elseif ($ctrl === 'password') {
                $formItemsHtml .= <<<HTML

        <el-form-item label="{$label}"{$propAttr}>
          <el-input
            v-model="form.{$name}"
            type="password"
            show-password
            :placeholder="isEdit ? '留空则保持原密码不变' : '请输入{$label}'"
          />
        </el-form-item>
HTML;
            } elseif ($ctrl === 'datetime') {
                $formItemsHtml .= <<<HTML

        <el-form-item label="{$label}"{$propAttr}>
          <el-date-picker
            v-model="form.{$name}"
            type="datetime"
            placeholder="请选择{$label}"
            value-format="YYYY-MM-DD HH:mm:ss"
            style="width: 100%"
          />
        </el-form-item>
HTML;
            } else {
                $formItemsHtml .= <<<HTML

        <el-form-item label="{$label}"{$propAttr}>
          <el-input v-model="form.{$name}" placeholder="请输入{$label}" clearable />
        </el-form-item>
HTML;
            }
        }

        // 构建默认表单字段与重置对象
        $formDefaultFields = [];
        $formRules = [];
        foreach ($formCols as $fc) {
            $name = $fc['name'];
            $ctrl = $fc['control_type'] ?? 'input';
            if ($ctrl === 'number') {
                $formDefaultFields[] = "  {$name}: 0,";
            } elseif ($ctrl === 'switch') {
                $formDefaultFields[] = "  {$name}: 1,";
            } else {
                $formDefaultFields[] = "  {$name}: '',";
            }
            if (!empty($fc['required'])) {
                $formRules[] = "  {$name}: [{ required: true, message: '{$fc['title']}不能为空', trigger: 'blur' }],";
            }
        }
        $formDefaultStr = implode("\n", $formDefaultFields);
        $rulesStr = implode("\n", $formRules);

        // 构建初始 queryForm 属性
        $queryFormFields = [];
        foreach ($searchInputs as $sc) {
            $queryFormFields[] = "  {$sc['name']}: undefined as any,";
        }
        $queryFormStr = $queryFormFields ? implode("\n", $queryFormFields) : "  // 无搜索字段";

        return <<<VUE
<template>
  <div class="crud-view-container">
    <!-- 顶部搜索筛选卡片 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form :inline="true" :model="queryForm" class="query-form" @submit.prevent>{$searchFormHtml}
        <el-form-item>
          <el-button type="primary" @click="handleSearch">
            <el-icon><vab-icon icon="search-line" /></el-icon>
            查询
          </el-button>
          <el-button @click="handleReset">
            <el-icon><vab-icon icon="refresh-line" /></el-icon>
            重置
          </el-button>
        </el-form-item>
      </el-form>

      <div class="action-buttons">
        <el-button type="primary" @click="handleAdd">
          <el-icon><vab-icon icon="add-line" /></el-icon>
          新建{$menuTitle}
        </el-button>
        <el-button
          type="danger"
          :disabled="selectedRows.length === 0"
          @click="handleBatchDelete"
        >
          <el-icon><vab-icon icon="delete-bin-line" /></el-icon>
          批量删除 ({{ selectedRows.length }})
        </el-button>
      </div>
    </el-card>

    <!-- 数据表格卡片 -->
    <el-card shadow="never" class="table-card">
      <el-table
        v-loading="loading"
        :data="tableData"
        row-key="id"
        border
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="50" align="center" />{$tableColsHtml}
        <el-table-column label="操作" width="168" fixed="right" align="center">
          <template #default="{ row }">
            <div class="hw-table-actions">
              <el-button plain type="primary" size="small" @click="handleEdit(row)">编辑</el-button>
              <el-button plain type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <!-- 底部分页 -->
      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.limit"
          :total="pagination.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </el-card>

    <!-- 新增 / 编辑对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑{$menuTitle}' : '新建{$menuTitle}'"
      width="640px"
      destroy-on-close
      @closed="resetForm"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="100px"
        status-icon
      >{$formItemsHtml}
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确认提交</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import request from '/@/utils/request'
import dayjs from 'dayjs'

defineOptions({
  name: '{$r['controller_class']}Crud',
})

const API_BASE = '{$apiPrefix}'

const loading = ref(false)
const submitLoading = ref(false)
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<number | null>(null)
const selectedRows = ref<any[]>([])
const tableData = ref<any[]>([])

const queryForm = reactive({
{$queryFormStr}
})

const pagination = reactive({
  page: 1,
  limit: 10,
  total: 0,
})

const formRef = ref<FormInstance>()
const form = reactive({
{$formDefaultStr}
})

const rules = reactive<FormRules>({
{$rulesStr}
})

const formatTime = (ts: any) => {
  if (!ts) return '-'
  if (typeof ts === 'number') {
    return dayjs.unix(ts).format('YYYY-MM-DD HH:mm:ss')
  }
  return dayjs(ts).format('YYYY-MM-DD HH:mm:ss')
}

const fetchList = async () => {
  loading.value = true
  try {
    const params: any = {
      page: pagination.page,
      limit: pagination.limit,
      ...queryForm,
    }
    const res: any = await request({
      url: `\${API_BASE}/index`,
      method: 'get',
      params,
    })
    tableData.value = res.data?.list || []
    pagination.total = res.data?.total || 0
  } catch (error) {
    // 拦截器已统一抛出错误提示
  } finally {
    loading.value = false
  }
}

const handleSearch = () => {
  pagination.page = 1
  fetchList()
}

const handleReset = () => {
  Object.keys(queryForm).forEach((key) => {
    (queryForm as any)[key] = undefined
  })
  pagination.page = 1
  fetchList()
}

const handleSelectionChange = (rows: any[]) => {
  selectedRows.value = rows
}

const handleAdd = () => {
  isEdit.value = false
  editId.value = null
  resetForm()
  dialogVisible.value = true
}

const handleEdit = (row: any) => {
  isEdit.value = true
  editId.value = row.id
  Object.keys(form).forEach((k) => {
    if (row[k] !== undefined) {
      ;(form as any)[k] = row[k]
    }
  })
  dialogVisible.value = true
}

const handleDelete = (row: any) => {
  ElMessageBox.confirm(`确定要删除选中的记录 (ID: \${row.id}) 吗？此操作不可逆！`, '安全删除警告', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await request({
        url: `\${API_BASE}/del`,
        method: 'post',
        data: { id: row.id },
      })
      ElMessage.success('删除成功')
      fetchList()
    } catch (e) {}
  })
}

const handleBatchDelete = () => {
  const ids = selectedRows.value.map((r) => r.id)
  if (!ids.length) return
  ElMessageBox.confirm(`确定要批量删除选中的 \${ids.length} 条记录吗？`, '批量操作确认', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  }).then(async () => {
    try {
      await request({
        url: `\${API_BASE}/del`,
        method: 'post',
        data: { id: ids },
      })
      ElMessage.success('批量删除成功')
      fetchList()
    } catch (e) {}
  })
}

const handleSwitchChange = async (row: any, field: string) => {
  try {
    await request({
      url: `\${API_BASE}/multi`,
      method: 'post',
      data: {
        id: row.id,
        [field]: row[field],
      },
    })
    ElMessage.success('状态更新成功')
  } catch (error) {
    row[field] = row[field] === 1 ? 0 : 1
  }
}

const handleSubmit = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitLoading.value = true
    try {
      if (isEdit.value && editId.value) {
        await request({
          url: `\${API_BASE}/edit`,
          method: 'post',
          data: { id: editId.value, ...form },
        })
        ElMessage.success('修改成功')
      } else {
        await request({
          url: `\${API_BASE}/add`,
          method: 'post',
          data: form,
        })
        ElMessage.success('创建成功')
      }
      dialogVisible.value = false
      fetchList()
    } catch (e) {
    } finally {
      submitLoading.value = false
    }
  })
}

const resetForm = () => {
  if (formRef.value) {
    formRef.value.resetFields()
  }
}

onMounted(() => {
  fetchList()
})
</script>

<style scoped lang="scss">
.crud-view-container {
  padding: 16px;

  .toolbar-card {
    margin-bottom: 16px;

    .query-form {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .action-buttons {
      margin-top: 12px;
      display: flex;
      gap: 10px;
    }
  }

  .table-card {
    .pagination-wrapper {
      margin-top: 16px;
      display: flex;
      justify-content: flex-end;
    }
  }

  .text-muted {
    color: #909399;
  }
}
</style>
VUE;
    }

    /**
     * 安全写入文件并自动创建父目录
     */
    protected function safeWriteFile(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new ApiException("无法创建目标目录：{$dir}");
            }
        }
        if (file_put_contents($filePath, $content) === false) {
            throw new ApiException("写入文件失败：{$filePath}");
        }
    }

    /**
     * 追加历史记录
     */
    protected function appendHistory(array $record): void
    {
        $historyDir = runtime_path() . 'crud';
        if (!is_dir($historyDir)) {
            @mkdir($historyDir, 0755, true);
        }
        $file = $historyDir . DIRECTORY_SEPARATOR . 'history.json';
        $list = [];
        if (is_file($file)) {
            $raw = json_decode((string) file_get_contents($file), true);
            if (is_array($raw)) {
                $list = $raw;
            }
        }
        $list[] = $record;
        // 限制最多保存 100 条
        if (count($list) > 100) {
            $list = array_slice($list, -100);
        }
        file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 判断数据表是否存在
     */
    protected function tableExists(string $tableName): bool
    {
        [$driver] = $this->getDbConfig();
        try {
            if ($driver === 'sqlite') {
                $res = Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tableName}'");
                return !empty($res);
            } elseif ($driver === 'pgsql') {
                $res = Db::query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name='{$tableName}'");
                return !empty($res);
            } else {
                $res = Db::query("SHOW TABLES LIKE '{$tableName}'");
                return !empty($res);
            }
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 判断是否系统核心表
     */
    protected function isSystemTable(string $table, string $prefix): bool
    {
        $clean = $this->stripPrefix($table, $prefix);
        return in_array($clean, self::SYSTEM_PROTECTED_TABLES, true);
    }

    /**
     * 判断是否属于插件的数据表
     */
    protected function isPluginTable(string $table, string $prefix): bool
    {
        $clean = $this->stripPrefix($table, $prefix);
        return (bool) preg_match('/^(cms|oss|sms|quiz|crud|mall|shop)_/', $clean);
    }

    /**
     * 剥离表前缀
     */
    protected function stripPrefix(string $table, string $prefix): string
    {
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            return substr($table, strlen($prefix));
        }
        return $table;
    }

    /**
     * 根据表名猜测中文注释
     */
    protected function guessTableComment(string $table): string
    {
        [$driver, $prefix] = $this->getDbConfig();
        $clean = $this->stripPrefix($table, $prefix);

        $map = [
            'admin'             => '系统管理员',
            'admin_log'         => '操作日志',
            'auth_group'        => '角色组',
            'auth_group_access' => '角色管理员关联',
            'auth_rule'         => '权限规则与菜单',
            'system_config'     => '系统配置',
            'user'              => '会员信息',
            'user_token'        => '会员令牌',
            'cms_category'      => 'CMS栏目分类',
            'cms_article'       => 'CMS内容与产品',
            'cms_banner'        => 'CMS横幅Banner',
            'cms_inquiry'       => 'CMS留言询单',
            'product'           => '商品信息',
            'goods'             => '商城商品',
            'order'             => '交易订单',
            'article'           => '文章管理',
            'category'          => '分类管理',
            'shop_goods'        => '商城商品',
        ];

        return $map[$clean] ?? ucwords(str_replace('_', ' ', $clean));
    }

    protected function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    protected function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    protected function validateColumnLength(string $type, string $length, int $idx): string
    {
        if ($length === '') {
            return '';
        }
        if (in_array($type, ['decimal', 'float', 'double'], true)) {
            if (!preg_match('/^(\d{1,2})(?:,(\d{1,2}))?$/', $length, $m)) {
                throw new ApiException("第 " . ($idx + 1) . " 个字段长度格式非法");
            }
            $precision = (int) $m[1];
            $scale = isset($m[2]) ? (int) $m[2] : 0;
            if ($precision < 1 || $precision > 65 || $scale > 30 || $scale > $precision) {
                throw new ApiException("第 " . ($idx + 1) . " 个字段精度范围非法");
            }
            return $length;
        }
        if (!preg_match('/^\d{1,5}$/', $length)) {
            throw new ApiException("第 " . ($idx + 1) . " 个字段长度必须为纯数字");
        }
        $num = (int) $length;
        if ($num < 1 || $num > 65535) {
            throw new ApiException("第 " . ($idx + 1) . " 个字段长度超出合法范围 (1-65535)");
        }
        if (in_array($type, ['char', 'tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true) && $num > 255) {
            throw new ApiException("第 " . ($idx + 1) . " 个字段长度超出该类型允许最大值 (255)");
        }
        return (string) $num;
    }

    protected function formatSqlDefault(mixed $value, string $driver): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $str = (string) $value;
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $str)) {
            return $str;
        }
        return $this->quoteSqlLiteral($str, $driver);
    }

    protected function quoteSqlLiteral(string $value, string $driver): string
    {
        $clean = str_replace("\0", '', $value);
        if ($driver === 'mysql') {
            return "'" . str_replace(["\\", "'", "\r", "\n"], ["\\\\", "\\'", "\\r", "\\n"], $clean) . "'";
        }
        return "'" . str_replace("'", "''", $clean) . "'";
    }

    protected function exportStringArray(array $items): string
    {
        if (!$items) {
            return '[]';
        }
        return '[' . implode(', ', array_map(static fn ($s) => "'" . addslashes((string) $s) . "'", $items)) . ']';
    }

    protected function exportAssocArray(array $items): string
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

    protected function exportSceneArray(array $items): string
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
}
