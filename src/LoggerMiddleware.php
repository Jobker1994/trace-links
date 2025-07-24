<?php

namespace TraceLinks;

use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\Context;
use Hyperf\Database\Schema\Blueprint;
class LoggerMiddleware implements MiddlewareInterface
{
    protected RequestInterface $request;
    protected \Psr\Log\LoggerInterface $logger;

    public function __construct(RequestInterface $request, LoggerFactory $loggerFactory)
    {
        $this->request = $request;
        $config = \Hyperf\Utils\ApplicationContext::getContainer()->get(\Hyperf\Contract\ConfigInterface::class);
        $logChannels = $config->get('logger', []);

        if (!isset($logChannels['trace-links'])) {
            // 动态注册 Logger
            $appName = env('APP_NAME', 'default-trace-links');
            $path = "/data/storage/{$appName}/logs/trace-links/{$appName}-trace-links.log";
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            $handler = new \Monolog\Handler\RotatingFileHandler($path, 7, Logger::INFO);
            $formatter = new \Monolog\Formatter\LineFormatter(null, 'Y-m-d H:i:s', true);
            $handler->setFormatter($formatter);

            $logger = new Logger('trace-links');
            $logger->pushHandler($handler);
            $this->logger = $logger;
        } else {
            $this->logger = $loggerFactory->get('trace-links');
        }

    }

    private function ensureTraceLinksTable(): void
    {
        if (Schema::hasTable('trace_links')) {
            return;
        }

        Schema::create('trace_links', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('operator_id')->default(0)->comment('操作员id');
            $table->string('operator', 50)->default('')->comment('操作员');
            $table->unsignedTinyInteger('rank')->default(1)->comment('级别:1=info,2=error');
            $table->string('uri', 200)->default('')->comment('请求uri');
            $table->string('method', 10)->default('')->comment('请求方法');
            $table->string('ip', 200)->default('')->comment('ip');
            $table->text('params')->comment('参数');
            $table->unsignedInteger('duration')->default(0)->comment('请求时间(ms)');
            $table->text('sqls')->comment('sql结果');
            $table->text('redis')->comment('redis结果');
            $table->text('throw_error')->comment('throw_error结果');
            $table->string('response_code', 5)->default('')->comment('响应code');
            $table->text('response_body')->comment('响应body');
            $table->dateTime('request_time')->nullable()->comment('请求时间');
            $table->dateTime('created_at')->useCurrent();
            // 索引（可选）
            $table->index('operator_id');
            $table->index('request_time');
        });
    }

    /**
     * 写数据
     * @param array $payload
     * @return void
     */
    private function writeTraceLink(array $payload): void
    {
        // 长文本裁剪，避免 TEXT 溢出（~64KB）；也可改成 MEDIUMTEXT
        $limit = 65000;

        $data = [
            'operator_id'   => (int)($payload['operator_id'] ?? 0),
            'operator'      => (string)($payload['operator'] ?? ''),
            'uri'           => (string)($payload['uri'] ?? ''),
            'method'        => (string)($payload['method'] ?? ''),
            'ip'            => (string)($payload['ip'] ?? ''),
            'params'        => mb_strimwidth(json_encode($payload['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, $limit),
            'duration'      => (int)($payload['duration'] ?? 0),
            'sqls'          => mb_strimwidth(json_encode($payload['sqls'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, $limit),
            'redis'         => mb_strimwidth(json_encode($payload['redis'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, $limit),
            'throw_error'   => mb_strimwidth(json_encode($payload['throw_error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, $limit),
            'response_code' => (string)($payload['response_code'] ?? ''),
            'response_body' => mb_strimwidth((string)$payload['response_body'], 0, $limit),
            'request_time'  => $payload['request_time'] ?? date('Y-m-d H:i:s'),
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        if(!empty($payload['throw_error'])){
            $data['rank'] = 2;
        }

        try {
            Db::table('trace_links')->insert($data);
        } catch (\Throwable $e) {
            // 写库失败时回退到日志文件，避免丢数据
            $this->logger->error('写 trace_links 失败：' . $e->getMessage(), $data);
        }
    }

    /**
     * 确认表不存在 存在即创建
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
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

        $throwError = Context::get('throwError.logs', []);

        $userInfo = Context::get('user_info', []); // 操作员

        $this->ensureTraceLinksTable(); // 确保存在（仅首次建）

        // 将下面的原来写入日志文件的数据插入表

        $this->writeTraceLink([
            'request_time'  => date('Y-m-d H:i:s'),
            'method'        => $this->request->getMethod(),
            'uri'           => (string)$this->request->getUri(),
            'ip'            => $this->request->getServerParams()['remote_addr'] ?? 'unknown',
            'operator'      => $userInfo ? $userInfo['name'] : '',
            'operator_id'   => $userInfo ? ($userInfo['id'] ?? 0) : 0,
            'params'        => $this->request->all(),
            'duration'      => $duration,
            'sqls'          => $sqls,        // 数组/字符串都行，会 JSON 化
            'redis'         => $redisLogs,   // 同上
            'throw_error'   => $throwError,   // 同上
            'response_code' => $response->getStatusCode(),
            'response_body' => $responseBody, // 已读取字符串
        ]);

        return $response;
    }
}
