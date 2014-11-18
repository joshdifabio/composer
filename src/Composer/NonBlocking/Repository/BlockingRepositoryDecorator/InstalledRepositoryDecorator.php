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

use Composer\NonBlocking\Repository\InstalledRepositoryInterface;
use Composer\Repository\InstalledRepositoryInterface as BlockingInstalledRepository;

/**
 * Can be used to wrap a standard, blocking installed repository object in order to make it
 * compatible with non-blocking Composer. Note that this will not actually make a blocking
 * installed repository non-blocking.
 * 
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class InstalledRepositoryDecorator extends WritableRepositoryDecorator
    implements InstalledRepositoryInterface
{
    public function __construct(BlockingInstalledRepository $blockingRepository)
    {
        parent::__construct($blockingRepository);
    }
}