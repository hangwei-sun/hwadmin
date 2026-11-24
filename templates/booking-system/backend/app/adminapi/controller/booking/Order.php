<?php
declare(strict_types=1);

namespace app\adminapi\controller\booking;

use app\common\model\BookingOrder as TargetModel;
use app\common\model\BookingServiceItem;
use app\common\model\BookingSlot;
use app\common\service\BookingService;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;

/**
 * 预约订单、服务项目与时段排期管理
 */
class Order extends AdminController
{
    use CrudTrait;

    protected array $searchFields = ['order_no', 'contact_name', 'contact_mobile'];
    protected array $allowFields = ['status', 'remark'];
    protected string|array $defaultSort = ['id' => 'desc'];

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new TargetModel();
    }

    /**
     * 管理员流转预约状态
     */
    public function transition(): void
    {
        $this->ensurePost();
        $id = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 0);
        $service = new BookingService();
        $res = $service->transition($id, $status);
        $this->success($res, '状态流转成功');
    }

    /**
     * 新增或更新服务项目
     */
    public function saveService(): void
    {
        $this->ensurePost();
        $id = (int) $this->request->post('id', 0);
        $title = trim((string) $this->request->post('title', ''));
        if ($title === '') {
            throw new ApiException('服务项目名称不能为空');
        }
        $payload = [
            'title'    => $title,
            'cover'    => trim((string) $this->request->post('cover', '')),
            'duration' => (int) $this->request->post('duration', 60),
            'price'    => (string) $this->request->post('price', '0.00'),
            'status'   => (int) $this->request->post('status', 1),
        ];
        if ($id > 0) {
            $item = BookingServiceItem::where('id', $id)->find();
            if (!$item) {
                throw new ApiException('服务项目不存在');
            }
            $item->save($payload);
        } else {
            $item = new BookingServiceItem();
            $item->save($payload);
        }
        $this->success($item->toArray(), '服务项目保存成功');
    }

    /**
     * 新增或更新预约时段
     */
    public function saveSlot(): void
    {
        $this->ensurePost();
        $id = (int) $this->request->post('id', 0);
        $serviceId = (int) $this->request->post('service_id', 0);
        $date = trim((string) $this->request->post('date', ''));
        $startTime = trim((string) $this->request->post('start_time', ''));
        $endTime = trim((string) $this->request->post('end_time', ''));
        $capacity = max(1, (int) $this->request->post('capacity', 1));

        if ($serviceId <= 0 || $date === '' || $startTime === '' || $endTime === '') {
            throw new ApiException('请完整填写时段所属服务、日期与起止时间');
        }
        $payload = [
            'service_id' => $serviceId,
            'date'       => $date,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'capacity'   => $capacity,
            'status'     => (int) $this->request->post('status', 1),
        ];
        if ($id > 0) {
            $slot = BookingSlot::where('id', $id)->find();
            if (!$slot) {
                throw new ApiException('预约时段不存在');
            }
            $slot->save($payload);
        } else {
            $payload['booked_count'] = 0;
            $slot = new BookingSlot();
            $slot->save($payload);
        }
        $this->success($slot->toArray(), '预约时段保存成功');
    }
}
