<?php
namespace Vaffel\Dao\Models;

use JsonSerializable;
use Exception;
use ArrayAccess;

abstract class ModelAbstract implements JsonSerializable, ArrayAccess
{

    /**
     * Fields to ignore when saving the model or calling getState()
     *
     * @var array
     */
    protected $ignoreFields = [];

    /**
     * Flag for giving notices when setting properties not defined in model implementation
     *
     * @var bool
     */
    private $disallowUndefinedProperties = false;

    /**
     * Get the ID of this element
     *
     * @return int
     */
    public function getId()
    {
        return (int) $this->id;
    }

    /**
     * @param array optional $state List of object properties
     */
    public function __construct(array $state = [])
    {
        if (is_array($state) && !empty($state)) {
            foreach ($state as $property => $value) {
                $this->{'set' . ucfirst($property)}($value);
            }
        }
    }

    /**
     * Automatically sets and gets internal variables in model
     *
     * @param string $name Non-existent method that's called on the model
     * @param array $arguments Arguments passed to method
     * @throws Exception If no method can be found by this name
     * @return mixed Returns result of either set or get.
     */
    public function __call($name, $arguments)
    {
        $method   = substr($name, 0, 3);
        $variable = substr($name, 3);

        if (!$variable) { // Makes sure it's possible to use ->[s|g]et('name') as well as ->[s|g]etName();
            $variable = $variable ? : $arguments[0];
        }

        if ($method === 'set') {
            $argument = $arguments[0];
            $variable !== $argument ? : $argument = $arguments[1];

            return $this->set($variable, $argument);
        } elseif ($method === 'get') {
            return $this->get($variable);
        }

        throw new Exception('No method was found for this "' . $name . '" call.');
    }

    /**
     * Set method that all setters MUST use
     *
     * If the model does not allow setting undefined properties it will trigger an notice.
     *
     * @param string $name Name of attribute to set
     * @param mixed $value Value of the attribute
     * @return object Instance of this
     */
    protected function set($name, $value)
    {
        $name = lcfirst($name);

        if ($this->disallowUndefinedProperties && !property_exists($this, $name)) {
            trigger_error('Setting undefined property "' . $name . '" on model "' . get_class($this) . '"', E_USER_NOTICE);

            return $this;
        }

        $this->$name = $value;

        return $this;
    }

    /**
     * Get method that all getters MUST use
     *
     * @param string $name Name of attribute to get
     * @return mixed Value of attribute
     */
    protected function get($name)
    {
        $name = lcfirst($name);

        return property_exists($this, $name) ? $this->$name : false;
    }

    /**
     * Makes the instance load the state found in given array
     *
     * @param array $data Associative array consisting state for this object
     * @return object Instance of this
     */
    public function loadState($data)
    {
        foreach ($data as $name => $value) {
            $this->set($name, $value);
        }

        return $this;
    }

    /**
     * Returns the instance state for this object
     *
     * @return array Associative array consisting state for this object
     */
    public function getState()
    {
        $classVars  = get_class_vars(__CLASS__);
        $objectVars = get_object_vars($this);
        $ignore     = array_flip($this->ignoreFields);

        // Remove any variables from this class, leaving only object attributes
        $attributes = array_diff_key($objectVars, $classVars, $ignore);

        return $attributes;
    }

    /**
     * Returns the instance as an array
     *
     * @return array Associative array consisting state for this object
     */
    public function toArray()
    {
        return $this->getState();
    }

    /**
     * Returns a JSON-serializable representation of the model
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->getState();
    }

    /**
     * Set this if you want notices when trying to set properties not defined in the specific model-implementation
     *
     * @param bool $disallow Set to true if you want notice when setting undefined properties, otherwise false
     * @return object Instance of this
     */
    final public function disallowUndefinedProperties($disallow = true)
    {
        $this->disallowUndefinedProperties = $disallow;

        return $this;
    }

    /**
     * Check if offset exists
     *
     * @param  mixed $offset
     * @return boolean
     */
    public function offsetExists($offset)
    {
        return property_exists($this, $offset);
    }

    /**
     * Get value for offset
     *
     * @param  mixed $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * Set value at offset
     * @param  mixed $offset
     * @param  mixed $value
     */
    public function offsetSet($offset, $value)
    {
        $this->set($offset, $value);
    }

    public function offsetUnset($offset)
    {
        // Disallowed, not implemented
    }
}
