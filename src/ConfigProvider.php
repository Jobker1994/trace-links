<?php

declare(strict_types=1);

namespace TraceLinks;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
//            'dependencies' => [
//                RedisLoggerAspect::class => \Hyperf\Di\Annotation\Inject::class,
//                SqlQueryListener::class => \Hyperf\Di\Annotation\Inject::class,
//            ],
            'aspects' => [
                RedisLoggerAspect::class,
                ExceptionAspect::class,
            ],
            'listeners' => [
                SqlQueryListener::class,
            ],
        ];
    }
}
