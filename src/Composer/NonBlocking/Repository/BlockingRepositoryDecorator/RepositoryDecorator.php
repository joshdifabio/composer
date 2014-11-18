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

namespace Composer\NonBlocking\Repository\BlockingRepositoryDecorator;

use Composer\NonBlocking\Repository\RepositoryInterface;
use Composer\Repository\RepositoryInterface as BlockingRepository;
use Composer\Package\PackageInterface;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;

/**
 * Can be used to wrap a standard, blocking repository object in order to make it compatible with
 * non-blocking Composer. Note that this will not actually make a blocking repository non-blocking.
 * 
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class RepositoryDecorator implements RepositoryInterface
{
    private $blockingRepository;
    
    public function __construct(BlockingRepository $blockingRepository)
    {
        $this->blockingRepository = $blockingRepository;
    }
    
    public function __call($name, $arguments)
    {
        try {
            $result = call_user_func_array(array($this->blockingRepository, $name), $arguments);
            return new FulfilledPromise($result);
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasPackage(PackageInterface $package)
    {
        try {
            return new FulfilledPromise($this->blockingRepository->hasPackage($package));
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findPackage($name, $version)
    {
        try {
            return new FulfilledPromise($this->blockingRepository->findPackage($name, $version));
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findPackages($name, $version = null)
    {
        try {
            return new FulfilledPromise($this->blockingRepository->findPackages($name, $version));
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getPackages()
    {
        try {
            return new FulfilledPromise($this->blockingRepository->getPackages());
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function search($query, $mode = 0)
    {
        try {
            return new FulfilledPromise($this->blockingRepository->search($query, $mode));
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }
    
    /**
     * @todo Find usages and make then method non-blocking in interface
     * 
     * {@inheritdoc}
     */
    public function count()
    {
        return $this->blockingRepository->count();
    }
}