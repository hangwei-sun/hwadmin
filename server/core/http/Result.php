<?php
declare(strict_types=1);

namespace core\http;

use think\Response;

/**
 * 统一响应结构（见 docs/接口契约规范.md）
 *
 * {
 *   "code": 1,        // 1=成功，0=业务失败，401=未登录，403=无权限，其他=自定义业务码
 *   "msg":  "",       // 提示信息
 *   "data": {},       // 业务数据；列表固定为 { "list": [], "total": 0 }
 *   "time": 1700000000
 * }
 */
final class Result
{
    public const SUCCESS = 1;
    public const FAIL = 0;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;

    public static function success(mixed $data = null, string $msg = 'ok', array $header = []): Response
    {
        return self::make(self::SUCCESS, $msg, $data, 200, $header);
    }

    public static function error(string $msg = '操作失败', int $code = self::FAIL, mixed $data = null, int $httpStatus = 200, array $header = []): Response
    {
        return self::make($code, $msg, $data, $httpStatus, $header);
    }

    /**
     * 列表响应
     * @param iterable|array $list
     */
    public static function page(iterable $list, int $total, array $extra = []): Response
    {
        $rows = is_array($list) ? $list : (method_exists($list, 'toArray') ? $list->toArray() : iterator_to_array($list));
        return self::success(array_merge(['list' => $rows, 'total' => $total], $extra));
    }

    public static function make(int $code, string $msg, mixed $data, int $httpStatus = 200, array $header = []): Response
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
            'time' => time(),
        ], $httpStatus, $header);
    }
}
