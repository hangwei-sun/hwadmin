<?php
declare(strict_types=1);

namespace plugins\crud\controller\adminapi;

use core\base\AdminController;
use core\http\Result;
use plugins\crud\service\CrudGeneratorService;
use think\Response;

/**
 * HwAdmin 官方可视化全栈 CRUD 代码生成器接口控制器
 */
class Generator extends AdminController
{
    /**
     * 仅允许超级管理员且受 RBAC 权限校验保护
     */
    protected array $noNeedRight = [];

    protected CrudGeneratorService $service;

    protected function initialize(): void
    {
        parent::initialize();
        if (!$this->auth->isSuperAdmin()) {
            $this->error('仅超级管理员可使用代码生成器', Result::FORBIDDEN, null, 403);
        }
        $this->service = $this->app->make(CrudGeneratorService::class);
    }

    /**
     * 获取数据库所有数据表列表
     */
    public function tables(): Response
    {
        $tables = $this->service->getTables();
        return $this->success($tables, '获取数据表列表成功');
    }

    /**
     * 读取指定数据表的字段信息与智能推导结果
     */
    public function columns(): Response
    {
        $table = (string) $this->request->param('table', '');
        if ($table === '') {
            return $this->error('请指定数据表名');
        }
        $columns = $this->service->getTableColumns($table);
        return $this->success($columns, '解析表字段信息成功');
    }

    /**
     * 在线可视化零 SQL 建表
     */
    public function createTable(): Response
    {
        $params = $this->request->post();
        $res = $this->service->createTable($params);
        return $this->success($res, $res['message'] ?? '建表成功');
    }

    /**
     * 实时全栈代码预览
     */
    public function preview(): Response
    {
        $config = $this->request->post();
        $res = $this->service->preview($config);
        return $this->success($res, '代码生成预览成功');
    }

    /**
     * 执行全栈代码生成与落盘
     */
    public function generate(): Response
    {
        $config = $this->request->post();
        $res = $this->service->generate($config);
        return $this->success($res, $res['message'] ?? '全栈 CRUD 代码生成并落盘成功');
    }

    /**
     * 查询生成历史记录
     */
    public function history(): Response
    {
        $list = $this->service->getHistory();
        return $this->success($list, '获取生成历史成功');
    }

    /**
     * 一键撤回已生成的代码与权限节点
     */
    public function rollback(): Response
    {
        $historyId = (string) $this->request->param('history_id', '');
        if ($historyId === '') {
            return $this->error('请指定历史记录编号');
        }
        $res = $this->service->rollback($historyId);
        return $this->success($res, $res['message'] ?? '撤回成功');
    }
}
