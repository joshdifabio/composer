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

use Composer\Cache;
use Composer\Json\JsonFile;
use Composer\Util\Svn as BlockingSvnUtil;
use Composer\NonBlocking\Util\Svn as SvnUtil;
use Composer\Repository\Vcs\SvnDriver as BlockingSvnDriver;
use React\EventLoop\LoopInterface;
use React\Promise\When;
use React\Promise\FulfilledPromise;
use Composer\Downloader\TransportException;

/**
 * @author Josh Di Fabio <jd@amp.co>
 */
class SvnDriver extends BlockingSvnDriver implements VcsDriverInterface
{
    protected $eventLoop;
    protected $nonBlockingUtil;
    
    /**
     * {@inheritDoc}
     */
    public function initializeNonBlocking(LoopInterface $eventLoop)
    {
        $this->url = $this->baseUrl = rtrim(self::normalizeUrl($this->url), '/');

        BlockingSvnUtil::cleanEnv();

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
        $this->eventLoop = $eventLoop;
        
        return When::all(array(
            $this->initBranches(),
            $this->initTags(),
        ));
    }
    
    public function initBranches()
    {
        $this->branches = array();

        if (false === $this->trunkPath) {
            $trunkParent = $this->baseUrl . '/';
        } else {
            $trunkParent = $this->baseUrl . '/' . $this->trunkPath;
        }

        $promises = array();
        
        $promises[] = $this->executeNonBlocking('svn ls --verbose', $trunkParent)
            ->then(array($this, 'processTrunk'));
        
        if ($this->branchesPath !== false) {
            $url = $this->baseUrl . '/' . $this->branchesPath;
            $promises[] = $this->executeNonBlocking('svn ls --verbose', $url)
                ->then(array($this, 'processBranches'));
        }
        
        return When::all($promises);
    }
    
    public function initTags()
    {
        $this->tags = array();

        if ($this->tagsPath === false) {
            return new FulfilledPromise;
        }
        
        $url = $this->baseUrl . '/' . $this->tagsPath;
        
        return $this->executeNonBlocking('svn ls --verbose', $url)
            ->then(array($this, 'processTags'));
    }
    
    public function getComposerInformation($identifier)
    {
        $identifier = '/' . trim($identifier, '/') . '/';
        
        if (!isset($this->infoCache[$identifier])) {
            return parent::getComposerInformation($identifier);
        }
        
        if ($this->infoCache[$identifier] instanceof \Exception) {
            throw $this->infoCache[$identifier];
        }
        
        return $this->infoCache[$identifier];
    }
    
    public function initComposerInformation($identifier)
    {
        $identifier = '/' . trim($identifier, '/') . '/';
        
        if ($res = $this->cache->read($identifier.'.json')) {
            $this->infoCache[$identifier] = JsonFile::parseJson($res);
        }
        
        if (isset($this->infoCache[$identifier])) {
            return new FulfilledPromise;
        }
        
        preg_match('{^(.+?)(@\d+)?/$}', $identifier, $match);
        if (!empty($match[2])) {
            $path = $match[1];
            $rev = $match[2];
        } else {
            $path = $identifier;
            $rev = '';
        }
        
        $baseUrl = $this->baseUrl;
        $process = $this->process;
        $cache = $this->cache;
        $infoCache = &$this->infoCache;
        $resource = $path . 'composer.json';
        $that = $this;
        
        return $this->executeNonBlocking('svn cat', $baseUrl . $resource . $rev)
            ->then(
                function ($output) use ($baseUrl, $resource, $path, $rev, $process, $that) {
                    if (!trim($output)) {
                        return false;
                    }

                    $composer = JsonFile::parseJson($output, $baseUrl . $resource . $rev);

                    if (!isset($composer['time'])) {
                        return $that->executeNonBlocking('svn info', $baseUrl . $path . $rev)->then(
                            function ($output) use ($process, $composer) {
                                foreach ($process->splitLines($output) as $line) {
                                    if ($line && preg_match('{^Last Changed Date: ([^(]+)}', $line, $match)) {
                                        $date = new \DateTime($match[1], new \DateTimeZone('UTC'));
                                        $composer['time'] = $date->format('Y-m-d H:i:s');
                                        break;
                                    }
                                }

                                return $composer;
                            }
                        );
                    }

                    return $composer;
                },
                function (\Exception $e) {
                    if (strstr($e->getMessage(), '160013')) {
                        return false;
                    }
                    if ($e instanceof \RuntimeException) {
                        $e = new TransportException($e->getMessage());
                    }
                    throw $e;
                }
            )
            ->then(
                function ($result) use ($identifier, $cache, &$infoCache) {
                    $cache->write($identifier . '.json', json_encode($result));
                    $infoCache[$identifier] = $result;
                },
                function (\Exception $e) use ($identifier, &$infoCache) {
                    $infoCache[$identifier] = $e;
                }
            );
    }
    
    public function processTrunk($svnOutput)
    {
        if ($svnOutput) {
            foreach ($this->process->splitLines($svnOutput) as $line) {
                $line = trim($line);
                if ($line && preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                    if (isset($match[1]) && isset($match[2]) && $match[2] === './') {
                        $this->branches['trunk'] = $this->buildIdentifier(
                            '/' . $this->trunkPath,
                            $match[1]
                        );
                        
                        $this->rootIdentifier = $this->branches['trunk'];
                        
                        return $this->initComposerInformation($this->rootIdentifier);
                    }
                }
            }
        }
        
        return new FulfilledPromise;
    }
    
    public function processBranches($svnOutput)
    {
        if (!$svnOutput) {
            return new FulfilledPromise;
        }
        
        $promises = array();
        
        foreach ($this->process->splitLines(trim($svnOutput)) as $line) {
            $line = trim($line);
            if ($line && preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                if (isset($match[1]) && isset($match[2]) && $match[2] !== './') {
                    $identifier = $this->branches[rtrim($match[2], '/')] = $this->buildIdentifier(
                        '/' . $this->branchesPath . '/' . $match[2],
                        $match[1]
                    );
                    
                    $promises[] = $this->initComposerInformation($identifier);
                }
            }
        }
        
        return When::all($promises);
    }
    
    public function processTags($svnOutput)
    {
        if (!$svnOutput) {
            return;
        }
        
        $promises = array();
        
        foreach ($this->process->splitLines($svnOutput) as $line) {
            $line = trim($line);
            if ($line && preg_match('{^\s*(\S+).*?(\S+)\s*$}', $line, $match)) {
                if (isset($match[1]) && isset($match[2]) && $match[2] !== './') {
                    $identifier = $this->tags[rtrim($match[2], '/')] = $this->buildIdentifier(
                        '/' . $this->tagsPath . '/' . $match[2],
                        $match[1]
                    );
                    
                    $promises[] = $this->initComposerInformation($identifier);
                }
            }
        }
        
        return When::all($promises);
    }
    
    public function executeNonBlocking($command, $url)
    {
        if (null === $this->nonBlockingUtil) {
            $this->nonBlockingUtil = new SvnUtil(
                $this->eventLoop,
                $this->baseUrl,
                $this->io,
                $this->config
            );
            
            $this->nonBlockingUtil->setCacheCredentials($this->cacheCredentials);
        }

        try {
            return $this->nonBlockingUtil->execute($command, $url);
        } catch (\RuntimeException $e) {
            if (0 !== $this->process->execute('svn --version', $ignoredOutput)) {
                throw new \RuntimeException('Failed to load '.$this->url.', svn was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput());
            }

            throw new \RuntimeException(
                'Repository '.$this->url.' could not be processed, '.$e->getMessage()
            );
        }
    }
}
