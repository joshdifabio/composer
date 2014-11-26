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
 * @author Josh Di Fabio <jd@amp.co>
 */
class InvokationBuffer
{
    private $invokationLimit;
    private $invokationCount;
    private $invokationQueue = array();
    
    public function __construct($invokationLimit)
    {
        $this->invokationLimit = $invokationLimit;
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
    
    public function setInvokationLimit($invokationLimit)
    {
        $this->invokationLimit = $invokationLimit;
    }
    
    public function handleResult($result = null)
    {
        $this->invokationCount--;
        
        while (
            $this->invokationLimit > $this->invokationCount
            && $deferredCommand = array_shift($this->invokationQueue)
        ) {
            $this->invokationCount++;
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
        if ($this->invokationLimit > $this->invokationCount) {
            $this->invokationCount++;
            return new FulfilledPromise;
        }
        
        return $this->invokationQueue[] = new Deferred;
    }
}