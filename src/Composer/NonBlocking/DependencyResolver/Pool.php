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

namespace Composer\NonBlocking\DependencyResolver;

use Composer\Package\BasePackage;
use Composer\Package\AliasPackage;
use Composer\Package\Version\VersionParser;
use Composer\Package\LinkConstraint\LinkConstraintInterface;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\LinkConstraint\EmptyConstraint;
use Composer\Package\PackageInterface;

use Composer\NonBlocking\Repository\RepositoryInterface;
use Composer\NonBlocking\Repository\StreamableRepositoryInterface;
use Composer\NonBlocking\Repository\InstalledRepositoryInterface;
use Composer\NonBlocking\Repository\BlockingRepositoryDecorator\ComposerRepositoryDecorator
    as ComposerRepository;
use Composer\NonBlocking\Repository\BlockingRepositoryDecorator\CompositeRepositoryDecorator
    as CompositeRepository;
use Composer\NonBlocking\Repository\BlockingRepositoryDecorator\PlatformRepositoryDecorator
    as PlatformRepository;
use React\Promise\FulfilledPromise;

/**
 * A package pool contains repositories that provide packages.
 * This is a non-blocking, promise-based implementation.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class Pool
{
    const MATCH_NAME = -1;
    const MATCH_NONE = 0;
    const MATCH = 1;
    const MATCH_PROVIDE = 2;
    const MATCH_REPLACE = 3;
    const MATCH_FILTERED = 4;

    protected $repositories = array();
    protected $providerRepos = array();
    protected $packages = array();
    protected $packageByName = array();
    protected $acceptableStabilities;
    protected $stabilityFlags;
    protected $versionParser;
    protected $providerCache = array();
    protected $filterRequires;
    protected $whitelist = null;
    protected $id = 1;

    public function __construct(
        $minimumStability = 'stable',
        array $stabilityFlags = array(),
        array $filterRequires = array()
    ) {
        $stabilities = BasePackage::$stabilities;
        $this->versionParser = new VersionParser;
        $this->acceptableStabilities = array();
        foreach (BasePackage::$stabilities as $stability => $value) {
            if ($value <= BasePackage::$stabilities[$minimumStability]) {
                $this->acceptableStabilities[$stability] = $value;
            }
        }
        $this->stabilityFlags = $stabilityFlags;
        $this->filterRequires = $filterRequires;
    }

    public function setWhitelist($whitelist)
    {
        $this->whitelist = $whitelist;
        $this->providerCache = array();
    }

    /**
     * Adds a repository and its packages to this package pool
     *
     * @param RepositoryInterface $repo        A package repository
     * @param array               $rootAliases
     */
    public function addRepository(RepositoryInterface $repo, array $rootAliases = array())
    {
        if ($repo instanceof CompositeRepository) {
            return $repo->getRepositories()->then(
                function ($repos) use ($rootAliases) {
                    return $this->addRepositories($repos, $rootAliases);
                }
            );
        }
        
        return $this->addRepositories(array($repo), $rootAliases);
    }
    
    private function addRepositories(array $repos, array $rootAliases)
    {
        $promises = array();
        
        foreach ($repos as $repo) {
            $this->repositories[] = $repo;

            if ($repo instanceof ComposerRepository) {
                $promises[] = $this->addComposerRepo($repo, $rootAliases);
            } else {
                $promises[] = $this->addRegularRepo($repo, $rootAliases);
            }
        }
        
        return \React\Promise\all($promises);
    }
    
    private function addComposerRepo(ComposerRepository $repo, array $rootAliases)
    {
        return $repo->hasProviders()->then(
            function ($repoHasProviders) use ($repo, $rootAliases) {
                if ($repoHasProviders) {
                    $this->providerRepos[] = $repo;

                    return $repo->setRootAliases($rootAliases)
                        ->then(array($repo, 'resetPackageIds'));
                }

                return $this->addRegularRepo($repo, $rootAliases);
            }
        );
    }
    
    private function addRegularRepo(RepositoryInterface $repo, array $rootAliases)
    {
        $exempt = $repo instanceof PlatformRepository
            || $repo instanceof InstalledRepositoryInterface;
        
        if ($repo instanceof StreamableRepositoryInterface) {
            return $this->addStreamableRepo($repo, $exempt, $rootAliases);
        }
        
        return $this->addNonStreamableRepo($repo, $exempt, $rootAliases);
    }
    
    private function addStreamableRepo(
        StreamableRepositoryInterface $repo,
        $exempt,
        array $rootAliases
    ) {
        return $repo->getMinimalPackages()->then(
            function (array $packages) use ($exempt, $rootAliases) {
                foreach ($packages as $package) {
                    $name = $package['name'];
                    $version = $package['version'];
                    $stability = VersionParser::parseStability($version);

                    // collect names
                    $names = array(
                        $name => true,
                    );
                    if (isset($package['provide'])) {
                        foreach ($package['provide'] as $target => $constraint) {
                            $names[$target] = true;
                        }
                    }
                    if (isset($package['replace'])) {
                        foreach ($package['replace'] as $target => $constraint) {
                            $names[$target] = true;
                        }
                    }
                    $names = array_keys($names);

                    if ($exempt || $this->isPackageAcceptable($names, $stability)) {
                        $package['id'] = $this->id++;
                        $package['stability'] = $stability;
                        $this->packages[] = $package;

                        foreach ($names as $provided) {
                            $this->packageByName[$provided][$package['id']] = $this->packages[$this->id - 2];
                        }

                        // handle root package aliases
                        unset($rootAliasData);
                        if (isset($rootAliases[$name][$version])) {
                            $rootAliasData = $rootAliases[$name][$version];
                        } elseif (isset($package['alias_normalized']) && isset($rootAliases[$name][$package['alias_normalized']])) {
                            $rootAliasData = $rootAliases[$name][$package['alias_normalized']];
                        }

                        if (isset($rootAliasData)) {
                            $alias = $package;
                            unset($alias['raw']);
                            $alias['version'] = $rootAliasData['alias_normalized'];
                            $alias['alias'] = $rootAliasData['alias'];
                            $alias['alias_of'] = $package['id'];
                            $alias['id'] = $this->id++;
                            $alias['root_alias'] = true;
                            $this->packages[] = $alias;

                            foreach ($names as $provided) {
                                $this->packageByName[$provided][$alias['id']] = $this->packages[$this->id - 2];
                            }
                        }

                        // handle normal package aliases
                        if (isset($package['alias'])) {
                            $alias = $package;
                            unset($alias['raw']);
                            $alias['version'] = $package['alias_normalized'];
                            $alias['alias'] = $package['alias'];
                            $alias['alias_of'] = $package['id'];
                            $alias['id'] = $this->id++;
                            $this->packages[] = $alias;

                            foreach ($names as $provided) {
                                $this->packageByName[$provided][$alias['id']] = $this->packages[$this->id - 2];
                            }
                        }
                    }
                }
            }
        );
    }
    
    private function addNonStreamableRepo(RepositoryInterface $repo, $exempt, array $rootAliases)
    {
        return $repo->getPackages()->then(
            function (array $packages) use ($exempt, $rootAliases) {
                foreach ($packages as $package) {
                    $names = $package->getNames();
                    $stability = $package->getStability();
                    if ($exempt || $this->isPackageAcceptable($names, $stability)) {
                        $package->setId($this->id++);
                        $this->packages[] = $package;

                        foreach ($names as $provided) {
                            $this->packageByName[$provided][] = $package;
                        }

                        // handle root package aliases
                        $name = $package->getName();
                        if (isset($rootAliases[$name][$package->getVersion()])) {
                            $alias = $rootAliases[$name][$package->getVersion()];
                            if ($package instanceof AliasPackage) {
                                $package = $package->getAliasOf();
                            }
                            $aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']);
                            $aliasPackage->setRootPackageAlias(true);
                            $aliasPackage->setId($this->id++);

                            $package->getRepository()->addPackage($aliasPackage);
                            $this->packages[] = $aliasPackage;

                            foreach ($aliasPackage->getNames() as $name) {
                                $this->packageByName[$name][] = $aliasPackage;
                            }
                        }
                    }
                }
            }
        );
    }

    public function getPriority(RepositoryInterface $repo)
    {
        $priority = array_search($repo, $this->repositories, true);

        if (false === $priority) {
            throw new \RuntimeException("Could not determine repository priority. The repository was not registered in the pool.");
        }

        return -$priority;
    }

    /**
    * Retrieves the package object for a given package id.
    *
    * @param int $id
    * @return PackageInterface
    */
    public function packageById($id)
    {
        return $this->ensurePackageIsLoaded($this->packages[$id - 1]);
    }

    /**
     * Searches all packages providing the given package name and match the constraint
     *
     * @param  string                  $name          The package name to be searched for
     * @param  LinkConstraintInterface $constraint    A constraint that all returned
     *                                                packages must match or null to return all
     * @param  bool                    $mustMatchName Whether the name of returned packages
     *                                                must match the given name
     * @return PackageInterface[]      A set of packages
     */
    public function whatProvides($name, LinkConstraintInterface $constraint = null, $mustMatchName = false)
    {
        $key = ((int) $mustMatchName).$constraint;
        if (isset($this->providerCache[$name][$key])) {
            return $this->providerCache[$name][$key];
        }

        return $this->providerCache[$name][$key] = $this->computeWhatProvides($name, $constraint, $mustMatchName);
    }

    /**
     * @see whatProvides
     */
    private function computeWhatProvides($name, $constraint, $mustMatchName = false)
    {
        $repoCandidatePromises = array();
        
        foreach ($this->providerRepos as $repo) {
            $repoCandidatePromises[] = $repo->whatProvides($this, $name);
        }
        
        return \React\Promise\all($repoCandidatePromises)->then(
            function ($repoCandidateArrays) use ($name, $constraint, $mustMatchName) {
                $candidates = array();

                foreach ($repoCandidateArrays as $repoCandidates) {
                    foreach ($repoCandidates as $candidate) {
                        $candidates[] = $candidate;
                        if ($candidate->getId() < 1) {
                            $candidate->setId($this->id++);
                            $this->packages[$this->id - 2] = $candidate;
                        }
                    }
                }

                if (isset($this->packageByName[$name])) {
                    $candidates = array_merge($candidates, $this->packageByName[$name]);
                }

                $matchPromises = $provideMatchPromises = array();
                $hasNameMatch = false;

                foreach ($candidates as $candidate) {
                    $aliasOfCandidate = null;

                    // alias packages are not white listed, make sure that the package
                    // being aliased is white listed
                    if ($candidate instanceof AliasPackage) {
                        $aliasOfCandidate = $candidate->getAliasOf();
                    }

                    if ($this->whitelist !== null && (
                        (is_array($candidate) && isset($candidate['id']) && !isset($this->whitelist[$candidate['id']])) ||
                        (is_object($candidate) && !($candidate instanceof AliasPackage) && !isset($this->whitelist[$candidate->getId()])) ||
                        (is_object($candidate) && $candidate instanceof AliasPackage && !isset($this->whitelist[$aliasOfCandidate->getId()]))
                    )) {
                        continue;
                    }
                    
                    switch ($this->match($candidate, $name, $constraint)) {
                        case self::MATCH_NONE:
                            break;

                        case self::MATCH_NAME:
                            $hasNameMatch = true;
                            break;

                        case self::MATCH:
                            $hasNameMatch = true;
                            $matchPromises[] = $this->ensurePackageIsLoaded($candidate);
                            break;

                        case self::MATCH_PROVIDE:
                            $provideMatchPromises[] = $this->ensurePackageIsLoaded($candidate);
                            break;

                        case self::MATCH_REPLACE:
                            $matchPromises[] = $this->ensurePackageIsLoaded($candidate);
                            break;

                        case self::MATCH_FILTERED:
                            break;

                        default:
                            throw new \UnexpectedValueException('Unexpected match type');
                    }
                }
                
                $nameToMatch = $mustMatchName ? $name : null;
                
                return \React\Promise\all($matchPromises)->then(
                    function (array $matches) use (
                        $nameToMatch,
                        $hasNameMatch,
                        $provideMatchPromises
                    ) {
                        if ($nameToMatch) {
                            return array_filter($matches, function ($match) use ($nameToMatch) {
                                return $match->getName() == $nameToMatch;
                            });
                        }

                        // if a package with the required name exists, we ignore providers
                        if ($hasNameMatch) {
                            return $matches;
                        }

                        return \React\Promise\all($provideMatchPromises)->then(
                            function ($provideMatches) use ($matches) {
                                return array_merge($matches, $provideMatches);
                            }
                        );
                    }
                );
            }
        );
    }

    public function literalToPackage($literal)
    {
        $packageId = abs($literal);

        return $this->packageById($packageId);
    }

    public function literalToString($literal)
    {
        return $this->literalToPackage($literal)->then(function ($package) use ($literal) {
            return ($literal > 0 ? '+' : '-') . $package;
        });
    }

    public function literalToPrettyString($literal, $installedMap)
    {
        return $this->literalToPackage($literal)->then(
            function ($package) use ($literal, $installedMap) {
                if (isset($installedMap[$package->getId()])) {
                    $prefix = ($literal > 0 ? 'keep' : 'remove');
                } else {
                    $prefix = ($literal > 0 ? 'install' : 'don\'t install');
                }

                return $prefix.' '.$package->getPrettyString();
            }
        );
    }

    public function isPackageAcceptable($name, $stability)
    {
        foreach ((array) $name as $n) {
            // allow if package matches the global stability requirement and has no exception
            if (!isset($this->stabilityFlags[$n]) && isset($this->acceptableStabilities[$stability])) {
                return true;
            }

            // allow if package matches the package-specific stability flag
            if (isset($this->stabilityFlags[$n]) && BasePackage::$stabilities[$stability] <= $this->stabilityFlags[$n]) {
                return true;
            }
        }

        return false;
    }

    private function ensurePackageIsLoaded($data)
    {
        if (!is_array($data)) {
            return new FulfilledPromise($data);
        }
        
        if (isset($data['alias_of'])) {
            $packagePromise = $this->packageById($data['alias_of'])
                ->then(function ($aliasOf) use ($data) {
                    return $data['repo']->loadAliasPackage($data, $aliasOf);
                })
                ->then(function ($package) use ($data) {
                    $package->setRootPackageAlias(!empty($data['root_alias']));
                    return $package;
                });
        } else {
            $packagePromise = $data['repo']->loadPackage($data);
        }
        
        return $packagePromise->then(
            function ($package) use ($data) {
                foreach ($package->getNames() as $name) {
                    $this->packageByName[$name][$data['id']] = $package;
                }
                $package->setId($data['id']);
            }
        );
    }

    /**
     * Checks if the package matches the given constraint directly or through
     * provided or replaced packages
     *
     * @param  array|PackageInterface  $candidate
     * @param  string                  $name       Name of the package to be matched
     * @param  LinkConstraintInterface $constraint The constraint to verify
     * @return int                     One of the MATCH* constants of this class or 0 if there is no match
     */
    private function match($candidate, $name, LinkConstraintInterface $constraint = null)
    {
        // handle array packages
        if (is_array($candidate)) {
            $candidateName = $candidate['name'];
            $candidateVersion = $candidate['version'];
            $isDev = $candidate['stability'] === 'dev';
            $isAlias = isset($candidate['alias_of']);
        } else {
            // handle object packages
            $candidateName = $candidate->getName();
            $candidateVersion = $candidate->getVersion();
            $isDev = $candidate->getStability() === 'dev';
            $isAlias = $candidate instanceof AliasPackage;
        }

        if (!$isDev && !$isAlias && isset($this->filterRequires[$name])) {
            $requireFilter = $this->filterRequires[$name];
        } else {
            $requireFilter = new EmptyConstraint;
        }

        if ($candidateName === $name) {
            $pkgConstraint = new VersionConstraint('==', $candidateVersion);

            if ($constraint === null || $constraint->matches($pkgConstraint)) {
                return $requireFilter->matches($pkgConstraint) ? self::MATCH : self::MATCH_FILTERED;
            }

            return self::MATCH_NAME;
        }

        if (is_array($candidate)) {
            $provides = isset($candidate['provide'])
                ? $this->versionParser->parseLinks($candidateName, $candidateVersion, 'provides', $candidate['provide'])
                : array();
            $replaces = isset($candidate['replace'])
                ? $this->versionParser->parseLinks($candidateName, $candidateVersion, 'replaces', $candidate['replace'])
                : array();
        } else {
            $provides = $candidate->getProvides();
            $replaces = $candidate->getReplaces();
        }

        // aliases create multiple replaces/provides for one target so they can not use the shortcut below
        if (isset($replaces[0]) || isset($provides[0])) {
            foreach ($provides as $link) {
                if ($link->getTarget() === $name && ($constraint === null || $constraint->matches($link->getConstraint()))) {
                    return $requireFilter->matches($link->getConstraint()) ? self::MATCH_PROVIDE : self::MATCH_FILTERED;
                }
            }

            foreach ($replaces as $link) {
                if ($link->getTarget() === $name && ($constraint === null || $constraint->matches($link->getConstraint()))) {
                    return $requireFilter->matches($link->getConstraint()) ? self::MATCH_REPLACE : self::MATCH_FILTERED;
                }
            }

            return self::MATCH_NONE;
        }

        if (isset($provides[$name]) && ($constraint === null || $constraint->matches($provides[$name]->getConstraint()))) {
            return $requireFilter->matches($provides[$name]->getConstraint()) ? self::MATCH_PROVIDE : self::MATCH_FILTERED;
        }

        if (isset($replaces[$name]) && ($constraint === null || $constraint->matches($replaces[$name]->getConstraint()))) {
            return $requireFilter->matches($replaces[$name]->getConstraint()) ? self::MATCH_REPLACE : self::MATCH_FILTERED;
        }

        return self::MATCH_NONE;
    }
}
