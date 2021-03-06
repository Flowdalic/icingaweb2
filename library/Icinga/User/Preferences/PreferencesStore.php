<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\User\Preferences;

use Icinga\Application\Config;
use Icinga\User;
use Icinga\User\Preferences;
use Icinga\Data\ResourceFactory;
use Icinga\Exception\ConfigurationError;
use Icinga\Data\Db\DbConnection;

/**
 * Preferences store factory
 *
 * Usage example:
 * <code>
 * <?php
 *
 * use Icinga\Application\Config;
 * use Icinga\User\Preferences;
 * use Icinga\User\Preferences\PreferencesStore;
 *
 * // Create a INI store
 * $store = PreferencesStore::create(
 *     new Config(
 *         'type'       => 'ini',
 *         'config_path' => '/path/to/preferences'
 *     ),
 *     $user // Instance of \Icinga\User
 * );
 *
 * $preferences = new Preferences($store->load());
 * $preferences->aPreference = 'value';
 * $store->save($preferences);
 * </code>
 */
abstract class PreferencesStore
{
    /**
     * Store config
     *
     * @var Config
     */
    protected $config;

    /**
     * Given user
     *
     * @var User
     */
    protected $user;

    /**
     * Create a new store
     *
     * @param   Config     $config     The config for this adapter
     * @param   User       $user       The user to which these preferences belong
     */
    public function __construct(Config $config, User $user)
    {
        $this->config = $config;
        $this->user = $user;
        $this->init();
    }

    /**
     * Getter for the store config
     *
     * @return  Config
     */
    public function getStoreConfig()
    {
        return $this->config;
    }

    /**
     * Getter for the user
     *
     * @return  User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Initialize the store
     */
    abstract protected function init();

    /**
     * Load preferences from source
     *
     * @return  array
     */
    abstract public function load();

    /**
     * Save the given preferences
     *
     * @param   Preferences     $preferences    The preferences to save
     */
    abstract public function save(Preferences $preferences);

    /**
     * Create preferences storage adapter from config
     *
     * @param   Config     $config     The config for the adapter
     * @param   User       $user       The user to which these preferences belong
     *
     * @return  self
     *
     * @throws  ConfigurationError          When the configuration defines an invalid storage type
     */
    public static function create(Config $config, User $user)
    {
        if (($type = $config->type) === null) {
            throw new ConfigurationError(
                'Preferences configuration is missing the type directive'
            );
        }

        $type = ucfirst(strtolower($type));
        $storeClass = 'Icinga\\User\\Preferences\\Store\\' . $type . 'Store';
        if (!class_exists($storeClass)) {
            throw new ConfigurationError(
                'Preferences configuration defines an invalid storage type. Storage type %s not found',
                $type
            );
        }

        if ($type === 'Ini') {
            $config->location = Config::resolvePath('preferences');
        } elseif ($type === 'Db') {
            $config->connection = new DbConnection(ResourceFactory::getResourceConfig($config->resource));
        }

        return new $storeClass($config, $user);
    }
}
