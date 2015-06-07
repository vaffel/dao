<?php
namespace Vaffel\Dao\Models\Dao;

use MongoId;
use MongoException;
use Vaffel\Dao\DaoFactory;
use Vaffel\Dao\Models\ModelAbstract;

abstract class Mongo extends DaoAbstract
{
    /**
     * Holds the database type to use for this DAO-model
     *
     * @var string
     */
    protected $dbType = DaoFactory::SERVICE_MONGO;

    /**
     * Holds the collection name for this model (for use with MongoDB)
     *
     * @var string
     */
    protected $collectionName = 'notSet';

    /**
     * Holds the actual collection instance
     *
     * @var MongoCollection
     */
    protected $collection;

    /**
     * Holds the primary key field, used for automatic lookup and retrieval of ids
     *
     * @var string
     */
    protected $idField   = '_id';

    /**
     * Constructor
     *
     * @param string $model Name of the Model to create
     * @param object $cache Instance of cache-layer to use
     */
    public function __construct($cache = null)
    {
        parent::__construct($cache);

        $this->collection = $this->getCollection();
    }

    /**
     * Fetch entries from collection
     *
     * @param integer $limit Maximum number of entries to fetch
     * @param integer $offset Offset to start at
     * @return array
     */
    public function fetchEntries($limit = 50, $offset = 0)
    {
        $cursor = $this->collection->find()->skip($offset)->limit($limit);
        $data   = iterator_to_array($cursor, ($this->idField == '_id'));

        $instances = $this->createInstances($data);
        return $instances;
    }

    /**
     * Fetch a given ID
     *
     * @param string|MongoId $id
     * @return Club_Model
     */
    public function fetch($id)
    {
        if (!($id instanceof MongoId)) {
            $id = new MongoId($id);
        }

        $data = $this->collection->findOne([$this->idField => $id]);
        return $this->createInstance($data);
    }

    /**
     * @see Vaffel\Dao\Models\Dao\Abstract::fetchByIds()
     */
    public function fetchByIds($ids)
    {
        return $this->fetchFromDb($ids);
    }

    /**
     * Create MongoId-instances from an array of strings
     *
     * @param array $ids Array of strings
     * @return array
     */
    protected function createMongoIds($ids)
    {
        foreach ($ids as &$id) {
            $id = ($id instanceof MongoId) ? $id : new MongoId($id);
        }

        return $ids;
    }

    /**
     * Fetch one or more IDs from database
     *
     * @param mixed $id Array of IDs or a single ID you want to fetch
     * @return mixed
     */
    protected function fetchFromDb($id)
    {
        if (!is_array($id)) {
            return $this->fetch($id);
        }

        $ids    = $this->createMongoIds($id);
        $cursor = $this->collection->find([$this->idField => ['$in' => $ids]]);

        return $this->createInstances($cursor);
    }

    /**
     * Takes an object and stores it in an persistent storage
     *
     * @param ModelAbstract $object Model Instance to save
     * @return bool True if successfull, otherwise false
     */
    public function save(ModelAbstract $object)
    {
        // Get state through object state-handler instead of asking directly
        $state = $object->getState();

        // Is this an insert or an update?
        $update = (bool) $object->get($this->idField);

        // Make sure we use the $set operator when updating,
        // so we don't overwrite ignored fields
        if ($update) {
            // Don't update the ID field
            $id = $state[$this->idField];
            unset($state[$this->idField]);

            // Update the collection
            $state = array('$set' => $state);
            $result = $this->collection->update(array(
                $this->idField => $id
            ), $state);
        } else {
            // Perform actual insertion
            try {
                $result = $this->collection->insert($state, array(
                    'w' => 1
                ));
            } catch (MongoException $e) {
                trigger_error($e->getMessage(), E_USER_WARNING);
                trigger_error('Failed to save model to collection "' . $this->collectionName . '"', E_USER_WARNING);
                trigger_error(var_export($state, true), E_USER_WARNING);

                return false;
            }
        }

        // If this was an insert operation, set ID field back to object
        if (!$update) {
            $object->set($this->idField, $state[$this->idField]);
        }

        $this->indexModel($object);

        return $result;
    }

    /**
     * Deletes an object
     *
     * @param ModelAbstract $object Model instance to delete
     * @return bool True if successfull, otherwise false
     */
    public function delete(ModelAbstract $object)
    {
        $this->deleteIndexedModel($object);

        $id = $object->get($this->idField);
        $id = ($id instanceof MongoId) ? $id : new MongoId($id);

        return $this->collection->remove(array($this->idField => $id));
    }

    /**
     * Returns the number of entries in the collection
     *
     * @return integer
     */
    public function getNumberOfEntries()
    {
        return $this->collection->count();
    }

    /**
     * Returns the collection specified in the model declaration
     *
     * @return MongoCollection
     */
    public function getCollection()
    {
        return $this->getDb(self::DB_TYPE_RW)->{$this->collectionName};
    }
}
