<?php
declare(strict_types=1);

namespace core\support;

use core\plugin\MenuSync;

/**
 * hw:crud 全栈脚手架代码模板渲染器（前端 API/Vue3 页面、Phinx 迁移、PHPUnit 特征测试、增量保护与 OpenAPI 契约）
 */
class CrudScaffoldBuilder
{
    /**
     * 探测前端源码目录
     */
    public function detectFrontendSrcDir(string $serverRoot): ?string
    {
        $candidates = [
            dirname(rtrim($serverRoot, '/\\')) . DIRECTORY_SEPARATOR . 'admin-ui' . DIRECTORY_SEPARATOR . 'src',
            rtrim($serverRoot, '/\\') . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR . 'admin-ui' . DIRECTORY_SEPARATOR . 'src',
        ];
        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * 探测移动端源码目录 (admin-ui/mobile/src)
     */
    public function detectMobileSrcDir(string $serverRoot): ?string
    {
        $candidates = [
            dirname(rtrim($serverRoot, '/\\')) . DIRECTORY_SEPARATOR . 'admin-ui' . DIRECTORY_SEPARATOR . 'mobile' . DIRECTORY_SEPARATOR . 'src',
            rtrim($serverRoot, '/\\') . DIRECTORY_SEPARATOR . 'sources' . DIRECTORY_SEPARATOR . 'admin-ui' . DIRECTORY_SEPARATOR . 'mobile' . DIRECTORY_SEPARATOR . 'src',
        ];
        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * 提取已有代码中的手写保护区内容 (@hw-custom-start ~ @hw-custom-end)
     *
     * @return array<string, string> key => custom code
     */
    public function extractCustomSections(string $content): array
    {
        $sections = [];

        // 匹配 PHP / TS 注释风格: /* @hw-custom-start: <id> */ ... /* @hw-custom-end: <id> */
        if (preg_match_all('/\/\*\s*@hw-custom-start:\s*([a-zA-Z0-9_\-]+)\s*\*\/([\s\S]*?)\/\*\s*@hw-custom-end:\s*\1\s*\*\//', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $sections[trim($m[1])] = $m[2];
            }
        }

        // 匹配 Vue / HTML 注释风格: <!-- @hw-custom-start: <id> --> ... <!-- @hw-custom-end: <id> -->
        if (preg_match_all('/<!--\s*@hw-custom-start:\s*([a-zA-Z0-9_\-]+)\s*-->([\s\S]*?)<!--\s*@hw-custom-end:\s*\1\s*-->/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $sections[trim($m[1])] = $m[2];
            }
        }

        return $sections;
    }

    /**
     * 将之前提取的手写保护区代码注入到新渲染的模板中
     */
    public function mergeCustomSections(string $newContent, array $sections): string
    {
        if (empty($sections)) {
            return $newContent;
        }

        // 合并 PHP/TS 保护区
        $newContent = (string) preg_replace_callback(
            '/\/\*\s*@hw-custom-start:\s*([a-zA-Z0-9_\-]+)\s*\*\/([\s\S]*?)\/\*\s*@hw-custom-end:\s*\1\s*\*\//',
            static function ($matches) use ($sections) {
                $key = trim($matches[1]);
                if (isset($sections[$key])) {
                    return "/* @hw-custom-start: {$key} */{$sections[$key]}/* @hw-custom-end: {$key} */";
                }
                return $matches[0];
            },
            $newContent
        );

        // 合并 Vue/HTML 保护区
        $newContent = (string) preg_replace_callback(
            '/<!--\s*@hw-custom-start:\s*([a-zA-Z0-9_\-]+)\s*-->([\s\S]*?)<!--\s*@hw-custom-end:\s*\1\s*-->/',
            static function ($matches) use ($sections) {
                $key = trim($matches[1]);
                if (isset($sections[$key])) {
                    return "<!-- @hw-custom-start: {$key} -->{$sections[$key]}<!-- @hw-custom-end: {$key} -->";
                }
                return $matches[0];
            },
            $newContent
        );

        return $newContent;
    }

    /**
     * 生成前端 TypeScript API 封装
     */
    public function renderFrontendApi(
        string $studlyName,
        string $apiUrlPrefix,
        string $pk,
        array $allFields,
        array $allowFields,
        array $hiddenFields,
        array $fieldDetails
    ): string {
        $visibleFields = array_values(array_diff($allFields, $hiddenFields));
        $tsProps = [];
        foreach ($visibleFields as $f) {
            $detail = $fieldDetails[$f] ?? [];
            $tsType = $this->mapSqlTypeToTs((string) ($detail['type'] ?? ''));
            $optional = $f === $pk || in_array($f, ['create_time', 'update_time'], true) ? '?' : '';
            $tsProps[] = "  {$f}{$optional}: {$tsType}";
        }
        $propsBlock = implode("\n", $tsProps);

        $formProps = [];
        foreach ($allowFields as $f) {
            $detail = $fieldDetails[$f] ?? [];
            $tsType = $this->mapSqlTypeToTs((string) ($detail['type'] ?? ''));
            $formProps[] = "  {$f}?: {$tsType}";
        }
        $formPropsBlock = implode("\n", $formProps);

        $urlBase = '${API_BASE}' . $apiUrlPrefix;

        return <<<TS
import request from '/@/utils/request'
import { projectConfig } from '/@/config/project'

const API_BASE = projectConfig.apiBase || '/adminapi'

export interface {$studlyName}Item {
{$propsBlock}
  [key: string]: any
}

export interface {$studlyName}Form {
{$formPropsBlock}
}

export const get{$studlyName}List = (params?: Record<string, any>) =>
  request({
    url: `{$urlBase}/index`,
    method: 'get',
    params,
  })

export const get{$studlyName}Detail = (id: number | string) =>
  request({
    url: `{$urlBase}/read`,
    method: 'get',
    params: { id },
  })

export const add{$studlyName} = (data: {$studlyName}Form) =>
  request({
    url: `{$urlBase}/add`,
    method: 'post',
    data,
  })

export const edit{$studlyName} = (id: number | string, data: {$studlyName}Form) =>
  request({
    url: `{$urlBase}/edit?id=\${id}`,
    method: 'post',
    data: { id, ...data },
  })

export const del{$studlyName} = (ids: (number | string)[] | string | number) =>
  request({
    url: `{$urlBase}/del`,
    method: 'post',
    data: { ids: Array.isArray(ids) ? ids : [ids] },
  })

/* @hw-custom-start: api-extensions */
// 业务自定义扩展接口封装写在此处，增量重新生成时自动保留
/* @hw-custom-end: api-extensions */

TS;
    }

    /**
     * 生成前端 Vue 3 管理页面（向后兼容别名）
     */
    public function buildFrontendViewContent(
        string $studlyName,
        string $title,
        string $apiImportPath,
        string $pk,
        array $allFields,
        array $allowFields,
        array $hiddenFields,
        array $fieldDetails,
        string $nodePrefix = ''
    ): string {
        return $this->renderFrontendView(
            $studlyName,
            $title,
            $apiImportPath,
            $pk,
            $allFields,
            $allowFields,
            $hiddenFields,
            $fieldDetails,
            $nodePrefix
        );
    }

    /**
     * 生成前端 Vue 3 管理页面
     */
    public function renderFrontendView(
        string $studlyName,
        string $title,
        string $apiImportPath,
        string $pk,
        array $allFields,
        array $allowFields,
        array $hiddenFields,
        array $fieldDetails,
        string $nodePrefix = ''
    ): string {
        $authPrefix = trim($nodePrefix, '/') !== ''
            ? trim($nodePrefix, '/')
            : strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $studlyName));
        $visibleFields = array_values(array_diff($allFields, $hiddenFields));

        $colItems = [];
        foreach ($visibleFields as $f) {
            $detail = $fieldDetails[$f] ?? [];
            $comment = (string) ($detail['comment'] ?? '');
            $label = $this->guessFieldLabel($f, $comment);
            $widthAttr = '';
            if ($f === $pk) {
                $widthAttr = ", width: 80";
            } elseif ($f === 'status') {
                $widthAttr = ", width: 100";
            } elseif (str_ends_with($f, '_time')) {
                $widthAttr = ", width: 170";
            }
            $colItems[] = "  { prop: '{$f}', label: '{$label}'{$widthAttr} },";
        }
        $columnsBlock = implode("\n", $colItems);

        $formItems = [];
        $formDefaults = [];
        $formRules = [];
        foreach ($allowFields as $f) {
            $detail = $fieldDetails[$f] ?? [];
            $comment = (string) ($detail['comment'] ?? '');
            $label = $this->guessFieldLabel($f, $comment);
            $type = strtolower((string) ($detail['type'] ?? ''));

            if ($f === 'status') {
                $formItems[] = <<<HTML
        <el-form-item label="{$label}" prop="status">
          <el-radio-group v-model="formData.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">禁用</el-radio>
          </el-radio-group>
        </el-form-item>
HTML;
                $formDefaults[] = "  status: 1,";
            } elseif (str_contains($type, 'text')) {
                $formItems[] = <<<HTML
        <el-form-item label="{$label}" prop="{$f}">
          <el-input v-model="formData.{$f}" type="textarea" :rows="3" placeholder="请输入{$label}" />
        </el-form-item>
HTML;
                $formDefaults[] = "  {$f}: '',";
            } elseif (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float')) {
                $formItems[] = <<<HTML
        <el-form-item label="{$label}" prop="{$f}">
          <el-input-number v-model="formData.{$f}" :min="0" style="width: 100%" />
        </el-form-item>
HTML;
                $formDefaults[] = "  {$f}: 0,";
            } else {
                $formItems[] = <<<HTML
        <el-form-item label="{$label}" prop="{$f}">
          <el-input v-model="formData.{$f}" placeholder="请输入{$label}" clearable />
        </el-form-item>
HTML;
                $formDefaults[] = "  {$f}: '',";
            }

            if (!empty($detail['notnull']) && ($detail['default'] ?? null) === null) {
                $formRules[] = "  {$f}: [{ required: true, message: '请输入{$label}', trigger: 'blur' }],";
            }
        }

        $formItemsStr = implode("\n", $formItems);
        $formDefaultsStr = implode("\n", $formDefaults);
        $formRulesStr = implode("\n", $formRules);

        $statusSlot = in_array('status', $visibleFields, true) ? <<<HTML

      <template #cell-status="{ row }">
        <el-tag :type="row.status === 1 ? 'success' : 'danger'">
          {{ row.status === 1 ? '启用' : '禁用' }}
        </el-tag>
      </template>
HTML : '';

        $timeSlots = '';
        foreach (['create_time', 'update_time'] as $tf) {
            if (in_array($tf, $visibleFields, true)) {
                $timeSlots .= <<<HTML

      <template #cell-{$tf}="{ row }">
        {{ formatTimestamp(row.{$tf}) }}
      </template>
HTML;
            }
        }

        return <<<VUE
<template>
  <div class="crud-page-container">
    <HwTable
      ref="tableRef"
      :columns="columns"
      :data="tableData"
      :loading="loading"
      :pagination="pagination"
      @selection-change="handleSelectionChange"
      @page-change="handlePageChange"
    >
      <template #toolbar>
        <div class="toolbar-actions">
          <el-button v-auth="'{$authPrefix}/add'" type="primary" @click="openCreate">新增{$title}</el-button>
          <el-button v-auth="'{$authPrefix}/del'" type="danger" :disabled="selectedRows.length === 0" @click="handleBatchDelete">
            批量删除 ({{ selectedRows.length }})
          </el-button>
          <!-- @hw-custom-start: toolbar-buttons -->
          <!-- 业务自定义工具栏按钮扩展写在此处 -->
          <!-- @hw-custom-end: toolbar-buttons -->
        </div>
        <div class="toolbar-search">
          <el-input
            v-model="queryForm.search"
            placeholder="请输入关键词搜索"
            clearable
            style="width: 220px"
            @keyup.enter="handleSearch"
            @clear="handleSearch"
          />
          <el-button type="primary" plain @click="handleSearch">查询</el-button>
          <el-button @click="handleReset">重置</el-button>
        </div>
      </template>
{$statusSlot}{$timeSlots}
      <template #actions="{ row }">
        <el-button v-auth="'{$authPrefix}/edit'" plain type="primary" size="small" @click="openEdit(row)">编辑</el-button>
        <el-button v-auth="'{$authPrefix}/del'" plain type="danger" size="small" @click="handleDelete(row)">删除</el-button>
        <!-- @hw-custom-start: row-actions -->
        <!-- 业务自定义行操作按钮扩展写在此处 -->
        <!-- @hw-custom-end: row-actions -->
      </template>
    </HwTable>

    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑{$title}' : '新增{$title}'" width="560px" destroy-on-close>
      <el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px">
{$formItemsStr}
        <!-- @hw-custom-start: form-fields -->
        <!-- 业务自定义表单项扩展写在此处 -->
        <!-- @hw-custom-end: form-fields -->
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitForm">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import type { FormInstance, FormRules } from 'element-plus'
import { ElMessage } from 'element-plus'
import { reactive, ref } from 'vue'
import HwTable from '/@/components/HwTable/index.vue'
import type { HwTableColumn } from '/@/components/HwTable/types'
import { useCrudTable } from '/@/hooks/useCrudTable'
import {
  add{$studlyName},
  del{$studlyName},
  edit{$studlyName},
  get{$studlyName}List,
  type {$studlyName}Form,
  type {$studlyName}Item,
} from '{$apiImportPath}'

defineOptions({
  name: '{$studlyName}Index',
})

const columns: HwTableColumn<{$studlyName}Item>[] = [
{$columnsBlock}
]

const {
  loading,
  tableData,
  selectedRows,
  pagination,
  queryForm,
  fetchList,
  handleSearch,
  handleReset,
  handleSelectionChange,
  handlePageChange,
  handleDelete,
  handleBatchDelete,
} = useCrudTable<{$studlyName}Item, { search: string }>({
  fetchApi: get{$studlyName}List,
  delApi: del{$studlyName},
  rowKey: '{$pk}',
  defaultQuery: () => ({ search: '' }),
})

const dialogVisible = ref(false)
const isEdit = ref(false)
const submitting = ref(false)
const editingId = ref<number | string>(0)
const formRef = ref<FormInstance>()

const createDefaultForm = (): {$studlyName}Form => ({
{$formDefaultsStr}
})

const formData = reactive<{$studlyName}Form>(createDefaultForm())

const formRules: FormRules = {
{$formRulesStr}
}

const openCreate = () => {
  isEdit.value = false
  editingId.value = 0
  Object.assign(formData, createDefaultForm())
  dialogVisible.value = true
}

const openEdit = (row: {$studlyName}Item) => {
  isEdit.value = true
  editingId.value = row.{$pk} ?? 0
  Object.assign(formData, createDefaultForm(), row)
  dialogVisible.value = true
}

const submitForm = async () => {
  if (!formRef.value) return
  await formRef.value.validate()
  submitting.value = true
  try {
    if (isEdit.value) {
      await edit{$studlyName}(editingId.value, formData)
      ElMessage.success('保存成功')
    } else {
      await add{$studlyName}(formData)
      ElMessage.success('新增成功')
    }
    dialogVisible.value = false
    await fetchList()
  } finally {
    submitting.value = false
  }
}

const formatTimestamp = (ts?: number | string) => {
  const num = Number(ts || 0)
  if (!num || num <= 0) return '-'
  const d = new Date(num > 1e12 ? num : num * 1000)
  return Number.isNaN(d.getTime()) ? '-' : d.toLocaleString('zh-CN', { hour12: false })
}

/* @hw-custom-start: script-methods */
// 页面业务扩展方法写在此处
/* @hw-custom-end: script-methods */
</script>

<style scoped lang="scss">
.crud-page-container {
  display: flex;
  flex-direction: column;
  gap: 14px;

  .toolbar-actions {
    display: flex;
    gap: 10px;
    align-items: center;
  }

  .toolbar-search {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
  }
}
</style>
VUE;
    }

