<?php
declare(strict_types=1);

namespace app;

use core\exception\ApiException;
use core\http\Result;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类：所有接口异常统一转换为 { code, msg, data, time } JSON。
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
        ApiException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param \think\Request $request
     */
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }
        if ($e instanceof ApiException) {
            return Result::error($e->getMessage(), (int) $e->getCode(), $e->getData(), $e->getHttpStatus());
        }
        if ($e instanceof ValidateException) {
            $error = $e->getError();
            return Result::error(is_array($error) ? (string) reset($error) : (string) $error, Result::FAIL, is_array($error) ? $error : null);
        }
        if ($e instanceof ModelNotFoundException || $e instanceof DataNotFoundException) {
            return Result::error('记录不存在', Result::FAIL, null, 404);
        }
        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            return Result::error($status === 404 ? '接口不存在' : ($e->getMessage() ?: 'HTTP ' . $status), Result::FAIL, null, $status);
        }

        // 未预期异常：调试模式返回详情，生产模式隐藏细节
        $debug = $this->app->isDebug();
        return Result::error(
            $debug ? $e->getMessage() : '服务器内部错误',
            500,
            $debug ? ['exception' => get_class($e), 'file' => $e->getFile() . ':' . $e->getLine(), 'trace' => explode("\n", $e->getTraceAsString())] : null,
            500
        );
    }
}
