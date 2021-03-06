<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Authentication;

use Iterator;
use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Exception\ConfigurationError;

/**
 * Iterate user backends created from config
 */
class AuthChain implements Iterator
{
    /**
     * User backends configuration
     *
     * @var Config
     */
    private $config;

    /**
     * The consecutive user backend while looping
     *
     * @var UserBackend
     */
    private $currentBackend;

    /**
     * Create a new authentication chain from config
     *
     * @param Config $config User backends configuration
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Rewind the chain
     */
    public function rewind()
    {
        $this->config->rewind();
        $this->currentBackend = null;
    }

    /**
     * Return the current user backend
     *
     * @return UserBackend
     */
    public function current()
    {
        return $this->currentBackend;
    }

    /**
     * Return the key of the current user backend config
     *
     * @return string
     */
    public function key()
    {
        return $this->config->key();
    }

    /**
     * Move forward to the next user backend config
     */
    public function next()
    {
        $this->config->next();
    }

    /**
     * Check if the current user backend is valid, i.e. it's enabled and the config's valid
     *
     * @return bool
     */
    public function valid()
    {
        if (!$this->config->valid()) {
            // Stop when there are no more backends to check
            return false;
        }
        $backendConfig = $this->config->current();
        if ((bool) $backendConfig->get('disabled', false) === true) {
            $this->next();
            return $this->valid();
        }
        try {
            $name = $this->key();
            $backend = UserBackend::create($name, $backendConfig);
        } catch (ConfigurationError $e) {
            Logger::error(
                new ConfigurationError(
                    'Cannot create authentication backend "%s". An exception was thrown:',
                    $name,
                    $e
                )
            );
            $this->next();
            return $this->valid();
        }
        $this->currentBackend = $backend;
        return true;
    }
}
