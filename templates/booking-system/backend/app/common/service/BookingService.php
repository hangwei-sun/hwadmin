<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\BookingOrder;
use app\common\model\BookingServiceItem;
use app\common\model\BookingSlot;
use core\exception\ApiException;
use think\facade\Db;

/**
 * 预约核心服务层（时段排期、并发防冲突锁、状态机流转与会员数据隔离）
 */
class BookingService
{
    public const STATUS_PENDING   = 0; // 待确认
    public const STATUS_CONFIRMED = 1; // 已确认
    public const STATUS_COMPLETED = 2; // 已完成
    public const STATUS_CANCELLED = 3; // 已取消

    /**
     * 查询指定服务与日期的可用时段（若 date 为空则查询该服务全部可用时段）
     */
    public function getAvailableSlots(int $serviceId, string $date = ''): array
    {
        $query = BookingSlot::where('status', 1);
        if ($serviceId > 0) {
            $query->where('service_id', $serviceId);
        }
        if ($date !== '') {
            $query->where('date', $date);
        }
        $list = $query->order('date', 'asc')
            ->order('start_time', 'asc')
            ->select()
            ->toArray();

        foreach ($list as &$slot) {
            $slot['remaining_capacity'] = max(0, (int) ($slot['capacity'] ?? 0) - (int) ($slot['booked_count'] ?? 0));
        }

        return $list;
    }

    /**
     * 会员创建预约（数据库事务防并发超卖与重复预约校验）
     */
    public function createBooking(
        int $userId,
        int $serviceId,
        int $slotId,
        string $contactName,
        string $contactMobile,
        string $remark = ''
    ): array {
        $contactName = trim($contactName);
        $contactMobile = trim($contactMobile);
        $remark = trim($remark);
        if (mb_strlen($remark, 'UTF-8') > 255) {
            throw new ApiException('预约备注长度不能超过255个字符');
        }
        if ($userId <= 0) {
            throw new ApiException('请先登录会员账号后再提交预约', 401);
        }
        if ($serviceId <= 0 || $slotId <= 0) {
            throw new ApiException('请选择预约服务项目与预约时段');
        }
        if ($contactName === '' || $contactMobile === '') {
            throw new ApiException('请填写联系人姓名与手机号码');
        }

        return Db::transaction(function () use ($userId, $serviceId, $slotId, $contactName, $contactMobile, $remark) {
            $service = BookingServiceItem::where('id', $serviceId)->where('status', 1)->find();
            if (!$service) {
                throw new ApiException('预约服务项目不存在或已下架');
            }

            // 加锁锁定时段记录
            $slot = BookingSlot::where('id', $slotId)->lock(true)->find();
            if (!$slot) {
                throw new ApiException('预约时段无效或已关闭');
            }

            if ((int) $slot['service_id'] !== $serviceId) {
                throw new ApiException('所选预约时段与服务项目不匹配');
            }

            if ((int) $slot['booked_count'] >= (int) $slot['capacity']) {
                throw new ApiException('该时段预约名额已满，请选择其他时段');
            }

            if ((int) $slot['status'] !== 1) {
                throw new ApiException('预约时段无效或已关闭');
            }

            // 防同一会员在同一时段重复提交有效预约
            $duplicate = BookingOrder::where('user_id', $userId)
                ->where('slot_id', $slotId)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_CONFIRMED])
                ->find();
            if ($duplicate) {
                throw new ApiException('您已预约过该时段，请勿重复提交');
            }

            // 递增已预约人数
            $newBookedCount = (int) $slot['booked_count'] + 1;
            $newSlotStatus = $newBookedCount >= (int) $slot['capacity'] ? 0 : 1;
            $slot->save([
                'booked_count' => $newBookedCount,
                'status'       => $newSlotStatus,
            ]);

            $orderNo = 'BK' . date('YmdHis') . random_int(1000, 9999);
            $order = new BookingOrder();
            $order->save([
                'order_no'       => $orderNo,
                'user_id'        => $userId,
                'service_id'     => $serviceId,
                'slot_id'        => $slotId,
                'booking_date'   => (string) $slot['date'],
                'contact_name'   => $contactName,
                'contact_mobile' => $contactMobile,
                'status'         => self::STATUS_PENDING,
                'remark'         => trim($remark),
            ]);

            return $order->toArray();
        });
    }

    /**
     * 状态机流转控制（支持会员本人取消与管理员流转，取消时自动归还时段名额）
     */
    public function transition(int $orderId, int $targetStatus, ?int $operatorUserId = null): array
    {
        return Db::transaction(function () use ($orderId, $targetStatus, $operatorUserId) {
            $order = BookingOrder::where('id', $orderId)->lock(true)->find();
            if (!$order) {
                throw new ApiException('预约订单不存在');
            }

            // 会员本人操作跨账号隔离校验
            if ($operatorUserId !== null && (int) $order['user_id'] !== $operatorUserId) {
                throw new ApiException('无权操作他人的预约订单', 403);
            }

            $currentStatus = (int) $order['status'];
            $allowed = [
                self::STATUS_PENDING   => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
                self::STATUS_CONFIRMED => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
                self::STATUS_COMPLETED => [],
                self::STATUS_CANCELLED => [],
            ];

            if (!in_array($targetStatus, $allowed[$currentStatus] ?? [], true)) {
                throw new ApiException("当前状态无法流转至目标状态 (从 {$currentStatus} 到 {$targetStatus})");
            }

            // 如果是取消预约，释放时段配额并恢复可约状态
            if ($targetStatus === self::STATUS_CANCELLED) {
                $slot = BookingSlot::where('id', $order['slot_id'])->lock(true)->find();
                if ($slot && (int) $slot['booked_count'] > 0) {
                    $newBooked = max(0, (int) $slot['booked_count'] - 1);
                    $slot->save([
                        'booked_count' => $newBooked,
                        'status'       => $newBooked < (int) $slot['capacity'] ? 1 : 0,
                    ]);
                }
            }

            $order->save(['status' => $targetStatus]);
            return $order->toArray();
        });
    }

    /**
     * 查询上架预约服务项目（支持按服务分类过滤）
     */
    public function getServices(string $category = ''): array
    {
        $query = BookingServiceItem::where('status', 1);
        $category = trim($category);
        if ($category !== '') {
            $fields = Db::getFields((new BookingServiceItem())->getTable());
            if (is_array($fields) && array_key_exists('category', $fields)) {
                $query->where('category', $category);
            }
        }
        return $query->order('id', 'desc')->select()->toArray();
    }

    /**
     * 查询会员本人的预约列表（强隔离）
     */
    public function getMemberOrders(int $userId, int $page = 1, int $limit = 10): array
    {
        $query = BookingOrder::where('user_id', $userId);
        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return ['list' => $list, 'total' => $total];
    }

    /* @hw-custom-start: booking-service-extensions */
    /* @hw-custom-end: booking-service-extensions */
}
