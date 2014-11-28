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

namespace Composer\NonBlocking\Util;

use Composer\Util\Git as BlockingGitUtil;
use Composer\Util\ProcessExecutor as BlockingProcessExecutor;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Josh Di Fabio <jd@amp.co>
 */
class Git
{
    protected $io;
    protected $config;
    protected $filesystem;
    protected $nonBlockingProcess;
    protected $blockingProcess;

    public function __construct(
        IOInterface $io,
        Config $config,
        Filesystem $fs,
        ProcessExecutor $nonBlockingProcess,
        BlockingProcessExecutor $blockingProcess = null
    ) {
        $this->io = $io;
        $this->config = $config;
        $this->filesystem = $fs;
        $this->nonBlockingProcess = $nonBlockingProcess;
        $this->blockingProcess = $blockingProcess ?: new BlockingProcessExecutor;
    }
    
    public function runCommand($commandCallable, $url, $cwd, $initialClone = false)
    {
        try {
            if (preg_match('{^ssh://[^@]+@[^:]+:[^0-9]+}', $url)) {
                throw new \InvalidArgumentException('The source URL '.$url.' is invalid, ssh URLs should have a port number after ":".'."\n".'Use ssh://git@example.com:22/path or just git@example.com:path if you do not want to provide a password or custom port.');
            }
            
            if (!$initialClone) {
                // capture username/password from URL if there is one
                $io = $this->io;
                $lastPromise = $this->nonBlockingProcess->execute('git remote -v', $cwd)->then(
                    function (ProcessResult $result) use ($io) {
                        if (preg_match('{^(?:composer|origin)\s+https?://(.+):(.+)@([^/]+)}im', $result->getStdOut(), $match)) {
                            $io->setAuthentication($match[3], urldecode($match[1]), urldecode($match[2]));
                        }
                    }
                );
            } else {
                $lastPromise = new FulfilledPromise;
            }
            
            $that = $this;
            return $lastPromise->then(function () use ($that, $commandCallable, $url, $cwd, $initialClone) {
                $that->doRunCommand($commandCallable, $url, $cwd, $initialClone);
            });
        } catch (\Exception $e) {
            return new RejectedPromise($e);
        }
    }

    public function doRunCommand($commandCallable, $url, $cwd, $initialClone)
    {
        if ($initialClone) {
            $origCwd = $cwd;
            $cwd = null;
        } else {
            $origCwd = null;
        }

        // public github, autoswitch protocols
        if (preg_match('{^(?:https?|git)://'.BlockingGitUtil::getGitHubDomainsRegex($this->config).'/(.*)}', $url, $match)) {
            return $this->executeGithubCommand($commandCallable, $cwd, $origCwd, $initialClone, $match[1], $match[2]);
        }

        $command = call_user_func($commandCallable, $url);
        $config = $this->config;
        $filesystem = $this->filesystem;
        $that = $this;
        
        return $this->nonBlockingProcess->execute($command, $cwd)->then(
            function (ProcessResult $result) use (
                $config,
                $commandCallable,
                $cwd,
                $url,
                $filesystem,
                $initialClone,
                $origCwd,
                $that,
                $command
            ) {
                if (0 === $result->getExitCode()) {
                    return;
                }
                
                $resultPromise = null;
                
                // private github repository without git access, try https with auth
                if (preg_match('{^git@'.BlockingGitUtil::getGitHubDomainsRegex($config).':(.+?)\.git$}i', $url, $match)) {
                    $resultPromise = $that->executePrivateGithubCommandOverHttps(
                        $match[1],
                        $match[2],
                        $commandCallable,
                        $cwd
                    );
                } elseif ( // private non-github repo that failed to authenticate
                    preg_match('{(https?://)([^/]+)(.*)$}i', $url, $match) &&
                    strpos($result->getStdErr(), 'fatal: Authentication failed') !== false
                ) {
                    $resultPromise = $that->executePrivateNonGithubCommand(
                        $match[1],
                        $match[2],
                        $match[3],
                        $url,
                        $commandCallable,
                        $cwd
                    );
                }
                
                if (!$resultPromise) {
                    $resultPromise = new FulfilledPromise($result);
                }
                
                return $resultPromise->then(
                    function (ProcessResult $result) use ($initialClone, $filesystem, $origCwd, $that, $command, $url) {
                        if (0 === $result->getExitCode()) {
                            return;
                        }
                        
                        if ($initialClone) {
                            $filesystem->removeDirectory($origCwd);
                        }
                        
                        $that->throwException('Failed to execute ' . BlockingGitUtil::sanitizeUrl($command) . "\n\n" . $result->getStdErr(), $url);
                    }
                );
            }
        );
    }
    
