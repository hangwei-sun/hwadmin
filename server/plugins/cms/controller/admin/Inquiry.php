<?php
declare(strict_types=1);

namespace plugins\cms\controller\admin;

use core\base\AdminController;
use plugins\cms\model\Inquiry as InquiryModel;
use think\facade\Db;

/**
 * CMS 留言与产品询单管理后台控制器
 */
class Inquiry extends AdminController
{
    /**
     * 分页查询留言与询单列表
     * GET /adminapi/plugin/cms/inquiry/index?scene=corp_contact&status=0&keyword=张&page=1&limit=10
     */
    public function index(): void
    {
        $scene = trim((string) $this->request->param('scene', ''));
        $status = $this->request->param('status', '');
        $keyword = trim((string) $this->request->param('keyword', ''));
        $isMember = $this->request->param('is_member', '');
        $page = max(1, (int) $this->request->param('page', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit', 15)));

        $query = InquiryModel::order('id', 'desc');

        if ($scene !== '') {
            $query->where('scene', $scene);
        }

        if ($status !== '' && $status !== null) {
            $query->where('status', (int) $status);
        }

        if ($isMember === '1' || $isMember === 1) {
            $query->where('user_id', '>', 0);
        } elseif ($isMember === '0' || $isMember === 0) {
            $query->where('user_id', 0);
        }

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', "%{$keyword}%")
                    ->whereOr('contact', 'like', "%{$keyword}%")
                    ->whereOr('company', 'like', "%{$keyword}%")
                    ->whereOr('content', 'like', "%{$keyword}%")
                    ->whereOr('user_nickname', 'like', "%{$keyword}%")
                    ->whereOr('target_title', 'like', "%{$keyword}%");
            });
        }

        $total = (clone $query)->count();
        $list = $query->page($page, $limit)->select()->toArray();

        // 批量关联查询会员账号信息
        $userIds = array_values(array_filter(array_unique(array_column($list, 'user_id'))));
        $userMap = [];
        if (!empty($userIds)) {
            $users = Db::name('user')
                ->whereIn('id', $userIds)
                ->field('id,username,nickname,avatar,level,score,mobile,email')
                ->select()
                ->toArray();
            foreach ($users as $u) {
                $userMap[$u['id']] = $u;
            }
        }

        foreach ($list as &$item) {
            $uid = (int) ($item['user_id'] ?? 0);
            $item['user_info'] = $userMap[$uid] ?? null;
        }
        unset($item);

        $this->page($list, $total);
    }

    /**
     * 回复并标记处理
     * POST /adminapi/plugin/cms/inquiry/reply
     */
    public function reply(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方法错误', 0, null, 405);
        }

        $id = (int) $this->request->param('id', 0);
        $replyContent = trim((string) $this->request->param('reply_content', ''));
        $status = (int) $this->request->param('status', 1);

        if ($id <= 0) {
            $this->error('参数错误');
        }

        $inquiry = InquiryModel::find($id);
        if (!$inquiry) {
            $this->error('留言或询单记录不存在');
        }

        $inquiry->save([
            'reply_content' => $replyContent,
            'status'        => $status,
            'update_time'   => time(),
        ]);

        $this->success(null, '回复与状态更新成功');
    }

    /**
     * 删除留言/询单
     * DELETE /adminapi/plugin/cms/inquiry/del
     */
    public function del(): void
    {
        $ids = $this->request->param('ids', $this->request->param('id'));
        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        } elseif (is_numeric($ids)) {
            $ids = [(int) $ids];
        }

        if (empty($ids) || !is_array($ids)) {
            $this->error('请选择要删除的记录');
        }

        InquiryModel::whereIn('id', $ids)->delete();
        $this->success(null, '删除成功');
    }
}
