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
    private $repoLoadPromises = array();
    private $eventLoop;
    
    /**
     * {@inheritdoc}
     */
    public function addRepository(BlockingRepositoryInterface $repository)
    {
        if ($repository instanceof NonBlockingRepositoryInterface) {
            $this->repoLoadPromises[] = $repository->initializeNonBlocking($this->getLoop());
        }
        
        parent::addRepository($repository);
    }

    /**
     * {@inheritdoc}
     */
    public function getRepositories()
    {
        if (count($this->repoLoadPromises)) {
            $loop = $this->getLoop();
        
            $allReposLoadedPromise = When::all($this->repoLoadPromises);
            $loop->addTimer(0.001, function () use ($loop, $allReposLoadedPromise) {
                $allReposLoadedPromise->then(array($loop, 'stop'), array($loop, 'stop'));
            });
            
            $loop->run();
            
            $this->repoLoadPromises = array();
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
            $this->eventLoop->addPeriodicTimer(5, function () {
                echo date("H:i:s\n");
            });
        }
        
        return $this->eventLoop;
    }
}
