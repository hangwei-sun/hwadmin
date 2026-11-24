<?php
declare(strict_types=1);

use think\migration\Migrator;

/**
 * 新增 PC 前端首页模式与管理后台安全入口、登录验证码系统配置
 */
class AddPortalAndSecurityConfigs extends Migrator
{
    public function up(): void
    {
        if (!$this->hasTable('config')) {
            return;
        }

        $prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? '');
        $tableName = $prefix . 'config';
        $now = time();

        $items = [
            [
                'group'       => 'basic',
                'name'        => 'home_mode',
                'title'       => 'PC前端首页模式',
                'type'        => 'select',
                'value'       => 'portal',
                'options'     => json_encode([
                    'portal'         => '系统默认门户模板',
                    'cms'            => 'CMS内容管理门户(插件)',
                    'custom_plugin'  => '自定义插件接管',
                    'redirect_admin' => '自动跳转管理后台',
                ], JSON_UNESCAPED_UNICODE),
                'tip'         => '设置用户访问根域名(/)时的展示策略，选择门户或插件接管可自动隐藏后台真实入口',
                'weight'      => 68,
            ],
            [
                'group'       => 'basic',
                'name'        => 'home_plugin',
                'title'       => '接管首页的插件标识',
                'type'        => 'string',
                'value'       => 'cms',
                'options'     => '',
                'tip'         => '当首页模式为插件接管时生效，如 cms（对应插件前端或门户模板），也可填写自定义模板路径标识',
                'weight'      => 66,
            ],
            [
                'group'       => 'security',
                'name'        => 'admin_entry',
                'title'       => '管理后台安全入口路径',
                'type'        => 'string',
                'value'       => 'admin',
                'options'     => '',
                'tip'         => '默认通过 /admin 访问后台；修改为其他英文数字路径（如 myconsole）后，访问原 /admin 将被拦截隐藏，大幅提升安全性',
                'weight'      => 64,
            ],
            [
                'group'       => 'security',
                'name'        => 'login_captcha',
                'title'       => '后台登录验证码开关',
                'type'        => 'switch',
                'value'       => '1',
                'options'     => '',
                'tip'         => '开启后管理后台登录必须通过图形验证码安全校验，防止暴力破解',
                'weight'      => 62,
            ],
        ];

        foreach ($items as $item) {
            $row = $this->fetchRow(sprintf("SELECT id FROM %s WHERE name = '%s' LIMIT 1", $tableName, $item['name']));
            if (!$row) {
                $this->execute(sprintf(
                    "INSERT INTO %s (`group`, `name`, `title`, `type`, `value`, `options`, `tip`, `rule`, `weight`, `create_time`, `update_time`) " .
                    "VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '', %d, %d, %d)",
                    $tableName,
                    $item['group'],
                    $item['name'],
                    addslashes($item['title']),
                    $item['type'],
                    addslashes($item['value']),
                    addslashes($item['options']),
                    addslashes($item['tip']),
                    $item['weight'],
                    $now,
                    $now
                ));
            }
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('config')) {
            return;
        }

        $prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? '');
        $tableName = $prefix . 'config';

        $names = ["'home_mode'", "'home_plugin'", "'admin_entry'", "'login_captcha'"];
        $this->execute(sprintf("DELETE FROM %s WHERE name IN (%s)", $tableName, implode(',', $names)));
    }
}
