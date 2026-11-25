<?php
declare(strict_types=1);

namespace core\support;

use think\App;

/**
 * 项目描述文件 (hw.project.json) 解析、完整 JSON Schema 校验、状态管理与契约导出服务（R12）
 */
class ProjectSchemaService
{
    protected string $projectRoot;
    protected string $stateDir;

    public function __construct(protected ?App $app = null)
    {
        $root = $app ? dirname($app->getRootPath()) : dirname(__DIR__, 3);
        if (!is_file($root . DIRECTORY_SEPARATOR . 'hw.project.json') && is_file(dirname($root) . DIRECTORY_SEPARATOR . 'hw.project.json')) {
            $root = dirname($root);
        }
        $this->projectRoot = $root;
        $this->stateDir = $this->projectRoot . DIRECTORY_SEPARATOR . '.hw-state';
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0755, true);
        }
    }

    /**
     * 读取并解析 hw.project.json
     *
     * @param string $path 自定义描述文件路径
     * @return array<string, mixed>
     */
    public function load(string $path = ''): array
    {
        $filePath = $path !== '' ? $path : ($this->projectRoot . DIRECTORY_SEPARATOR . 'hw.project.json');
        if (!is_file($filePath)) {
            return [];
        }
        $content = (string) file_get_contents($filePath);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 读取 server/core/support/hw.project.schema.json 协议定义
     *
     * @return array<string, mixed>
     */
    public function loadSchema(): array
    {
        $candidates = [
            __DIR__ . DIRECTORY_SEPARATOR . 'hw.project.schema.json',
            $this->projectRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'hw.project.schema.json',
            $this->projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'schemas' . DIRECTORY_SEPARATOR . 'hw.project.schema.json',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return [];
    }

    /**
     * 完整校验应用描述数据结构合规性（递归 JSON Schema 校验 + 领域模型与引用完整性校验）
     *
     * @param array<string, mixed>|null $data
     * @return array{valid: bool, errors: string[], warnings: string[]}
     */
    public function validate(?array $data = null): array
    {
        if ($data === null) {
            $data = $this->load();
        }

        $errors = [];
        $warnings = [];

        if (empty($data) || array_is_list($data)) {
            $errors[] = '未找到有效的 hw.project.json 应用描述对象';
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        // 1. 基于 hw.project.schema.json 执行递归 Schema 校验
        $schema = $this->loadSchema();
        if (!empty($schema)) {
            $this->validateNodeAgainstSchema($data, $schema, '$', $errors);
        }

        // 2. 核心基础字段与严格语义化版本校验
        $required = ['app_id', 'app_name', 'app_version', 'framework_version', 'clients'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '' || $data[$field] === []) {
                $errors[] = "缺少必需的基础属性: [{$field}]";
            }
        }

        if (isset($data['app_id'])) {
            if (!is_string($data['app_id']) || !preg_match('/^[a-z0-9_-]+$/', $data['app_id'])) {
                $errors[] = 'app_id 格式非法，仅允许小写字母、数字、下划线与中划线';
            }
        }

        if (isset($data['app_name']) && (!is_string($data['app_name']) || trim($data['app_name']) === '')) {
            $errors[] = 'app_name 必须为非空字符串';
        }

        $semverRegex = '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';
        foreach (['app_version', 'framework_version'] as $verField) {
            if (isset($data[$verField])) {
                if (!is_string($data[$verField]) || !preg_match($semverRegex, $data[$verField])) {
                    $errors[] = "{$verField} [" . (is_scalar($data[$verField]) ? (string) $data[$verField] : gettype($data[$verField])) . '] 不是合法的语义化版本号 (x.y.z)';
                }
            }
        }

        // 3. 客户端列表枚举校验（拒绝非数组或空数组或非法枚举项）
        $validClients = ['admin-pc', 'mobile-h5', 'mobile-mp-weixin', 'desktop-tauri'];
        if (array_key_exists('clients', $data)) {
            if (!is_array($data['clients']) || !array_is_list($data['clients']) || empty($data['clients'])) {
                $errors[] = 'clients 必须为非空字符串数组';
            } else {
                foreach ($data['clients'] as $idx => $c) {
                    if (!is_string($c) || !in_array($c, $validClients, true)) {
                        $valStr = is_scalar($c) ? (string) $c : gettype($c);
                        $errors[] = "clients[{$idx}] 包含不支持的客户端类型 [{$valStr}]，有效类型为: " . implode(', ', $validClients);
                    }
                }
            }
        }

        // 4. 实体结构与字段深度校验（拒绝非对象映射、非法表名、非法字段与复合主键）
        $supportedTypes = [
            'string', 'integer', 'biginteger', 'smallinteger', 'boolean',
            'decimal', 'text', 'longtext', 'json', 'datetime', 'date', 'timestamp', 'float',
        ];

        $entityFieldMap = [];
        $entityTableMap = [];

        if (array_key_exists('entities', $data)) {
            if (!is_array($data['entities']) || (!empty($data['entities']) && array_is_list($data['entities']))) {
                $errors[] = 'entities 必须为以实体标识为键的对象映射 (object)';
            } else {
                foreach ($data['entities'] as $entityKey => $entity) {
                    if (!is_string($entityKey) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $entityKey)) {
                        $errors[] = "实体键名 [{$entityKey}] 格式非法";
                    }
                    if (!is_array($entity) || array_is_list($entity)) {
                        $errors[] = "实体 [{$entityKey}] 定义必须为对象结构";
                        continue;
                    }
                    if (empty($entity['table']) || !is_string($entity['table'])) {
                        $errors[] = "实体 [{$entityKey}] 缺少有效的 table 属性";
                        continue;
                    }
                    if (!preg_match('/^[a-z0-9_]+$/', $entity['table'])) {
                        $errors[] = "实体 [{$entityKey}] 的数据表名 [{$entity['table']}] 格式非法";
                    }
                    if (empty($entity['title']) || !is_string($entity['title'])) {
                        $errors[] = "实体 [{$entityKey}] 缺少有效的 title 属性";
                    }

                    $entityTableMap[$entity['table']] = $entityKey;

                    $fields = $entity['fields'] ?? null;
                    if (!is_array($fields) || !array_is_list($fields) || empty($fields)) {
                        $errors[] = "实体 [{$entityKey}] 的 fields 必须为非空数组且至少定义一个字段";
                        continue;
                    }

                    $primaryCount = 0;
                    $seenFields = [];

                    foreach ($fields as $fIdx => $field) {
                        if (!is_array($field) || array_is_list($field)) {
                            $errors[] = "实体 [{$entityKey}].fields[{$fIdx}] 必须为对象";
                            continue;
                        }
                        $fieldName = $field['name'] ?? '';
                        $fieldType = $field['type'] ?? '';
                        if (!is_string($fieldName) || $fieldName === '' || !is_string($fieldType) || $fieldType === '') {
                            $errors[] = "实体 [{$entityKey}] 存在未定义有效 name 或 type 的字段";
                            continue;
                        }
                        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
                            $errors[] = "实体 [{$entityKey}] 字段名 [{$fieldName}] 格式非法";
                        }
                        if (isset($seenFields[$fieldName])) {
                            $errors[] = "实体 [{$entityKey}] 存在重复字段名 [{$fieldName}]";
                        }
                        $seenFields[$fieldName] = true;

                        if (!in_array($fieldType, $supportedTypes, true)) {
                            $errors[] = "实体 [{$entityKey}].{$fieldName} 类型 [{$fieldType}] 不受支持";
                        }
                        if (!empty($field['primary'])) {
                            $primaryCount++;
                        }
                        if ($fieldType === 'decimal') {
                            if (!isset($field['precision']) || !isset($field['scale'])) {
                                $warnings[] = "实体 [{$entityKey}].{$fieldName} 为 decimal 类型，建议显式声明 precision 与 scale";
                            }
                        }
                    }

                    $entityFieldMap[$entityKey] = $seenFields;

                    // 显式拒绝复合主键
                    if ($primaryCount > 1) {
                        $errors[] = "实体 [{$entityKey}] 声明了多个主键字段（检测到 {$primaryCount} 个），不支持复合主键，HwAdmin 实体仅支持单列主键";
                    }
                    if (array_key_exists('primary_key', $entity)) {
                        $declaredPk = $entity['primary_key'];
                        if (!is_string($declaredPk) || $declaredPk === '' || !isset($seenFields[$declaredPk])) {
                            $errors[] = "实体 [{$entityKey}] 声明的 primary_key [" . (is_scalar($declaredPk) ? (string) $declaredPk : gettype($declaredPk)) . '] 不存在于 fields 中';
                        }
                    }

                    // 校验索引引用字段
                    if (array_key_exists('indexes', $entity)) {
                        if (!is_array($entity['indexes']) || (!empty($entity['indexes']) && !array_is_list($entity['indexes']))) {
                            $errors[] = "实体 [{$entityKey}].indexes 必须为数组";
                        } else {
                            foreach ($entity['indexes'] as $iIdx => $indexDef) {
                                if (!is_array($indexDef) || empty($indexDef['columns']) || !is_array($indexDef['columns']) || !array_is_list($indexDef['columns'])) {
                                    $errors[] = "实体 [{$entityKey}].indexes[{$iIdx}] 必须包含非空 columns 数组";
                                    continue;
                                }
                                foreach ($indexDef['columns'] as $colName) {
                                    if (!is_string($colName) || !isset($seenFields[$colName])) {
                                        $errors[] = "实体 [{$entityKey}].indexes[{$iIdx}] 引用了不存在的字段 [" . (is_scalar($colName) ? (string) $colName : gettype($colName)) . ']';
                                    }
                                }
                            }
                        }
                    }

                    foreach (['public_fields', 'submit_fields'] as $apiListKey) {
                        if (array_key_exists($apiListKey, $entity)) {
                            if (!is_array($entity[$apiListKey]) || (!empty($entity[$apiListKey]) && !array_is_list($entity[$apiListKey]))) {
                                $errors[] = "实体 [{$entityKey}].{$apiListKey} 必须为字符串数组";
                            } else {
                                foreach ($entity[$apiListKey] as $colName) {
                                    if (!is_string($colName) || !isset($seenFields[$colName])) {
                                        $errors[] = "实体 [{$entityKey}].{$apiListKey} 引用了不存在的字段 [" . (is_scalar($colName) ? (string) $colName : gettype($colName)) . ']';
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // 5. 校验实体关联引用完整性 (relations)
        if (array_key_exists('relations', $data)) {
            if (!is_array($data['relations']) || (!empty($data['relations']) && !array_is_list($data['relations']))) {
                $errors[] = 'relations 必须为关联定义数组';
            } else {
                $validRelTypes = ['belongs_to', 'has_one', 'has_many', 'many_to_many'];
                foreach ($data['relations'] as $rIdx => $rel) {
                    if (!is_array($rel) || array_is_list($rel)) {
                        $errors[] = "relations[{$rIdx}] 必须为对象";
                        continue;
                    }
                    $from = $rel['from'] ?? '';
                    $to = $rel['to'] ?? '';
                    $relType = $rel['type'] ?? '';
                    $fk = $rel['foreign_key'] ?? '';

                    if (!is_string($from) || $from === '' || !is_string($to) || $to === '' || !is_string($fk) || $fk === '') {
                        $errors[] = "relations[{$rIdx}] 缺少有效的 from / to / foreign_key 属性";
                        continue;
                    }
                    if (!is_string($relType) || !in_array($relType, $validRelTypes, true)) {
                        $errors[] = "relations[{$rIdx}] 关联类型 [" . (is_scalar($relType) ? (string) $relType : gettype($relType)) . '] 不受支持';
                    }
                    $fromExists = isset($entityFieldMap[$from]) || isset($entityTableMap[$from]);
                    $toExists = isset($entityFieldMap[$to]) || isset($entityTableMap[$to]);
                    if (!$fromExists) {
                        $errors[] = "relations[{$rIdx}].from 引用了未定义的实体 [{$from}]";
                    }
                    if (!$toExists) {
                        $errors[] = "relations[{$rIdx}].to 引用了未定义的实体 [{$to}]";
                    }
                }
            }
        }

        // 6. 校验状态机引用完整性 (state_machines)
        if (array_key_exists('state_machines', $data)) {
            if (!is_array($data['state_machines']) || (!empty($data['state_machines']) && array_is_list($data['state_machines']))) {
                $errors[] = 'state_machines 必须为对象映射';
            } else {
                foreach ($data['state_machines'] as $smKey => $sm) {
                    if (!is_array($sm) || array_is_list($sm)) {
                        $errors[] = "state_machines.{$smKey} 必须为对象";
                        continue;
                    }
                    $smEntity = (string) ($sm['entity'] ?? '');
                    $smField = (string) ($sm['field'] ?? '');
                    $states = $sm['states'] ?? null;
                    $transitions = $sm['transitions'] ?? null;

                    if ($smEntity === '' || $smField === '' || !is_array($states) || empty($states) || !is_array($transitions)) {
                        $errors[] = "state_machines.{$smKey} 缺少有效的 entity / field / states / transitions 定义";
                        continue;
                    }
                    $resolvedEntity = isset($entityFieldMap[$smEntity]) ? $smEntity : ($entityTableMap[$smEntity] ?? '');
                    if ($resolvedEntity === '') {
                        $errors[] = "state_machines.{$smKey} 引用了不存在的实体 [{$smEntity}]";
                    } elseif (!isset($entityFieldMap[$resolvedEntity][$smField])) {
                        $errors[] = "state_machines.{$smKey} 引用了实体 [{$resolvedEntity}] 中不存在的字段 [{$smField}]";
                    }
                    $stateKeys = array_map('strval', array_keys($states));
                    foreach ($transitions as $tIdx => $tr) {
                        if (!is_array($tr) || !isset($tr['from'], $tr['to'], $tr['action'])) {
                            $errors[] = "state_machines.{$smKey}.transitions[{$tIdx}] 缺少 from / to / action";
                            continue;
                        }
                        if (!in_array((string) $tr['from'], $stateKeys, true) || !in_array((string) $tr['to'], $stateKeys, true)) {
                            $errors[] = "state_machines.{$smKey}.transitions[{$tIdx}] 引用了未在 states 中声明的状态值";
                        }
                    }
                }
            }
        }

        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));
        $valid = empty($errors);

        $this->saveState('validation-result', [
            'checked_at' => time(),
            'valid'      => $valid,
            'errors'     => $errors,
            'warnings'   => $warnings,
        ]);

        return [
            'valid'    => $valid,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * 递归执行 JSON Schema 节点校验
     *
     * @param array<string, mixed> $schema
     * @param string[]             $errors
     */
    protected function validateNodeAgainstSchema(mixed $value, array $schema, string $path, array &$errors): void
    {
        $expectedType = $schema['type'] ?? null;

        if (is_string($expectedType)) {
            $allowNumericKeyMap = $expectedType === 'object'
                && isset($schema['additionalProperties'])
                && empty($schema['properties']);

            $typeOk = match ($expectedType) {
                'object'  => is_array($value) && (empty($value) || !array_is_list($value) || $allowNumericKeyMap),
                'array'   => is_array($value) && array_is_list($value),
                'string'  => is_string($value),
                'integer' => is_int($value),
                'number'  => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                default   => true,
            };

            if (!$typeOk) {
                $actual = is_array($value) ? (array_is_list($value) ? 'array(list)' : 'object(map)') : gettype($value);
                $errors[] = "Schema 校验失败 [{$path}]: 期望类型为 {$expectedType}，实际为 {$actual}";
                return;
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $valStr = is_scalar($value) ? (string) $value : gettype($value);
                $errors[] = "Schema 校验失败 [{$path}]: 值 [{$valStr}] 不在允许枚举列表 [" . implode(', ', array_map('strval', $schema['enum'])) . '] 中';
            }
        }

        if (is_string($value) && isset($schema['pattern']) && is_string($schema['pattern'])) {
            $regex = '/' . str_replace('/', '\/', $schema['pattern']) . '/';
            if (@preg_match($regex, $value) !== 1) {
                $errors[] = "Schema 校验失败 [{$path}]: 字符串 [{$value}] 不匹配正则约束 {$schema['pattern']}";
            }
        }

        if ($expectedType === 'array' && is_array($value)) {
            $itemSchema = $schema['items'] ?? null;
            if (is_array($itemSchema)) {
                foreach ($value as $idx => $item) {
                    $this->validateNodeAgainstSchema($item, $itemSchema, "{$path}[{$idx}]", $errors);
                }
            }
        }

        if ($expectedType === 'object' && is_array($value)) {
            if (isset($schema['required']) && is_array($schema['required'])) {
                foreach ($schema['required'] as $reqField) {
                    if (!is_string($reqField)) {
                        continue;
                    }
                    if (!array_key_exists($reqField, $value) || $value[$reqField] === null || $value[$reqField] === '') {
                        $errors[] = "Schema 校验失败 [{$path}]: 缺少必填字段 [{$reqField}]";
                    }
                }
            }

            $props = (isset($schema['properties']) && is_array($schema['properties'])) ? $schema['properties'] : [];
            $additional = $schema['additionalProperties'] ?? null;

            foreach ($value as $k => $v) {
                if ($k === '$schema') {
                    continue;
                }
                $childPath = "{$path}.{$k}";
                if (isset($props[$k]) && is_array($props[$k])) {
                    $this->validateNodeAgainstSchema($v, $props[$k], $childPath, $errors);
                } elseif (is_array($additional)) {
                    $this->validateNodeAgainstSchema($v, $additional, $childPath, $errors);
                }
            }
        }
    }

    /**
     * 获取项目概览状态与建模指标
     */
    public function getStatus(): array
    {
        $data = $this->load();
        $entities = (isset($data['entities']) && is_array($data['entities']) && !array_is_list($data['entities'])) ? $data['entities'] : [];
        $modules = (isset($data['modules']) && is_array($data['modules'])) ? $data['modules'] : [];
        $clients = (isset($data['clients']) && is_array($data['clients'])) ? $data['clients'] : [];

        $entitySummary = [];
        foreach ($entities as $key => $ent) {
            if (!is_array($ent)) {
                continue;
            }
            $entitySummary[$key] = [
                'table'       => $ent['table'] ?? $key,
                'title'       => $ent['title'] ?? $key,
                'field_count' => is_array($ent['fields'] ?? null) ? count($ent['fields']) : 0,
            ];
        }

        $status = [
            'app_id'            => $data['app_id'] ?? 'unknown',
            'app_name'          => $data['app_name'] ?? 'unknown',
            'app_version'       => $data['app_version'] ?? '1.0.0',
            'framework_version' => $data['framework_version'] ?? '1.6.7',
            'clients'           => $clients,
            'modules_count'     => count($modules),
            'entities_count'    => count($entitySummary),
            'entities'          => $entitySummary,
            'deployment_target' => $data['deployment']['target'] ?? 'docker',
            'database_target'   => $data['deployment']['database'] ?? 'sqlite',
        ];

        $this->saveState('project-status', $status);
        return $status;
    }

    /**
     * 集中诊断外部依赖与凭据配置
     */
    public function checkExternal(): array
    {
        $data = $this->load();
        $reqs = (isset($data['external_requirements']) && is_array($data['external_requirements'])) ? $data['external_requirements'] : [];

        $items = [];
        $hasMissingRequired = false;

        foreach ($reqs as $req) {
            if (!is_array($req)) {
                continue;
            }
            $key = (string) ($req['key'] ?? '');
            $envKey = (string) ($req['env_mapping'] ?? '');
            $required = !empty($req['required']);
            $val = $envKey !== '' ? (string) env($envKey, '') : '';

            $configured = $val !== '';
            $status = 'configured';
            if (!$configured) {
                if ($required) {
                    $status = 'missing';
                    $hasMissingRequired = true;
                } else {
                    $status = 'optional_unconfigured';
                }
            }

            $maskedVal = '';
            if ($configured) {
                $len = strlen($val);
                $maskedVal = $len <= 6 ? '******' : (substr($val, 0, 3) . '****' . substr($val, -3));
            }

            $items[] = [
                'key'         => $key,
                'title'       => $req['title'] ?? $key,
                'category'    => $req['category'] ?? 'other',
                'required'    => $required,
                'env_mapping' => $envKey,
                'configured'  => $configured,
                'status'      => $status,
                'value_mask'  => $maskedVal,
                'description' => $req['description'] ?? '',
            ];
        }

        $result = [
            'all_required_met' => !$hasMissingRequired,
            'total_items'      => count($items),
            'requirements'     => $items,
        ];

        $this->saveState('external-check', $result);
        return $result;
    }

    /**
     * 导出 PC 管理端与移动端共享的 TypeScript 类型契约
     */
    public function exportSharedTypes(?string $customContent = null): array
    {
        $data = $this->load();
        $entities = (isset($data['entities']) && is_array($data['entities']) && !array_is_list($data['entities'])) ? $data['entities'] : [];

        $lines = [];
        $lines[] = '/**';
        $lines[] = ' * HwAdmin 全端共享数据模型与 API 类型契约';
        $lines[] = ' * 自动生成自 hw.project.json 实体建模规范与路由自省契约，严禁手工篡改';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'export interface ApiResponse<T = any> {';
        $lines[] = '  code: number;';
        $lines[] = '  msg: string;';
        $lines[] = '  data: T;';
        $lines[] = '  time: number;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface PageResult<T = any> {';
        $lines[] = '  list: T[];';
        $lines[] = '  total: number;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface UserProfile {';
        $lines[] = '  id: number;';
        $lines[] = '  username: string;';
        $lines[] = '  nickname: string;';
        $lines[] = '  avatar: string;';
        $lines[] = '  email: string;';
        $lines[] = '  mobile: string;';
        $lines[] = '  level: number;';
        $lines[] = '  score: number;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface UserLoginParams {';
        $lines[] = '  account: string;';
        $lines[] = '  password: string;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface UserRegisterParams {';
        $lines[] = '  username: string;';
        $lines[] = '  password: string;';
        $lines[] = '  nickname?: string;';
        $lines[] = '  mobile?: string;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface UserSendResetCodeParams {';
        $lines[] = '  account: string;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface UserSendResetCodeResult {';
        $lines[] = '  expires_in: number;';
        $lines[] = '  cooldown: number;';
        $lines[] = '  reset_token?: string;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface UserResetPasswordParams {';
        $lines[] = '  account?: string;';
        $lines[] = '  new_password: string;';
        $lines[] = '  reset_token?: string;';
        $lines[] = '  old_password?: string;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface UserWechatLoginParams {';
        $lines[] = '  code: string;';
        $lines[] = '  nickname?: string;';
        $lines[] = '  avatar?: string;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface UserAuthResult {';
        $lines[] = '  token: string;';
        $lines[] = '  expires_in: number;';
        $lines[] = '  openid?: string;';
        $lines[] = '  unionid?: string;';
        $lines[] = '  is_new?: boolean;';
        $lines[] = '  user: UserProfile;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export interface UserUploadResult {';
        $lines[] = '  url: string;';
        $lines[] = '  file_name: string;';
        $lines[] = '  mime?: string;';
        $lines[] = '  width?: number;';
        $lines[] = '  height?: number;';
        $lines[] = '}';
        $lines[] = '';

        foreach ($entities as $entityKey => $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $pascal = $this->studly((string) $entityKey);
            $title = (string) ($entity['title'] ?? $entityKey);
            $lines[] = "/** {$title} 实体接口 */";
            $lines[] = "export interface {$pascal}Item {";

            foreach ($entity['fields'] ?? [] as $f) {
                if (!is_array($f) || empty($f['name'])) {
                    continue;
                }
                $tsType = match ($f['type'] ?? 'string') {
                    'integer', 'biginteger', 'smallinteger', 'float' => 'number',
                    'boolean' => 'boolean',
                    'decimal' => 'string | number',
                    'json'    => 'Record<string, any> | any[]',
                    default   => 'string',
                };
                $opt = !empty($f['nullable']) ? '?' : '';
                $comment = !empty($f['title']) ? " // {$f['title']}" : '';
                $lines[] = "  {$f['name']}{$opt}: {$tsType};{$comment}";
            }

            $lines[] = '}';
            $lines[] = '';
            $lines[] = "export type {$pascal}ListResponse = ApiResponse<PageResult<{$pascal}Item>>;";
            $lines[] = "export type {$pascal}DetailResponse = ApiResponse<{$pascal}Item>;";
            $lines[] = '';
        }

        $typeDef = $customContent ?? implode("\n", $lines);

        $adminPath = $this->projectRoot . '/admin-ui/src/types/shared-api.d.ts';
        $mobilePath = $this->projectRoot . '/admin-ui/mobile/src/types/shared-api.d.ts';

        $exported = [];
        $adminDir = dirname($adminPath);
        if (!is_dir($adminDir)) {
            @mkdir($adminDir, 0755, true);
        }
        file_put_contents($adminPath, $typeDef);
        $exported[] = $adminPath;

        $mobileDir = dirname($mobilePath);
        if (!is_dir($mobileDir)) {
            @mkdir($mobileDir, 0755, true);
        }
        file_put_contents($mobilePath, $typeDef);
        $exported[] = $mobilePath;

        return [
            'exported_files' => $exported,
            'type_content'   => $typeDef,
        ];
    }

    public function saveState(string $key, mixed $value): void
    {
        $file = $this->stateDir . DIRECTORY_SEPARATOR . "{$key}.json";
        @file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public function loadState(string $key): mixed
    {
        $file = $this->stateDir . DIRECTORY_SEPARATOR . "{$key}.json";
        if (!is_file($file)) {
            return null;
        }
        return json_decode((string) file_get_contents($file), true);
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    private function studly(string $value): string
    {
        $words = explode(' ', str_replace(['-', '_'], ' ', $value));
        $studlyWords = array_map('ucfirst', $words);
        return implode('', $studlyWords);
    }
}
