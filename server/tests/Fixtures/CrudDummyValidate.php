<?php
declare(strict_types=1);

namespace tests\Fixtures;

use think\Validate;

final class CrudDummyValidate extends Validate
{
    protected $rule = [
        'title' => 'require|max:50',
        'email' => 'require|email',
    ];

    protected $message = [
        'title.require' => '标题不能为空',
        'email.require' => '邮箱不能为空',
        'email.email'   => '邮箱格式不正确',
    ];

    protected $scene = [
        'add'  => ['title', 'email'],
        'edit' => ['title', 'email'],
    ];
}
