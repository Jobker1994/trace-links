<?php

declare(strict_types=1);

return [
    // 注册 Redis 切面类
    \TraceLinks\RedisLoggerAspect::class => \Hyperf\Di\Annotation\Inject::class,

    // 注册 SQL 查询监听器
    \TraceLinks\SqlQueryListener::class => \Hyperf\Di\Annotation\Inject::class,
];