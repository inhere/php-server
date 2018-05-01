<?php
/**
 * Created by PhpStorm.
 * User: Inhere
 * Date: 2018/5/1 0001
 * Time: 00:01
 */

namespace Inhere\Server\Event;

/**
 * Class SwooleEvent
 * @package Inhere\Server\Event
 */
final class SwooleEvent
{
    const START = 'start';
    const SHUTDOWN = 'shutdown';

    const MANAGER_START = 'managerStart';
    const MANAGER_STOP = 'managerStop';

    const WORKER_START = 'workerStart';
    const WORKER_STOP = 'workerStop';
    const WORKER_EXIT = 'workerExit';
    const WORKER_ERROR = 'workerError';

    const PIPE_MESSAGE = 'pipeMessage';

    const BUFFER_FULL = 'bufferFull';
    const BUFFER_EMPTY = 'bufferEmpty';

    const CONNECT = 'connect';
    const RECEIVE = 'receive';
    const CLOSE = 'close';

    /**
     * for task
     */
    const TASK = 'task';
    const FINISH = 'finish';

    /**
     * for http server
     */
    const REQUEST = 'request';

    /**
     * for websocket server
     */
    const OPEN = 'open';
    const HANDSHAKE = 'handshake';
    const MESSAGE = 'message';

    /**
     * @var array
     */
    const DEFAULT_HANDLERS = [
        // basic
        'start' => 'onStart',
        'shutdown' => 'onShutdown',
        'managerStart' => 'onManagerStart',
        'managerStop' => 'onManagerStop',

        // worker
        'workerStart' => 'onWorkerStart',
        'workerStop' => 'onWorkerStop',
        'workerExit' => 'onWorkerExit',
        'workerError' => 'onWorkerError',

        // special
        'pipeMessage' => 'onPipeMessage',
        'bufferFull' => 'onBufferFull',
        'bufferEmpty' => 'onBufferEmpty',

        // tcp/udp
        'connect' => 'onConnect',
        'receive' => 'onReceive',
        'packet' => 'onPacket',
        'close' => 'onClose',

        // task
        'task' => 'onTask',
        'finish' => 'onFinish',

        // http server
        'request' => 'onRequest',

        // webSocket server
        'message' => 'onMessage',
        'open' => 'onOpen',
        'handShake' => 'onHandshake'
    ];

    /**
     * @param string $event
     * @return bool
     */
    public function isValid(string $event): bool
    {
        return isset(self::DEFAULT_HANDLERS[$event]);
    }

    /**
     * @return array
     */
    public static function getAllEvents(): array
    {
        return \array_keys(self::DEFAULT_HANDLERS);
    }
}