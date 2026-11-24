<?php
declare(strict_types=1);

use think\facade\Route;

Route::get('/', 'Index/index');
// @hw-page-routes-start
// @hw-page-routes-end
Route::any(':entry', 'Index/entry')->pattern(['entry' => '^[a-zA-Z0-9_-]+$']);
