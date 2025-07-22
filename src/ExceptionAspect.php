<?php
namespace TraceLinks;

use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Throwable;

/**
 * @Aspect
 */
class ExceptionAspect extends AbstractAspect
{
    public  $classes = [
        'App\\Controller\\*::*',  // 匹配所有控制器方法
    ];

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        try {
            return $proceedingJoinPoint->process(); // 正常执行
        } catch (Throwable $e) {
            $class = $proceedingJoinPoint->className;
            $method = $proceedingJoinPoint->methodName;

            $logs[] = [
                'class' => $class,
                'method' => $method,
                'info'   => $e->getMessage(),
                'trace'  => $e->getTrace(),
            ];

            Context::set('throwError.logs', $logs);
        }
    }
}
