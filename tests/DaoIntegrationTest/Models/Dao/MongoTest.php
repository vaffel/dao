<?php
namespace Vaffel\DaoIntegrationTest\Models\Dao;

use Memcached;
use MongoId;
use MongoClient;
use ReflectionProperty;
use Vaffel\Dao\Models\Dao\Mongo;
use Vaffel\Dao\Models\Interfaces\Indexable;
use Vaffel\Dao\Models\Interfaces\DateAwareModel;
use Vaffel\Dao\DaoFactory;
use Elastica\Client as ElasticaClient;
use Elastica\Document;

class MongoDaoTestModel extends Mongo
{
    protected $modelName = 'Vaffel\DaoIntegrationTest\Models\Dao\TestModel';
    protected $collectionName = 'daoTests';
}

class MongoTest extends BaseTest
{
    private $daoModel;

    public function setUp()
    {
        if (!class_exists('Memcached')) {
            return $this->markTestIncomplete('Memcached extension not loaded');
        }

        if (!class_exists('MongoClient')) {
            return $this->markTestIncomplete('MongoDB extension not loaded');
        }

        $constants = [
            'DAO_MONGO_HOST',
            'DAO_MONGO_PORT',
            'DAO_MONGO_NAME',

            'DAO_ELASTIC_SEARCH_HOST',
            'DAO_ELASTIC_SEARCH_PORT',
            'DAO_ELASTIC_SEARCH_INDEX',

            'DAO_MEMCACHED_HOST',
            'DAO_MEMCACHED_PORT',
        ];

        foreach ($constants as $constant) {
            if (!defined($constant)) {
                return $this->markTestIncomplete('Missing value for constant: "' . $constant . '" - check phpunit.xml');
            }
        }

        if (!DAO_MONGO_NAME) {
            return $this->markTestIncomplete('Missing MongoDB database name. Constant: DAO_MONGO_NAME');
        }

        $mongo = new MongoClient(sprintf(
            'mongodb://%s:%s',
            DAO_MONGO_HOST,
            DAO_MONGO_PORT
        ));

        // Select database
        $mongo = $mongo->{DAO_MONGO_NAME};

        $memcached = new Memcached();
        $memcached->addServer(DAO_MEMCACHED_HOST, DAO_MEMCACHED_PORT);

        $elastic = new ElasticaClient([
            'host' => DAO_ELASTIC_SEARCH_HOST,
            'port' => DAO_ELASTIC_SEARCH_PORT,
        ]);

        DaoFactory::setServiceLayer(DaoFactory::SERVICE_MONGO, $mongo, Mongo::DB_TYPE_RW);
        DaoFactory::setServiceLayer(DaoFactory::SERVICE_MEMCACHED, $memcached);
        DaoFactory::setServiceLayer(DaoFactory::SERVICE_ELASTIC_SEARCH, $elastic);

        $this->daoModel = new MongoDaoTestModel();
        $this->daoModel->setModelname('Vaffel\DaoIntegrationTest\Models\Dao\TestModel');

        $this->daoDateAwareModel = new MongoDaoTestModel();
        $this->daoDateAwareModel->setModelname('Vaffel\DaoIntegrationTest\Models\Dao\DateAwareTestModel');
    }

    public function tearDown()
    {
        if (!$this->daoModel) {
            return;
        }

        $db = DaoFactory::getServiceLayer(DaoFactory::SERVICE_MONGO, Mongo::DB_TYPE_RW);
        $db->drop();

        $cache = DaoFactory::getServiceLayer(DaoFactory::SERVICE_MEMCACHED);
        $cache->flush();

        $index = $this->getElasticIndex();
        try {
            $index->delete();
        } catch (\Exception $e) {
        }

        $this->daoModel = null;
    }

    public function testCanFetchDataNotSavedThroughDaofff()
    {
        $db = DaoFactory::getServiceLayer(DaoFactory::SERVICE_MONGO, Mongo::DB_TYPE_RW);

        $db->daoTests->insert([
            '_id' => new MongoId('000000000000000000001337'),
            'strField' => 'moo'
        ]);

        $model = $this->daoModel->fetch('000000000000000000001337');
        $this->assertSame('moo', $model->getStrField());
    }

    public function testCanInsertNewEntries()
    {
        $model = new MongoTestModel();

        $model->setStrField('value');
        $model->setIntField(1337);
        $model->setNullField(null);

        $this->daoModel->save($model);

        $this->assertGreaterThan(0, strlen($model->getId()));
        $entries = $this->daoModel->fetchEntries();
        $this->assertCount(1, $entries);
    }

    public function testCanUpdateEntry()
    {
        $model = new MongoTestModel();
        $model->setStrField('foobar');
        $this->daoModel->save($model);

        $prevId = $model->getId();
        $this->assertGreaterThan(0, strlen($model->getId()));

        $model->setStrField('Something');
        $this->daoModel->save($model);

        $newId = $model->getId();
        $this->assertSame($prevId, $newId);

        $entries = $this->daoModel->fetchEntries();
        $this->assertCount(1, $entries);
    }

    public function testCanFetchSingleModel()
    {
        $model = new MongoTestModel();
        $model->setStrField('foobar');
        $this->daoModel->save($model);

        $fetchedModel =  $this->daoModel->fetch($model->getId());
        $this->assertSame('foobar', $fetchedModel->getStrField());
    }

    public function testCanFetchMultipleModelsByIds()
    {
        $ids = [];

        for ($i = 0; $i < 2; $i++) {
            $model = new MongoTestModel();
            $model->setStrField('foobar');
            $this->daoModel->save($model);

            $ids[] = (string) $model->getId();
        }

        $fetchedModels = $this->daoModel->fetchByIds($ids);

        $this->assertEquals($ids, array_keys($fetchedModels));
    }

    public function testCanFetchSingleModelUsingFetchByIds()
    {
        $model = new MongoTestModel();
        $model->setStrField('foobar');
        $this->daoModel->save($model);

        $fetchedModel = $this->daoModel->fetchByIds($model->getId());

        $this->assertEquals(
            (string) $fetchedModel->getId(),
            (string) $model->getId()
        );
    }

    public function testCanCountCorrectNumberOfEntries()
    {
        $this->assertSame(0, $this->daoModel->getNumberOfEntries());

        $model = new TestModel();
        $model->setStrField('foobar');
        $this->daoModel->save($model);

        $this->assertSame(1, $this->daoModel->getNumberOfEntries());
    }

    public function testCanDeleteModel()
    {
        $model = new TestModel();
        $model->setStrField('foobar');

        // Check that we're starting with empty collection
        $this->assertCount(0, $this->daoModel->fetchEntries());

        // Save and check that the item count has increased
        $this->daoModel->save($model);
        $this->assertCount(1, $this->daoModel->fetchEntries());

        // Delete the item and check that the count has decreased
        $this->daoModel->delete($model);
        $this->assertCount(0, $this->daoModel->fetchEntries());
    }
}
