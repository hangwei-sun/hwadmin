<?php
declare(strict_types=1);

namespace core\crud;

use think\db\BaseQuery;
use think\Request;

/**
 * 列表查询参数解析（统一列表协议，见 docs/接口契约规范.md#列表查询协议）
 *
 * GET 参数：
 *   page / pageNo        页码，默认 1
 *   limit / pageSize     每页条数，默认 20，最大 500
 *   sort, order          排序字段与方向（asc|desc），字段必须存在于可筛选白名单中
 *   keyword              快速搜索，作用于控制器 $searchFields（LIKE）
 *   filter               JSON 对象 {"status":"1","create_time":"1700000000,1800000000"}
 *   op                   JSON 对象 {"status":"=","create_time":"between"}，缺省为 "="
 *
 * 安全基线（2-P0-2 防盲注与类型污染）：
 *   1. 自动剔除全局敏感字段（password, salt, token, secret, *_key 等），严禁通过 filter/sort 对隐藏或敏感列做逐字符盲注枚举；
 *   2. 严格校验条件值类型：标量操作符（=, <>, like 等）仅接受 string|int|float|bool 标量，拒绝非预期数组注入；
 *   3. 集合操作符（in, not_in）强制过滤为标量数组并限制最大元素数（≤ 500）。
 */
final class QueryParams
{
    public const MAX_LIMIT = 500;
    public const MAX_IN_ITEMS = 500;

    protected const OPS = ['=', '<>', '!=', '>', '>=', '<', '<=', 'like', 'not_like', 'in', 'not_in', 'between', 'not_between', 'null', 'not_null', 'find_in_set'];

    /** 永远禁止用于 filter 或 sort 的敏感字段名正则（2-P0-2） */
    private const FORBIDDEN_FIELD_PATTERN = '/(password|pass|pwd|salt|token|secret|private_key|api_key|access_key|auth_key|remember_token)/i';

    public int $page = 1;
    public int $limit = 20;
    public string $sort = '';
    public string $order = 'desc';
    public string $keyword = '';
    public array $filter = [];
    public array $op = [];

