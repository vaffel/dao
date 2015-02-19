<?php
namespace Vaffel\DaoTest\Models\Dao;

use ReflectionProperty;
use Vaffel\Dao\Models\Dao\MySQL;
use Vaffel\Dao\Models\ModelAbstract;
use Vaffel\Dao\Models\Interfaces\Indexable;
use Vaffel\Dao\DaoFactory;
use Elastica\Document;

class PdoMock extends \PDO
{
    public function __construct()
    {
    }
}

class ModelMock extends ModelAbstract
{
}

class IndexableModelMock extends ModelMock implements Indexable
{
    private $document;

    public function setDocument($document)
    {
        $this->document = $document;
    }

    public function getIndexableDocument()
    {
        return $this->document ?: false;
    }

    public function getIndexName()
    {
        return 'indexName';
    }

    public function getIndexType()
    {
        return 'typeName';
    }
}

class MySQLTest extends \PHPUnit_Framework_TestCase
{

    private $model;
    private $cacheMock;
    private $roDbMock;
    private $rwDbMock;
    private $elasticMock;

    public function setUp()
    {
        if (!class_exists('Memcached')) {
            return $this->markTestIncomplete('Memcached extension not loaded');
        }

        $this->initDbMocks();
        $this->initMemcacheMock();
        $this->initElasticMock();

        $this->model = $this->getMockForAbstractClass('Vaffel\Dao\Models\Dao\MySQL');
        $this->model->setModelName('Vaffel\DaoTest\Models\Dao\ModelMock');

        DaoFactory::setServiceLayer(DaoFactory::SERVICE_MYSQL, $this->roDbMock, MySQL::DB_TYPE_RO);
        DaoFactory::setServiceLayer(DaoFactory::SERVICE_MYSQL, $this->rwDbMock, MySQL::DB_TYPE_RW);
        DaoFactory::setServiceLayer(DaoFactory::SERVICE_MEMCACHED, $this->cacheMock);
        DaoFactory::setServiceLayer(DaoFactory::SERVICE_ELASTIC_SEARCH, $this->elasticMock);
    }

