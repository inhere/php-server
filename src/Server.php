<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-02-20
 * Time: 12:41
 */

namespace Inhere\Server;

use Inhere\Console\IO\Input;
use Inhere\Console\Utils\Show;
use Inhere\Library\Components\ErrorHandler;
use Inhere\Library\Helpers\Arr;
use Inhere\Library\Helpers\PhpHelper;
use Inhere\Library\Traits\EventTrait;
use Inhere\Server\Components\FileLogHandler;
use Inhere\Server\Traits\ServerCreateTrait;
use Inhere\Server\Traits\ServerManageTrait;
use Inhere\Server\Traits\SomeSwooleEventTrait;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;

/**
 * Class Server - Server Manager
 * @package Inhere\Server
 * Running processes:
 *
 * ```
 * ```
 */
class Server extends AbstractServer
{
    use EventTrait, ServerManageTrait, ServerCreateTrait, SomeSwooleEventTrait;

    /** @var static */
    private static $instance;

    /** @var array */
    private static $_stats = [];

    /** @var string Current server name */
    public $name = 'server';

    /** @var Logger */
    public $logger;

    /** @var \Swoole\Server */
    public $server;

    /** @var bool */
    private $debug = false;

    /** @var bool */
    private $daemon = false;

    /** @var bool */
    private $bootstrapped = false;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var string pid File */
    protected $pidFile = '';

    /** @var Input */
    protected $input;

    /**
     * config data
     * @var array
     */
    protected $config = [
        // basic config
        'name' => '',
        'debug' => false,
        'rootPath' => '',
        'pidFile' => '/tmp/swoole_server.pid',

        // will create a process auto reload server
        'auto_reload' => '', // 'src,config'

        // error handle
        'error' => [
            // the error handler class
            'class' => '',
            'exitOnHandled' => true,
        ],

        // 当前server的日志配置(不是swoole的日志)
        'log' => [
            'name' => 'server_log',
            'file' => './temp/logs/test_server.log',
            'level' => Logger::DEBUG,
            'bufferSize' => 0, // 1000,
        ],

        // for main server
        'main_server' => [
            'host' => '0.0.0.0',
            'port' => '8662',

            // 运行模式
            // SWOOLE_PROCESS 业务代码在Worker进程中执行 SWOOLE_BASE 业务代码在Reactor进程中直接执行
            'mode' => 'process',
            'type' => 'tcp', // http https tcp udp ws wss

            // append register swoole events
            'extend_events' => [], // e.g [ 'onRequest', ]
        ],

        // for attach servers
        'attach_servers' => [
            // 'tcp1' => [
            //     'host' => '0.0.0.0',
            //     'port' => '9661',
            //     'type' => 'tcp',

            // setting event handler
            //     'event_handler' => '', // e.g '\Inhere\Server\listeners\TcpListenHandler'
            //     'event_list'   => [], // e.g [ 'onReceive', ]
            // ],

            // 'udp1' => [
            //     'host' => '0.0.0.0',
            //     'port' => '9660',
            // ]
        ],

        // the swoole runtime setting
        'swoole' => [
            // 'user'    => '',
            'worker_num' => 4,
            'task_worker_num' => 2, // 启用 task worker,必须为Server设置onTask和onFinish回调
            'daemonize' => 0,
            'max_request' => 1000,
            // 在1.7.15以上版本中，当设置dispatch_mode = 1/3时会自动去掉onConnect/onClose事件回调。
            // see @link https://wiki.swoole.com/wiki/page/49.html
            'dispatch_mode' => 2,
            // 'log_file' , // '/tmp/swoole.log', // 不设置log_file会打印到屏幕

            // 使用SSL必须在编译swoole时加入--enable-openssl选项 并且配置下面两项
            // 'ssl_cert_file' => __DIR__.'/config/ssl.crt',
            // 'ssl_key_file' => __DIR__.'/config/ssl.key',
        ],

        'options' => []
    ];