    /**
     * 校验主键与字段定义合法性（拒绝复合主键与不支持的字段类型）
     *
     * @param string $entityName
     * @param mixed $declaredPk
     * @param array<int, array<string, mixed>> $fields
     * @return string 解析后的单列主键名
     */
    public function validateEntityDefinition(string $entityName, mixed $declaredPk, array $fields): string
    {
        if (is_array($declaredPk)) {
            throw new \InvalidArgumentException("实体 [{$entityName}] 声明了复合主键，不支持复合主键，HwAdmin 实体仅支持单列主键");
        }
        $pkStr = trim((string) ($declaredPk ?? ''));
        if ($pkStr !== '' && (str_contains($pkStr, ',') || preg_match('/\s/', $pkStr))) {
            throw new \InvalidArgumentException("实体 [{$entityName}] 声明了复合主键 [{$pkStr}]，不支持复合主键，HwAdmin 实体仅支持单列主键");
        }

        $supportedTypes = [
            'string', 'varchar', 'char', 'integer', 'int', 'biginteger', 'bigint',
            'smallinteger', 'smallint', 'tinyint', 'boolean', 'bool', 'decimal',
            'numeric', 'float', 'double', 'text', 'longtext', 'json', 'datetime',
            'date', 'timestamp',
        ];
        $supportedPkTypes = ['string', 'varchar', 'char', 'integer', 'int', 'biginteger', 'bigint'];

        $primaryFields = [];
        foreach ($fields as $f) {
            $colName = trim((string) ($f['name'] ?? ''));
            $rawType = strtolower(trim((string) ($f['type'] ?? '')));
            $baseType = (string) preg_replace('/\(.*\)$/', '', $rawType);
            if ($colName === '' || $baseType === '') {
                throw new \InvalidArgumentException("实体 [{$entityName}] 存在未定义 name 或 type 的字段");
            }
            if (!in_array($baseType, $supportedTypes, true)) {
                throw new \InvalidArgumentException("实体 [{$entityName}].{$colName} 类型 [{$rawType}] 不受支持");
            }
            if (!empty($f['primary'])) {
                $primaryFields[] = $colName;
            }
        }

        if (count($primaryFields) > 1) {
            throw new \InvalidArgumentException("实体 [{$entityName}] 声明了多个主键字段（" . implode(', ', $primaryFields) . "），不支持复合主键，HwAdmin 实体仅支持单列主键");
        }

        if ($pkStr !== '' && count($primaryFields) === 1 && $primaryFields[0] !== $pkStr && $pkStr !== 'id') {
            throw new \InvalidArgumentException("实体 [{$entityName}] 的 primary_key [{$pkStr}] 与字段级 primary [{$primaryFields[0]}] 冲突，不支持复合主键");
        }

        $resolvedPk = ($pkStr !== '' && ($pkStr !== 'id' || empty($primaryFields)))
            ? $pkStr
            : ($primaryFields[0] ?? ($pkStr !== '' ? $pkStr : 'id'));

        foreach ($fields as $f) {
            if (($f['name'] ?? '') === $resolvedPk) {
                $rawType = strtolower(trim((string) ($f['type'] ?? 'integer')));
                $baseType = (string) preg_replace('/\(.*\)$/', '', $rawType);
                if (!in_array($baseType, $supportedPkTypes, true)) {
                    throw new \InvalidArgumentException("实体 [{$entityName}] 主键字段 [{$resolvedPk}] 类型 [{$rawType}] 不受支持，主键仅支持 integer/biginteger/string");
                }
            }
        }

        return $resolvedPk;
    }

