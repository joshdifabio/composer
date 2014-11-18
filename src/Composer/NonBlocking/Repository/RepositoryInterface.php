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
 * Repository interface.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
interface RepositoryInterface extends \Countable
{
    const SEARCH_FULLTEXT = 0;
    const SEARCH_NAME = 1;

    /**
     * Checks if specified package registered (installed).
     *
     * @param PackageInterface $package package instance
     *
     * @return \React\Promise\PromiseInterface resolves to boolean
     */
    public function hasPackage(PackageInterface $package);

    /**
     * Searches for the first match of a package by name and version.
     *
     * @param string $name    package name
     * @param string $version package version
     *
     * @return \React\Promise\PromiseInterface resolves to PackageInterface|null
     */
    public function findPackage($name, $version);

    /**
     * Searches for all packages matching a name and optionally a version.
     *
     * @param string $name    package name
     * @param string $version package version
     *
     * @return \React\Promise\PromiseInterface resolves to array
     */
    public function findPackages($name, $version = null);

    /**
     * Returns list of registered packages.
     *
     * @return \React\Promise\PromiseInterface resolves to array
     */
    public function getPackages();

    /**
     * Searches the repository for packages containing the query
     *
     * @param  string  $query search query
     * @param  int     $mode  a set of SEARCH_* constants to search on, implementations should do a best effort only
     * 
     * @return \React\Promise\PromiseInterface resolves to an array of array('name' => '...', 'description' => '...')
     */
    public function search($query, $mode = 0);
}
