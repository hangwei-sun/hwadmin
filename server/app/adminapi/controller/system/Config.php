<?php
declare(strict_types=1);

namespace app\adminapi\controller\system;

use app\common\model\Config as ConfigModel;
use core\base\AdminController;
use core\crud\CrudTrait;
use core\exception\ApiException;
use think\facade\Db;
use think\Model;

/**
 * 系统配置管理
 *
 * 节点：system/config/{index|read|add|edit|del}
 */
class Config extends AdminController
{
    use CrudTrait {
        index as protected crudIndex;
        edit as protected crudEdit;
        buildIndexQuery as protected crudBuildIndexQuery;
    }

    /** 严禁通过系统配置列表明文返回的敏感凭据键 */
    private const SENSITIVE_CONFIG_NAMES = ['market_auth_info'];
    private const SENSITIVE_NAME_PATTERN = '/(?:^|_)(?:token|secret|password|private_key|app_secret|api_key|access_key|auth_key|credential|auth_info)(?:$|_)/i';

    protected array $searchFields = ['name', 'title', 'group'];
    protected array $filterFields = ['id', 'group', 'name', 'title', 'type', 'create_time'];
    protected array $sortableFields = ['id', 'weight', 'create_time'];
    protected array $allowFields = ['group', 'name', 'title', 'type', 'value', 'options', 'tip', 'rule', 'weight'];
    protected string|array $defaultSort = 'weight';

    protected function initialize(): void
    {
        parent::initialize();
        $this->model = new ConfigModel();
    }

    protected function buildIndexQuery()
    {
        return $this->crudBuildIndexQuery()->whereNotIn('name', self::SENSITIVE_CONFIG_NAMES);
    }