    /**
     * 生成 Phinx 迁移文件（区分新建表迁移 vs 已有表接管迁移，准确保留主键、索引与 decimal 精度）
     *
     * @param bool $isTakeover 是否为已有表接管迁移（若为 true，down() 绝不删除原数据表）
     */
    public function renderMigration(
        string $migrationClass,
        string $table,
        string|array $pk,
        array $fieldDetails,
        array $menuNode,
        bool $isTakeover = false
    ): string {
        $normalizedFields = [];
        foreach ($fieldDetails as $colName => $meta) {
            $normalizedFields[] = [
                'name'    => (string) $colName,
                'type'    => (string) ($meta['type'] ?? 'string'),
                'primary' => !empty($meta['primary']) || ($colName === $pk),
            ];
        }
        $resolvedPk = $this->validateEntityDefinition($table, $pk, $normalizedFields);

        $pkMeta = $fieldDetails[$resolvedPk] ?? null;
        $pkRawType = strtolower(trim((string) ($pkMeta['type'] ?? 'integer')));
        $pkBaseType = (string) preg_replace('/\(.*\)$/', '', $pkRawType);
        $isCustomPk = ($resolvedPk !== 'id') || in_array($pkBaseType, ['string', 'varchar', 'char'], true);

        $tableDeclaration = $isCustomPk
            ? "\$this->table('{$table}', ['id' => false, 'primary_key' => ['{$resolvedPk}']])"
            : "\$this->table('{$table}')";

        $columnLines = [];
        if ($isCustomPk) {
            if (in_array($pkBaseType, ['string', 'varchar', 'char'], true) || ($pkMeta === null && $resolvedPk !== 'id')) {
                $limit = (int) ($pkMeta['length'] ?? 64);
                if ($limit <= 0 && preg_match('/\((\d+)\)/', $pkRawType, $m)) {
                    $limit = (int) $m[1];
                }
                if ($limit <= 0) {
                    $limit = 64;
                }
                $columnLines[] = "                ->addColumn('{$resolvedPk}', 'string', ['limit' => {$limit}, 'null' => false])";
            } else {
                $phinxPkType = str_contains($pkBaseType, 'big') ? 'biginteger' : 'integer';
                $columnLines[] = "                ->addColumn('{$resolvedPk}', '{$phinxPkType}', ['identity' => true, 'null' => false])";
            }
        }

        foreach ($fieldDetails as $colName => $meta) {
            if ($colName === $resolvedPk) {
                continue;
            }
            $type = strtolower((string) ($meta['type'] ?? 'varchar(255)'));
            $notnull = (bool) ($meta['notnull'] ?? false);
            $default = $meta['default'] ?? null;
            $precision = isset($meta['precision']) ? (int) $meta['precision'] : null;
            $scale = isset($meta['scale']) ? (int) $meta['scale'] : null;

            [$phinxType, $opts] = $this->mapSqlMetaToPhinx($colName, $type, $notnull, $default, $precision, $scale);
            if (!empty($meta['comment'])) {
                $opts['comment'] = (string) $meta['comment'];
            }
            if (!empty($meta['length']) && $phinxType === 'string') {
                $opts['limit'] = (int) $meta['length'];
            }
            $optsExport = $this->exportPhpShortArray($opts);
            $columnLines[] = "                ->addColumn('{$colName}', '{$phinxType}', {$optsExport})";
        }

        $columnsBlock = implode("\n", $columnLines);
        $menuExport = var_export($menuNode, true);
        $removeNames = [$menuNode['name']];
        foreach ($menuNode['children'] ?? [] as $child) {
            if (!empty($child['name'])) {
                $removeNames[] = $child['name'];
            }
        }
        $removeExport = var_export($removeNames, true);

        // 如果是已有表接管迁移，down() 绝不 drop 原表
        $downTableAction = $isTakeover
            ? "        // [已有表接管迁移] 此表并非本次迁移所创建，回滚时仅撤销菜单，保留原数据表及数据\n        // if (\$this->hasTable('{$table}')) { /* 保留原表数据 */ }"
            : "        if (\$this->hasTable('{$table}')) {\n            \$this->table('{$table}')->drop()->save();\n        }";

        $modeText = $isTakeover ? '已有表接管' : '新建表';

        return <<<PHP
<?php
declare(strict_types=1);

use core\plugin\MenuSync;
use think\migration\Migrator;

/**
 * 迁移: {$migrationClass}（由 php think hw:crud 自动生成）
 * 模式: {$migrationClass} ({$modeText})
 */
class {$migrationClass} extends Migrator
{
    public function up(): void
    {
        if (!\$this->hasTable('{$table}')) {
            {$tableDeclaration}
{$columnsBlock}
                ->create();
        }

        if (\$this->hasTable('auth_rule')) {
            MenuSync::withAdapter(\$this->getAdapter(), static function (): void {
                MenuSync::ensureNode({$menuExport});
            });
        }
    }

    public function down(): void
    {
        if (\$this->hasTable('auth_rule')) {
            MenuSync::withAdapter(\$this->getAdapter(), static function (): void {
                MenuSync::removeNodes({$removeExport});
            });
        }

{$downTableAction}
    }
}
PHP;
    }

    /**
     * 根据 hw.project.json 声明式实体生成迁移文件
     */
    public function renderMigrationFromEntity(
        string $migrationClass,
        array $entity,
        array $menuNode,
        bool $isTakeover = false
    ): string {
        $table = (string) ($entity['table'] ?? '');
        $fields = is_array($entity['fields'] ?? null) ? $entity['fields'] : [];
        $indexes = is_array($entity['indexes'] ?? null) ? $entity['indexes'] : [];
        $pk = $this->validateEntityDefinition($table ?: 'entity', $entity['primary_key'] ?? 'id', $fields);

        $fieldDetails = [];
        foreach ($fields as $f) {
            $fieldDetails[$f['name']] = $f;
        }

        $pkField = $fieldDetails[$pk] ?? null;
        $pkRawType = strtolower(trim((string) ($pkField['type'] ?? ($entity['primary_key_type'] ?? ($pk !== 'id' ? 'string' : 'integer')))));
        $pkBaseType = (string) preg_replace('/\(.*\)$/', '', $pkRawType);
        $isCustomPk = ($pk !== 'id') || in_array($pkBaseType, ['string', 'varchar', 'char'], true);

        $tableDeclaration = $isCustomPk
            ? "\$this->table('{$table}', ['id' => false, 'primary_key' => ['{$pk}']])"
            : "\$this->table('{$table}')";

        $columnLines = [];
        if ($isCustomPk) {
            if (in_array($pkBaseType, ['string', 'varchar', 'char'], true)) {
                $limit = (int) ($pkField['length'] ?? 64);
                if ($limit <= 0) {
                    $limit = 64;
                }
                $columnLines[] = "                ->addColumn('{$pk}', 'string', ['limit' => {$limit}, 'null' => false])";
            } else {
                $phinxPkType = str_contains($pkBaseType, 'big') ? 'biginteger' : 'integer';
                $columnLines[] = "                ->addColumn('{$pk}', '{$phinxPkType}', ['identity' => true, 'null' => false])";
            }
        }

        foreach ($fields as $f) {
            $colName = $f['name'];
            if ($colName === $pk) {
                continue;
            }
            $type = $f['type'];
            $notnull = empty($f['nullable']);
            $default = $f['default'] ?? null;
            $precision = isset($f['precision']) ? (int) $f['precision'] : null;
            $scale = isset($f['scale']) ? (int) $f['scale'] : null;

            [$phinxType, $opts] = $this->mapSqlMetaToPhinx($colName, $type, $notnull, $default, $precision, $scale);
            if (!empty($f['title'])) {
                $opts['comment'] = $f['title'];
            }
            if (!empty($f['length'])) {
                $opts['limit'] = (int) $f['length'];
            }
            $optsExport = $this->exportPhpShortArray($opts);
            $columnLines[] = "                ->addColumn('{$colName}', '{$phinxType}', {$optsExport})";
        }

        // 索引
        $indexLines = [];
        foreach ($indexes as $idx) {
            $cols = $idx['columns'] ?? [];
            $colsExport = '[' . implode(', ', array_map(static fn ($c) => "'{$c}'", $cols)) . ']';
            $uniqueVal = !empty($idx['unique']) ? 'true' : 'false';
            $nameVal = !empty($idx['name']) ? ", 'name' => '{$idx['name']}'" : '';
            $indexLines[] = "                ->addIndex({$colsExport}, ['unique' => {$uniqueVal}{$nameVal}])";
        }

        $allTableOperations = array_merge($columnLines, $indexLines);
        $columnsBlock = implode("\n", $allTableOperations);

        $menuExport = var_export($menuNode, true);
        $removeNames = [$menuNode['name']];
        foreach ($menuNode['children'] ?? [] as $child) {
            if (!empty($child['name'])) {
                $removeNames[] = $child['name'];
            }
        }
        $removeExport = var_export($removeNames, true);

        $downTableAction = $isTakeover
            ? "        // [已有表接管迁移] 此表并非本次迁移所创建，回滚时仅撤销菜单，保留原数据表及数据\n        // if (\$this->hasTable('{$table}')) { /* 保留原表数据 */ }"
            : "        if (\$this->hasTable('{$table}')) {\n            \$this->table('{$table}')->drop()->save();\n        }";

        return <<<PHP
<?php
declare(strict_types=1);

use core\plugin\MenuSync;
use think\migration\Migrator;

/**
 * 迁移: {$migrationClass}（正向生成自 hw.project.json 声明式实体）
 */
class {$migrationClass} extends Migrator
{
    public function up(): void
    {
        if (!\$this->hasTable('{$table}')) {
            {$tableDeclaration}
{$columnsBlock}
                ->create();
        }

        if (\$this->hasTable('auth_rule')) {
            MenuSync::withAdapter(\$this->getAdapter(), static function (): void {
                MenuSync::ensureNode({$menuExport});
            });
        }
    }

    public function down(): void
    {
        if (\$this->hasTable('auth_rule')) {
            MenuSync::withAdapter(\$this->getAdapter(), static function (): void {
                MenuSync::removeNodes({$removeExport});
            });
        }

{$downTableAction}
    }
}
PHP;
    }