    /**
     * @var array
     */
    protected static $swooleEvents = [
        // 'event'  => 'callback',
        'start' => 'onMasterStart',
        'shutdown' => 'onMasterStop',

        'managerStart' => 'onManagerStart',
        'managerStop' => 'onManagerStop',

        'workerStart' => 'onWorkerStart',
        'workerStop' => 'onWorkerStop',
        'workerError' => 'onWorkerError',

        'pipeMessage' => 'onPipeMessage',

        // Task 任务相关 (若配置了 task_worker_num 则必须注册这两个事件)
        'task' => 'onTask',
        'finish' => 'onFinish',
    ];

    /**
     * @return static
     */
    public static function instance()
    {
        return self::$instance;
    }

    /**
     * BaseServer constructor.
     * @param array $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $config = [], LoggerInterface $logger = null)
    {
        self::$instance = $this;
        $this->input = new Input;

        $this->init();

        // create logger if not setting
        if (!$this->logger = $logger) {
            $this->makeLogger();
        }

        // register error handler
        if ($conf = $this->config['error']) {
            $errClass = Arr::remove($conf, 'class');
            $this->errorHandler = new $errClass($this->logger, $conf);
            $this->errorHandler->register();
        }
    }

    /**
     * @throws \RuntimeException
     */
    protected function init()
    {
        $this->loadCommandLineOpts($this->input);

        if (!$this->pidFile = $this->config('pidFile')) {
            throw new \RuntimeException("The config option 'pidFile' is must setting");
        }

        // project root path
        if (!$this->config('rootPath')) {
            throw new \RuntimeException("The project path 'rootPath' is must setting");
        }

        if (!$this->name = $this->config('name')) {
            $this->name = basename($this->config('rootPath'));
            $this->config['name'] = $this->name;
        }

        // Get server is debug mode
        $this->debug = (bool)$this->config('debug', false);

        // register attach server from config
        if ($attachServers = $this->config['attach_servers']) {
            foreach ((array)$attachServers as $name => $conf) {
                $this->attachPortListener($name, $conf);
            }
        }

        // register main server event method
        if ($methods = $this->config('main_server.extend_events')) {
            $this->setSwooleEvents($methods);
        }
    }

    /**
     * @param Input $input
     */
    protected function loadCommandLineOpts(Input $input)
    {
        if (($val = $input->sameOpt(['d', 'daemon'])) !== null) {
            $this->asDaemon($val);
        }

        if (($val = $input->sameOpt(['n', 'worker-number'])) > 0) {
            $this->config['swoole']['worker_num'] = $val;
        }
    }

