<?php
declare(strict_types=1);

namespace core\exception;

use RuntimeException;
use Throwable;

/**
 * 业务异常：在任意层抛出，由全局异常处理器转换为统一 JSON 响应。
 *
 * 用法：throw new ApiException('用户名已存在');
 *      throw new ApiException('请先登录', 401, null, 401);
 */
class ApiException extends RuntimeException
{
    /**
     * @param string $message    提示信息
     * @param int    $code       业务码（0=失败，401=未登录，403=无权限，其他自定义）
     * @param mixed  $data       附加数据
     * @param int    $httpStatus HTTP 状态码
     */
    public function __construct(
        string $message = '操作失败',
        int $code = 0,
        protected mixed $data = null,
        protected int $httpStatus = 200,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
