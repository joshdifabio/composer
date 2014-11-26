<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\NonBlocking\Util;

use React\EventLoop\LoopInterface;
use React\ChildProcess\Process;
use React\Promise\Deferred;
use React\Stream\Stream;

/**
 * @author Josh Di Fabio <jd@amp.co>
 */
class ProcessExecutor
{
    private static $invokationBuffer;
    
    private $execute;
    
    public function __construct()
    {
        $this->initExecute();
    }
    
    public static function setProcessLimit($processLimit)
    {
        self::getInvokationBuffer()->setInvokationLimit($processLimit);
    }
    
    public function execute(LoopInterface $eventLoop, $command, $cwd, $exitPollInterval = 0.1)
    {
        return self::getInvokationBuffer()->invoke($this->execute, array(
            $eventLoop,
            $command,
            $cwd,
            $exitPollInterval
        ));
    }
    
    private function initExecute()
    {
        $streamToString = $this->getStreamToStringFunction();
        
        $this->execute = function ($eventLoop, $command, $cwd, $interval) use ($streamToString) {
            $deferred = new Deferred;

            $stdOut = '';
            $stdErr = '';
            $process = new Process($command, $cwd);

            $process->on('exit', function ($exitCode) use ($deferred, &$stdOut, &$stdErr) {
                $deferred->resolve(new ProcessResult($exitCode, $stdOut, $stdErr));
            });

            $process->start($eventLoop, $interval);

            $streamToString($process->stdout, $stdOut);
            $streamToString($process->stderr, $stdErr);

            return $deferred->promise();
        };
    }
    
    private function getStreamToStringFunction()
    {
        return function (Stream $stream, &$output) {
            $stream->on('data', function ($data) use (&$output) {
                $output .= $data;
            });

            $stream->on('end', function (Stream $stream) use (&$output) {
                while (
                    (false !== $data = stream_get_contents($stream->stream, 4096))
                    && '' !== $data
                ) {
                    $output .= $data;
                }
            });
        };
    }
    
    /**
     * @return InvokationBuffer
     */
    private static function getInvokationBuffer()
    {
        if (is_null(self::$invokationBuffer)) {
            self::$invokationBuffer = new InvokationBuffer(100);
        }
        
        return self::$invokationBuffer;
    }
}