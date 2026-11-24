<?php
declare(strict_types=1);

use core\plugin\MenuSync;
use think\migration\Migrator;

class CreateBookingSystemTables extends Migrator
{
    public function up(): void
    {
        // 预约服务项目表
        if (!$this->hasTable('booking_service')) {
            $this->table('booking_service')
                ->addColumn('title', 'string', ['limit' => 128, 'comment' => '服务项目名称'])
                ->addColumn('cover', 'string', ['limit' => 255, 'default' => '', 'comment' => '服务封面图'])
                ->addColumn('duration', 'integer', ['default' => 60, 'comment' => '服务时长(分钟)'])
                ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0.00', 'comment' => '服务价格'])
                ->addColumn('status', 'integer', ['default' => 1, 'comment' => '状态(1上架,0下架)'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '创建时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->create();
        }

        // 预约时段规划表
        if (!$this->hasTable('booking_slot')) {
            $this->table('booking_slot')
                ->addColumn('service_id', 'integer', ['comment' => '关联服务项目ID'])
                ->addColumn('date', 'string', ['limit' => 16, 'comment' => '预约日期(YYYY-MM-DD)'])
                ->addColumn('start_time', 'string', ['limit' => 10, 'comment' => '开始时间(HH:mm)'])
                ->addColumn('end_time', 'string', ['limit' => 10, 'comment' => '结束时间(HH:mm)'])
                ->addColumn('capacity', 'integer', ['default' => 1, 'comment' => '时段总配额容积'])
                ->addColumn('booked_count', 'integer', ['default' => 0, 'comment' => '已预约人数'])
                ->addColumn('status', 'integer', ['default' => 1, 'comment' => '时段状态(1可约,0已满或关闭)'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '创建时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->addIndex(['service_id', 'date'], ['unique' => false])
                ->create();
        }

        // 预约订单表
        if (!$this->hasTable('booking_order')) {
            $this->table('booking_order')
                ->addColumn('order_no', 'string', ['limit' => 32, 'comment' => '预约流水号'])
                ->addColumn('user_id', 'integer', ['comment' => '所属会员用户ID'])
                ->addColumn('service_id', 'integer', ['comment' => '服务项目ID'])
                ->addColumn('slot_id', 'integer', ['comment' => '时段ID'])
                ->addColumn('booking_date', 'string', ['limit' => 16, 'comment' => '预约日期'])
                ->addColumn('contact_name', 'string', ['limit' => 64, 'comment' => '联系人姓名'])
                ->addColumn('contact_mobile', 'string', ['limit' => 32, 'comment' => '联系人电话'])
                ->addColumn('status', 'integer', ['default' => 0, 'comment' => '订单状态(0待处理,1已确认,2已完成,3已取消)'])
                ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => '用户备注'])
                ->addColumn('create_time', 'biginteger', ['default' => 0, 'comment' => '下单时间'])
                ->addColumn('update_time', 'biginteger', ['default' => 0, 'comment' => '更新时间'])
                ->addIndex(['order_no'], ['unique' => true])
                ->addIndex(['user_id'], ['unique' => false])
                ->create();
        }

        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), static function (): void {
                MenuSync::ensureNode([
                    'type'     => 'dir',
                    'name'     => 'booking',
                    'title'    => '预约系统管理',
                    'path'     => 'booking',
                    'icon'     => 'calendar-check-line',
                    'weight'   => 570,
                    'children' => [
                        [
                            'type'      => 'menu',
                            'name'      => 'booking/order',
                            'title'     => '预约订单管理',
                            'path'      => 'order',
                            'component' => '@/views/booking/order/index.vue',
                            'icon'      => 'calendar-todo-line',
                            'weight'    => 100,
                            'children'  => [
                                ['type' => 'button', 'name' => 'booking/order/index', 'title' => '查看'],
                                ['type' => 'button', 'name' => 'booking/order/read', 'title' => '详情'],
                                ['type' => 'button', 'name' => 'booking/order/edit', 'title' => '编辑'],
                                ['type' => 'button', 'name' => 'booking/order/del', 'title' => '删除'],
                                ['type' => 'button', 'name' => 'booking/order/transition', 'title' => '状态流转'],
                                ['type' => 'button', 'name' => 'booking/order/saveService', 'title' => '保存服务'],
                                ['type' => 'button', 'name' => 'booking/order/saveSlot', 'title' => '保存时段'],
                            ],
                        ],
                    ],
                ]);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasTable('auth_rule')) {
            MenuSync::withAdapter($this->getAdapter(), static function (): void {
                MenuSync::removeNodes([
                    'booking/order/index', 'booking/order/read', 'booking/order/edit',
                    'booking/order/del', 'booking/order/transition', 'booking/order/saveService',
                    'booking/order/saveSlot', 'booking/order', 'booking',
                ]);
            });
        }

        if ($this->hasTable('booking_order')) {
            $this->table('booking_order')->drop()->save();
        }
        if ($this->hasTable('booking_slot')) {
            $this->table('booking_slot')->drop()->save();
        }
        if ($this->hasTable('booking_service')) {
            $this->table('booking_service')->drop()->save();
        }
    }
}
