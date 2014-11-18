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

use Composer\NonBlocking\Repository\StreamableRepositoryInterface;
use Composer\Repository\StreamableRepositoryInterface as BlockingStreamableRepository;
use Composer\Package\PackageInterface;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;

/**
 * Can be used to wrap a standard, blocking streamable repository object in order to make it
 * compatible with non-blocking Composer. Note that this will not actually make a blocking
 * streamable repository non-blocking.
 * 
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class StreamableRepositoryDecorator extends RepositoryDecorator
    implements StreamableRepositoryInterface
{
    private $blockingRepository;
    
    public function __construct(BlockingStreamableRepository $blockingRepository)
    {
        parent::__construct($blockingRepository);
        $this->blockingRepository = $blockingRepository;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getMinimalPackages()
    {
        try {
            return new FulfilledPromise($this->blockingRepository->getMinimalPackages());
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadPackage(array $data)
    {
        try {
            return new FulfilledPromise($this->blockingRepository->loadPackage($data));
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadAliasPackage(array $data, PackageInterface $aliasOf)
    {
        try {
            return new FulfilledPromise($this->blockingRepository->loadAliasPackage($data, $aliasOf));
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }
}