    protected function makeLogger()
    {
        $opts = $this->config('log', []);

        $fileHandler = new FileLogHandler($opts['file'], (int)$opts['level'], (int)$opts['splitType']);
        $mainHandler = new FingersCrossedHandler($fileHandler, (int)$opts['level'], (int)$opts['bufferSize']);
        $fileHandler->setServer($this);

        $logger = new Logger($opts['name'] ?? 'server');
//        $logger->pushProcessor(new UidProcessor());
        $logger->pushHandler($mainHandler);

        $this->logger = $logger;
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /*******************************************************************************
     * start server logic
     ******************************************************************************/

    protected function beforeBootstrap()
    {
    }

    /**
     * bootstrap start
     */
    protected function bootstrap()
    {
        $this->bootstrapped = false;

        // prepare start server
        $this->fire(self::ON_BOOTSTRAP, [$this]);
        $this->beforeBootstrap();

        // do something for before create main server
        $this->fire(self::ON_SERVER_CREATE, [$this]);

        // create swoole server instance
        $this->createServer();

        // do something for after create main server(eg add custom process)
        $this->fire(self::ON_SERVER_CREATED, [$this]);

        // attach Extend Server
        // $this->attachExtendServer();

        // setting swoole config
        // 对于Server的配置即 $server->set() 中传入的参数设置，必须关闭/重启整个Server才可以重新加载
        $this->server->set($this->config['swoole']);

        // register swoole events handler
        $this->registerServerEvents();

        // attach user's custom process
        $this->attachUserProcesses();

        // attach registered listen port server to main server
        $this->createListenServers($this->server);

        // prepared for start server
        $this->fire(self::ON_BOOTSTRAPPED, [$this]);
        $this->afterBootstrap();

        $this->bootstrapped = true;
    }

    protected function afterBootstrap()
    {
        // do something ...
    }

    /**
     * Show server info
     */
    protected function showInformation()
    {
        $swOpts = $this->config['swoole'];
        $main = $this->config['main_server'];
        $panelData = [
            'System Info' => [
                'PHP Version' => PHP_VERSION,
                'Operate System' => PHP_OS,
            ],
            'Swoole Info' => [
                'version' => SWOOLE_VERSION,
                'coroutine' => class_exists(Coroutine::class, false),
            ],
            'Swoole Config' => [
                'dispatch_mode' => $swOpts['dispatch_mode'],
                'worker_num' => $swOpts['worker_num'],
                'task_worker_num' => $swOpts['task_worker_num'],
                'max_request' => $swOpts['max_request'],
            ],
            'Main Server' => [
                'type' => $main['type'],
                'mode' => $main['mode'],
                'host' => $main['host'],
                'port' => $main['port'],
                'class' => static::class,
            ],
            'Project Config' => [
                'name' => $this->name,
                'path' => $this->config['rootPath'],
                'auto_reload' => $this->config['auto_reload'],
                'pidFile' => $this->config['pidFile'],
            ],
            'Server Log' => $this->config['log'],
        ];


        // 'Server Information'
        Show::mList($panelData, [
            'ucfirst' => false,
        ]);
        // Show::panel($panelData, 'Server Information');
    }

    /**
     * show server runtime status information
     */
    protected function showRuntimeStatus()
    {
        Show::notice('Sorry, The function un-completed!', 0);
    }

    /*******************************************************************************
     * getter/setter methods
     ******************************************************************************/

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @return bool
     */
    public function isDaemon(): bool
    {
        return $this->daemon;
    }

    /**
     * @param array $events
     */
    public function setSwooleEvents(array $events)
    {
        foreach ($events as $key => $value) {
            $this->setSwooleEvent(
                \is_int($key) && \is_string($value) ? lcfirst(substr($value, 2)) : $key,
                $value
            );
        }
    }

    /**
     * register a swoole Event Handler Callback
     * @param string $event
     * @param callable|string $handler
     */
    public function onSwoole($event, $handler)
    {
        $this->setSwooleEvent($event, $handler);
    }

    /**
     * @param string $event The event name
     * @param string|\Closure $cb The callback name
     */
    public function setSwooleEvent($event, $cb)
    {
        $event = trim($event);

        if (!$this->isSwooleEvent($event)) {
            $supported = implode(',', self::SWOOLE_EVENTS);
            Show::error("You want add a not supported swoole event: $event. supported: \n $supported", -2);
        }

        self::$swooleEvents[$event] = $cb;
    }

    /**
     * @return array
     */
    public static function getSwooleEvents(): array
    {
        return self::$swooleEvents;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * get Logger service
     * @return LoggerInterface
     * @throws \RuntimeException
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return array
     */
    public function getSupportedProtocols(): array
    {
        return [
            self::PROTOCOL_HTTP,
            self::PROTOCOL_HTTPS,
            self::PROTOCOL_TCP,
            self::PROTOCOL_UDP,
            self::PROTOCOL_WS,
            self::PROTOCOL_WSS,
        ];
    }

    /**
     * @return array
     */
    public function allSwooleEvents(): array
    {
        return self::SWOOLE_EVENTS;
    }

    /**
     * @param string $event
     * @return bool
     */
    public function isSwooleEvent(string $event): bool
    {
        return \in_array($event, self::SWOOLE_EVENTS, true);
    }

    /**
     * @param string $name
     * @param $value
     */
    public static function addStat(string $name, $value)
    {
        self::$_stats[$name] = $value;
    }

    /**
     * @return array
     */
    public static function getStats(): array
    {
        return self::$_stats;
    }

    /*******************************************************************************
     * some help method(from swoole)
     ******************************************************************************/

    /**
     * checkEnvWhenEnableSSL
     */
    protected function checkEnvWhenEnableSSL()
    {
        if (!\defined('SWOOLE_SSL')) {
            Show::error(
                "If you want use SSL(https), must add option '--enable-openssl' on the compile swoole.",
                1
            );
        }

        // check ssl config
        if (!$this->config['swoole']['ssl_cert_file'] || !$this->config['swoole']['ssl_key_file']) {
            Show::error(
                "If you want use SSL(https), must config the 'swoole.ssl_cert_file' and 'swoole.ssl_key_file'",
                1
            );
        }
    }

    /**
     * 获取对端socket的IP地址和端口
     * @param int $cid
     * @return array
     */
    public function getPeerName(int $cid): array
    {
        $data = $this->getClientInfo($cid);

        return [
            'ip' => $data['remote_ip'] ?? '',
            'port' => $data['remote_port'] ?? 0,
        ];
    }

    /**
     * @param int $cid
     * @return array
     * [
     *  // 大于0 是webSocket(=2) 等于0 是 http/...
     *  websocket_status => int [可选项] WebSocket连接状态，当服务器是Swoole\WebSocket\Server时会额外增加此项信息
     *  from_id => int
     *  server_fd => int 来自哪个server socket
     *  server_port => int 来自哪个Server端口
     *  remote_port => int 客户端连接的端口
     *  remote_ip => string 客户端连接的ip
     *  connect_time => int 连接到Server的时间，单位秒
     *  last_time => int  最后一次发送数据的时间，单位秒
     *  close_errno => int 连接关闭的错误码，如果连接异常关闭，close_errno的值是非零
     * ]
     */
    public function getClientInfo(int $cid): array
    {
        // @link https://wiki.swoole.com/wiki/page/p-connection_info.html
        return $this->server->getClientInfo($cid);
    }

    /**
     * @return int
     */
    public function getErrorNo(): int
    {
        return $this->server->getLastError();
    }

    /**
     * @return string
     */
    public function getErrorMsg(): string
    {
        $err = error_get_last();

        return $err['message'] ?? '';
    }

    /**
     * @return resource
     */
    public function getSocket(): resource
    {
        return $this->server->getSocket();
    }

    /**
     * @return bool
     */
    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    /**
     * @return ErrorHandler
     */
    public function getErrorHandler(): ErrorHandler
    {
        return $this->errorHandler;
    }

    /**
     * @param ErrorHandler $errorHandler
     */
    public function setErrorHandler(ErrorHandler $errorHandler)
    {
        $this->errorHandler = $errorHandler;
    }

    /*******************************************************************************
     * some help method
     ******************************************************************************/

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function config(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * @return array
     */
    protected function prepareRuntimeContext(): array
    {
        return [
            'workerId' => $this->getWorkerId(),
            'workerPid' => $this->getWorkerPid(),
            'isTaskWorker' => $this->isTaskWorker(),
            'isUserWorker' => $this->isUserWorker(),
        ];
    }

    /**
     * output log message
     * @param  string $msg
     * @param  array $data
     * @param string|int $type
     * @return void
     */
    public function log(string $msg, array $data = [], $type = Logger::INFO)
    {
        $appendContext = $this->prepareRuntimeContext();

        if (isset($data['_env'])) {
            $data['_env'] = array_merge($data['_env'], $appendContext);
        } else {
            $data['_env'] = $appendContext;
        }

        // if close debug, don't output debug log.
        if (!$this->daemon) {
            list($ts, $ms) = explode('.', sprintf('%.4f', microtime(true)));
            $ms = str_pad($ms, 4, 0);
            $time = date('Y-m-d H:i:s', $ts);
            $json = $data ? json_encode($data) : '';
            $type = Logger::getLevelName($type);

            Show::write(sprintf('[%s.%s] [%s.%s] %s %s', $time, $ms, $this->name, strtoupper($type), $msg, $json));
        }

        if ($this->logger) {
            $this->logger->log($type, strip_tags($msg), $data);
        }

        // return;
    }

    /**
     * @param \Throwable|\Exception $e (\Exception \Error)
     * @param string $catcher
     */
    public function handleException($e, string $catcher)
    {
        $content = PhpHelper::exceptionToString($e, $this->isDebug(), $catcher);

        $this->log($content, [], Logger::ERROR);
    }

}
