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

use Composer\NonBlocking\Repository\WritableRepositoryInterface;
use Composer\Repository\WritableRepositoryInterface as BlockingWritableRepository;
use Composer\Package\PackageInterface;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;

/**
 * Can be used to wrap a standard, blocking writable repository object in order to make it
 * compatible with non-blocking Composer. Note that this will not actually make a blocking
 * writable repository non-blocking.
 * 
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class WritableRepositoryDecorator extends RepositoryDecorator
    implements WritableRepositoryInterface
{
    private $blockingRepository;
    
    public function __construct(BlockingWritableRepository $blockingRepository)
    {
        parent::__construct($blockingRepository);
        $this->blockingRepository = $blockingRepository;
    }
    
    /**
     * {@inheritdoc}
     */
    public function write()
    {
        try {
            return new FulfilledPromise($this->blockingRepository->write());
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addPackage(PackageInterface $package)
    {
        try {
            return new FulfilledPromise($this->blockingRepository->addPackage($package));
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removePackage(PackageInterface $package)
    {
        try {
            return new FulfilledPromise($this->blockingRepository->removePackage($package));
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getCanonicalPackages()
    {
        try {
            return new FulfilledPromise($this->blockingRepository->getCanonicalPackages());
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function reload()
    {
        try {
            return new FulfilledPromise($this->blockingRepository->reload());
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }
}