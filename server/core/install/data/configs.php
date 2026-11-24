<?php
// +----------------------------------------------------------------------
// | 核心系统配置项初始数据（config 表）。仅在不存在时插入，不覆盖用户修改。
// | type：string|text|number|switch|select|image|file|json  （后台配置表单据此渲染）
// +----------------------------------------------------------------------

return [
    ['group' => 'basic', 'name' => 'site_name', 'title' => '站点名称', 'type' => 'string', 'value' => 'HwAdmin', 'options' => '', 'tip' => '', 'weight' => 100],
    ['group' => 'basic', 'name' => 'site_logo', 'title' => '站点 Logo', 'type' => 'image', 'value' => '', 'options' => '', 'tip' => '', 'weight' => 90],
    ['group' => 'basic', 'name' => 'site_favicon', 'title' => '浏览器图标 (Favicon)', 'type' => 'image', 'value' => '', 'options' => '', 'tip' => '展示于浏览器标签页的 Favicon 图标', 'weight' => 85],
    ['group' => 'basic', 'name' => 'login_image', 'title' => '登录页插图', 'type' => 'image', 'value' => '', 'options' => '', 'tip' => '展示于后台登录、找回密码与注册页面左侧的主插图', 'weight' => 83],
    ['group' => 'basic', 'name' => 'default_avatar', 'title' => '默认头像', 'type' => 'image', 'value' => '', 'options' => '', 'tip' => '管理员或会员未设置个性头像时的全局默认头像', 'weight' => 81],
    ['group' => 'basic', 'name' => 'beian', 'title' => '备案号', 'type' => 'string', 'value' => '', 'options' => '', 'tip' => '', 'weight' => 80],
    ['group' => 'basic', 'name' => 'site_copyright', 'title' => '底部版权信息', 'type' => 'string', 'value' => 'Copyright © 2026 HwAdmin. All rights reserved.', 'options' => '', 'tip' => '展示于管理后台底部 Footer 区域', 'weight' => 70],
    ['group' => 'basic', 'name' => 'home_mode', 'title' => 'PC前端首页模式', 'type' => 'select', 'value' => 'portal', 'options' => '{"portal":"系统默认门户模板","cms":"CMS内容管理门户(插件)","custom_plugin":"自定义插件接管","redirect_admin":"自动跳转管理后台"}', 'tip' => '设置用户访问根域名(/)时的展示策略，选择门户或插件接管可自动隐藏后台真实入口', 'weight' => 68],
    ['group' => 'basic', 'name' => 'home_plugin', 'title' => '接管首页的插件标识', 'type' => 'string', 'value' => 'cms', 'options' => '', 'tip' => '当首页模式为插件接管时生效，如 cms（对应插件前端或门户模板），也可填写自定义模板路径标识', 'weight' => 66],
    ['group' => 'security', 'name' => 'admin_entry', 'title' => '管理后台安全入口路径', 'type' => 'string', 'value' => 'admin', 'options' => '', 'tip' => '默认通过 /admin 访问后台；修改为其他英文数字路径（如 myconsole）后，访问原 /admin 将被拦截隐藏，大幅提升安全性', 'weight' => 64],
    ['group' => 'security', 'name' => 'login_captcha', 'title' => '后台登录验证码开关', 'type' => 'switch', 'value' => '1', 'options' => '', 'tip' => '开启后管理后台登录必须通过图形验证码安全校验，防止暴力破解', 'weight' => 62],
    ['group' => 'upload', 'name' => 'upload_storage', 'title' => '存储方式', 'type' => 'select', 'value' => 'local', 'options' => '{"local":"本地"}', 'tip' => '对象存储由插件扩展', 'weight' => 100],
    ['group' => 'upload', 'name' => 'upload_allow_ext', 'title' => '允许上传格式', 'type' => 'text', 'value' => 'jpg,jpeg,png,gif,webp,bmp,ico,mp4,webm,mov,m4v,mp3,wav,ogg,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z,txt,csv,md', 'options' => '', 'tip' => '常用扩展名白名单，半角逗号分隔', 'weight' => 90],
    ['group' => 'upload', 'name' => 'upload_max_size', 'title' => '单文件最大限制 (MB)', 'type' => 'number', 'value' => '50', 'options' => '', 'tip' => '单位：MB，如 50 代表最大 50MB', 'weight' => 80],
    ['group' => 'user', 'name' => 'user_register', 'title' => '开放会员注册', 'type' => 'switch', 'value' => '1', 'options' => '', 'tip' => '', 'weight' => 100],
];
