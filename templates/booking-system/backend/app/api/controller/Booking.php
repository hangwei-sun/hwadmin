<?php
declare(strict_types=1);

namespace app\api\controller;

use app\common\model\BookingServiceItem;
use app\common\service\BookingService;
use core\base\ApiController;

/**
 * 移动端/小程序预约接口（会员体系）
 */
class Booking extends ApiController
{
    protected array $noNeedLogin = ['services', 'slots'];

    public function services(): void
    {
        $category = trim((string) $this->request->get('category', ''));
        $list = (new BookingService())->getServices($category);
        $this->success($list);
    }

    public function slots(): void
    {
        $serviceId = (int) $this->request->get('service_id', 0);
        $date = trim((string) $this->request->get('date', ''));
        $service = new BookingService();
        $this->success($service->getAvailableSlots($serviceId, $date));
    }

    public function create(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $userId = (int) $this->auth->id();
        $serviceId = (int) $this->request->post('service_id', 0);
        $slotId = (int) $this->request->post('slot_id', 0);
        $name = (string) $this->request->post('contact_name', '');
        $mobile = (string) $this->request->post('contact_mobile', '');
        $remark = (string) $this->request->post('remark', '');

        $service = new BookingService();
        $order = $service->createBooking($userId, $serviceId, $slotId, $name, $mobile, $remark);
        $this->success($order, '预约创建成功');
    }

    public function cancel(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误', 0, null, 405);
        }
        $userId = (int) $this->auth->id();
        $orderId = (int) $this->request->post('id', 0);

        $service = new BookingService();
        $res = $service->transition($orderId, BookingService::STATUS_CANCELLED, $userId);
        $this->success($res, '预约已取消');
    }

    public function myOrders(): void
    {
        $userId = (int) $this->auth->id();
        $page = (int) $this->request->get('page', 1);
        $limit = (int) $this->request->get('limit', 10);

        $service = new BookingService();
        $this->success($service->getMemberOrders($userId, $page, $limit));
    }

    /* @hw-custom-start: booking-api-extensions */
    /* @hw-custom-end: booking-api-extensions */
}