    public function tearDown()
    {
        $this->roDbMock = null;
        $this->rwDbMock = null;
        $this->cacheMock = null;
        $this->elasticMock = null;
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::__construct
     */
    public function testCanConstructModel()
    {
        $this->assertInstanceOf('Vaffel\Dao\Models\Dao\MySQL', $this->model);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::getDb
     */
    public function testCanGetDbInstance()
    {
        $this->assertTrue(method_exists($this->model->getDb(), 'prepare'));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::getCache
     */
    public function testCanGetCacheInstance()
    {
        $this->assertTrue(method_exists($this->model->getCache(), 'getMulti'));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::getSearchService
     */
    public function testCanGetSearchService()
    {
        $this->assertTrue(method_exists($this->model->getSearchService(), 'getIndex'));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::setModelName
     * @covers Vaffel\Dao\Models\Dao\MySQL::getModelName
     */
    public function testCanSetAndGetModelName()
    {
        $this->model->setModelName('foo');
        $this->assertEquals('foo', $this->model->getModelName());
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetchEntries
     */
    public function testFetchEntriesReturnsEmptyArrayOnEmptyResultSet()
    {
        $query = $this->getQueryMock();

        $ids = [];
        $query
            ->expects($this->once())
            ->method('fetchAll')
            ->will($this->returnValue($ids));

        $this->roDbMock
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT id FROM notSet'))
            ->will($this->returnValue($query));

        $this->assertEmpty($this->model->fetchEntries(15, 30));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetchByIds
     * @covers Vaffel\Dao\Models\Dao\MySQL::prefixKeys
     * @covers Vaffel\Dao\Models\Dao\MySQL::deprefixKeys
     * @covers Vaffel\Dao\Models\Dao\MySQL::createInstances
     * @covers Vaffel\Dao\Models\Dao\MySQL::createInstance
     */
    public function testFetchByIdsDoesNotHitDatabaseIfAllEntriesInCache()
    {
        $ids = [1, 7];

        $this->roDbMock
            ->expects($this->never())
            ->method('prepare');

        $this->rwDbMock
            ->expects($this->never())
            ->method('prepare');

        $cachePrefix = get_class($this->model) . ':';
        $expectedKeys = [$cachePrefix . '1', $cachePrefix . '7'];

        $this->cacheMock
            ->expects($this->once())
            ->method('getMulti')
            ->with($expectedKeys)
            ->will($this->returnValue(array_combine(
                $expectedKeys,
                [['id' => 1], ['id' => 7]]
            )));

        $result = $this->model->fetchByIds($ids);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(7, $result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf('Vaffel\DaoTest\Models\Dao\ModelMock', $result[1]);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetchByIds
     * @covers Vaffel\Dao\Models\Dao\MySQL::prefixKeys
     * @covers Vaffel\Dao\Models\Dao\MySQL::deprefixKeys
     * @covers Vaffel\Dao\Models\Dao\MySQL::createInstances
     * @covers Vaffel\Dao\Models\Dao\MySQL::createInstance
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetchFromDb
     */
    public function testFetchByIdsMergeCacheAndDatabaseResult()
    {
        $ids = [1, 7, 1337];

        // We should not be using the RW mode for fetching
        $this->rwDbMock
            ->expects($this->never())
            ->method('prepare');

        $query = $this->getQueryMock();
        $query
            ->expects($this->once())
            ->method('execute');

        $query
            ->expects($this->at(1))
            ->method('fetch')
            ->will($this->returnValue([
                'id' => 7,
                'fromDb' => true,
            ]));

        $query
            ->expects($this->at(2))
            ->method('fetch')
            ->will($this->returnValue([
                'id' => 1337,
                'fromDb' => true,
            ]));

        $this->roDbMock
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT * FROM notSet WHERE id IN'))
            ->will($this->returnValue($query));

        $cachePrefix = get_class($this->model) . ':';
        $expectedKeys = [$cachePrefix . '1', $cachePrefix . '7', $cachePrefix . '1337'];

        $this->cacheMock
            ->expects($this->once())
            ->method('getMulti')
            ->with($expectedKeys)
            ->will($this->returnValue([
                ($cachePrefix . '1') => ['id' => 1, 'fromDb' => false],
            ]));

        $result = $this->model->fetchByIds($ids);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(1337, $result);
        $this->assertCount(3, $result);
        $this->assertTrue($result[7]->fromDb);
        $this->assertTrue($result[1337]->fromDb);
        $this->assertFalse($result[1]->fromDb);
        $this->assertInstanceOf('Vaffel\DaoTest\Models\Dao\ModelMock', $result[1]);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetchByIds
     * @covers Vaffel\Dao\Models\Dao\MySQL::prefixKeys
     * @covers Vaffel\Dao\Models\Dao\MySQL::deprefixKeys
     * @covers Vaffel\Dao\Models\Dao\MySQL::createInstances
     * @covers Vaffel\Dao\Models\Dao\MySQL::createInstance
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetchFromDb
     */
    public function testFetchByIdsCachesMissingEntries()
    {
        $ids = [7];
        $query = $this->getQueryMock();
        $query
            ->expects($this->at(1))
            ->method('fetch')
            ->will($this->returnValue([
                'id' => 7,
                'fromDb' => true,
            ]));

        $this->roDbMock
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT * FROM notSet WHERE id IN'))
            ->will($this->returnValue($query));

        $cachePrefix = get_class($this->model) . ':';

        $this->cacheMock
            ->expects($this->once())
            ->method('getMulti')
            ->will($this->returnValue([]));

        $this->cacheMock
            ->expects($this->once())
            ->method('setMulti')
            ->with($this->arrayHasKey($cachePrefix . '7'));

        $this->model->fetchByIds($ids);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetchByIds
     * @covers Vaffel\Dao\Models\Dao\MySQL::prefixKeys
     * @covers Vaffel\Dao\Models\Dao\MySQL::deprefixKeys
     */
    public function testFetchByIdsReturnsEmptyArrayOnEmptyArray()
    {
        $result = $this->model->fetchByIds([]);
        $this->assertCount(0, $result);
        $this->assertInternalType('array', $result);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetchByIds
     * @covers Vaffel\Dao\Models\Dao\MySQL::prefixKeys
     * @covers Vaffel\Dao\Models\Dao\MySQL::deprefixKeys
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetchFromDb
     */
    public function testFetchByIdsReturnsEmptyArrayIfEntriesNotFound()
    {
        $query = $this->getQueryMock();
        $query
            ->expects($this->once())
            ->method('fetch')
            ->will($this->returnValue(false));

        $this->roDbMock
            ->expects($this->once())
            ->method('prepare')
            ->will($this->returnValue($query));

        $this->cacheMock
            ->expects($this->once())
            ->method('getMulti')
            ->will($this->returnValue([]));

        $result = $this->model->fetchByIds([15]);
        $this->assertCount(0, $result);
        $this->assertInternalType('array', $result);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::getIdsFromModels
     */
    public function testGetIdsFromModels()
    {
        $models = [new ModelMock(), new ModelMock()];
        $models[0]->loadState(['id' => 5]);
        $models[1]->loadState(['id' => 1337]);

        $ids = $this->model->getIdsFromModels($models);
        $this->assertEquals([5, 1337], $ids);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::getNumberOfEntries
     */
    public function testGetNumberOfEntries()
    {
        $query = $this->getQueryMock();
        $query
            ->expects($this->once())
            ->method('fetch')
            ->will($this->returnValue([150]));

        $this->roDbMock
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT COUNT'))
            ->will($this->returnValue($query));

        $result = $this->model->getNumberOfEntries();
        $this->assertSame(150, $result);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::fetch
     */
    public function testFetchReturnsModel()
    {
        $query = $this->getQueryMock();
        $query
            ->expects($this->at(1))
            ->method('fetch')
            ->will($this->returnValue(['id' => 1337]));

        $this->roDbMock
            ->expects($this->once())
            ->method('prepare')
            ->will($this->returnValue($query));

        $result = $this->model->fetch(1337);
        $this->assertInstanceOf('Vaffel\DaoTest\Models\Dao\ModelMock', $result);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::delete
     */
    public function testDelete()
    {
        $model = new ModelMock();
        $model->loadState(['id' => 1337]);

        $query = $this->getQueryMock();
        $query
            ->expects($this->once())
            ->method('execute')
            ->will($this->returnValue(true));

        $this->rwDbMock
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE FROM'))
            ->will($this->returnValue($query));

        $this->model->delete($model);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::expire
     * @covers Vaffel\Dao\Models\Dao\MySQL::prefixKeys
     */
    public function testExpireHandlesModels()
    {
        $model = new ModelMock();
        $model->loadState(['id' => 1337]);

        $this->cacheMock
            ->expects($this->once())
            ->method('delete')
            ->with($this->stringContains('1337'))
            ->will($this->returnValue(true));

        $this->assertSame(1, $this->model->expire($model));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::expire
     * @covers Vaffel\Dao\Models\Dao\MySQL::prefixKeys
     */
    public function testExpireHandlesSingleId()
    {
        $this->cacheMock
            ->expects($this->once())
            ->method('delete')
            ->with($this->stringContains('1337'))
            ->will($this->returnValue(true));

        $this->assertSame(1, $this->model->expire(1337));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::expire
     * @covers Vaffel\Dao\Models\Dao\MySQL::prefixKeys
     */
    public function testExpireHandlesArrayOfIds()
    {
        $this->cacheMock
            ->expects($this->at(0))
            ->method('delete')
            ->with($this->stringContains('1337'))
            ->will($this->returnValue(true));

        $this->cacheMock
            ->expects($this->at(1))
            ->method('delete')
            ->with($this->stringContains('555'))
            ->will($this->returnValue(true));

        $this->assertSame(2, $this->model->expire([1337, 555]));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::save
     * @covers Vaffel\Dao\Models\Dao\MySQL::getDataType
     */
    public function testSavePopulatesIdFieldOnInsert()
    {
        $model = new ModelMock();
        $model->loadState(['foo' => 'bar', 'numeric' => 1337]);

        $query = $this->getQueryMock();
        $query
            ->expects($this->exactly(2))
            ->method('bindValue');

        $query
            ->expects($this->once())
            ->method('execute')
            ->will($this->returnValue(true));

        $this->rwDbMock
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO notSet'))
            ->will($this->returnValue($query));

        $this->rwDbMock
            ->expects($this->once())
            ->method('lastInsertId')
            ->will($this->returnValue(1337));

        $result = $this->model->save($model);

        $this->assertSame(1337, $model->getId());
        $this->assertTrue($result);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::save
     * @covers Vaffel\Dao\Models\Dao\MySQL::getDataType
     */
    public function testSaveUpdatesOnExistingId()
    {
        $model = new ModelMock();
        $model->loadState(['id' => 1337, 'foo' => 'bar', 'nullField' => null]);

        $query = $this->getQueryMock();
        $query
            ->expects($this->exactly(2))
            ->method('bindValue');

        $query
            ->expects($this->once())
            ->method('execute')
            ->will($this->returnValue(true));

        $this->rwDbMock
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('UPDATE notSet'))
            ->will($this->returnValue($query));

        $result = $this->model->save($model);

        $this->assertSame(1337, $model->getId());
        $this->assertTrue($result);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::save
     * @covers Vaffel\Dao\Models\Dao\MySQL::getDataType
     */
    public function testSaveUsesOnDuplicateKeyUpdateOnNonAutoIncrementModel()
    {
        $autoIncrement = new ReflectionProperty($this->model, 'idFieldAutoIncrements');
        $autoIncrement->setAccessible(true);
        $autoIncrement->setValue($this->model, false);

        $model = new ModelMock();
        $model->loadState(['id' => 1337, 'foo' => 'bar', 'moo' => 'tools']);

        $query = $this->getQueryMock();
        $query
            ->expects($this->exactly(3))
            ->method('bindValue');

        $query
            ->expects($this->once())
            ->method('execute')
            ->will($this->returnValue(true));

        $this->rwDbMock
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('ON DUPLICATE KEY UPDATE'))
            ->will($this->returnValue($query));

        $result = $this->model->save($model);

        $this->assertSame(1337, $model->getId());
        $this->assertTrue($result);
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::indexModel
     */
    public function testIndexModelOnUnindexableModelReturnsFalse()
    {
        $model = new ModelMock();
        $this->assertFalse($this->model->indexModel($model));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::indexModel
     */
    public function testIndexModelReturnsFalseIfDocumentIsFalse()
    {
        $model = new IndexableModelMock();
        $this->assertFalse($this->model->indexModel($model));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::indexModel
     */
    public function testIndexModelReturnsTrueOnIndexingSuccess()
    {
        $model = new IndexableModelMock();
        $model->setDocument(new Document());

        $type = $this->getElasticTypeMock();
        $type
            ->expects($this->once())
            ->method('addDocument')
            ->will($this->returnValue(true));

        $this->assertTrue($this->model->indexModel($model));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::deleteIndexedModel
     */
    public function testDeleteIndexedModelOnUnindexableModelReturnsFalse()
    {
        $model = new ModelMock();
        $this->assertFalse($this->model->deleteIndexedModel($model));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::deleteIndexedModel
     */
    public function testDeleteIndexedModelReturnsFalseIfElasticSearchServiceIsNotSet()
    {
        $model = new IndexableModelMock();
        $model->setDocument(new Document());
        DaoFactory::setServiceLayer(DaoFactory::SERVICE_ELASTIC_SEARCH, false);
        $this->assertFalse($this->model->deleteIndexedModel($model));
    }

    /**
     * @covers Vaffel\Dao\Models\Dao\MySQL::deleteIndexedModel
     */
    public function testDeleteIndexedModelReturnsTrueOnSuccessfulDocumentDelete()
    {
        $model = new IndexableModelMock();
        $model->setDocument(new Document(1337));

        $type = $this->getElasticTypeMock();
        $type
            ->expects($this->once())
            ->method('deleteById')
            ->with(1337)
            ->will($this->returnValue(true));

        $this->assertTrue($this->model->deleteIndexedModel($model));
    }

    private function initDbMocks()
    {
        $this->roDbMock = $this->getMock('PdoMock', get_class_methods('PDO'));
        $this->rwDbMock = $this->getMock('PdoMock', get_class_methods('PDO'));
    }

    private function initMemcacheMock()
    {
        $this->cacheMock = $this->getMock('Memcached', get_class_methods('Memcached'));
    }

    private function initElasticMock()
    {
        $this->elasticMock = ($this->getMockBuilder('Elastica\Client')
            ->disableOriginalConstructor()
            ->getMock());
    }

    private function getElasticTypeMock()
    {
        $type = $this->getMockBuilder('Elastica\Type')->disableOriginalConstructor()->getMock();
        $index = $this->getMockBuilder('Elastica\Index')->disableOriginalConstructor()->getMock();

        $this->elasticMock
            ->expects($this->once())
            ->method('getIndex')
            ->will($this->returnValue($index));

        $index
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue($type));

        return $type;
    }

    private function getQueryMock()
    {
        return $this->getMock('PDOStatement');
    }
}
