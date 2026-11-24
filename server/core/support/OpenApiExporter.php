<?php
declare(strict_types=1);

namespace core\support;

use think\App;

/**
 * OpenAPI 3.0.0 规范契约导出器（R12：结合 RouteInspector 真实动词、参数规则、鉴权契约与启用插件路由）
 */
class OpenApiExporter
{
    public function __construct(protected App $app)
    {
    }

    /**
     * 导出 OpenAPI 3.0.0 标准结构
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $inspector = new RouteInspector($this->app);
        $projectService = new ProjectSchemaService($this->app);

        $inspectData = $inspector->inspect(['framework', 'routes']);
        $projectData = $projectService->load();

        $appName = (string) ($projectData['app_name'] ?? 'HwAdmin Application');
        $appVersion = (string) ($projectData['app_version'] ?? '1.0.0');

        $spec = [
            'openapi' => '3.0.0',
            'info'    => [
                'title'       => $appName . ' API Specification',
                'version'     => $appVersion,
                'description' => 'HwAdmin 自动生成的 OpenAPI 3.0 标准契约，供管理端与移动端强类型消费',
            ],
            'paths'      => [],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'bearerFormat' => 'OpaqueToken',
                        'description'  => '管理端或会员端不透明令牌 (Authorization: Bearer <token> 或 X-Token)',
                    ],
                ],
                'schemas' => [
                    'ApiResponse' => [
                        'type'       => 'object',
                        'properties' => [
                            'code' => ['type' => 'integer', 'example' => 1],
                            'msg'  => ['type' => 'string', 'example' => 'ok'],
                            'data' => ['type' => 'object', 'nullable' => true],
                            'time' => ['type' => 'integer', 'example' => 1700000000],
                        ],
                        'required'   => ['code', 'msg'],
                    ],
                    'PageResult'  => [
                        'type'       => 'object',
                        'properties' => [
                            'list'  => [
                                'type'  => 'array',
                                'items' => ['type' => 'object'],
                            ],
                            'total' => ['type' => 'integer', 'example' => 0],
                        ],
                        'required'   => ['list', 'total'],
                    ],
                ],
            ],
        ];

        // 1. 注入实体 Schema
        $entities = (isset($projectData['entities']) && is_array($projectData['entities']) && !array_is_list($projectData['entities']))
            ? $projectData['entities']
            : [];

        foreach ($entities as $entityKey => $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $pascal = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', (string) $entityKey)));
            $properties = [];
            $required = [];

            foreach ($entity['fields'] ?? [] as $f) {
                if (!is_array($f) || empty($f['name'])) {
                    continue;
                }
                $propType = match ($f['type'] ?? 'string') {
                    'integer', 'biginteger', 'smallinteger' => 'integer',
                    'decimal', 'float'                      => 'number',
                    'boolean'                               => 'boolean',
                    default                                 => 'string',
                };
                $properties[$f['name']] = [
                    'type'        => $propType,
                    'description' => $f['title'] ?? $f['name'],
                ];
                if (empty($f['nullable']) && empty($f['primary'])) {
                    $required[] = $f['name'];
                }
            }

            $spec['components']['schemas'][$pascal . 'Item'] = [
                'type'       => 'object',
                'properties' => $properties,
                'required'   => $required,
            ];
        }

        // 2. 收集 adminapi、api 以及所有已启用插件的控制器路由
        $routes = $inspectData['routes'] ?? [];
        $controllerGroups = [];
        foreach (['adminapi', 'api'] as $module) {
            foreach ($routes[$module] ?? [] as $ctrl) {
                $controllerGroups[] = $ctrl;
            }
        }
        foreach ($routes['plugins'] ?? [] as $pluginName => $pluginSuites) {
            if (!is_array($pluginSuites)) {
                continue;
            }
            foreach (['adminapi', 'api'] as $module) {
                foreach ($pluginSuites[$module] ?? [] as $ctrl) {
                    $ctrl['plugin'] = $pluginName;
                    $controllerGroups[] = $ctrl;
                }
            }
        }

        // 3. 逐控制器端点生成准确动词、参数与鉴权契约
        foreach ($controllerGroups as $ctrl) {
            $ctrlTitle = (string) ($ctrl['title'] ?? 'Default');
            foreach ($ctrl['endpoints'] ?? [] as $ep) {
                $url = (string) $ep['url'];
                $method = strtolower((string) $ep['method']);
                $operationId = str_replace(['/', '.', '-'], '_', trim($url, '/'));
                $needLogin = !empty($ep['need_login']);
                $needRight = !empty($ep['need_right']);
                $reqParams = is_array($ep['request_params'] ?? null) ? $ep['request_params'] : [];

                $parameters = [];
                $bodyProperties = [];
                $bodyRequired = [];
                $hasFileUpload = false;

                foreach ($reqParams as $param) {
                    $pName = (string) ($param['name'] ?? '');
                    if ($pName === '') {
                        continue;
                    }
                    $pIn = (string) ($param['in'] ?? ($method === 'get' ? 'query' : 'body'));
                    $pType = (string) ($param['type'] ?? 'string');
                    $pTitle = (string) ($param['title'] ?? $pName);
                    $pRequired = !empty($param['required']);

                    if ($method === 'get' || $pIn === 'query') {
                        $schemaDef = [
                            'type' => in_array($pType, ['integer', 'number', 'boolean', 'array'], true) ? $pType : 'string',
                        ];
                        if ($pName === 'page') {
                            $schemaDef['default'] = 1;
                        } elseif ($pName === 'limit') {
                            $schemaDef['default'] = 15;
                        }
                        $parameters[] = [
                            'name'        => $pName,
                            'in'          => 'query',
                            'required'    => $pRequired,
                            'description' => $pTitle,
                            'schema'      => $schemaDef,
                        ];
                    } else {
                        if ($pType === 'file' || $pIn === 'formData') {
                            $hasFileUpload = true;
                            $bodyProperties[$pName] = [
                                'type'        => 'string',
                                'format'      => 'binary',
                                'description' => $pTitle,
                            ];
                        } else {
                            $propSchema = [
                                'type'        => in_array($pType, ['integer', 'number', 'boolean', 'array', 'object'], true) ? $pType : 'string',
                                'description' => $pTitle,
                            ];
                            if ($pType === 'array') {
                                $propSchema['items'] = ['type' => 'string'];
                            }
                            if (!empty($param['rule'])) {
                                $propSchema['x-validation-rule'] = (string) $param['rule'];
                            }
                            $bodyProperties[$pName] = $propSchema;
                        }
                        if ($pRequired) {
                            $bodyRequired[] = $pName;
                        }
                    }
                }

                $requestBody = null;
                if ($method !== 'get' && (!empty($bodyProperties) || $hasFileUpload)) {
                    $bodySchema = [
                        'type'       => 'object',
                        'properties' => $bodyProperties,
                    ];
                    if (!empty($bodyRequired)) {
                        $bodySchema['required'] = array_values(array_unique($bodyRequired));
                    }

                    $contentTypes = [];
                    if ($hasFileUpload) {
                        $contentTypes['multipart/form-data'] = ['schema' => $bodySchema];
                    } else {
                        $contentTypes['application/json'] = ['schema' => $bodySchema];
                    }

                    $requestBody = [
                        'description' => '请求载荷',
                        'required'    => !empty($bodyRequired) || $hasFileUpload,
                        'content'     => $contentTypes,
                    ];
                }

                $responses = [
                    '200' => [
                        'description' => '成功响应',
                        'content'     => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ApiResponse',
                                ],
                            ],
                        ],
                    ],
                ];
                if ($needLogin) {
                    $responses['401'] = [
                        'description' => '未登录或身份令牌失效 (code=401)',
                    ];
                }
                if ($needRight) {
                    $responses['403'] = [
                        'description' => '无权访问该权限节点 (code=403)',
                    ];
                }

                $op = [
                    'tags'            => [$ctrlTitle],
                    'summary'         => $ep['title'] ?? $ep['action'],
                    'operationId'     => $operationId,
                    'security'        => $needLogin ? [['BearerAuth' => []]] : [],
                    'x-method-source' => $ep['method_source'] ?? 'convention',
                    'x-need-login'    => $needLogin,
                    'x-login-source'  => $ep['login_source'] ?? 'convention',
                    'responses'       => $responses,
                ];

                if (isset($ep['need_right'])) {
                    $op['x-need-right'] = $needRight;
                    $op['x-permission-node'] = $ep['permission_node'] ?? '';
                }
                if (!empty($parameters)) {
                    $op['parameters'] = $parameters;
                }
                if ($requestBody !== null) {
                    $op['requestBody'] = $requestBody;
                }

                $spec['paths'][$url][$method] = $op;
            }
        }

        return $spec;
    }

    /**
     * 导出为 OpenAPI JSON 文件
     */
    public function exportToFile(string $outputPath): string
    {
        $spec = $this->export();
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = (string) json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        file_put_contents($outputPath, $json);
        return $outputPath;
    }
}
