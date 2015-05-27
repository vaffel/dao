<?php
namespace Vaffel\Dao\Models\Dao;

use Vaffel\Dao\DaoFactory;
use Vaffel\Dao\Exceptions\DaoException as Exception;
use Vaffel\Dao\Models\ModelAbstract;
use Vaffel\Dao\Models\Interfaces\Indexable;

abstract class DaoAbstract
{
    /**
     * Database access types
     *
     * @var string
     */
    const DB_TYPE_RO = 'RO';
    const DB_TYPE_RW = 'RW';

    /**
     * Holds the database type to use for this DAO-model
     *
     * @var string
     */
    protected $dbType = DaoFactory::SERVICE_MYSQL;

    /**
     * Holds the database adapter instances
     *
     * @var object[]
     */
    protected $dbs = [];

    /**
     * Holds the cache layer instance
     *
     * @var Memcached
     */
    protected $cache = null;

    /**
     * Holds the search service layer instance
     *
     * @var Elastica\Client
     */
    protected $searchService = null;

    /**
     * Holds a cachekey prefix for use in all cache keys within model
     *
     * @var string
     */
    protected $cacheKey = '';

    /**
     * Holds the name of the model to be constructed
     *
     * @var string
     */
    protected $modelName = '';

    /**
     * Holds the primary key field, used for automatic lookup and retrieval of ids
     *
     * @var string
     */
    protected $idField   = 'id';

    /**
     * Constructor
     *
     * @param array $options An array of options
     */
    public function __construct($options = [])
    {
        $this->cacheKey = get_called_class() . ':';
    }

    /**
     * Set cache service layer to use for the model
     *
     * @param Memcached $service
     */
    public function setCacheService($service = null)
    {
        $this->cache = $service;
        $this->cacheKey = get_called_class() . ':';
    }

    /**
     * Set cache service layer to use for the model
     *
     * @param Elastica_Client $service
     */
    public function setSearchService($service = null)
    {
        $this->searchClient = $service;
    }

    /**
     * Returns search service layer
     *
     * @return mixed
     */
    public function getSearchService()
    {
        if (is_null($this->searchService)) {
            $this->searchService = DaoFactory::getServiceLayer(DaoFactory::SERVICE_ELASTIC_SEARCH);
        }

        return $this->searchService;
    }

    /**
     * Returns cache layer
     *
     * @return mixed
     */
    public function getCache()
    {
        if (is_null($this->cache)) {
            $this->cache = DaoFactory::getServiceLayer(DaoFactory::SERVICE_MEMCACHED);
        }

        return $this->cache;
    }

    /**
     * Returns database layer
     *
     * @param  string $mode Database mode (RO/RW)
     * @return mixed
     */
    public function getDb($mode = self::DB_TYPE_RO)
    {
        if (!isset($this->dbs[$mode])) {
            $this->dbs[$mode] = DaoFactory::getServiceLayer($this->dbType, $mode);
        }

        return $this->dbs[$mode];
    }

    /**
     * Set the name of the model to use for this DAO instance
     *
     * @param string $name Name of the model to create
     * @return Vaffel\Dao\Models\Dao\DaoAbstract
     */
    public function setModelName($name)
    {
        $this->modelName = $name;

        return $this;
    }

    /**
     * Get the name of the model to instantiate
     *
     * @return string
     */
    public function getModelName()
    {
        return $this->modelName;
    }

    /**
     * Prefix keys for cache purposes
     *
     * @param array $keys Array of IDs to prefix
     * @return array Array of keys with prefix
     */
    protected function prefixKeys($keys)
    {
        array_walk($keys, function (&$value, $key, $prefix) {
            $value = $prefix . $value;
        }, $this->cacheKey);

        return $keys;
    }

    /**
     * Remove prefix from keys
     *
     * @param array $keys Array of IDs to remove prefix on
     * @return array Array of keys without prefix
     */
    protected function deprefixKeys($keys)
    {
        array_walk($keys, function (&$value, $key, $prefix) {
            $value = str_replace($prefix, '', $value);
        }, $this->cacheKey);

        return $keys;
    }

    /**
     * Creates instances of each element
     *
     * @param array $elements Arrays containing data for this model
     * @return array Array of instances
     */
    protected function createInstances($elements)
    {
        $objects = [];

        foreach ($elements as $key => $element) {
            $object = $this->createInstance($element);
            $objects[$key] = $object;
        }

        return $objects;
    }

    /**
     * Create instance of element
     *
     * @param array $element Data for this model
     * @return Model
     */
    protected function createInstance($element)
    {
        $object = new $this->modelName();
        $object->loadState($element);

        return $object;
    }

    /**
     * From a set of models with links to this model, pick the related IDs
     * (IE: CarBrand::getIdsFromModels($cars) would return all the linked carBrandId's)
     *
     * @param  array $models
     * @return array
     */
    public function getIdsFromModels($models)
    {
        $ids = [];
        foreach ($models as $model) {
            $ids[] = $model->get($this->idField);
        }

        return array_unique($ids);
    }

