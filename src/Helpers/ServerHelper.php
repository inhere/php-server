<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-02-20
 * Time: 15:11
 */

namespace Inhere\Server\Helpers;

use inhere\library\helpers\PhpHelper;

/**
 * Class ServerHelper
 * @package Inhere\Server
 */
class ServerHelper
{
    /**
     * 获取资源消耗
     * @param int $startTime
     * @param int|float $startMem
     * @return array
     */
    public static function runtime($startTime, $startMem)
    {
        // 显示运行时间
        $return['time'] = number_format(microtime(true) - $startTime, 4) . 's';

        $startMem = array_sum(explode(' ', $startMem));
        $endMem = array_sum(explode(' ', memory_get_usage()));

        $return['memory'] = number_format(($endMem - $startMem) / 1024) . 'kb';

        return $return;
    }

    /**
     * @throws \RuntimeException
     */
    public static function checkRuntimeEnv()
    {
        if (!PhpHelper::isCli()) {
            throw new \RuntimeException('Server must run in the CLI mode.');
        }

        if (!extension_loaded('swoole')) {
            throw new \RuntimeException('Run the server, extension \'swoole\' is required!');
        }
    }
}
