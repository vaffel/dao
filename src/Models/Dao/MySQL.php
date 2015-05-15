<?php
namespace Vaffel\Dao\Models\Dao;

use PDO;
use DateTime;
use Vaffel\Dao\DaoFactory;
use Vaffel\Dao\Exceptions\DaoException as Exception;
use Vaffel\Dao\Models\ModelAbstract;
use Vaffel\Dao\Models\Interfaces\DateAwareModel;

abstract class MySQL extends DaoAbstract
{
    /**
     * Holds the database type to use for this DAO-model
     *
     * @var string
     */
    protected $dbType = DaoFactory::SERVICE_MYSQL;

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
