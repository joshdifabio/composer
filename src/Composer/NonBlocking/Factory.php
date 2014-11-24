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

namespace Composer\NonBlocking;

use Composer\Factory as BaseFactory;
use Composer\IO\IOInterface;
use Composer\EventDispatcher\EventDispatcher;
use Composer\NonBlocking\Repository\RepositoryManager;

/**
 * Creates a configured instance of composer.
 *
 * @author Josh Di Fabio <jd@amp.co>
 */
class Factory extends BaseFactory
{
    /**
     * {@inheritdoc}
     */
    protected function createRepositoryManager(IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null)
    {
        $rm = new RepositoryManager($io, $config, $eventDispatcher);
        
        $rm->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $rm->setRepositoryClass('vcs', 'Composer\NonBlocking\Repository\VcsRepository');
        $rm->setRepositoryClass('package', 'Composer\Repository\PackageRepository');
        $rm->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $rm->setRepositoryClass('git', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('svn', 'Composer\NonBlocking\Repository\VcsRepository');
        $rm->setRepositoryClass('perforce', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('hg', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('artifact', 'Composer\Repository\ArtifactRepository');

        return $rm;
    }
}