    public function executeGithubCommand($commandCallable, $cwd, $origCwd, $initialClone, $domain, $repo)
    {
        $protocols = $this->config->get('github-protocols');
        
        if (!is_array($protocols) || !count($protocols)) {
            throw new \RuntimeException('Config value "github-protocols" must be a non-empty array, got '.gettype($protocols));
        }
        
        $messages = array();
        $lastPromise = new RejectedPromise;
        $filesystem = $this->filesystem;
        
        foreach ($protocols as $protocol) {
            if ('ssh' === $protocol) {
                $url = "git@" . $domain . ":" . $repo;
            } else {
                $url = $protocol ."://" . $domain . "/" . $repo;
            }
            
            $lastPromise->then(null, function () use (
                &$messages,
                $commandCallable,
                $url,
                $cwd,
                $initialClone,
                $origCwd,
                $filesystem
            ) {
                $command = call_user_func($commandCallable, $url);
                
                return $this->nonBlockingProcess->execute($command, $cwd)->then(
                    function (ProcessResult $result) use (&$messages, $initialClone, $filesystem, $origCwd) {
                        if (0 === $result->getExitCode()) {
                            return;
                        }
                        
                        $messages[] = '- ' . $url . "\n" . preg_replace('#^#m', '  ', $result->getStdErr());
                        if ($initialClone) {
                            $filesystem->removeDirectory($origCwd);
                        }
                        
                        throw new \Exception;
                    }
                );
            });
        }
        
        $that = $this;
        return $lastPromise->then(null, function () use ($that, $url, $protocols, &$messages) {
            // failed to checkout, first check git accessibility
            $that->throwException('Failed to clone ' . BlockingGitUtil::sanitizeUrl($url) .' via '.implode(', ', $protocols).' protocols, aborting.' . "\n\n" . implode("\n", $messages), $url);
        });
    }
    
    public function executePrivateGithubCommandOverHttps($domain, $repo, $commandCallable, $cwd)
    {
        if (!$this->io->hasAuthentication($domain)) {
            $gitHubUtil = new GitHub($this->io, $this->config, $this->blockingProcess);
            $message = 'Cloning failed using an ssh key for authentication, enter your GitHub credentials to access private repos';

            if (!$gitHubUtil->authorizeOAuth($domain) && $this->io->isInteractive()) {
                $gitHubUtil->authorizeOAuthInteractively($domain, $message);
            }
        }

        if ($this->io->hasAuthentication($domain)) {
            $auth = $this->io->getAuthentication($domain);
            $url = 'https://'.rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@'.$domain.'/'.$repo.'.git';
            $command = call_user_func($commandCallable, $url);
            
            return $this->nonBlockingProcess->execute($command, $cwd);
        }
    }
    
    public function executePrivateNonGithubCommand($protocol, $domain, $path, $url, $commandCallable, $cwd)
    {
        if (strpos($domain, '@')) {
            list($authParts, $domain) = explode('@', $domain, 2);
        }

        $storeAuth = false;
        if ($this->io->hasAuthentication($domain)) {
            $auth = $this->io->getAuthentication($domain);
        } elseif ($this->io->isInteractive()) {
            $defaultUsername = null;
            if (isset($authParts) && $authParts) {
                if (false !== strpos($authParts, ':')) {
                    list($defaultUsername,) = explode(':', $authParts, 2);
                } else {
                    $defaultUsername = $authParts;
                }
            }

            $this->io->write('    Authentication required (<info>'.parse_url($url, PHP_URL_HOST).'</info>):');
            $auth = array(
                'username'  => $this->io->ask('      Username: ', $defaultUsername),
                'password'  => $this->io->askAndHideAnswer('      Password: '),
            );
            $storeAuth = $this->config->get('store-auths');
        }

        if ($auth) {
            $url = $protocol.rawurlencode($auth['username']).':'.rawurlencode($auth['password']).'@'.$domain.$path;
            $command = call_user_func($commandCallable, $url);
            $io = $this->io;
            $config = $this->config;
            
            return $this->nonBlockingProcess->execute($command, $cwd)->then(
                function (ProcessResult $result) use ($io, $domain, $auth, $config, $storeAuth) {
                    if (0 === $result->getExitCode()) {
                        $io->setAuthentication($domain, $auth['username'], $auth['password']);
                        $authHelper = new AuthHelper($io, $config);
                        $authHelper->storeAuth($domain, $storeAuth);
                    }
                    
                    return $result;
                }
            );
        }
    }

    public function throwException($message, $url)
    {
        if (0 !== $this->blockingProcess->execute('git --version', $ignoredOutput)) {
            throw new \RuntimeException('Failed to clone '.BlockingGitUtil::sanitizeUrl($url).', git was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->blockingProcess->getErrorOutput());
        }

        throw new \RuntimeException($message);
    }
}