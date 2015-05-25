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
}
