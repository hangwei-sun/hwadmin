<?php
// 全局中间件定义文件
return [
    // 全局 HTTP 安全响应头与 RequestId 追踪 (S09)
    \core\middleware\SecurityHeaders::class,
    // 跨域（白名单见 .env HW_CORS_ORIGINS）
    \core\middleware\Cors::class,
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化（HwAdmin 使用令牌认证，默认不启用 Session）
    // \think\middleware\SessionInit::class
];
