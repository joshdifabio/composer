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
use Composer\Repository\Vcs\VcsDriverInterface as BlockingDriver;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Config;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;
use React\EventLoop\LoopInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class VcsRepository extends BlockingVcsRepository implements NonBlockingRepositoryInterface
{
    private $loadPackagesPromise;
    private $driver;

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
                $repository = $this;
                $this->loadPackagesPromise = $initPromise->then(
                    function () use ($repository, $driver) {
                        $repository->setDriver($driver);
                        return $driver;
                    },
                    function ($error) {
                        echo "Failed to load packages:\n$error\n";
                    }
                );
            } elseif ($driver instanceof BlockingDriver) {
                $driver->initialize();
                $this->driver = $driver;
                $this->loadPackagesPromise = new FulfilledPromise;
            } else {
                $this->loadPackagesPromise = new RejectedPromise;
            }
        }
        
        return $this->loadPackagesPromise;
    }
    
    public function getDriver()
    {
        if (is_null($this->driver)) {
            $driver = $this->getDriverInstance();
            $driver->initialize();
            $this->driver = $driver;
        }
        
        return $this->driver;
    }
    
    public function setDriver($driver)
    {
        $this->driver = $driver;
    }
    
    private function getDriverInstance()
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
