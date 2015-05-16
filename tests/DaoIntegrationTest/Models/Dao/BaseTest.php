<?php
namespace Vaffel\DaoIntegrationTest\Models\Dao;

use Elastica\Document;
use Vaffel\Dao\Models\ModelAbstract;
use Vaffel\Dao\Models\Interfaces\Indexable;
use Vaffel\Dao\Models\Interfaces\DateAwareModel;
use Vaffel\Dao\Models\Traits\DateAwareModelTrait;

class TestModel extends ModelAbstract
{
    public $id;
}

class DateAwareTestModel extends ModelAbstract implements DateAwareModel
{
    use DateAwareModelTrait;

    public $id;
}

class IndexableTestModel extends TestModel implements Indexable
{
    public function getIndexableDocument()
    {
        return new Document($this->id, $this->getState());
    }

    public function getIndexName()
    {
        return defined('DAO_ELASTIC_SEARCH_INDEX') ? DAO_ELASTIC_SEARCH_INDEX : 'daoTest';
    }

    public function getIndexType()
    {
        return 'daoTestType';
    }
}

abstract class BaseTest extends \PHPUnit_Framework_TestCase
{
}
