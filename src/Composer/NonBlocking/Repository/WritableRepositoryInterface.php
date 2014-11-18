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

use Composer\Package\PackageInterface;

/**
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
interface WritableRepositoryInterface extends RepositoryInterface
{
    /**
     * Writes repository (f.e. to the disc).
     * 
     * @return \React\Promise\PromiseInterface
     */
    public function write();

    /**
     * Adds package to the repository.
     *
     * @param PackageInterface $package package instance
     * 
     * @return \React\Promise\PromiseInterface
     */
    public function addPackage(PackageInterface $package);

    /**
     * Removes package from the repository.
     *
     * @param PackageInterface $package package instance
     * 
     * @return \React\Promise\PromiseInterface
     */
    public function removePackage(PackageInterface $package);

    /**
     * Get unique packages, with aliases resolved and removed
     *
     * @return \React\Promise\PromiseInterface resolves to \Composer\Package\PackageInterface[]
     */
    public function getCanonicalPackages();

    /**
     * Forces a reload of all packages
     * 
     * @return \React\Promise\PromiseInterface
     */
    public function reload();
}
