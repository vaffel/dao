<?php
namespace Vaffel\Dao\Models\Traits;

use DateTime;

/**
 * Provides created/updated along with getters that converts to DateTime instances
 * Note: Models should also implement the DateAwareModel-interface
 */
trait DateAwareModelTrait
{

    /**
     * Date/time of creation
     *
     * @var string
     */
    public $created;

    /**
     * Date/time of last modification date
     *
     * @var string
     */
    public $updated;

    /**
     * Get date of creation
     *
     * @return DateTime|null
     */
    public function getCreated()
    {
        return $this->created ? DateTime::createFromFormat('Y-m-d H:i:s', $this->created) : null;
    }

    /**
     * Get date of last update
     *
     * @return DateTime|null
     */
    public function getUpdated()
    {
        return $this->updated ? DateTime::createFromFormat('Y-m-d H:i:s', $this->updated) : null;
    }
}