    /**
     * Fetch one specific element
     *
     * @param int $id ID of element to retrieve
     * @return object|boolean Instance of model with data or false if it does not exist
     */
    public function fetch($id)
    {
        return current($this->fetchByIds([(int) $id]));
    }

    /**
     * Fetch a set of items with the given keys from storage
     *
     * @param array $ids The IDs to retrieve elements for.
     * @return array Array with ID as key, value is instance of model object.
     */
    public function fetchByIds($ids)
    {
        if (empty($ids)) {
            return [];
        }

        // Prefix keys
        $keys = $this->prefixKeys($ids);

        $result = $this->getCache()->getMulti($keys) ?: [];

        // Find the missing keys (if any)...
        $diff = array_diff($keys, array_keys($result));

        if (!empty($diff)) {
            // Remove cache prefix
            $diff = $this->deprefixKeys($diff);

            // Fetch missing items from database
            $data = $this->fetchFromDb($diff);

            if (!empty($data)) {
                // Store in cache
                $missing = $this->prefixKeys(array_keys($data));
                $missing = array_combine($missing, $data);
                $this->getCache()->setMulti($missing);

                // Combine DB results with cache results
                $result = array_merge($result, $missing);
            }
        }

        // Don't combine anything if we have an empty set, return it as-is
        if (empty($result)) {
            return $result;
        }

        // Remove the prefix keys and return the entire set
        $sorted = [];
        foreach ($ids as $id) {
            $key = $this->cacheKey . $id;
            if (isset($result[$key])) {
                $sorted[$id] = $result[$key];
            }
        }

        // Create instances of the elements
        return $this->createInstances($sorted);
    }

    /**
     * Expire a set of items with the given IDs
     *
     * @param int|array $ids Array with several IDs or a single one.
     * @return int Number of items expired
     */
    public function expire($ids)
    {
        // Make sure we have an array
        if ($ids instanceof ModelAbstract) {
            $ids = [$ids->getId()];
        } elseif (!is_array($ids)) {
            $ids = [$ids];
        }

        // Prefix keys
        $keys = $this->prefixKeys($ids);

        // Loop and expire
        $expired = 0;
        foreach ($keys as $key) {
            if ($this->getCache()->delete($key)) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * Add or update a document in ElasticSearch
     *
     * @param ModelAbstract $model
     * @return bool
     */
    public function indexModel(ModelAbstract $model)
    {
        if (!$model instanceof Indexable) {
            return false;
        }

        $client    = $this->getSearchService();
        $document  = $model->getIndexableDocument();
        if (!$client || !$document) {
            return false;
        }

        try {
            $client->getIndex($model->getIndexName())
                   ->getType($model->getIndexType())
                   ->addDocument($document);
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
            trigger_error('Failed to add document from model "' . get_class($model) . '" to ElasticSearch (Index: ' . $model->getIndexName() . ', type: ' . $model->getIndexType() . ')', E_USER_WARNING);

            return false;
        }

        return true;
    }

    /**
     * Remove a document from ElasticSearch
     *
     * @param ModelAbstract $model
     * @return bool
     */
    public function deleteIndexedModel(ModelAbstract $model)
    {
        if (!$model instanceof Indexable) {
            return false;
        }

        $client = $this->getSearchService();
        if (!$client) {
            return false;
        }

        try {
            $document = $model->getIndexableDocument();
            $client->getIndex($model->getIndexName())
                   ->getType($model->getIndexType())
                   ->deleteById($document ? $document->getId() : $model->getId());
        } catch (Exception $e) {
            trigger_error('Failed to remove document from model "' . get_class($model) . '" from ElasticSearch (Index: ' . $model->getIndexName() . ', type: ' . $model->getIndexType() . ')', E_USER_WARNING);
            trigger_error($e->getMessage(), E_USER_WARNING);

            return false;
        }

        return true;
    }

    /**
     * Fetch entries from table
     *
     * @param integer $limit Maximum number of entries to fetch
     * @param integer $offset Offset to start at
     * @return array
     */
    abstract public function fetchEntries($limit = 50, $offset = 0);

    /**
     * Fetch one or more IDs from database
     *
     * @param mixed $id Array of IDs or a single ID you want to fetch
     * @return mixed
     */
    abstract protected function fetchFromDb($id);

    /**
     * Takes an object and stores it in an persistent storage
     *
     * @param ModelAbstract $object Model Instance to save
     * @return bool True if successfull, otherwise false
     */
    abstract public function save(ModelAbstract $object);

    /**
     * Deletes an object
     *
     * @param ModelAbstract $object Model instance to delete
     * @return bool True if successfull, otherwise false
     */
    abstract public function delete(ModelAbstract $object);

    /**
     * Returns the number of entries in the table/collection
     *
     * @return integer
     */
    abstract public function getNumberOfEntries();
}
