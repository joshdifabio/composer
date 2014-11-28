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

use Composer\Repository\Vcs\GitDriver as BlockingGitDriver;
use React\EventLoop\LoopInterface;
use React\Promise\FulfilledPromise;
use Composer\NonBlocking\Util\ProcessExecutor;
use Composer\NonBlocking\Util\Git as GitUtil;
use Composer\Util\Git as BlockingGitUtil;
use Composer\Util\ProcessExecutor as BlockingProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Cache;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Josh Di Fabio <joshdifabio@gmail.com>
 */
class GitDriver extends BlockingGitDriver implements VcsDriverInterface
{
    protected $cache;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $repoDir;
    protected $infoCache = array();

    /**
     * {@inheritDoc}
     */
    public function initializeNonBlocking(LoopInterface $eventLoop)
    {
        if (Filesystem::isLocalPath($this->url)) {
            $this->repoDir = $this->url;
            $cacheUrl = realpath($this->url);
            $updatePromise = new FulfilledPromise;
        } else {
            $this->repoDir = $this->config->get('cache-vcs-dir') . '/' . preg_replace('{[^a-z0-9.]}i', '-', $this->url) . '/';

            BlockingGitUtil::cleanEnv();

            $fs = new Filesystem();
            $fs->ensureDirectoryExists(dirname($this->repoDir));

            if (!is_writable(dirname($this->repoDir))) {
                throw new \RuntimeException('Can not clone '.$this->url.' to access package information. The "'.dirname($this->repoDir).'" directory is not writable by the current user.');
            }

            if (preg_match('{^ssh://[^@]+@[^:]+:[^0-9]+}', $this->url)) {
                throw new \InvalidArgumentException('The source URL '.$this->url.' is invalid, ssh URLs should have a port number after ":".'."\n".'Use ssh://git@example.com:22/path or just git@example.com:path if you do not want to provide a password or custom port.');
            }

            $processExecutor = new ProcessExecutor($eventLoop);
            $gitUtil = new GitUtil($this->io, $this->config, $fs, $processExecutor, $this->process);

            // update the repo if it is a valid git repository
            if (is_dir($this->repoDir) && 0 === $this->process->execute('git rev-parse --git-dir', $output, $this->repoDir) && trim($output) === '.') {
                $commandCallable = function ($url) {
                    return sprintf('git remote set-url origin %s && git remote update --prune origin', BlockingProcessExecutor::escape($url));
                };
                $io = $this->io;
                $url = $this->url;
                
                $updatePromise = $gitUtil->runCommand($commandCallable, $this->url, $this->repoDir)->then(
                    null,
                    function (\Exception $e) use ($io, $url) {
                        $io->write('<error>Failed to update '.$url.', package information from this repository may be outdated ('.$e->getMessage().')</error>');
                    }
                );
            } else {
                // clean up directory and do a fresh clone into it
                $fs->removeDirectory($this->repoDir);

                $repoDir = $this->repoDir;
                $commandCallable = function ($url) use ($repoDir) {
                    return sprintf('git clone --mirror %s %s', BlockingProcessExecutor::escape($url), BlockingProcessExecutor::escape($repoDir));
                };

                $updatePromise = $gitUtil->runCommand($commandCallable, $this->url, $this->repoDir, true);
            }

            $cacheUrl = $this->url;
        }
        
        $that = $this;
        $io = $this->io;
        $config = $this->config;
        $cache = &$this->cache;
        
        return $updatePromise->then(function () use ($that, &$cache, $io, $config, $cacheUrl) {
            $that->getTags();
            $that->getBranches();
            
            $cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $cacheUrl));
        });
    }
}
