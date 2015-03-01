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

namespace Composer\Repository\Vcs;

use Composer\Cache;
use Composer\Config;
use Composer\Json\JsonFile;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Util\Svn as SvnUtil;
use Composer\IO\IOInterface;
use Composer\Downloader\TransportException;
use FutureSVN\FutureCommit;
use FutureSVN\Repository\FutureNodeCollection;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Till Klampaeckel <till@php.net>
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class SvnDriver extends VcsDriver
{
    /**
     * @var Cache
     */
    protected $cache;
    protected $baseUrl;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $infoCache = array();

    protected $trunkPath    = 'trunk';
    protected $branchesPath = 'branches';
    protected $tagsPath     = 'tags';
    protected $packagePath   = '';
    protected $cacheCredentials = true;

    /**
     * @var \Composer\Util\Svn
     */
    private $util;
    private $repository;
    private $lastCommitToTrunk;
    private $branchDirs;
    private $tagDirs;
    private $lastCommitTimes = array();
    private $composerFileContents = array();

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->url = $this->baseUrl = rtrim(self::normalizeUrl($this->url), '/');

        SvnUtil::cleanEnv();

        if (isset($this->repoConfig['trunk-path'])) {
            $this->trunkPath = $this->repoConfig['trunk-path'];
        }
        if (isset($this->repoConfig['branches-path'])) {
            $this->branchesPath = $this->repoConfig['branches-path'];
        }
        if (isset($this->repoConfig['tags-path'])) {
            $this->tagsPath = $this->repoConfig['tags-path'];
        }
        if (array_key_exists('svn-cache-credentials', $this->repoConfig)) {
            $this->cacheCredentials = (bool) $this->repoConfig['svn-cache-credentials'];
        }
        if (isset($this->repoConfig['package-path'])) {
            $this->packagePath = '/' . trim($this->repoConfig['package-path'], '/');
        }

        if (false !== ($pos = strrpos($this->url, '/' . $this->trunkPath))) {
            $this->baseUrl = substr($this->url, 0, $pos);
        }

        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->baseUrl));
        
        $this->util = new SvnUtil($this->baseUrl, $this->io, $this->config, $this->process);
        $this->util->setCacheCredentials($this->cacheCredentials);
        $this->repository = $this->util->getRepository();
        
        $this->getLastCommitToTrunk();
        $this->getBranchDirs();
        $this->getTagDirs();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        $this->getLastCommitToTrunk()->wait();
        
        return $this->rootIdentifier ?: $this->trunkPath;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        return array('type' => 'svn', 'url' => $this->baseUrl, 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        return null;
    }
    
    public function downloadComposerFile($identifier)
    {
        $identifier = '/' . trim($identifier, '/') . '/';

        if (!$this->cache->read($identifier.'.json') && !isset($this->infoCache[$identifier])) {
            if (!isset($this->composerFileContents[$identifier])) {
                preg_match('{^(.+?)(@\d+)?/$}', $identifier, $match);
                if (!empty($match[2])) {
                    $path = $match[1];
                    $rev = $match[2];
                } else {
                    $path = $identifier;
                    $rev = null;
                }

                $resource = $path . 'composer.json';
                $this->composerFileContents[$identifier] = $this->repository->getFile($resource, $rev)
                    ->getContents();
            }
        
            return $this->composerFileContents[$identifier];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        $normalizedId = '/' . trim($identifier, '/') . '/';

        if ($res = $this->cache->read($normalizedId.'.json')) {
            $this->infoCache[$normalizedId] = JsonFile::parseJson($res);
        }

        if (!isset($this->infoCache[$normalizedId])) {
            preg_match('{^(.+?)(@\d+)?/$}', $normalizedId, $match);
            if (!empty($match[2])) {
                $path = $match[1];
                $rev = $match[2];
            } else {
                $path = $normalizedId;
                $rev = '';
            }

            try {
                $output = $this->downloadComposerFile($identifier)->getStreamContents();
                if (!trim($output)) {
                    return;
                }
            } catch (\RuntimeException $e) {
                throw new TransportException($e->getMessage());
            }

            $composer = JsonFile::parseJson($output, $this->baseUrl . $path.'composer.json' . $rev);

            if (empty($composer['time'])) {
                if (array_key_exists($identifier, $this->lastCommitTimes)) {
                    $timeOfLastCommit = $this->lastCommitTimes[$identifier];
                } else {
                    $timeOfLastCommit = $this->repository->getDirectory($path, $rev ?: null)
                        ->getLastCommit()
                            ->getDate();
                }

                if ($timeOfLastCommit) {
                    $composer['time'] = $timeOfLastCommit->format('Y-m-d H:i:s');
                }
            }

            $this->cache->write($normalizedId.'.json', json_encode($composer));
            $this->infoCache[$normalizedId] = $composer;
        }

        return $this->infoCache[$normalizedId];
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        if (null === $this->tags) {
            $this->tags = array();
            
            foreach ($this->getTagDirs() as $dir) {
                /* @var $dir \FutureSVN\Repository\Directory */
                $identifier = $this->buildIdentifier($dir->getPath(), $dir->getRevision());
                $this->tags[$dir->getName()] = $identifier;
                $this->lastCommitTimes[$identifier] = $dir->getLastCommit()->getDate();
            }
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            $this->branches = array();
            
            $lastCommitToTrunk = $this->getLastCommitToTrunk();
            if ($lastCommitToTrunk->getRevision()) {
                $this->branches['trunk'] = $this->buildIdentifier(
                    '/' . $this->trunkPath,
                    $lastCommitToTrunk->getRevision()
                );
                $this->rootIdentifier = $this->branches['trunk'];
                $this->lastCommitTimes[$this->rootIdentifier] = $lastCommitToTrunk->getDate();
            }
            
            foreach ($this->getBranchDirs() as $dir) {
                /* @var $dir \FutureSVN\Repository\Directory */
                $identifier = $this->buildIdentifier($dir->getPath(), $dir->getRevision());
                $this->branches[$dir->getName()] = $identifier;
                $this->lastCommitTimes[$identifier] = $dir->getLastCommit()->getDate();
            }
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        $url = self::normalizeUrl($url);
        if (preg_match('#(^svn://|^svn\+ssh://|svn\.)#i', $url)) {
            return true;
        }

        // proceed with deep check for local urls since they are fast to process
        if (!$deep && !Filesystem::isLocalPath($url)) {
            return false;
        }

        $processExecutor = new ProcessExecutor();

        $exit = $processExecutor->execute(
            "svn info --non-interactive {$url}",
            $ignoredOutput
        );

        if ($exit === 0) {
            // This is definitely a Subversion repository.
            return true;
        }

        if (false !== stripos($processExecutor->getErrorOutput(), 'authorization failed:')) {
            // This is likely a remote Subversion repository that requires
            // authentication. We will handle actual authentication later.
            return true;
        }

        return false;
    }
    
    /**
     * @return \FutureSVN\FutureCommit
     */
    private function getLastCommitToTrunk()
    {
        if (!$this->lastCommitToTrunk) {
            $trunkPath = (string)$this->trunkPath;
            $commit = $this->repository->getDirectory($trunkPath)->getLastCommit();
            $that = $this;
            $rootIdentifier = &$this->rootIdentifier;
            $commit->then(function (FutureCommit $commit) use ($that, $trunkPath, &$rootIdentifier) {
                if ($rev = $commit->getRevision()) {
                    $rootIdentifier = $that->buildIdentifier(
                        '/' . $trunkPath,
                        $commit->getRevision()
                    );
                    $that->downloadComposerFile($rootIdentifier);
                }
            });
            $this->lastCommitToTrunk = $commit;
        }
        
        return $this->lastCommitToTrunk;
    }
    
    /**
     * @return array|\FutureSVN\Repository\FutureNodeCollection
     */
    private function getBranchDirs()
    {
        if (is_null($this->branchDirs)) {
            $this->branchDirs = $this->loadPackageDirs($this->branchesPath);
        }
        
        return $this->branchDirs;
    }

    /**
     * @return array|\FutureSVN\Repository\FutureNodeCollection
     */
    private function getTagDirs()
    {
        if (is_null($this->tagDirs)) {
            $this->tagDirs = $this->loadPackageDirs($this->tagsPath);
        }
        
        return $this->tagDirs;
    }
    
    private function loadPackageDirs($path)
    {
        if (false === $path) {
            return array();
        }
        
        $collection = $this->repository->getDirectory($path)->getDirectories();
        
        $that = $this;
        $collection->then(function (FutureNodeCollection $dirs) use ($that) {
            foreach ($dirs as $dir) {
                /* @var $dir \FutureSVN\Repository\Directory */
                if ($rev = $dir->getRevision()) {
                    $identifier = $that->buildIdentifier($dir->getPath(), $rev);
                    $that->downloadComposerFile($identifier);
                }
            }
        });
        
        return $collection;
    }
    
    /**
     * An absolute path (leading '/') is converted to a file:// url.
     *
     * @param string $url
     *
     * @return string
     */
    protected static function normalizeUrl($url)
    {
        $fs = new Filesystem();
        if ($fs->isAbsolutePath($url)) {
            return 'file://' . strtr($url, '\\', '/');
        }

        return $url;
    }

    /**
     * Build the identifier respecting "package-path" config option
     *
     * @param string $baseDir  The path to trunk/branch/tag
     * @param int    $revision The revision mark to add to identifier
     *
     * @return string
     */
    public function buildIdentifier($baseDir, $revision)
    {
        return rtrim($baseDir, '/') . $this->packagePath . '/@' . $revision;
    }
}
