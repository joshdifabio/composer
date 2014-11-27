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

namespace Composer\NonBlocking\Repository;

use Composer\Repository\RepositoryManager as BlockingRepositoryManager;
use Composer\Repository\RepositoryInterface as BlockingRepositoryInterface;
use React\EventLoop\Factory as LoopFactory;
use React\Promise\When;

/**
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class RepositoryManager extends BlockingRepositoryManager
{
    private $repoInitPromises = array();
    private $eventLoop;
    
    /**
     * {@inheritdoc}
     */
    public function addRepository(BlockingRepositoryInterface $repository)
    {
        if ($repository instanceof NonBlockingRepositoryInterface) {
            $this->repoInitPromises[] = $repository->initializeNonBlocking($this->getLoop());
        }
        
        parent::addRepository($repository);
    }

    /**
     * {@inheritdoc}
     */
    public function getRepositories()
    {
        if (count($this->repoInitPromises)) {
            $exception = null;
            
            $loop = $this->getLoop();
        
            $allReposInitdPromise = When::all($this->repoInitPromises)->then(
                null,
                function ($error) use (&$exception) {
                    if ($error instanceof \Exception) {
                        $exception = $error;
                    } elseif (is_string($error)) {
                        $exception = new \Exception($error);
                    } else {
                        $exception = new \Exception('Unable to intialise repositories.');
                    }
                }
            );
            
            $loop->addTimer(0.001, function () use ($loop, $allReposInitdPromise) {
                $allReposInitdPromise->then(array($loop, 'stop'));
            });
            
            $loop->run();
            
            if ($exception) {
                throw $exception;
            }
            
            $this->repoInitPromises = array();
        }
        
        return parent::getRepositories();
    }
    
    /**
     * @return \React\EventLoop\LoopInterface
     */
    private function getLoop()
    {
        if (is_null($this->eventLoop)) {
            $this->eventLoop = LoopFactory::create();
        }
        
        return $this->eventLoop;
    }
}
