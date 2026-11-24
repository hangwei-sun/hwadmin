<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\EnterpriseAttachment;
use app\common\model\EnterpriseProject;
use app\common\model\EnterpriseTask;
use core\exception\ApiException;
use think\facade\Db;

/**
 * 业务管理后台服务层（主从表事务级协同、附件安全校验、结构化导出与 created_by 数据归属隔离）
 */
class EnterpriseProjectService
{
    public const ALLOWED_ATTACHMENT_EXTS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'zip',
    ];

    public const MAX_ATTACHMENT_SIZE = 10485760; // 10MB

    /**
     * 主从表协同新增：创建项目并初始化子任务及可选附件（强制注入 created_by 数据归属）
     */
    public function createProjectWithTasks(
        array $projectData,
        array $tasks = [],
        int $operatorAdminId = 0,
        array $attachments = []
    ): array {
        $title = trim((string) ($projectData['title'] ?? ''));
        if ($title === '') {
            throw new ApiException('项目名称不能为空');
        }

        $ownerId = $operatorAdminId > 0
            ? $operatorAdminId
            : (int) ($projectData['created_by'] ?? ($projectData['admin_id'] ?? 0));

        // 预先校验所有附件参数合法性
        $normalizedAttachments = [];
        foreach ($attachments as $att) {
            if (is_array($att)) {
                $normalizedAttachments[] = $this->validateAttachmentPayload($att);
            }
        }

        return Db::transaction(function () use ($projectData, $tasks, $ownerId, $title, $normalizedAttachments) {
            $projectNo = 'PRJ' . date('Ymd') . random_int(1000, 9999);
            $project = new EnterpriseProject();
            $project->save([
                'project_no' => $projectNo,
                'title'      => $title,
                'admin_id'   => $ownerId,
                'created_by' => $ownerId,
                'priority'   => (int) ($projectData['priority'] ?? 2),
                'budget'     => self::normalizeBudget($projectData['budget'] ?? '0.00'),
                'status'     => (int) ($projectData['status'] ?? 1),
            ]);

            $savedTasks = [];
            foreach ($tasks as $t) {
                $taskTitle = trim((string) ($t['title'] ?? ''));
                if ($taskTitle === '') {
                    continue;
                }

                $task = new EnterpriseTask();
                $task->save([
                    'project_id' => (int) $project->id,
                    'title'      => $taskTitle,
                    'assignee'   => trim((string) ($t['assignee'] ?? '')),
                    'deadline'   => trim((string) ($t['deadline'] ?? '')),
                    'created_by' => $ownerId,
                    'status'     => (int) ($t['status'] ?? 0),
                ]);
                $savedTasks[] = $task->toArray();
            }

            $savedAttachments = [];
            foreach ($normalizedAttachments as $att) {
                $attachment = new EnterpriseAttachment();
                $attachment->save([
                    'target_type' => 'project',
                    'target_id'   => (int) $project->id,
                    'file_name'   => $att['file_name'],
                    'file_url'    => $att['file_url'],
                    'file_size'   => $att['file_size'],
                    'file_ext'    => $att['file_ext'],
                    'admin_id'    => $ownerId,
                    'created_by'  => $ownerId,
                ]);
                $savedAttachments[] = $attachment->toArray();
            }

            $res = $project->toArray();
            $res['tasks'] = $savedTasks;
            $res['attachments'] = $savedAttachments;
            return $res;
        });
    }

    /**
     * 分页查询项目列表（Service 层强制执行 created_by 归属隔离）
     */
    public function listProjects(
        array $filters = [],
        ?int $operatorAdminId = null,
        bool $isSuperAdmin = false,
        int $page = 1,
        int $limit = 20
    ): array {
        $query = EnterpriseProject::where('status', '>=', 0);
        if ($operatorAdminId !== null && !$isSuperAdmin) {
            $query->where('created_by', $operatorAdminId);
        }
        if (!empty($filters['priority'])) {
            $query->where('priority', (int) $filters['priority']);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }
        if (!empty($filters['title'])) {
            $query->whereLike('title', '%' . trim((string) $filters['title']) . '%');
        }

        $total = (clone $query)->count();
        $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();

        foreach ($list as &$row) {
            $row['task_count'] = EnterpriseTask::where('project_id', (int) $row['id'])->count();
        }

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 主从详情查询（含子任务与附件，Service 层强制校验 created_by 归属权限）
     */
    public function getProjectWithTasks(
        int $projectId,
        ?int $operatorAdminId = null,
        bool $isSuperAdmin = false
    ): array {
        $project = EnterpriseProject::where('id', $projectId)->find();
        if (!$project) {
            throw new ApiException('项目不存在');
        }

        $this->assertOwnership($project->toArray(), $operatorAdminId, $isSuperAdmin);

        $tasks = EnterpriseTask::where('project_id', $projectId)->order('id', 'asc')->select()->toArray();
        $attachments = EnterpriseAttachment::where('target_type', 'project')
            ->where('target_id', $projectId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $res = $project->toArray();
        $res['tasks'] = $tasks;
        $res['attachments'] = $attachments;
        return $res;
    }

    /**
     * 校验并绑定上传附件到项目或任务
     */
    public function addAttachment(
        array $data,
        ?int $operatorAdminId = null,
        bool $isSuperAdmin = false
    ): array {
        $validated = $this->validateAttachmentPayload($data);
        $targetType = (string) ($data['target_type'] ?? 'project');
        if (!in_array($targetType, ['project', 'task'], true)) {
            throw new ApiException('不支持的附件关联主体类型');
        }
        $targetId = (int) ($data['target_id'] ?? 0);
        if ($targetId <= 0) {
            throw new ApiException('请指定有效的附件关联目标 ID');
        }

        if ($targetType === 'project') {
            $project = EnterpriseProject::where('id', $targetId)->find();
            if (!$project) {
                throw new ApiException('关联项目不存在');
            }
            $this->assertOwnership($project->toArray(), $operatorAdminId, $isSuperAdmin);
        } else {
            $task = EnterpriseTask::where('id', $targetId)->find();
            if (!$task) {
                throw new ApiException('关联任务不存在');
            }
            $project = EnterpriseProject::where('id', (int) $task['project_id'])->find();
            if ($project) {
                $this->assertOwnership($project->toArray(), $operatorAdminId, $isSuperAdmin);
            }
        }

        $ownerId = $operatorAdminId ?? (int) ($data['created_by'] ?? ($data['admin_id'] ?? 0));
        $attachment = new EnterpriseAttachment();
        $attachment->save([
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'file_name'   => $validated['file_name'],
            'file_url'    => $validated['file_url'],
            'file_size'   => $validated['file_size'],
            'file_ext'    => $validated['file_ext'],
            'admin_id'    => $ownerId,
            'created_by'  => $ownerId,
        ]);

        return $attachment->toArray();
    }

    /**
     * 附件元数据与安全边界校验
     *
     * @return array{file_name: string, file_url: string, file_size: int, file_ext: string}
     */
    public function validateAttachmentPayload(array $data): array
    {
        $fileName = trim((string) ($data['file_name'] ?? ''));
        $fileUrl = trim((string) ($data['file_url'] ?? ''));
        $fileSize = (int) ($data['file_size'] ?? 0);

        if ($fileName === '' || $fileUrl === '') {
            throw new ApiException('附件文件名与存储路径不能为空');
        }
        if (str_contains($fileName, '..') || str_contains($fileUrl, '..')) {
            throw new ApiException('检测到非法的附件路径穿越字符');
        }

        $ext = strtolower(trim((string) ($data['file_ext'] ?? pathinfo($fileName, PATHINFO_EXTENSION))));
        if ($ext === '' || !in_array($ext, self::ALLOWED_ATTACHMENT_EXTS, true)) {
            throw new ApiException("不支持的附件类型 [{$ext}]，允许类型: " . implode(', ', self::ALLOWED_ATTACHMENT_EXTS));
        }

        // 双扩展名危险脚本检测（如 shell.php.jpg 或 payload.phar）
        if (preg_match('/\.(php\d*|phtml|phar|sh|exe|bat|cmd|js|jsp|asp|aspx|cgi|pl|py)\b/i', $fileName)) {
            throw new ApiException('安全拦截：禁止上传包含可执行脚本扩展名的文件');
        }

        if ($fileSize <= 0 || $fileSize > self::MAX_ATTACHMENT_SIZE) {
            throw new ApiException('附件大小不合法（必须大于 0 且不超过 10MB）');
        }

        return [
            'file_name' => $fileName,
            'file_url'  => $fileUrl,
            'file_size' => $fileSize,
            'file_ext'  => $ext,
        ];
    }

    /**
     * 导出项目结构化数据（遵循 created_by 数据归属隔离，同时输出结构化行与 CSV）
     */
    public function exportProjects(
        array $condition = [],
        ?int $operatorAdminId = null,
        bool $isSuperAdmin = false
    ): array {
        $query = EnterpriseProject::where('status', '>=', 0);
        if ($operatorAdminId !== null && !$isSuperAdmin) {
            $query->where('created_by', $operatorAdminId);
        }
        if (!empty($condition['priority'])) {
            $query->where('priority', (int) $condition['priority']);
        }
        $list = $query->order('id', 'desc')->select()->toArray();

        $rows = [];
        $csvLines = ['ID,项目编号,项目名称,创建人ID,优先级,预算(元),状态,创建时间'];
        foreach ($list as $item) {
            $statusText = (int) $item['status'] === 1 ? '进行中' : ((int) $item['status'] === 2 ? '已完成' : '已归档');
            $createdAt = !empty($item['create_time']) ? date('Y-m-d H:i:s', (int) $item['create_time']) : '-';
            $row = [
                'ID'       => $item['id'],
                '项目编号' => $item['project_no'],
                '项目名称' => $item['title'],
                '创建人ID' => (int) ($item['created_by'] ?? ($item['admin_id'] ?? 0)),
                '优先级'   => $item['priority'],
                '预算(元)' => $item['budget'],
                '状态'     => $statusText,
                '创建时间' => $createdAt,
            ];
            $rows[] = $row;
            $escapedTitle = '"' . str_replace('"', '""', (string) $item['title']) . '"';
            $csvLines[] = implode(',', [
                $item['id'],
                $item['project_no'],
                $escapedTitle,
                $row['创建人ID'],
                $item['priority'],
                $item['budget'],
                $statusText,
                $createdAt,
            ]);
        }

        return [
            'total' => count($rows),
            'items' => $rows,
            'csv'   => implode("\n", $csvLines),
        ];
    }

    /**
     * 校验并规范化 decimal(12,2) 预算金额
     */
    public static function normalizeBudget(mixed $rawBudget): string
    {
        if ($rawBudget === null || $rawBudget === '') {
            return '0.00';
        }
        $text = trim((string) $rawBudget);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $text)) {
            throw new ApiException('项目预算须为非负 decimal(12,2) 金额（最多 10 位整数与 2 位小数）');
        }
        $parts = explode('.', $text, 2);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        if (strlen($intPart) > 10) {
            throw new ApiException('项目预算超出 decimal(12,2) 上限');
        }
        $decPart = str_pad($parts[1] ?? '', 2, '0', STR_PAD_RIGHT);
        return $intPart . '.' . substr($decPart, 0, 2);
    }

    /**
     * 安全删除项目（强制 created_by 归属隔离并联动清理任务与附件）
     */
    public function deleteProject(int $projectId, int $operatorAdminId, bool $isSuperAdmin = false): void
    {
        $project = EnterpriseProject::find($projectId);
        if (!$project) {
            throw new ApiException('项目不存在');
        }
        $this->assertOwnership($project->toArray(), $operatorAdminId, $isSuperAdmin);

        Db::transaction(function () use ($projectId, $project) {
            EnterpriseTask::where('project_id', $projectId)->delete();
            EnterpriseAttachment::where('target_type', 'project')->where('target_id', $projectId)->delete();
            $project->delete();
        });
    }

    /**
     * 校验非超管用户对目标记录的 created_by 归属权
     */
    private function assertOwnership(array $record, ?int $operatorAdminId, bool $isSuperAdmin): void
    {
        if ($isSuperAdmin || $operatorAdminId === null) {
            return;
        }
        $ownerId = (int) ($record['created_by'] ?? 0);
        if ($ownerId === 0 && isset($record['admin_id'])) {
            $ownerId = (int) $record['admin_id'];
        }
        if ($ownerId !== $operatorAdminId) {
            throw new ApiException('数据权限隔离拦截：无权访问或操作他人创建的项目数据', 403);
        }
    }

    /* @hw-custom-start: enterprise-service-extensions */
    /* @hw-custom-end: enterprise-service-extensions */
}
