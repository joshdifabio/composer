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
    private static $invocationBuffer;
    
    private $eventLoop;
    private $execute;
    
    public function __construct(LoopInterface $eventLoop)
    {
        $this->eventLoop = $eventLoop;
        $this->initExecute();
    }
    
    public static function setProcessLimit($processLimit)
    {
        self::getInvocationBuffer()->setInvocationLimit($processLimit);
    }
    
    public function execute($command, $cwd, $exitPollInterval = 0.1)
    {
        return self::getInvocationBuffer()->invoke($this->execute, array(
            $command,
            $cwd,
            $exitPollInterval
        ));
    }
    
    private function initExecute()
    {
        $eventLoop = $this->eventLoop;
        $streamToString = $this->getStreamToStringFunction();
        
        $this->execute = function ($command, $cwd, $interval) use ($eventLoop, $streamToString) {
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
     * @return InvocationBuffer
     */
    private static function getInvocationBuffer()
    {
        if (is_null(self::$invocationBuffer)) {
            self::$invocationBuffer = new InvocationBuffer(200);
        }
        
        return self::$invocationBuffer;
    }
}