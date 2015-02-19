<?php
namespace Vaffel\Dao\Models\Dao;

use PDO;
use DateTime;
use Vaffel\Dao\DaoFactory;
use Vaffel\Dao\Exceptions\DaoException as Exception;
use Vaffel\Dao\Models\ModelAbstract;
use Vaffel\Dao\Models\Interfaces\Indexable;
use Vaffel\Dao\Models\Interfaces\DateAwareModel;

abstract class MySQL
{

    /**
     * Database access types
     *
     * @var string
     */
    const DB_TYPE_RO = 'RO';
    const DB_TYPE_RW = 'RW';

    /**
     * Holds the database adapter instances
     *
     * @var array
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
     * Holds the table name for this model
     *
     * @var string
     */
    protected $tableName = 'notSet';

    /**
     * Wether the id field auto increments (and therefore should not be included in any INSERTs)
     *
     * @var boolean
     */
    protected $idFieldAutoIncrements = true;

    /**
     * Constructor
     *
     */
    public function __construct()
    {
        $this->cacheKey = get_called_class() . ':';
    }

    /**
     * Set the name of the model to use for this DAO instance
     *
     * @param string $name Name of the model to create
     * @return Vaffel\Dao\Models\Dao\MySQL
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
     * Returns database layer
     *
     * @param  string $mode Database mode (RO/RW)
     * @return mixed
     */
    public function getDb($mode = self::DB_TYPE_RO)
    {
        if (!isset($this->dbs[$mode])) {
            $this->dbs[$mode] = DaoFactory::getServiceLayer(DaoFactory::SERVICE_MYSQL, $mode);
        }

        return $this->dbs[$mode];
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
     * Fetch entries from table
     *
     * @param integer $limit Maximum number of entries to fetch
     * @param integer $offset Offset to start at
     * @return array
     */
    public function fetchEntries($limit = 50, $offset = 0)
    {
        $query  = '/* ' . $this->modelName .  '::fetchEntries */ ';
        $query .= 'SELECT ' . $this->idField . ' FROM ' . $this->tableName;
        $query .= ' LIMIT :offset, :limit';

        $stmt = $this->getDb(self::DB_TYPE_RO)->prepare($query);
        $stmt->bindValue('offset', (int) $offset, PDO::PARAM_INT);
        $stmt->bindValue('limit',  (int) $limit,  PDO::PARAM_INT);
        $stmt->execute();

        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return empty($ids) ? [] : $this->fetchByIds($ids);
    }

    /**
     * Fetch one or more IDs from database
     *
     * @param mixed $id Array of IDs or a single ID you want to fetch
     * @return mixed
     */
    protected function fetchFromDb($id)
    {
        // Make sure we have an array consisting of nothing else than ints
        $ids = !is_array($id) ? [(int) $id] : array_map('intval', $id);

        // Build an query based on table name and id field.
        $query  = '/* ' . $this->modelName .  '::fetchFromDb */ ';
        $query .= 'SELECT * FROM ' . $this->tableName . ' WHERE ' . $this->idField;
        $query .= ' IN (' . implode(', ', $ids) . ')';

        $stmt = $this->getDb(self::DB_TYPE_RO)->prepare($query);
        $stmt->execute();

        // Multiple rows
        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[$row[$this->idField]] = $row;
        }

        return $entries;
    }

