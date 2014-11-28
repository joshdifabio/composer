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

use React\Promise\Deferred;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;

/**
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class InvocationBuffer
{
    private $invocationLimit;
    private $invocationCount;
    private $invocationQueue = array();
    
    public function __construct($invocationLimit)
    {
        $this->invocationLimit = $invocationLimit;
    }
    
    public function invoke($callable, $args = array())
    {
        return $this->getSlot()
            ->then(function () use ($callable, $args) {
                return call_user_func_array($callable, $args);
            })
            ->then(
                array($this, 'handleResult'),
                array($this, 'handleFailure')
            );
    }
    
    public function setInvocationLimit($invocationLimit)
    {
        $this->invocationLimit = $invocationLimit;
    }
    
    public function handleResult($result = null)
    {
        $this->invocationCount--;
        
        while (
            $this->invocationLimit > $this->invocationCount
            && $deferredCommand = array_shift($this->invocationQueue)
        ) {
            $this->invocationCount++;
            $deferredCommand->resolve();
        }
        
        return $result;
    }
    
    public function handleFailure($error = null)
    {
        $this->handleResult();
        
        return new RejectedPromise($error);
    }
    
    private function getSlot()
    {
        if ($this->invocationLimit > $this->invocationCount) {
            $this->invocationCount++;
            return new FulfilledPromise;
        }
        
        return $this->invocationQueue[] = new Deferred;
    }
}