    public function index(): void
    {
        // 仅在配置表数据不齐全时执行一次兜底初始化补齐
        static $checked = false;
        if (!$checked) {
            $checked = true;
            $count = Db::name('config')->count();
            if ($count < 15) {
                $now = time();
                $seedFile = $this->app->getRootPath() . 'core/install/data/configs.php';
                if (is_file($seedFile)) {
                    $dataConfigs = require $seedFile;
                    if (is_array($dataConfigs)) {
                        foreach ($dataConfigs as $cfg) {
                            if (!Db::name('config')->where('name', $cfg['name'])->find()) {
                                Db::name('config')->insert($cfg + ['create_time' => $now, 'update_time' => $now]);
                            }
                        }
                    }
                }

                $idempotentConfigs = [
                    ['group' => 'basic', 'name' => 'site_favicon', 'title' => '浏览器图标 (Favicon)', 'type' => 'image', 'value' => '', 'options' => '', 'tip' => '展示于浏览器标签页的 Favicon 图标', 'weight' => 85],
                    ['group' => 'basic', 'name' => 'login_image', 'title' => '登录页插图', 'type' => 'image', 'value' => '', 'options' => '', 'tip' => '展示于后台登录、找回密码与注册页面左侧的主插图', 'weight' => 83],
                    ['group' => 'basic', 'name' => 'default_avatar', 'title' => '默认头像', 'type' => 'image', 'value' => '', 'options' => '', 'tip' => '管理员或会员未设置个性头像时的全局默认头像', 'weight' => 81],
                    ['group' => 'basic', 'name' => 'home_mode', 'title' => 'PC前端首页模式', 'type' => 'select', 'value' => 'portal', 'options' => '{"portal":"系统默认门户模板","cms":"CMS内容管理门户(插件)","custom_plugin":"自定义插件接管","redirect_admin":"自动跳转管理后台"}', 'tip' => '设置用户访问根域名(/)时的展示策略，选择门户或插件接管可自动隐藏后台真实入口', 'weight' => 68],
                    ['group' => 'basic', 'name' => 'home_plugin', 'title' => '接管首页的插件标识', 'type' => 'string', 'value' => 'cms', 'options' => '', 'tip' => '当首页模式为插件接管时生效，如 cms（对应插件前端或门户模板），也可填写自定义模板路径标识', 'weight' => 66],
                    ['group' => 'security', 'name' => 'admin_entry', 'title' => '管理后台安全入口路径', 'type' => 'string', 'value' => 'admin', 'options' => '', 'tip' => '默认通过 /admin 访问后台；修改为其他英文数字路径（如 myconsole）后，访问原 /admin 将被拦截隐藏，大幅提升安全性', 'weight' => 64],
                    ['group' => 'security', 'name' => 'login_captcha', 'title' => '后台登录验证码开关', 'type' => 'switch', 'value' => '1', 'options' => '', 'tip' => '开启后管理后台登录必须通过图形验证码安全校验，防止暴力破解', 'weight' => 62],
                    ['group' => 'upload', 'name' => 'upload_allow_ext', 'title' => '允许上传格式', 'type' => 'text', 'value' => 'jpg,jpeg,png,gif,webp,bmp,ico,mp4,webm,mov,m4v,mp3,wav,ogg,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z,txt,csv,md', 'options' => '', 'tip' => '常用扩展名白名单，半角逗号分隔', 'weight' => 90],
                    ['group' => 'upload', 'name' => 'upload_max_size', 'title' => '单文件最大限制 (MB)', 'type' => 'number', 'value' => '50', 'options' => '', 'tip' => '单位：MB，如 50 代表最大 50MB', 'weight' => 80],
                ];
                foreach ($idempotentConfigs as $item) {
                    if (!Db::name('config')->where('name', $item['name'])->find()) {
                        Db::name('config')->insert($item + ['create_time' => $now, 'update_time' => $now]);
                    }
                }
            }
        }

        $rows = $this->buildIndexQuery()->order('weight', 'desc')->order('id', 'asc')->select()->toArray();
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || in_array($name, self::SENSITIVE_CONFIG_NAMES, true)) {
                return false;
            }
            return !preg_match(self::SENSITIVE_NAME_PATTERN, $name);
        }));
        $cloudEnabled = $this->isCloudStorageEnabled();
        foreach ($rows as &$row) {
            if (($row['name'] ?? '') === 'upload_storage') {
                $options = $this->resolveStorageOptions($cloudEnabled, (string) ($row['options'] ?? ''));
                $row['options'] = json_encode($options, JSON_UNESCAPED_UNICODE);
                if (!$cloudEnabled) {
                    if (!isset($options[(string) ($row['value'] ?? 'local')])) {
                        $row['value'] = 'local';
                    }
                    $row['tip'] = '当前仅使用本地存储；安装并启用云存储插件后，可在此下拉菜单选择云端存储';
                }
            }
        }
        unset($row);

        $groups = ['basic' => '基础配置', 'security' => '安全配置', 'upload' => '上传配置', 'user' => '会员配置'];
        
        $this->success([
            'list'   => $rows,
            'total'  => count($rows),
            'groups' => $groups,
        ]);
    }

    public function edit(): void
    {
        $this->ensurePost();
        $values = $this->request->post('values');
        $id = $this->request->param('id');

        if (is_array($values) && ($id === null || $id === '')) {
            if (isset($values['admin_entry'])) {
                $this->validateAdminEntry((string) $values['admin_entry']);
            }

            $now = time();
            foreach ($values as $key => $val) {
                $keyName = (string) $key;
                if (in_array($keyName, self::SENSITIVE_CONFIG_NAMES, true)) {
                    continue;
                }
                Db::name('config')->where('name', $keyName)->update([
                    'value'       => is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val,
                    'update_time' => $now,
                ]);
            }

            if (isset($values['admin_entry'])) {
                $this->syncAdminEntryRuntime((string) $values['admin_entry']);
            }

            event('ConfigSaveAfter', $values);
            $this->success(null, '配置已保存');
        }

        $this->crudEdit();
    }

    public function save(): void
    {
        $this->edit();
    }

    protected function beforeWrite(array $data, ?Model $row): array
    {
        $this->validate($data, [
            'name|配置键'  => ($row ? '' : 'require|') . 'regex:/^[a-zA-Z0-9_]+$/|max:50',
            'title|配置名' => ($row ? '' : 'require|') . 'max:100',
            'group|分组'   => 'max:30',
        ]);

        if (isset($data['name']) && $data['name'] === 'admin_entry' && isset($data['value'])) {
            $this->validateAdminEntry((string) $data['value']);
            $this->syncAdminEntryRuntime((string) $data['value']);
        } elseif ($row && $row->name === 'admin_entry' && isset($data['value'])) {
            $this->validateAdminEntry((string) $data['value']);
            $this->syncAdminEntryRuntime((string) $data['value']);
        }

        if (isset($data['name'])) {
            $exists = Db::name('config')->where('name', $data['name'])
                ->when($row, fn ($q) => $q->where('id', '<>', $row->id))->find();
            if ($exists) {
                throw new ApiException('配置键已存在');
            }
        }

        return $data;
    }

    /**
     * 校验管理后台安全入口有效性与系统保留字
     */
    protected function validateAdminEntry(string $entry): void
    {
        $entry = trim($entry);
        if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $entry)) {
            throw new ApiException('管理后台安全入口路径必须为 3-32 位字母、数字、下划线或连字符');
        }

        $reserved = ['api', 'adminapi', 'install', 'static', 'storage', 'plugins', 'index', 'core', 'config', 'runtime', 'view'];
        if (in_array(strtolower($entry), $reserved, true)) {
            throw new ApiException("路径名称 [{$entry}] 为系统保留字，禁止用作后台安全入口");
        }
    }

    /**
     * 写入后台安全入口运行时文件供 router.php 快速读取
     */
    protected function syncAdminEntryRuntime(string $entry): void
    {
        \core\support\AdminEntry::setEntry($entry);
    }

    /**
     * 检测云存储插件是否已安装并启用
     */
    protected function isCloudStorageEnabled(): bool
    {
        try {
            $plugin = Db::name('plugin')->where('name', 'oss')->find();
            if ($plugin && (int) ($plugin['status'] ?? 0) === 1 && is_dir($this->app->getRootPath() . 'plugins/oss')) {
                return true;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    /**
     * 按云存储插件启用状态解析存储方式下拉选项
     *
     * @return array<string, string>
     */
    protected function resolveStorageOptions(bool $cloudEnabled, string $rawOptions): array
    {
        $options = ['local' => '本地'];
        if (!$cloudEnabled) {
            return $options;
        }

        $parsed = json_decode($rawOptions, true);
        if (is_array($parsed)) {
            foreach ($parsed as $k => $v) {
                $key = trim((string) $k);
                $label = trim((string) $v);
                if ($key !== '' && $label !== '') {
                    $options[$key] = $label;
                }
            }
        }

        foreach (['cos' => '腾讯云 COS', 'oss' => '阿里云 OSS', 'qiniu' => '七牛云 Kodo'] as $key => $label) {
            if (!isset($options[$key])) {
                $options[$key] = $label;
            }
        }

        return $options;
    }
}