    /**
     * Takes an object and stores it in an persistent storage
     *
     * @param ModelAbstract $model Model Instance to save
     * @return bool True if successfull, otherwise false
     */
    public function save(ModelAbstract $model)
    {

        // Get state through object state-handler instead of asking directly
        $state = $model->getState();

        // Avoid binding id-field if it auto-increments
        if ($this->idFieldAutoIncrements) {
            unset($state[$this->idField]);
        }

        // Is the model date-aware?
        $dateAware = $model instanceof DateAwareModel;

        // Are we updating?
        $updating = is_numeric($model->get($this->idField));

        // Create an array consisting of column name an bind name
        $fields = [];
        $sets   = [];
        foreach ($state as $name => $value) {
            // We want to use current date if this is a updated/created field
            if ($dateAware && $name == 'updated') {
                $sets[] = $name . ' = UTC_TIMESTAMP()';
                continue;
            } elseif ($dateAware && $name == 'created') {
                continue;
            }

            $fields[$name] = ':' . $name;
            $sets[] = $name . ' = ' . $fields[$name];
        }

        // Set for inserting
        $insertSets = $dateAware ? array_merge($sets, ['created = UTC_TIMESTAMP()']) : $sets;

        // Create query
        if ($updating && $this->idFieldAutoIncrements) {
            // Update
            $query  = '/* ' . $this->modelName .  '::save(update) */ ';
            $query .= 'UPDATE ' . $this->tableName . ' SET ' . implode(', ', $sets);
            $query .= ' WHERE ' . $this->idField . ' = ' . $model->get($this->idField);
        } elseif ($updating) {
            // ID-field is not auto-incrementing, use on duplicate key update
            $query  = '/* ' . $this->modelName .  '::save(upsert) */ ';
            $query .= 'INSERT INTO ' . $this->tableName . ' SET ' . implode(', ', $insertSets);
            $query .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
        } else {
            // Insert
            $query  = '/* ' . $this->modelName .  '::save(insert) */ ';
            $query .= 'INSERT INTO ' . $this->tableName . ' SET ' . implode(', ', $insertSets);
        }

        // Prepare an PDO statement
        $stmt = $this->getDb(self::DB_TYPE_RW)->prepare($query);

        // Bind all values
        foreach ($fields as $name => $bind) {
            $stmt->bindValue($bind, $state[$name], $this->getDataType($state[$name]));
        }

        // Save object
        $result = $stmt->execute();

        if ($result) {
            // Set objects new id if it was an insert
            if (!$updating) {
                $model->set(
                    $this->idField,
                    $this->getDb(self::DB_TYPE_RW)->lastInsertId()
                );
            }

            // If the model is date-aware, set the modified/created timestamps
            if ($dateAware) {
                $timestamp = (new DateTime('@' . time()))->format('Y-m-d H:i:s');
                $model->set('created', $timestamp);
                $model->set('updated', $timestamp);
            }

            // Expire cache
            $this->expire($model->get($this->idField));

            $this->indexModel($model);
        } else {
            $error = $stmt->errorInfo();
            throw new Exception(implode(' ', $error));
        }

        return $result;
    }

    /**
     * Deletes an object
     *
     * @param ModelAbstract $model Model instance to delete
     * @return bool True if successfull, otherwise false
     */
    public function delete(ModelAbstract $model)
    {
        // Build query
        $query  = '/* ' . $this->modelName .  '::delete */ ';
        $query .= 'DELETE FROM ' . $this->tableName;
        $query .= ' WHERE ' . $this->idField . ' = ?';

        $id = (int) $model->get($this->idField);

        // Execute query
        $stmt = $this->getDb(self::DB_TYPE_RW)->prepare($query);
        $result = $stmt->execute([$id]);

        // Expire cache
        $this->expire($id);

        // Delete from search-index
        $this->deleteIndexedModel($model);

        return $result;
    }

    /**
     * Returns the number of entries in the table
     *
     * @return integer
     */
    public function getNumberOfEntries()
    {
        $query  = '/* ' . $this->modelName .  '::getNumberOfEntries */ ';
        $query .= 'SELECT COUNT(1) FROM ' . $this->tableName;
        $stmt  = $this->getDb(self::DB_TYPE_RO)->prepare($query);
        $stmt->execute();

        return (int) $stmt->fetch(PDO::FETCH_NUM)[0];
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
     * Get data type for a variable, for use in PDO binding
     *
     * @param mixed $var
     * @return integer
     */
    protected function getDataType($var)
    {
        if (is_null($var)) {
            return PDO::PARAM_NULL;
        } elseif (is_int($var)) {
            return PDO::PARAM_INT;
        }

        return PDO::PARAM_STR;
    }
}
