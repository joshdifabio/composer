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

use Composer\Repository\RepositoryInterface as BlockingRepositoryInterface;
use React\EventLoop\LoopInterface;

/**
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
interface NonBlockingRepositoryInterface extends BlockingRepositoryInterface
{
    public function initializeNonBlocking(LoopInterface $eventLoop);
}
