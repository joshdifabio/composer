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
use Composer\Config;
use Composer\IO\IOInterface;

/**
 * @author Josh Di Fabio <jd@amp.co>
 */
class Svn extends BlockingSvnUtil
{
    protected $processExecutor;
    
    public function __construct(
        $url,
        IOInterface $io,
        Config $config,
        ProcessExecutor $processExecutor
    ) {
        parent::__construct($url, $io, $config);
        
        $this->processExecutor = $processExecutor;
    }
    
    public function execute($command, $url, $cwd = null, $path = null, $verbose = false)
    {
        $bashCommand = $this->getCommand($command, $url, $path);
        $that = $this;
        $execArgs = func_get_args();
        
        return $this->processExecutor->execute($bashCommand, $cwd)->then(
            function (ProcessResult $result) use ($that, $verbose, $execArgs) {
                return $that->handleProcessResult($result, $verbose, $execArgs);
            }
        );
    }
    
    public function handleProcessResult(ProcessResult $result, $verbose, array $execArgs)
    {
        $output = $result->getStdOut();

        if (0 === $result->getExitCode()) {
            if ($verbose) {
                $this->io->write($output);
            }

            return $output;
        }

        if (empty($output)) {
            $output = $result->getStdErr();
        }

        if ($verbose) {
            $this->io->write($output);
        }

        // the error is not auth-related
        if (
            false === stripos($output, 'Could not authenticate to server:')
            && false === stripos($output, 'authorization failed')
            && false === stripos($output, 'svn: E170001:')
            && false === stripos($output, 'svn: E215004:')
        ) {
            throw new \RuntimeException($output);
        }

        return $this->handleAuthError($output, $execArgs);
    }
    
    private function handleAuthError($output, array $execArgs)
    {
        if (!$this->hasAuth()) {
            $this->doAuthDance();
        }

        // try to authenticate if maximum quantity of tries not reached
        if ($this->qtyAuthTries++ < self::MAX_QTY_AUTH_TRIES) {
            // restart the process
            return call_user_func_array(array($this, 'execute'), $execArgs);
        }

        throw new \RuntimeException(
            'wrong credentials provided ('.$output.')'
        );
    }
}
