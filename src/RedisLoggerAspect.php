<?php
//
//namespace RequestSqlTrace\RequestSqlLogger;
//
//use Hyperf\Context\Context;
//use Hyperf\Di\Annotation\Aspect;
//use Hyperf\Di\Aop\AbstractAspect;
//use Psr\Container\ContainerInterface;
//
//#[Aspect]
//class RedisLoggerAspect extends AbstractAspect
//{
//    public array $classes = [
//        \Hyperf\Redis\RedisProxy::class . '::__call',
//    ];
//
//    public function process(\Hyperf\Di\Aop\ProceedingJoinPoint $proceedingJoinPoint)
//    {
//        var_dump("=======redis aoc call");
//        $result = $proceedingJoinPoint->process();
//        $method = $proceedingJoinPoint->arguments['keys']['method'];
//        $arguments = $proceedingJoinPoint->arguments['keys']['arguments'];
//
//        $logs = Context::get('redis.logs', []);
//        $logs[] = [
//            'method' => $method,
//            'arguments' => $arguments,
//        ];
//        Context::set('redis.logs', $logs);
//
//        return $result;
//    }
//}


namespace TraceLinks;

use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Redis\RedisProxy;

use Hyperf\Di\Aop\ProceedingJoinPoint;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @Aspect
 */
class RedisLoggerAspect extends AbstractAspect
{
    public  $classes = [
        RedisProxy::class . '::__call',
    ];

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        // 只在 HTTP 请求中记录 Redis 调用
        $request = Context::get(ServerRequestInterface::class);
        if (! $request instanceof ServerRequestInterface) {
            return $proceedingJoinPoint->process();
        }

        $result = $proceedingJoinPoint->process();

        $keys = $proceedingJoinPoint->arguments['keys'] ?? [];
        // 防止未定义时报错
        $arguments = $keys['arguments'] ?? [];
        $method = $keys['name'] ??  'unknown';

        $logs = Context::get('redis.logs', []);
        $logs[] = [
            'method' => $method,
            'arguments' => $arguments,
        ];
        Context::set('redis.logs', $logs);

        return $result;
    }
}
