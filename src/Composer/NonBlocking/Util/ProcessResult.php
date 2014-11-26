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

/**
 * @author Josh Di Fabio <jd@amp.co>
 */
class ProcessResult
{
    private $exitCode;
    private $stdOut;
    private $stdErr;
    
    public function __construct($exitCode, $stdOut, $stdErr)
    {
        $this->exitCode = $exitCode;
        $this->stdOut = $stdOut;
        $this->stdErr = $stdErr;
    }
    
    public function getExitCode()
    {
        return $this->exitCode;
    }
    
    public function getStdOut()
    {
        return $this->stdOut;
    }
    
    public function getStdErr()
    {
        return $this->stdErr;
    }
}