    public static function fromRequest(Request $request): self
    {
        $p = new self();
        $p->page = max(1, (int) ($request->param('page') ?? $request->param('pageNo') ?? 1));
        $p->limit = min(self::MAX_LIMIT, max(1, (int) ($request->param('limit') ?? $request->param('pageSize') ?? 20)));
        $p->sort = (string) $request->param('sort', '');
        $p->order = strtolower((string) $request->param('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $p->keyword = mb_substr(trim((string) ($request->param('keyword') ?? $request->param('search') ?? '')), 0, 100);
        $p->filter = self::decode($request->param('filter', []));
        $p->op = self::decode($request->param('op', []));

        // 兼容扁平查询参数（如 ?status=1）：排除保留分页/路由参数后合并入 filter
        $reserved = ['page', 'pageNo', 'limit', 'pageSize', 'sort', 'order', 'keyword', 'search', 'filter', 'op', 's', 'tree', 'flat', 'keyValue', 'labelField', 'cursor', 'with_total'];
        foreach ($request->get() as $k => $v) {
            if (is_string($k) && !in_array($k, $reserved, true) && !isset($p->filter[$k]) && is_scalar($v) && (string) $v !== '') {
                $p->filter[$k] = $v;
            }
        }
        return $p;
    }

    /**
     * 将参数应用到查询
     * @param string[]      $fields       允许筛选/排序的字段白名单
     * @param string[]      $searchFields 关键词搜索字段
     * @param string|array  $defaultSort  默认排序（字符串字段名或 ['weight' => 'desc', 'id' => 'asc']）
     */
    public function apply(BaseQuery $query, array $fields, array $searchFields = [], string|array $defaultSort = 'id', array $sortableFields = []): BaseQuery
    {
        // 强制剔除全局敏感列，双重防御盲注枚举
        $safeFields = array_values(array_filter(
            $fields,
            static fn ($f) => is_string($f) && !preg_match(self::FORBIDDEN_FIELD_PATTERN, $f)
        ));
        $fieldSet = array_flip($safeFields);
        $allowedSort = $sortableFields ? array_values(array_filter(
            $sortableFields,
            static fn ($f) => is_string($f) && !preg_match(self::FORBIDDEN_FIELD_PATTERN, $f)
        )) : $safeFields;
        $sortSet = array_flip($allowedSort);

        if ($this->keyword !== '' && $searchFields) {
            $valid = array_values(array_filter($searchFields, static fn ($f) => isset($fieldSet[$f])));
            if ($valid) {
                $query->whereLike(implode('|', $valid), '%' . addcslashes($this->keyword, '%_\\') . '%');
            }
        }

        foreach ($this->filter as $field => $value) {
            if (!is_string($field) || !isset($fieldSet[$field])) {
                continue;
            }
            $op = strtolower((string) ($this->op[$field] ?? '='));
            if (!in_array($op, self::OPS, true)) {
                continue;
            }
            $this->applyCondition($query, $field, $op, $value);
        }

        if ($this->sort !== '' && isset($sortSet[$this->sort])) {
            $query->order($this->sort, $this->order);
        } elseif (is_array($defaultSort)) {
            foreach ($defaultSort as $sortField => $sortDir) {
                if (is_int($sortField) && is_string($sortDir) && isset($sortSet[$sortDir])) {
                    $query->order($sortDir, $this->order);
                } elseif (is_string($sortField) && isset($sortSet[$sortField])) {
                    $query->order($sortField, strtolower((string) $sortDir) === 'asc' ? 'asc' : 'desc');
                }
            }
        } elseif (is_string($defaultSort) && $defaultSort !== '' && isset($sortSet[$defaultSort])) {
            $query->order($defaultSort, $this->order);
        }

        return $query;
    }

    protected function applyCondition(BaseQuery $query, string $field, string $op, mixed $value): void
    {
        // null / not_null 无需值
        if ($op === 'null') {
            $query->whereNull($field);
            return;
        }
        if ($op === 'not_null') {
            $query->whereNotNull($field);
            return;
        }

        // in / not_in / between / not_between 支持数组或逗号分隔字符串
        if (in_array($op, ['in', 'not_in', 'between', 'not_between'], true)) {
            $rawList = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');
            $list = [];
            foreach ($rawList as $item) {
                if (is_scalar($item) && trim((string) $item) !== '') {
                    $list[] = trim((string) $item);
                }
                if (count($list) >= self::MAX_IN_ITEMS) {
                    break;
                }
            }
            if (!$list) {
                return;
            }
            if ($op === 'in') {
                $query->whereIn($field, $list);
            } elseif ($op === 'not_in') {
                $query->whereNotIn($field, $list);
            } else {
                [$from, $to] = array_pad($list, 2, '');
                if ($from !== '' && $to !== '') {
                    $op === 'between' ? $query->whereBetween($field, [$from, $to]) : $query->whereNotBetween($field, [$from, $to]);
                } elseif ($from !== '') {
                    $query->where($field, $op === 'between' ? '>=' : '<', $from);
                } elseif ($to !== '') {
                    $query->where($field, $op === 'between' ? '<=' : '>', $to);
                }
            }
            return;
        }

        // 其余标量操作符（=, <>, >, >=, <, <=, like, not_like, find_in_set）严禁传入数组或对象
        if (!is_scalar($value)) {
            return;
        }
        $scalarVal = (string) $value;
        if ($scalarVal === '') {
            return;
        }

        switch ($op) {
            case 'like':
                $query->whereLike($field, '%' . addcslashes(mb_substr($scalarVal, 0, 100), '%_\\') . '%');
                break;
            case 'not_like':
                $query->whereNotLike($field, '%' . addcslashes(mb_substr($scalarVal, 0, 100), '%_\\') . '%');
                break;
            case 'find_in_set':
                $query->whereFindInSet($field, $scalarVal);
                break;
            default:
                $query->where($field, $op === '!=' ? '<>' : $op, $scalarVal);
        }
    }

    protected static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode(htmlspecialchars_decode($value), true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
