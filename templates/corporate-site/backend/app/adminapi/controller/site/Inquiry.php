<?php
declare(strict_types=1);

namespace app\adminapi\controller\site;

use app\common\model\SiteInquiry as TargetModel;
use app\common\service\SiteArticleService;
use core\base\AdminController;
use core\crud\CrudTrait;
use think\Model;

/**
 * 客户询盘管理
 */
class Inquiry extends AdminController
{
    use CrudTrait;

    protected array $searchFields = ['name', 'mobile', 'email', 'company', 'remark', 'content'];
    protected array $allowFields = ['status', 'remark'];
    protected string|array $defaultSort = ['id' => 'desc'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new TargetModel();
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        if (isset($data['status']) && !in_array((int) $data['status'], [0, 1, 2], true)) {
            $this->error('无效的询盘跟进状态');
        }
        if (isset($data['remark']) && mb_strlen((string) $data['remark']) > 255) {
            $this->error('跟进备注不能超过 255 个字符');
        }
        return $data;
    }

    /**
     * 跟进备注与状态流转
     */
    public function followUp(): void
    {
        $this->ensurePost();
        $id = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 1);
        $remark = (string) $this->request->post('remark', '');
        $service = new SiteArticleService();
        $this->success($service->followUpInquiry($id, $status, $remark), '跟进记录已更新');
    }

    /* @hw-custom-start: site-inquiry-controller-extensions */
    /* @hw-custom-end: site-inquiry-controller-extensions */
}
