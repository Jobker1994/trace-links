<?php

namespace TraceLinks;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\Context;

class LoggerMiddleware implements MiddlewareInterface
{
    protected RequestInterface $request;
    protected \Psr\Log\LoggerInterface $logger;

    public function __construct(RequestInterface $request, LoggerFactory $loggerFactory)
    {
        $this->request = $request;
        $this->logger = $loggerFactory->get('trace-links');

    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);

        $response = $handler->handle($request);

        $duration = round((microtime(true) - $start) * 1000, 2) . ' ms';
        $sqls = Context::get('sql.logs', []);
        
        // 获取响应体
        $responseBody = '';
        if ($response->getBody() instanceof SwooleStream) {
            $responseBody = (string) $response->getBody();
            $responseBody = config('app_env') !== 'prod' ? $responseBody : '[hidden]';
            $responseBody =  mb_substr($responseBody, 0, 2000);
        }

        $redisLogs = Context::get('redis.logs', []);

        $this->logger->info('请求响应 + SQL 日志：', [
            'request_time' => date('Y-m-d H:i:s'), // 加上这一行
            'method' => $this->request->getMethod(),
            'uri' => (string) $this->request->getUri(),
            'ip' => $this->request->getServerParams()['remote_addr'] ?? 'unknown',
            'params' => $this->request->all(),
            'duration' => $duration,
            'sqls' => $sqls,
            'response_code' => $response->getStatusCode(),
            'response_body' => $responseBody,
            'redis' => $redisLogs,
        ]);

        return $response;
    }
}
