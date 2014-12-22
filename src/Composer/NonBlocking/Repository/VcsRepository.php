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

use Composer\Repository\VcsRepository as BlockingVcsRepository;
use Composer\NonBlocking\Repository\Vcs\VcsDriverInterface as NonBlockingDriver;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Config;
use React\Promise\FulfilledPromise;
use React\EventLoop\LoopInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class VcsRepository extends BlockingVcsRepository implements NonBlockingRepositoryInterface
{
    private $loadPackagesPromise;
    private $driver;
    private $isDriverInitd = false;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, EventDispatcher $dispatcher = null, array $drivers = null)
    {
        $drivers = $drivers ?: array(
            'github'        => 'Composer\Repository\Vcs\GitHubDriver',
            'git-bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
            'git'           => 'Composer\NonBlocking\Repository\Vcs\GitDriver',
            'hg-bitbucket'  => 'Composer\Repository\Vcs\HgBitbucketDriver',
            'hg'            => 'Composer\Repository\Vcs\HgDriver',
            'perforce'      => 'Composer\Repository\Vcs\PerforceDriver',
            // svn must be last because identifying a subversion server for sure is practically impossible
            'svn'           => 'Composer\NonBlocking\Repository\Vcs\SvnDriver',
        );

        parent::__construct($repoConfig, $io, $config, $dispatcher, $drivers);
    }
    
    public function initializeNonBlocking(LoopInterface $eventLoop)
    {
        if (is_null($this->loadPackagesPromise)) {
            $driver = $this->getDriverInstance();
            
            if ($driver instanceof NonBlockingDriver) {
                $initPromise = $driver->initializeNonBlocking($eventLoop);
                $isDriverInitd = &$this->isDriverInitd;
                $io = $this->io;
                $verbose = $this->verbose;
                $url = $this->url;
                
                $this->loadPackagesPromise = $initPromise
                    ->then(
                        function () use (&$isDriverInitd) {
                            $isDriverInitd = true;
                        },
                        function ($e) use ($io, $verbose, $url) {
                            if ($verbose && $e instanceof \Exception) {
                                $io->write('<error>Unable to perform non-blocking init of repository '.$url.', '.$e->getMessage().'</error>');
                            }
                        }
                    );
            } else {
                $this->loadPackagesPromise = new FulfilledPromise;
            }
        }
        
        return $this->loadPackagesPromise;
    }
    
    public function getDriver()
    {
        if (!$driver = $this->getDriverInstance()) {
            return null;
        }
        
        if (!$this->isDriverInitd) {
            $driver->initialize();
            $this->isDriverInitd = true;
        }
        
        return $driver;
    }
    
    private function getDriverInstance()
    {
        if (is_null($this->driver)) {
            $this->driver = $this->createDriver();
        }
        
        return $this->driver;
    }
    
    private function createDriver()
    {
        if (isset($this->drivers[$this->type])) {
            $class = $this->drivers[$this->type];
            return new $class($this->repoConfig, $this->io, $this->config);
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->config, $this->url)) {
                return new $driver($this->repoConfig, $this->io, $this->config);
            }
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->config, $this->url, true)) {
                return new $driver($this->repoConfig, $this->io, $this->config);
            }
        }
    }
}
