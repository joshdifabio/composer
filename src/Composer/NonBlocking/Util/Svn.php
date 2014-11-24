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

use Composer\Util\Svn as BlockingSvnUtil;
use React\EventLoop\LoopInterface;
use Composer\Config;
use Composer\IO\IOInterface;
use React\ChildProcess\Process;
use React\Promise\Deferred;

/**
 * @author Josh Di Fabio <jd@amp.co>
 */
class Svn extends BlockingSvnUtil
{
    protected $eventLoop;
    
    public function __construct(LoopInterface $eventLoop, $url, IOInterface $io, Config $config)
    {
        parent::__construct($url, $io, $config);
        $this->eventLoop = $eventLoop;
    }
    
    public function execute($command, $url, $cwd = null, $path = null, $verbose = false)
    {
        $deferredResult = new Deferred;
        
        $bashCommand = $this->getCommand($command, $url, $path);
        $process = new Process($bashCommand, $cwd);
        $output = null;
        $errorOutput = null;
        $authErrorHandler = $this->getAuthErrorHandler(
            $deferredResult,
            $command,
            $url,
            $cwd,
            $path,
            $verbose
        );
        
        $process->on('exit', function ($status) use (
            $deferredResult,
            &$output,
            &$errorOutput,
            $authErrorHandler,
            $bashCommand
        ) {
            if (0 === $status) {
                $deferredResult->resolve($output);
                return;
            }
            
            if (empty($output)) {
                $output = $errorOutput;
            }

            // the error is not auth-related
            if (
                false === stripos($output, 'Could not authenticate to server:')
                && false === stripos($output, 'authorization failed')
                && false === stripos($output, 'svn: E170001:')
                && false === stripos($output, 'svn: E215004:')
            ) {
                $deferredResult->reject(new \RuntimeException("$output\n\tcommand was $bashCommand"));
                return;
            }
            
            $authErrorHandler($output);
        });
        
        $eventLoop = $this->eventLoop;
        $stdOutDataHandler = $this->getStdOutDataHandler($this->io, $verbose, $output);
        $stdErrDataHandler = $this->getStdErrDataHandler($errorOutput);
        
        $eventLoop->addTimer(0.001, function () use (
            $eventLoop,
            $process,
            $stdOutDataHandler,
            $stdErrDataHandler
        ) {
            $process->start($eventLoop, 0.1);
            $process->stdout->on('data', $stdOutDataHandler);
            $process->stderr->on('data', $stdErrDataHandler);
        });
        
        return $deferredResult->promise();
    }
    
    protected function getAuthErrorHandler($deferredResult, $command, $url, $cwd, $path, $verbose)
    {
        $commandCallback = function () use ($deferredResult, $command, $url, $cwd, $path, $verbose) {
            $this->execute($command, $url, $cwd, $path, $verbose)->then(
                function ($output) use ($deferredResult) {
                    $deferredResult->resolve($output);
                },
                function ($error) use ($deferredResult) {
                    $deferredResult->reject($error);
                }
            );
        };
        
        return function () use ($commandCallback) {
            $this->handleAuthError($commandCallback);
        };
    }
    
    public function handleAuthError($output, $commandCallback)
    {
        if (!$this->hasAuth()) {
            $this->doAuthDance();
        }

        // try to authenticate if maximum quantity of tries not reached
        if ($this->qtyAuthTries++ < self::MAX_QTY_AUTH_TRIES) {
            // restart the process
            return $commandCallback();
        }

        throw new \RuntimeException(
            'wrong credentials provided ('.$output.')'
        );
    }
    
    protected function getStdOutDataHandler($io, $verbose, &$output)
    {
        return function ($data) use ($io, $verbose, &$output) {
            if ('Redirecting to URL ' === substr($data, 0, 19)) {
                return;
            }
            $output .= $data;
            if ($verbose) {
                $io->write($data, false);
            }
        };
    }
    
    protected function getStdErrDataHandler(&$errorOutput)
    {
        return function ($data) use (&$errorOutput) {
            $errorOutput .= $data;
        };
    }
}