    /**
     * 生成 PHPUnit CRUD 特征测试骨架
     */
    public function renderFeatureTest(
        string $studlyName,
        string $table,
        string $apiUrlPrefix,
        array $allowFields,
        array $fieldDetails,
        string $pk = 'id',
        bool $isStringPk = false
    ): string {
        $samplePayload = [];
        foreach ($allowFields as $f) {
            $detail = $fieldDetails[$f] ?? [];
            $type = strtolower((string) ($detail['type'] ?? ''));
            if ($f === 'status') {
                $samplePayload[$f] = 1;
            } elseif ($f === 'email') {
                $samplePayload[$f] = 'crud@example.com';
            } elseif ($f === 'mobile') {
                $samplePayload[$f] = '13800138000';
            } elseif (str_contains($type, 'int')) {
                $samplePayload[$f] = 1;
            } elseif (str_contains($type, 'decimal') || str_contains($type, 'float')) {
                $samplePayload[$f] = 10.5;
            } else {
                $samplePayload[$f] = 'test_' . $f;
            }
        }
        $payloadExport = var_export($samplePayload, true);

        $idExtract = $isStringPk
            ? "\$id = (string) (\$created['data']['id'] ?? '');\n        \$this->assertNotSame('', \$id);"
            : "\$id = (int) (\$created['data']['id'] ?? 0);\n        \$this->assertGreaterThan(0, \$id);";

        return <<<PHP
<?php
declare(strict_types=1);

namespace tests\Feature;

use tests\TestCase;

final class {$studlyName}CrudTest extends TestCase
{
    public function testGuestCannotAccessIndex(): void
    {
        \$resp = \$this->guestGet('{$apiUrlPrefix}/index');
        \$this->assertSame(401, \$resp['code']);
    }

    public function testAdminCrudLifecycle(): void
    {
        \$list = \$this->adminGet('{$apiUrlPrefix}/index');
        \$this->assertApiSuccess(\$list);
        \$this->assertArrayHasKey('list', \$list['data']);
        \$this->assertArrayHasKey('total', \$list['data']);

        \$created = \$this->adminPost('{$apiUrlPrefix}/add', {$payloadExport});
        \$this->assertApiSuccess(\$created);
        {$idExtract}

        \$detail = \$this->adminGet('{$apiUrlPrefix}/read', ['id' => \$id]);
        \$this->assertApiSuccess(\$detail);

        \$edited = \$this->adminPost('{$apiUrlPrefix}/edit?id=' . \$id, {$payloadExport});
        \$this->assertApiSuccess(\$edited);

        \$deleted = \$this->adminPost('{$apiUrlPrefix}/del', ['ids' => [\$id]]);
        \$this->assertApiSuccess(\$deleted);
    }
}
PHP;
    }

    private function mapSqlTypeToTs(string $sqlType): string
    {
        $t = strtolower($sqlType);
        if (str_contains($t, 'int') || str_contains($t, 'decimal') || str_contains($t, 'float') || str_contains($t, 'double') || str_contains($t, 'numeric')) {
            return 'number';
        }
        if (str_contains($t, 'bool')) {
            return 'boolean';
        }
        return 'string';
    }

    public function mapSqlMetaToPhinx(
        string $colName,
        string $sqlType,
        bool $notnull,
        mixed $default,
        ?int $precision = null,
        ?int $scale = null
    ): array {
        $opts = [];
        $t = strtolower($sqlType);

        if (str_ends_with($colName, '_time') || str_contains($t, 'bigint') || $t === 'biginteger') {
            $phinxType = 'biginteger';
        } elseif (str_contains($t, 'tinyint(1)') || $t === 'boolean' || $t === 'bool') {
            $phinxType = 'boolean';
        } elseif (str_contains($t, 'smallint') || $t === 'smallinteger') {
            $phinxType = 'smallinteger';
        } elseif (str_contains($t, 'int') || $t === 'integer') {
            $phinxType = 'integer';
        } elseif (str_contains($t, 'decimal') || str_contains($t, 'numeric')) {
            $phinxType = 'decimal';
            // 精确保留 decimal(p, s) 精度
            if ($precision !== null && $scale !== null) {
                $opts['precision'] = $precision;
                $opts['scale'] = $scale;
            } elseif (preg_match('/decimal\((\d+)\s*,\s*(\d+)\)/i', $sqlType, $m)) {
                $opts['precision'] = (int) $m[1];
                $opts['scale'] = (int) $m[2];
            } else {
                $opts['precision'] = 10;
                $opts['scale'] = 2;
            }
        } elseif (str_contains($t, 'text') || $t === 'longtext') {
            $phinxType = 'text';
        } elseif (str_contains($t, 'json')) {
            $phinxType = 'json';
        } elseif (str_contains($t, 'datetime')) {
            $phinxType = 'datetime';
        } elseif (str_contains($t, 'date')) {
            $phinxType = 'date';
        } elseif (str_contains($t, 'float') || str_contains($t, 'double')) {
            $phinxType = 'float';
        } else {
            $phinxType = 'string';
            if (preg_match('/\((\d+)\)/', $sqlType, $m)) {
                $opts['limit'] = (int) $m[1];
            } else {
                $opts['limit'] = 255;
            }
        }

        if (!$notnull) {
            $opts['null'] = true;
        }
        $normalizedDefault = $this->normalizeSqlDefault($default, $phinxType);
        if ($normalizedDefault !== null) {
            $opts['default'] = $normalizedDefault;
        } elseif ($notnull && in_array($phinxType, ['integer', 'biginteger', 'smallinteger'], true)) {
            $opts['default'] = 0;
        } elseif ($notnull && $phinxType === 'string') {
            $opts['default'] = '';
        }

        return [$phinxType, $opts];
    }

