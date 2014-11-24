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

namespace Composer\NonBlocking\Repository\Vcs;

use Composer\Repository\Vcs\VcsDriverInterface as BlockingVcsDriverInterface;
use React\EventLoop\LoopInterface;

/**
 * @author Josh Di Fabio <jd@amp.co>
 */
interface VcsDriverInterface extends BlockingVcsDriverInterface
{
    /**
     * @return \React\Promise\PromiseInterface
     */
    public function initializeNonBlocking(LoopInterface $eventLoop);
}
