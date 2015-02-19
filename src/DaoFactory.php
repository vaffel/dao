<?php
namespace Vaffel\Dao;

use Vaffel\Dao\Exceptions\ServiceNotFoundException;

class DaoFactory
{
    /**
     * Service layer types
     *
     * @var string
     */
    const SERVICE_MYSQL          = 'mysql';
    const SERVICE_MEMCACHED      = 'memcached';
    const SERVICE_ELASTIC_SEARCH = 'elastic';

    /**
     * Array of DAO-instances created
     *
     * @var array
     */
    private static $instances = [];

    /**
     * Array of service instances created
     *
     * @var array
     */
    private static $services = [];

    /**
     * Create or retrieve a service instance based on requested type
     *
     * @param string $type Type of service to return
     * @param string $mode Optional mode of service (RO/RW for database, etc)
     * @return object Instance of service layer
     */
    public static function getServiceLayer($type, $mode = '')
    {
        $type = $type . $mode;

        if (!isset(self::$services[$type])) {
            throw new ServiceNotFoundException('Service of type "' . $type . '" not found');
        } elseif (is_callable(self::$services[$type])) {
            $service = self::$services[$type];
            self::$services[$type] = $service();
        }

        return self::$services[$type];
    }

    /**
     * Set the service layer instance for the given type
     *
     * @param string $type  Use Factory::SERVICE_* constants
     * @param mixed  $db    Service instance or a callable factory-method
     * @param string $mode  Optional mode of service (RO/RW for database, etc)
     */
    public static function setServiceLayer($type, $service, $mode = '')
    {
        $type = $type . $mode;

        self::$services[$type] = $service;
    }

    /**
     * Create an new DAO model based on requested model type
     *
     * @param string  $model Type of model to create DAO for
     * @param array   $options An array of options to pass on to the Dao class
     * @return object Instance of DAO for the model
     */
    public static function getDao($model, $options = [])
    {
        if (!isset(self::$instances[$model])) {
            $dao = substr_replace($model, '\\Dao\\', strrpos($model, '\\'), 1);

            $instance = new $dao($options);
            $instance->setModelName($model);

            self::$instances[$model] = $instance;
        }

        return self::$instances[$model];
    }
}