    /**
     * 规范化各数据库（尤其是 SQLite PRAGMA table_info）返回的默认值字面量
     *
     * SQLite 的 dflt_value 会保留外层单引号（如 DEFAULT '' 返回 "''"，DEFAULT 'abc' 返回 "'abc'"），
     * 这里统一剥离外层 SQL 引号并还原转义引号，按目标 Phinx 类型转换为精准的 PHP 标量或 null。
     */
    public function normalizeSqlDefault(mixed $default, string $phinxType): mixed
    {
        if ($default === null || is_bool($default)) {
            return $default;
        }
        if (is_int($default) || is_float($default)) {
            return $phinxType === 'boolean' ? (bool) $default : $default;
        }
        if (!is_string($default)) {
            return null;
        }

        $trimmed = trim($default);
        if ($trimmed === '' || strcasecmp($trimmed, 'NULL') === 0) {
            return $trimmed === '' ? '' : null;
        }

        if (preg_match("/^'(.*)'$/s", $trimmed, $m)) {
            $value = str_replace("''", "'", $m[1]);
        } elseif (preg_match('/^"(.*)"$/s', $trimmed, $m)) {
            $value = str_replace('""', '"', $m[1]);
        } else {
            $value = $trimmed;
        }

        if (in_array(strtoupper((string) $value), ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()', 'CURRENT_DATE', 'CURRENT_TIME'], true)) {
            return in_array($phinxType, ['datetime', 'date', 'timestamp'], true) ? 'CURRENT_TIMESTAMP' : null;
        }
        if (in_array($phinxType, ['text', 'json'], true) && $value === '') {
            return null;
        }
        if ($phinxType === 'boolean') {
            if (in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array(strtolower((string) $value), ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        if (in_array($phinxType, ['integer', 'biginteger', 'smallinteger'], true) && preg_match('/^-?\d+$/', (string) $value)) {
            return (int) $value;
        }
        if ($phinxType === 'float' && is_numeric($value)) {
            return (float) $value;
        }

        return (string) $value;
    }

    private function exportPhpShortArray(array $items): string
    {
        $parts = [];
        foreach ($items as $k => $v) {
            $val = is_bool($v) ? ($v ? 'true' : 'false') : (is_int($v) || is_float($v) ? (string) $v : var_export((string) $v, true));
            $parts[] = "'{$k}' => {$val}";
        }
        return '[' . implode(', ', $parts) . ']';
    }

    private function guessFieldLabel(string $field, string $comment): string
    {
        $clean = trim($comment);
        if ($clean !== '') {
            return $clean;
        }
        $dict = [
            'id'          => 'ID',
            'title'       => '标题',
            'name'        => '名称',
            'username'    => '用户名',
            'nickname'    => '昵称',
            'mobile'      => '手机号',
            'email'       => '邮箱',
            'content'     => '内容',
            'amount'      => '金额',
            'remark'      => '备注',
            'status'      => '状态',
            'weight'      => '排序权重',
            'sort'        => '排序',
            'create_time' => '创建时间',
            'update_time' => '更新时间',
        ];
        return $dict[$field] ?? $field;
    }

    /**
     * 生成仅返回白名单安全字段的公开前台 ApiController（app/api/controller/*）
     */
    public function renderPublicApiController(
        string $ctrlClass,
        string $modelFqn,
        string $validateFqn,
        array $publicReadFields,
        array $submitFields,
        array $searchFields,
        string $pk = 'id',
        bool $hasStatus = false
    ): string {
        $readExport = var_export(array_values(array_unique($publicReadFields)), true);
        $submitExport = var_export(array_values(array_unique($submitFields)), true);
        $searchExpr = $searchFields ? implode('|', $searchFields) : '';
        $statusFilter = $hasStatus ? "\n        \$query->where('status', 1);" : '';
        $readStatusFilter = $hasStatus ? "\n        \$query->where('status', 1);" : '';
        $defaultStatusAssign = $hasStatus ? "\n        \$payload['status'] = 1;" : '';

        $noNeedLogin = $submitFields ? "['index', 'read', 'submit']" : "['index', 'read']";

        $submitMethod = '';
        if ($submitFields) {
            $submitMethod = <<<PHP

    /**
     * 前台公开提交（仅接收显式白名单字段）
     */
    public function submit(): void
    {
        if (!\$this->request->isPost()) {
            \$this->error('请求方式错误', 0, null, 405);
        }

        \$input = \$this->request->only(\$this->submitFields, 'post');
        \$payload = [];
        foreach (\$this->submitFields as \$field) {
            if (array_key_exists(\$field, \$input)) {
                \$payload[\$field] = is_string(\$input[\$field]) ? trim(\$input[\$field]) : \$input[\$field];
            }
        }

        if (class_exists(\\{$validateFqn}::class)) {
            (new \\{$validateFqn}())
                ->only(\$this->submitFields)
                ->failException(true)
                ->check(\$payload);
        }
{$defaultStatusAssign}

        \$model = new \\{$modelFqn}();
        // 同步放行自动时间戳字段，避免 allowField() 过滤掉 UnixTimeModel 自动写入的 create_time/update_time
        \$allowWriteFields = array_values(array_unique(array_merge(
            array_keys(\$payload),
            ['create_time', 'update_time'],
            array_values(array_filter(\$model->getAutoTimeFields(), static fn (\$f) => is_string(\$f) && \$f !== ''))
        )));
        \$model->allowField(\$allowWriteFields)->save(\$payload);

        \$this->success([
            '{$pk}' => \$model->getAttr('{$pk}'),
        ], '提交成功');
    }
PHP;
        }

        $searchBlock = $searchExpr !== ''
            ? <<<PHP
        if (\$keyword !== '') {
            \$query->whereLike('{$searchExpr}', '%' . \$keyword . '%');
        }
PHP
            : '';

        return <<<PHP
<?php
declare(strict_types=1);

namespace app\\api\\controller;

use core\\base\\ApiController;

/**
 * 前台公开只读/白名单提交接口（由 hw:crud --with-api / hw:page 自动生成）
 *
 * 仅查询并输出 \$publicFields 白名单字段，禁止暴露密码、Token、归属人等敏感列。
 */
class {$ctrlClass} extends ApiController
{
    protected array \$noNeedLogin = {$noNeedLogin};

    /** @var string[] 前台允许返回的公开字段白名单 */
    protected array \$publicFields = {$readExport};

    /** @var string[] 前台允许访客提交的字段白名单 */
    protected array \$submitFields = {$submitExport};

    /**
     * 公开分页列表
     */
    public function index(): void
    {
        \$page = max(1, (int) \$this->request->get('page', 1));
        \$limit = max(1, min(50, (int) \$this->request->get('limit', 12)));
        \$keyword = trim((string) \$this->request->get('keyword', ''));

        \$model = new \\{$modelFqn}();
        \$query = \$model->field(\$this->publicFields)->order('{$pk}', 'desc');{$statusFilter}
{$searchBlock}

        \$total = (clone \$query)->count();
        \$list = \$query->page(\$page, \$limit)->select()->toArray();

        \$this->success([
            'list'  => \$list,
            'total' => (int) \$total,
            'page'  => \$page,
            'limit' => \$limit,
        ]);
    }

    /**
     * 公开单条详情
     */
    public function read(): void
    {
        \$id = \$this->request->param('id', '');
        if (\$id === '' || \$id === null) {
            \$this->error('缺少参数 id');
        }

        \$model = new \\{$modelFqn}();
        \$query = \$model->field(\$this->publicFields)->where('{$pk}', \$id);{$readStatusFilter}
        \$row = \$query->find();
        if (!\$row) {
            \$this->error('记录不存在', 0, null, 404);
        }

        \$this->success(\$row->toArray());
    }
{$submitMethod}

    /* @hw-custom-start: api_actions */
    // 可在此补充其它前台接口（公开方法记得同步声明 \$noNeedLogin）
    /* @hw-custom-end: api_actions */
}
PHP;
    }

    /**
     * 生成前台页面控制器（app/index/controller/*）
     */
    public function renderForegroundPageController(
        string $ctrlClass,
        string $slug,
        string $title,
        string $theme = 'light'
    ): string {
        $safeTitle = var_export($title, true);
        $safeTheme = var_export($theme === 'dark' ? 'dark' : 'light', true);

        return <<<PHP
<?php
declare(strict_types=1);

namespace app\\index\\controller;

use think\\facade\\Db;
use think\\Response;

/**
 * 前台页面控制器：/{$slug}（由 hw:page / hw:crud --with-api 自动生成）
 */
class {$ctrlClass}
{
    public function index(): Response
    {
        \$viewPath = app_path() . 'view' . DIRECTORY_SEPARATOR . '{$slug}' . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file(\$viewPath)) {
            return response('Page template not found', 404);
        }

        \$siteName = 'HwAdmin';
        \$siteFavicon = is_file(public_path() . 'favicon.svg') ? '/favicon.svg' : '/favicon.ico';
        try {
            \$cfgRows = Db::name('config')->whereIn('name', ['site_name', 'site_favicon'])->column('value', 'name');
            if (isset(\$cfgRows['site_name']) && is_string(\$cfgRows['site_name']) && trim(\$cfgRows['site_name']) !== '') {
                \$siteName = trim(\$cfgRows['site_name']);
            }
            if (isset(\$cfgRows['site_favicon']) && is_string(\$cfgRows['site_favicon']) && trim(\$cfgRows['site_favicon']) !== '') {
                \$rawFavicon = trim(\$cfgRows['site_favicon']);
                \$siteFavicon = preg_match('/^(?:https?:|data:|blob:)/i', \$rawFavicon) || str_starts_with(\$rawFavicon, '/')
                    ? \$rawFavicon
                    : '/' . \$rawFavicon;
            }
        } catch (\Throwable) {
        }

        \$html = (string) file_get_contents(\$viewPath);
        \$html = strtr(\$html, [
            '{{page_title}}'   => htmlspecialchars({$safeTitle}, ENT_QUOTES, 'UTF-8'),
            '{{site_name}}'    => htmlspecialchars(\$siteName, ENT_QUOTES, 'UTF-8'),
            '{{site_favicon}}' => htmlspecialchars(\$siteFavicon, ENT_QUOTES, 'UTF-8'),
            '{{page_theme}}'   => htmlspecialchars({$safeTheme}, ENT_QUOTES, 'UTF-8'),
        ]);

        return response(\$html, 200, [
            'Content-Type'           => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-cache',
        ]);
    }

    /* @hw-custom-start: page_controller */
    // 可在此扩展页面服务端数据注入或子路由动作
    /* @hw-custom-end: page_controller */
}
PHP;
    }

    /**
     * 生成响应式前台 HTML/CSS 骨架页（app/index/view/{slug}/index.html，支持亮/暗双主题切换）
     */
    public function renderForegroundPageView(
        string $slug,
        string $title,
        array $displayFields = [],
        array $submitFields = [],
        array $fieldDetails = [],
        string $pk = 'id',
        string $theme = 'light',
        bool $hasApi = false
    ): string {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $initTheme = $theme === 'dark' ? 'dark' : 'light';
        $apiEndpoint = "/api/{$slug}";

        // 构建表单字段 HTML（若有提交字段）
        $formHtml = '';
        if ($hasApi && !empty($submitFields)) {
            $inputs = [];
            foreach ($submitFields as $f) {
                $meta = $fieldDetails[$f] ?? [];
                $typeRaw = strtolower((string) ($meta['type'] ?? 'string'));
                $label = htmlspecialchars($this->guessFieldLabel($f, (string) ($meta['comment'] ?? '')), ENT_QUOTES, 'UTF-8');
                $reqAttr = !empty($meta['notnull']) ? ' required' : '';
                if (str_contains($typeRaw, 'text') || in_array($f, ['content', 'message', 'remark', 'description'], true)) {
                    $inputs[] = <<<HTML
          <div class="hw-field hw-field-full">
            <label for="field-{$f}">{$label}</label>
            <textarea id="field-{$f}" name="{$f}" rows="4" placeholder="请输入{$label}"{$reqAttr}></textarea>
          </div>
HTML;
                } else {
                    $inputType = $f === 'email' ? 'email' : (in_array($f, ['mobile', 'phone', 'tel'], true) ? 'tel' : 'text');
                    $inputs[] = <<<HTML
          <div class="hw-field">
            <label for="field-{$f}">{$label}</label>
            <input id="field-{$f}" name="{$f}" type="{$inputType}" placeholder="请输入{$label}"{$reqAttr} />
          </div>
HTML;
                }
            }
            $fieldsBlock = implode("\n", $inputs);
            $formHtml = <<<HTML
      <section class="hw-card" aria-labelledby="form-heading">
        <h2 id="form-heading" class="hw-section-title">在线提交</h2>
        <form id="hwPageForm" class="hw-form-grid">
{$fieldsBlock}
          <div class="hw-field hw-field-full hw-actions">
            <button type="submit" class="hw-btn hw-btn-primary">立即提交</button>
            <span id="hwFormMsg" class="hw-form-msg" role="status" aria-live="polite"></span>
          </div>
        </form>
      </section>
HTML;
        }

        // 展示字段元数据供前端 JS 安全渲染
        $displayMeta = [];
        foreach ($displayFields as $f) {
            if ($f === $pk || $f === 'status') {
                continue;
            }
            $meta = $fieldDetails[$f] ?? [];
            $displayMeta[] = [
                'name'  => $f,
                'label' => $this->guessFieldLabel($f, (string) ($meta['comment'] ?? '')),
            ];
        }
        $displayMetaJson = (string) json_encode($displayMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($hasApi) {
            $bodySection = <<<HTML
{$formHtml}
      <section class="hw-card" aria-labelledby="list-heading">
        <div class="hw-list-header">
          <h2 id="list-heading" class="hw-section-title">最新公开列表</h2>
          <button type="button" id="hwReloadBtn" class="hw-btn hw-btn-ghost">刷新</button>
        </div>
        <div id="hwItemList" class="hw-list-grid">
          <p class="hw-muted">正在加载...</p>
        </div>
      </section>
HTML;
            $scriptBlock = <<<JS
  <script>
    (function () {
      const apiBase = '{$apiEndpoint}';
      const displayFields = {$displayMetaJson};
      const listEl = document.getElementById('hwItemList');
      const formEl = document.getElementById('hwPageForm');
      const msgEl = document.getElementById('hwFormMsg');
      const reloadBtn = document.getElementById('hwReloadBtn');

      function formatVal(name, val) {
        if (val === null || val === undefined || val === '') return '-';
        if (name.endsWith('_time')) {
          const ts = Number(val || 0);
          if (!ts || ts <= 0) return '-';
          return new Date(ts > 1e12 ? ts : ts * 1000).toLocaleString();
        }
        return String(val);
      }

      function renderRows(rows) {
        listEl.innerHTML = '';
        if (!Array.isArray(rows) || rows.length === 0) {
          const empty = document.createElement('p');
          empty.className = 'hw-muted';
          empty.textContent = '暂无公开记录';
          listEl.appendChild(empty);
          return;
        }
        rows.forEach(function (row) {
          const card = document.createElement('article');
          card.className = 'hw-item-card';
          displayFields.forEach(function (col) {
            if (!(col.name in row)) return;
            const line = document.createElement('div');
            line.className = 'hw-item-row';
            const k = document.createElement('span');
            k.className = 'hw-item-key';
            k.textContent = col.label + '：';
            const v = document.createElement('span');
            v.className = 'hw-item-val';
            v.textContent = formatVal(col.name, row[col.name]);
            line.appendChild(k);
            line.appendChild(v);
            card.appendChild(line);
          });
          listEl.appendChild(card);
        });
      }

      async function loadList() {
        try {
          const res = await fetch(apiBase + '/index?page=1&limit=12');
          const json = await res.json();
          if (json.code === 1 && json.data) {
            renderRows(json.data.list || []);
          } else {
            listEl.textContent = json.msg || '加载失败';
          }
        } catch (err) {
          listEl.textContent = '网络请求失败';
        }
      }

      if (reloadBtn) {
        reloadBtn.addEventListener('click', loadList);
      }

      if (formEl) {
        formEl.addEventListener('submit', async function (e) {
          e.preventDefault();
          msgEl.textContent = '提交中...';
          msgEl.className = 'hw-form-msg';
          const payload = Object.fromEntries(new FormData(formEl).entries());
          try {
            const res = await fetch(apiBase + '/submit', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (json.code === 1) {
              msgEl.textContent = json.msg || '提交成功';
              msgEl.className = 'hw-form-msg is-ok';
              formEl.reset();
              loadList();
            } else {
              msgEl.textContent = json.msg || '提交失败';
              msgEl.className = 'hw-form-msg is-err';
            }
          } catch (err) {
            msgEl.textContent = '网络请求异常';
            msgEl.className = 'hw-form-msg is-err';
          }
        });
      }

      loadList();
    })();
  </script>
JS;
        } else {
            $bodySection = <<<HTML
      <section class="hw-card">
        <h2 class="hw-section-title">{$escapedTitle}</h2>
        <p class="hw-muted">当前页面已通过 <code>php think hw:page</code> 自动注册路由 <code>/{$slug}</code>。请根据业务需求继续完善模块与展示内容。</p>
      </section>
HTML;
            $scriptBlock = '';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN" data-theme="{$initTheme}">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{page_title}} - {{site_name}}</title>
  <link rel="icon" href="{{site_favicon}}" />
  <!-- @hw-custom-start: page_head -->
  <!-- @hw-custom-end: page_head -->
  <style>
    :root {
      --bg: #f8fafc;
      --surface: #ffffff;
      --surface-alt: #f1f5f9;
      --border: #e2e8f0;
      --text: #0f172a;
      --muted: #64748b;
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --ok: #059669;
      --err: #dc2626;
    }
    [data-theme="dark"] {
      --bg: #0b1120;
      --surface: #111827;
      --surface-alt: #1e293b;
      --border: #1f2937;
      --text: #f8fafc;
      --muted: #94a3b8;
      --primary: #3b82f6;
      --primary-hover: #60a5fa;
      --ok: #34d399;
      --err: #f87171;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      line-height: 1.6;
    }
    .hw-header {
      border-bottom: 1px solid var(--border);
      background: var(--surface);
      padding: 16px 24px;
    }
    .hw-header-inner, .hw-main {
      max-width: 1040px;
      margin: 0 auto;
    }
    .hw-header-inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .hw-brand { font-weight: 700; font-size: 18px; color: var(--text); text-decoration: none; }
    .hw-main { padding: 32px 24px 64px; display: grid; gap: 24px; }
    .hw-hero h1 { font-size: clamp(26px, 4vw, 36px); margin-bottom: 8px; }
    .hw-muted { color: var(--muted); font-size: 14px; }
    .hw-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 24px;
    }
    .hw-section-title { font-size: 18px; margin-bottom: 16px; }
    .hw-list-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .hw-list-header .hw-section-title { margin-bottom: 0; }
    .hw-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .hw-field { display: flex; flex-direction: column; gap: 6px; }
    .hw-field-full { grid-column: 1 / -1; }
    .hw-field label { font-size: 13px; font-weight: 600; color: var(--text); }
    .hw-field input, .hw-field textarea {
      width: 100%;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--bg);
      color: var(--text);
      font: inherit;
    }
    .hw-actions { flex-direction: row; align-items: center; gap: 12px; }
    .hw-btn {
      cursor: pointer;
      border-radius: 8px;
      padding: 9px 18px;
      font-size: 14px;
      font-weight: 600;
      border: 1px solid transparent;
    }
    .hw-btn-primary { background: var(--primary); color: #fff; }
    .hw-btn-primary:hover { background: var(--primary-hover); }
    .hw-btn-ghost { background: var(--surface-alt); color: var(--text); border-color: var(--border); }
    .hw-form-msg { font-size: 13px; }
    .hw-form-msg.is-ok { color: var(--ok); }
    .hw-form-msg.is-err { color: var(--err); }
    .hw-list-grid { display: grid; gap: 12px; }
    .hw-item-card {
      background: var(--surface-alt);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 14px 16px;
      display: grid;
      gap: 6px;
    }
    .hw-item-row { font-size: 14px; }
    .hw-item-key { color: var(--muted); }
    @media (max-width: 768px) {
      .hw-form-grid { grid-template-columns: 1fr; }
      .hw-main { padding: 20px 16px 48px; }
    }
  </style>
</head>
<body>
  <header class="hw-header">
    <div class="hw-header-inner">
      <a href="/" class="hw-brand">{{site_name}}</a>
      <div style="display:flex;align-items:center;gap:10px;">
        <!-- @hw-custom-start: page_nav -->
        <a href="/" class="hw-btn hw-btn-ghost">返回首页</a>
        <!-- @hw-custom-end: page_nav -->
        <button type="button" id="hwThemeToggle" class="hw-btn hw-btn-ghost">切换主题</button>
      </div>
    </div>
  </header>
  <main class="hw-main">
    <!-- @hw-custom-start: page_hero -->
    <section class="hw-hero">
      <h1>{{page_title}}</h1>
      <p class="hw-muted">由 HwAdmin 前台骨架生成，支持响应式布局与白名单公开接口安全接入。</p>
    </section>
    <!-- @hw-custom-end: page_hero -->
    <!-- @hw-custom-start: page_body -->
{$bodySection}
    <!-- @hw-custom-end: page_body -->
  </main>
  <script>
    (function () {
      const btn = document.getElementById('hwThemeToggle');
      if (btn) {
        btn.addEventListener('click', function () {
          const root = document.documentElement;
          root.setAttribute('data-theme', root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        });
      }
    })();
  </script>
{$scriptBlock}
  <!-- @hw-custom-start: page_script -->
  <!-- @hw-custom-end: page_script -->
</body>
</html>
HTML;
    }

    /**
     * 解析逗号分隔的 CLI 字段列表选项（如 "nickname,contact,content"）
     *
     * @return string[]|null
     */
    public function parseFieldListOption(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $cols = [];
        foreach (explode(',', $trimmed) as $item) {
            $col = trim($item);
            if ($col !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col) && !in_array($col, $cols, true)) {
                $cols[] = $col;
            }
        }
        return $cols;
    }

    /**
     * 解析轻量内联字段定义 DSL（如 "nickname:string(64),contact:string(100)?,bio:?text=默认:简介,status:int=1:状态"）
     *
     * @return array{pk: string, fields: string[], field_details: array<string, array<string, mixed>>}
     */
    public function parseInlineFields(string $spec, string $pk = 'id', bool $withTimestamps = true): array
    {
        $tokens = $this->splitTopLevelComma($spec);
        if (!$tokens) {
            throw new \InvalidArgumentException('--fields 不能为空，示例：nickname:string(64),contact:string(100)?,bio:?text:简介');
        }

        $fields = [];
        $fieldDetails = [];
        $typeAliasMap = [
            'str'          => 'varchar',
            'string'       => 'varchar',
            'varchar'      => 'varchar',
            'char'         => 'char',
            'text'         => 'text',
            'mediumtext'   => 'text',
            'longtext'     => 'longtext',
            'int'          => 'int',
            'integer'      => 'int',
            'tinyint'      => 'tinyint',
            'smallint'     => 'smallint',
            'smallinteger' => 'smallint',
            'bigint'       => 'bigint',
            'biginteger'   => 'bigint',
            'bool'         => 'boolean',
            'boolean'      => 'boolean',
            'decimal'      => 'decimal',
            'numeric'      => 'decimal',
            'float'        => 'float',
            'double'       => 'double',
            'json'         => 'json',
            'datetime'     => 'datetime',
            'date'         => 'date',
            'timestamp'    => 'timestamp',
        ];

        foreach ($tokens as $rawToken) {
            $parts = explode(':', $rawToken, 3);
            $colName = trim($parts[0]);
            if ($colName === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $colName)) {
                throw new \InvalidArgumentException("内联字段名 [{$colName}] 格式非法，仅支持字母、数字与下划线");
            }
            if (isset($fieldDetails[$colName])) {
                throw new \InvalidArgumentException("内联字段 [{$colName}] 重复定义");
            }

            $typeSpec = trim($parts[1] ?? 'string(120)');
            $comment = trim($parts[2] ?? '');

            $hasDefault = false;
            $rawDefault = null;
            if (preg_match('/^([^=]+?)(?:=(.*))$/', $typeSpec, $dm)) {
                $typeSpec = trim($dm[1]);
                $hasDefault = true;
                $rawDefault = trim($dm[2], " \t'\"");
            }

            // 同时兼容前缀 ?（如 ?text / ?string(100)）与后缀 ?（如 text? / string(100)?）可空写法
            $nullable = false;
            if (str_starts_with($typeSpec, '?')) {
                $nullable = true;
                $typeSpec = trim(substr($typeSpec, 1));
            }
            if (str_ends_with($typeSpec, '?')) {
                $nullable = true;
                $typeSpec = trim(substr($typeSpec, 0, -1));
            }
            if ($typeSpec === '') {
                $typeSpec = 'string(120)';
            }

            if (!preg_match('/^([a-zA-Z_]+)(?:\(([^)]+)\))?$/', $typeSpec, $tm)) {
                throw new \InvalidArgumentException("字段 [{$colName}] 的类型声明 [{$typeSpec}] 无法解析（示例：nickname:string(64),contact:string(100)?,bio:?text=默认值:简介；可空支持 ?text 或 text?）");
            }

            $baseKey = strtolower($tm[1]);
            if (!isset($typeAliasMap[$baseKey])) {
                throw new \InvalidArgumentException("字段 [{$colName}] 使用了不支持的类型 [{$tm[1]}]（支持 string/text/int/tinyint/bigint/boolean/decimal/float/json/datetime/date 等，可加 ? 标记可空如 ?text 或 text?）");
            }
            $canonicalType = $typeAliasMap[$baseKey];
            $argStr = trim($tm[2] ?? '');

            $length = null;
            $precision = null;
            $scale = null;
            $sqlType = $canonicalType;

            if (in_array($canonicalType, ['varchar', 'char'], true)) {
                $length = ($argStr !== '' && ctype_digit($argStr) && (int) $argStr > 0) ? (int) $argStr : 255;
                $sqlType = "{$canonicalType}({$length})";
            } elseif ($canonicalType === 'decimal') {
                if (preg_match('/^(\d+)\s*,\s*(\d+)$/', $argStr, $pm)) {
                    $precision = (int) $pm[1];
                    $scale = (int) $pm[2];
                } else {
                    $precision = 10;
                    $scale = 2;
                }
                $sqlType = "decimal({$precision},{$scale})";
            } elseif ($canonicalType === 'boolean') {
                $sqlType = 'tinyint(1)';
            }

            if ($hasDefault) {
                if (strcasecmp((string) $rawDefault, 'null') === 0) {
                    $defaultVal = null;
                } elseif (in_array($canonicalType, ['int', 'tinyint', 'smallint', 'bigint'], true) && preg_match('/^-?\d+$/', (string) $rawDefault)) {
                    $defaultVal = (int) $rawDefault;
                } elseif (in_array($canonicalType, ['decimal', 'float', 'double'], true) && is_numeric($rawDefault)) {
                    $defaultVal = (float) $rawDefault;
                } elseif ($canonicalType === 'boolean') {
                    $defaultVal = in_array(strtolower((string) $rawDefault), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
                } else {
                    $defaultVal = $rawDefault;
                }
            } else {
                $defaultVal = $colName === 'status' ? 1 : null;
            }

            $fields[] = $colName;
            $fieldDetails[$colName] = [
                'name'      => $colName,
                'type'      => $sqlType,
                'length'    => $length,
                'notnull'   => !$nullable,
                'default'   => $defaultVal,
                'comment'   => $comment !== '' ? $comment : $this->guessFieldLabel($colName, ''),
                'precision' => $precision,
                'scale'     => $scale,
                'primary'   => $colName === $pk,
            ];
        }

        if (!in_array($pk, $fields, true)) {
            array_unshift($fields, $pk);
            $fieldDetails = [$pk => [
                'name'      => $pk,
                'type'      => 'int',
                'length'    => null,
                'notnull'   => true,
                'default'   => null,
                'comment'   => 'ID',
                'precision' => null,
                'scale'     => null,
                'primary'   => true,
            ]] + $fieldDetails;
        }

        if ($withTimestamps) {
            foreach (['create_time' => '创建时间', 'update_time' => '更新时间'] as $tsCol => $tsLabel) {
                if (!in_array($tsCol, $fields, true)) {
                    $fields[] = $tsCol;
                    $fieldDetails[$tsCol] = [
                        'name'      => $tsCol,
                        'type'      => 'bigint',
                        'length'    => null,
                        'notnull'   => false,
                        'default'   => null,
                        'comment'   => $tsLabel,
                        'precision' => null,
                        'scale'     => null,
                        'primary'   => false,
                    ];
                }
            }
        }

        return [
            'pk'            => $pk,
            'fields'        => $fields,
            'field_details' => $fieldDetails,
        ];
    }

    /**
     * 按括号层级切分顶层逗号表达式
     *
     * @return string[]
     */
    private function splitTopLevelComma(string $spec): array
    {
        $items = [];
        $buf = '';
        $depth = 0;
        $len = strlen($spec);
        for ($i = 0; $i < $len; $i++) {
            $ch = $spec[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')' && $depth > 0) {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $trimmed = trim($buf);
                if ($trimmed !== '') {
                    $items[] = $trimmed;
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $last = trim($buf);
        if ($last !== '') {
            $items[] = $last;
        }
        return $items;
    }

    /**
     * 按安全与隐私启发式规则拆解前台公开返回字段 ($public_read_fields) 与访客提交字段 ($submit_fields)
     *
     * 支持通过 $explicitPublicFields / $explicitSubmitFields（CLI 参数或 hw.project.json）覆盖默认规则，
     * 用于明确区分“只收不展”（如 contact 仅提交不公开）与“只展不收”（如 reply/is_top 公开展示不允许访客提交）；
     * 无论是否覆盖，高危凭证列（密码/Token/密钥）与归属/权限列始终强制隔离。
     *
     * @param string[]      $fields
     * @param string[]      $allowFields
     * @param string[]|null $explicitPublicFields
     * @param string[]|null $explicitSubmitFields
     * @param string[]      $warnings
     * @return array{public_read_fields: string[], submit_fields: string[]}
     */
    public function partitionPublicApiFields(
        array $fields,
        array $allowFields,
        string $pk = 'id',
        ?array $explicitPublicFields = null,
        ?array $explicitSubmitFields = null,
        array &$warnings = []
    ): array {
        $secretPattern = '/(password|pass|pwd|salt|token|secret|access_token|refresh_token|remember_token|auth_key|private_key|api_key)/i';
        $ownershipPattern = '/(tenant_id|admin_id|user_id)/i';
        $privilegePattern = '/(rules|permissions|role_id|role_ids|group_id|group_ids|is_admin|is_super|money|balance|score|credit|quota)/i';
        $piiPattern = '/^(contact|email|mail|phone|mobile|tel|telephone|wechat|wx|qq|address|id_card|idcard|ip|client_ip|user_ip|ua|user_agent|device_id)$|(_ip|_phone|_mobile|_email|_contact)$/i';
        $moderationPattern = '/^(reply|reply_.*|is_top|is_pin|is_hot|audit|audit_.*|review|review_.*|handle_.*|admin_note|internal_note|operator_.*)$/i';
        $readOnlyMetaCols = ['status', 'weight', 'sort', 'views', 'likes', 'downloads'];
        $systemCols = [$pk, 'create_time', 'update_time', 'delete_time'];

        $hasCreateTime = in_array('create_time', $fields, true);

        if ($explicitPublicFields !== null) {
            $publicReadFields = [];
            foreach ($explicitPublicFields as $col) {
                if (!in_array($col, $fields, true)) {
                    $warnings[] = "public_fields 中声明的字段 [{$col}] 不在数据表列中，已忽略";
                    continue;
                }
                if (preg_match($secretPattern, $col) || preg_match($ownershipPattern, $col) || preg_match($privilegePattern, $col)) {
                    $warnings[] = "public_fields 中的受限字段 [{$col}] 属于机密/归属/权限列，已被安全基线强制剔除";
                    continue;
                }
                if (!in_array($col, $publicReadFields, true)) {
                    $publicReadFields[] = $col;
                }
            }
        } else {
            $publicCandidates = array_values(array_unique(array_merge(
                [$pk],
                $allowFields,
                $hasCreateTime ? ['create_time'] : []
            )));

            $publicReadFields = array_values(array_filter(
                $publicCandidates,
                static function (string $col) use ($pk, $secretPattern, $piiPattern, $moderationPattern): bool {
                    if ($col === $pk) {
                        return true;
                    }
                    if (preg_match($secretPattern, $col) || preg_match($piiPattern, $col) || preg_match($moderationPattern, $col)) {
                        return false;
                    }
                    return true;
                }
            ));
        }

        if ($explicitSubmitFields !== null) {
            $submitFields = [];
            foreach ($explicitSubmitFields as $col) {
                if (!in_array($col, $fields, true)) {
                    $warnings[] = "submit_fields 中声明的字段 [{$col}] 不在数据表列中，已忽略";
                    continue;
                }
                if (in_array($col, $systemCols, true)) {
                    $warnings[] = "submit_fields 中的系统托管字段 [{$col}] 不得由访客提交，已剔除";
                    continue;
                }
                if (preg_match($secretPattern, $col) || preg_match($ownershipPattern, $col) || preg_match($privilegePattern, $col)) {
                    $warnings[] = "submit_fields 中的受限字段 [{$col}] 属于机密/归属/权限列，已被安全基线强制剔除";
                    continue;
                }
                if (!in_array($col, $submitFields, true)) {
                    $submitFields[] = $col;
                }
            }
        } else {
            $submitFields = array_values(array_filter(
                $allowFields,
                static function (string $col) use ($secretPattern, $piiPattern, $moderationPattern, $readOnlyMetaCols): bool {
                    if (in_array($col, $readOnlyMetaCols, true)) {
                        return false;
                    }
                    // IP / UA / 设备 / 回复 / 置顶 / 审核等列不应由访客提交；联系方式等隐私列也默认收敛为按需显式放开
                    if (preg_match($secretPattern, $col) || preg_match($piiPattern, $col) || preg_match($moderationPattern, $col)) {
                        return false;
                    }
                    return true;
                }
            ));
        }

        return [
            'public_read_fields' => $publicReadFields,
            'submit_fields'      => $submitFields,
        ];
    }

    /**
     * 向系统默认门户首页（app/index/view/portal/index.html）幂等挂载独立前台页面入口链接
     */
    public function buildPortalNavUpdate(string $existingHtml, string $slug, string $title): string
    {
        $href = '/' . ltrim($slug, '/');
        if (str_contains($existingHtml, "href=\"{$href}\"") || str_contains($existingHtml, "href='{$href}'")) {
            return $existingHtml;
        }
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $linkHtml = "<a href=\"{$href}\" class=\"btn btn-secondary\">{$escapedTitle}</a>";
        $endMarker = '<!-- @hw-page-nav-end -->';
        if (str_contains($existingHtml, $endMarker)) {
            return str_replace($endMarker, "        {$linkHtml}\n        {$endMarker}", $existingHtml);
        }
        return $existingHtml;
    }

    /**
     * 向 app/index/route/app.php 幂等注册单段前台路由（位于 :entry 兜底路由之前）
     */
    public function buildIndexRouteUpdate(string $existingContent, string $slug, string $ctrlClass): string
    {
        $routeLine = "Route::get('{$slug}', '{$ctrlClass}/index');";
        if (str_contains($existingContent, $routeLine) || str_contains($existingContent, "'{$ctrlClass}/index'")) {
            return $existingContent;
        }

        $startMarker = '// @hw-page-routes-start';
        $endMarker = '// @hw-page-routes-end';
        if (str_contains($existingContent, $endMarker)) {
            return str_replace($endMarker, "{$routeLine}\n{$endMarker}", $existingContent);
        }

        if (str_contains($existingContent, "Route::any(':entry'")) {
            return str_replace(
                "Route::any(':entry'",
                "{$startMarker}\n{$routeLine}\n{$endMarker}\nRoute::any(':entry'",
                $existingContent
            );
        }

        return rtrim($existingContent) . "\n{$startMarker}\n{$routeLine}\n{$endMarker}\n";
    }

    /**
     * 生成移动端 API 强类型封装（admin-ui/mobile/src/api/<slug>.ts）
     * 注意：src/http 已自动拼接 VITE_API_ORIGIN + /api，path 严禁重复写 /api 前缀
     */
    public function renderMobileApi(
        string $studlyName,
        string $slug,
        string $pk,
        array $publicReadFields,
        array $submitFields,
        array $fieldDetails
    ): string {
        $itemProps = [];
        foreach ($publicReadFields as $f) {
            $tsType = $this->mapSqlTypeToTs((string) ($fieldDetails[$f]['type'] ?? ''));
            $optional = ($f === $pk || str_ends_with($f, '_time')) ? '?' : '';
            $itemProps[] = "  {$f}{$optional}: {$tsType}";
        }
        $itemPropsBlock = implode("\n", $itemProps);

        $submitProps = [];
        foreach ($submitFields as $f) {
            $tsType = $this->mapSqlTypeToTs((string) ($fieldDetails[$f]['type'] ?? ''));
            $submitProps[] = "  {$f}?: {$tsType}";
        }
        $submitPropsBlock = $submitProps ? implode("\n", $submitProps) : '  [key: string]: unknown';

        $submitExport = $submitFields ? <<<TS

export const submit{$studlyName} = (data: {$studlyName}SubmitForm) =>
  request<Record<string, unknown>>({
    path: '/{$slug}/submit',
    method: 'POST',
    data,
    authenticated: false,
  })
TS : '';

        return <<<TS
import { request } from '@/http'

export interface {$studlyName}Item {
{$itemPropsBlock}
  [key: string]: unknown
}

export interface {$studlyName}SubmitForm {
{$submitPropsBlock}
  [key: string]: unknown
}

export interface {$studlyName}PageResult {
  list: {$studlyName}Item[]
  total: number
  page: number
  limit: number
}

export const get{$studlyName}List = (params?: { page?: number; limit?: number; keyword?: string }) =>
  request<{$studlyName}PageResult>({
    path: '/{$slug}/index',
    method: 'GET',
    data: params,
    authenticated: false,
  })

export const get{$studlyName}Detail = (id: number | string) =>
  request<{$studlyName}Item>({
    path: '/{$slug}/read',
    method: 'GET',
    data: { id },
    authenticated: false,
  })
{$submitExport}

/* @hw-custom-start: mobile-api-extensions */
// 移动端业务扩展 API 请求写在此处，增量更新时自动保留
/* @hw-custom-end: mobile-api-extensions */

TS;
    }

    /**
     * 生成移动端 uni-app + Wot UI V2 页面（admin-ui/mobile/src/pages/<slug>/index.vue）
     */
    public function renderMobilePage(
        string $studlyName,
        string $title,
        string $slug,
        string $pk,
        array $publicReadFields,
        array $submitFields,
        array $fieldDetails
    ): string {
        $safeTitle = addcslashes($title, "'\\");
        $formDefaults = [];
        $formFieldsHtml = [];
        $requiredChecks = [];

        foreach ($submitFields as $f) {
            $detail = $fieldDetails[$f] ?? [];
            $label = $this->guessFieldLabel($f, (string) ($detail['comment'] ?? ''));
            $type = strtolower((string) ($detail['type'] ?? ''));
            $len = (int) ($detail['length'] ?? 0);
            $maxLen = $len > 0 ? $len : 64;

            if (str_contains($type, 'text')) {
                $formDefaults[] = "  {$f}: '',";
                $formFieldsHtml[] = <<<HTML
      <wd-cell title="{$label}" vertical>
        <wd-textarea
          v-model="formData.{$f}"
          placeholder="请输入{$label}"
          :maxlength="500"
          show-word-limit
          clearable
        />
      </wd-cell>
HTML;
            } elseif (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
                $formDefaults[] = "  {$f}: '',";
                $formFieldsHtml[] = <<<HTML
      <wd-cell title="{$label}">
        <wd-input
          v-model="formData.{$f}"
          compact
          type="number"
          placeholder="请输入{$label}"
          :maxlength="20"
          clearable
        />
      </wd-cell>
HTML;
            } else {
                $formDefaults[] = "  {$f}: '',";
                $formFieldsHtml[] = <<<HTML
      <wd-cell title="{$label}">
        <wd-input
          v-model="formData.{$f}"
          compact
          placeholder="请输入{$label}"
          :maxlength="{$maxLen}"
          clearable
        />
      </wd-cell>
HTML;
            }

            if (!empty($detail['notnull']) && ($detail['default'] ?? null) === null) {
                $requiredChecks[] = "  if (!String(formData.{$f} ?? '').trim()) {\n    toast.warning('请填写{$label}')\n    return\n  }";
            }
        }

        $displayMeta = [];
        foreach ($publicReadFields as $f) {
            if ($f === $pk || $f === 'status') {
                continue;
            }
            $label = $this->guessFieldLabel($f, (string) ($fieldDetails[$f]['comment'] ?? ''));
            $isTime = str_ends_with($f, '_time') ? 'true' : 'false';
            $displayMeta[] = "  { key: '{$f}', label: '{$label}', isTime: {$isTime} },";
        }

        $hasSubmit = !empty($submitFields);
        $submitImport = $hasSubmit
            ? "get{$studlyName}List, submit{$studlyName}, type {$studlyName}Item"
            : "get{$studlyName}List, type {$studlyName}Item";

        $formDefaultsStr = implode("\n", $formDefaults);
        $formFieldsStr = implode("\n", $formFieldsHtml);
        $requiredChecksStr = implode("\n", $requiredChecks);
        $displayMetaStr = implode("\n", $displayMeta);

        $submitScript = $hasSubmit ? <<<TS

const submitting = ref(false)
const formData = reactive<Record<string, string>>({
{$formDefaultsStr}
})

function buildSubmitPayload() {
  const payload: Record<string, unknown> = {}
  for (const [k, v] of Object.entries(formData)) {
    payload[k] = typeof v === 'string' ? v.trim() : v
  }
  return payload
}

async function handleSubmit() {
  if (submitting.value) return
{$requiredChecksStr}
  submitting.value = true
  try {
    await submit{$studlyName}(buildSubmitPayload())
    toast.success('提交成功')
    for (const k of Object.keys(formData)) {
      formData[k] = ''
    }
    await loadList()
  } catch (error) {
    toast.error(error instanceof Error ? error.message : '提交失败')
  } finally {
    submitting.value = false
  }
}
TS : '';

        $submitTemplate = $hasSubmit ? <<<HTML

    <view class="card stack">
      <view class="section-title" style="margin-bottom: 0;">在线提交</view>
{$formFieldsStr}
      <wd-button block :loading="submitting" @click="handleSubmit">提交{$title}</wd-button>
    </view>
HTML : '';

        return <<<VUE
<script setup lang="ts">
definePage({ layout: 'default', style: { navigationBarTitleText: '{$safeTitle}' } })
import { onMounted, reactive, ref } from 'vue'
import { useToast } from '@wot-ui/ui'
import { {$submitImport} } from '@/api/{$slug}'

const toast = useToast()
const loading = ref(false)
const items = ref<{$studlyName}Item[]>([])
const displayCols = [
{$displayMetaStr}
] as const

function formatTimestamp(ts?: unknown): string {
  const n = Number(ts || 0)
  if (!n || n <= 0) return '-'
  const d = new Date(n > 1e12 ? n : n * 1000)
  return Number.isNaN(d.getTime()) ? '-' : d.toLocaleString('zh-CN', { hour12: false })
}

function formatCell(val: unknown, isTime: boolean): string {
  if (isTime) return formatTimestamp(val)
  if (val === null || val === undefined || val === '') return '-'
  return String(val)
}

async function loadList() {
  loading.value = true
  try {
    const res = await get{$studlyName}List({ page: 1, limit: 20 })
    items.value = Array.isArray(res?.list) ? res.list : []
  } catch (error) {
    toast.error(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}
{$submitScript}

/* @hw-custom-start: mobile-page-methods */
// 移动端页面自定义方法与交互逻辑写在此处，增量更新时自动保留
/* @hw-custom-end: mobile-page-methods */

onMounted(() => {
  loadList()
})
</script>

<template>
  <view class="page">
    <view class="eyebrow">HWADMIN / MOBILE</view>
    <view class="title">{$title}</view>
    <view class="subtitle">由 php think hw:crud --with-mobile 生成的移动端业务骨架</view>
{$submitTemplate}
    <view class="card stack">
      <view class="row">
        <view class="section-title" style="margin-bottom: 0;">最新公开记录</view>
        <wd-button plain size="small" :loading="loading" @click="loadList">刷新</wd-button>
      </view>
      <view v-if="items.length === 0 && !loading" class="hint">暂无公开记录</view>
      <view v-for="(item, idx) in items" :key="String(item.{$pk} ?? idx)" class="item-box">
        <view v-for="col in displayCols" :key="col.key" class="item-line">
          <text class="item-label">{{ col.label }}：</text>
          <text class="item-value">{{ formatCell(item[col.key], col.isTime) }}</text>
        </view>
      </view>
      <!-- @hw-custom-start: mobile-page-actions -->
      <!-- 移动端页面自定义区块写在此处 -->
      <!-- @hw-custom-end: mobile-page-actions -->
    </view>
  </view>
</template>

<style scoped>
.item-box {
  padding: 20rpx 24rpx;
  border-radius: 16rpx;
  background: #f6f8fa;
  border: 1rpx solid #e4e9ed;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}
.item-line {
  font-size: 26rpx;
  line-height: 1.6;
}
.item-label {
  color: #687587;
}
.item-value {
  color: #192b3e;
  word-break: break-all;
}
</style>
VUE;
    }
}
