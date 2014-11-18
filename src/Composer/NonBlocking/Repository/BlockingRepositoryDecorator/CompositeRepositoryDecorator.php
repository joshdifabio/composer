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

use Composer\Repository\CompositeRepository as BlockingCompositeRepository;

/**
 * Can be used to wrap a standard, blocking composite repository object in order to make it
 * compatible with non-blocking Composer. Note that this will not actually make a blocking
 * repository non-blocking.
 * 
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class CompositeRepositoryDecorator extends RepositoryDecorator
{
    public function __construct(BlockingCompositeRepository $blockingRepository)
    {
        parent::__construct($blockingRepository);
    }
}