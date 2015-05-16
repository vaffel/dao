<?php
namespace Vaffel\DaoIntegrationTest\Models\Dao;

use Memcached;
use PDO;
use ReflectionProperty;
use Vaffel\Dao\Models\Dao\MySQL;
use Vaffel\Dao\Models\ModelAbstract;
use Vaffel\Dao\Models\Interfaces\Indexable;
use Vaffel\Dao\Models\Interfaces\DateAwareModel;
use Vaffel\Dao\Models\Traits\DateAwareModelTrait;
use Vaffel\Dao\DaoFactory;
use Elastica\Client as ElasticaClient;
use Elastica\Document;

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

class DaoTestModel extends MySQL
{
    protected $modelName = 'Vaffel\DaoIntegrationTest\Models\Dao\TestModel';
    protected $tableName = 'daoTests';
}

class MySQLTest extends \PHPUnit_Framework_TestCase
{

    private $daoModel;

    public function setUp()
    {
        if (!class_exists('Memcached')) {
            return $this->markTestIncomplete('Memcached extension not loaded');
        }

        if (!class_exists('PDO')) {
            return $this->markTestIncomplete('PDO extension not loaded');
        }

        $constants = [
            'DAO_MYSQL_HOST',
            'DAO_MYSQL_PORT',
            'DAO_MYSQL_NAME',

            'DAO_MYSQL_RO_USER',
            'DAO_MYSQL_RO_PASS',

            'DAO_MYSQL_RW_USER',
            'DAO_MYSQL_RW_PASS',

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

        $roDb = new PDO(
            $this->getDsn(),
            DAO_MYSQL_RO_USER,
            DAO_MYSQL_RO_PASS
        );

        $rwDb = new PDO(
            $this->getDsn(),
            DAO_MYSQL_RW_USER,
            DAO_MYSQL_RW_PASS
        );

        $memcached = new Memcached();
        $memcached->addServer(DAO_MEMCACHED_HOST, DAO_MEMCACHED_PORT);

        $elastic = new ElasticaClient([
            'host' => DAO_ELASTIC_SEARCH_HOST,
            'port' => DAO_ELASTIC_SEARCH_PORT,
        ]);

        $stmt = $rwDb->prepare(implode(PHP_EOL, [
            'CREATE TABLE daoTests (',
            '   `id` int(10) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,',
            '   `strField` char(128) COLLATE utf8_danish_ci,',
            '   `intField` int(10) unsigned,',
            '   `nullField` char(8) DEFAULT NULL,',
            '   `created` DATETIME NOT NULL,',
            '   `updated` DATETIME NOT NULL',
            ') CHARSET=utf8',
        ]))->execute();

        DaoFactory::setServiceLayer(DaoFactory::SERVICE_MYSQL, $roDb, MySQL::DB_TYPE_RO);
        DaoFactory::setServiceLayer(DaoFactory::SERVICE_MYSQL, $rwDb, MySQL::DB_TYPE_RW);
        DaoFactory::setServiceLayer(DaoFactory::SERVICE_MEMCACHED, $memcached);
        DaoFactory::setServiceLayer(DaoFactory::SERVICE_ELASTIC_SEARCH, $elastic);

        $this->daoModel = new DaoTestModel();
        $this->daoModel->setModelname('Vaffel\DaoIntegrationTest\Models\Dao\TestModel');

        $this->daoDateAwareModel = new DaoTestModel();
        $this->daoDateAwareModel->setModelname('Vaffel\DaoIntegrationTest\Models\Dao\DateAwareTestModel');
    }

    public function tearDown()
    {
        if (!$this->daoModel) {
            return;
        }

        $db = DaoFactory::getServiceLayer(DaoFactory::SERVICE_MYSQL, MySQL::DB_TYPE_RW);
        $db->prepare('DROP TABLE daoTests')->execute();

        $cache = DaoFactory::getServiceLayer(DaoFactory::SERVICE_MEMCACHED);
        $cache->flush();

        $index = $this->getElasticIndex();
        try {
            $index->delete();
        } catch (\Exception $e) {
        }

        $this->daoModel = null;
    }

    public function testCanFetchDataNotSavedThroughDao()
    {
        $rwDb = DaoFactory::getServiceLayer(DaoFactory::SERVICE_MYSQL, MySQL::DB_TYPE_RW);
        $rwDb->exec('INSERT INTO daoTests (id, strField) VALUES (1337, "moo")');

        $model = $this->daoModel->fetch(1337);
        $this->assertSame('moo', $model->getStrField());
    }

    public function testCanInsertNewEntries()
    {
        $model = new TestModel();
        $model->setStrField('value');
        $model->setIntField(1337);
        $model->setNullField(null);

        $this->daoModel->save($model);

        $this->assertGreaterThan(0, $model->getId());

        $entries = $this->daoModel->fetchEntries();
        $this->assertCount(1, $entries);
    }

    public function testCanUpdateEntry()
    {
        $model = new TestModel();
        $model->setStrField('foobar');
        $this->daoModel->save($model);

        $prevId = $model->getId();
        $this->assertGreaterThan(0, $model->getId());

        $model->setStrField('Something');
        $this->daoModel->save($model);

        $newId = $model->getId();
        $this->assertSame($prevId, $newId);

        $entries = $this->daoModel->fetchEntries();
        $this->assertCount(1, $entries);
    }

    public function testCanUpdateOnDuplicateKey()
    {
        $prop = new ReflectionProperty($this->daoModel, 'idFieldAutoIncrements');
        $prop->setAccessible(true);
        $prop->setValue($this->daoModel, false);

        $model = new TestModel();
        $model->setStrField('foobar');
        $this->daoModel->save($model);
        $prevId = $model->getId();

        $model->setStrField('Something');
        $this->daoModel->save($model);

        $this->assertSame($prevId, $model->getId());

        $entries = $this->daoModel->fetchEntries();
        $this->assertCount(1, $entries);

        $prop->setValue($this->daoModel, true);
    }

    public function testCreatedAndUpdatedIsSetOnDateAwareModel()
    {
        $prop = new ReflectionProperty($this->daoModel, 'idFieldAutoIncrements');
        $prop->setAccessible(true);
        $prop->setValue($this->daoDateAwareModel, false);

        $id = 13371337;

        $model = new DateAwareTestModel();
        $model->setId($id);

        $this->assertTrue($this->daoDateAwareModel->save($model));

        $model = $this->daoDateAwareModel->fetch($id);

        $this->assertInstanceOf('DateTime', $model->getCreated());
        $this->assertInstanceOf('DateTime', $model->getUpdated());

        $this->assertLessThan(10, $model->getCreated()->diff(new \DateTime(), true)->format('%s'));
        $this->assertLessThan(10, $model->getUpdated()->diff(new \DateTime(), true)->format('%s'));
    }

    public function testCreatedAndUpdatedIsSetOnDateAwareModelInstanceAfterSave()
    {
        $model = new DateAwareTestModel();
        $this->assertTrue($this->daoDateAwareModel->save($model));
        $this->assertInstanceOf('DateTime', $model->getCreated());
        $this->assertInstanceOf('DateTime', $model->getUpdated());

        $this->assertLessThan(10, $model->getCreated()->diff(new \DateTime(), true)->format('%s'));
        $this->assertLessThan(10, $model->getUpdated()->diff(new \DateTime(), true)->format('%s'));
    }

    public function testUpdatedFieldIsUpdatedOnDateAwareModel()
    {
        $model = new DateAwareTestModel();

        $this->assertTrue($this->daoDateAwareModel->save($model));
        $prevId = $model->getId();

        $storedModel = $this->daoDateAwareModel->fetch($prevId);

        $this->assertInstanceOf('DateTime', $storedModel->getCreated());
        $this->assertEquals($storedModel->getCreated(), $storedModel->getUpdated());
        $this->assertLessThan(10, $storedModel->getCreated()->diff(new \DateTime(), true)->format('%s'));

        sleep(2);

        $this->daoDateAwareModel->save($model);
        $updatedModel = $this->daoDateAwareModel->fetch($prevId);

        $this->assertGreaterThan(0, $updatedModel->getUpdated()->diff($updatedModel->getCreated())->format('%s'));
    }

    public function testCanDeleteModel()
    {
        $model = new TestModel();
        $model->setStrField('foobar');

        $this->assertTrue($this->daoModel->save($model));
        $this->assertCount(1, $this->daoModel->fetchEntries());

        $this->daoModel->delete($model);
        $this->assertCount(0, $this->daoModel->fetchEntries());
    }

    public function testCanCountCorrectNumberOfEntries()
    {
        $this->assertSame(0, $this->daoModel->getNumberOfEntries());

        $model = new TestModel();
        $model->setStrField('foobar');
        $this->daoModel->save($model);

        $this->assertSame(1, $this->daoModel->getNumberOfEntries());
    }

    public function testCanFetchSingleModel()
    {
        $model = new TestModel();
        $model->setStrField('foobar');
        $this->daoModel->save($model);

        $fetchedModel =  $this->daoModel->fetch($model->getId());
        $this->assertSame('foobar', $fetchedModel->getStrField());
    }

    public function testAddsDocumentToElasticSearchOnSave()
    {
        $model = new IndexableTestModel();
        $model->setStrField('foo');

        $this->daoModel->save($model);
        sleep(1);
        $this->assertSame(1, $this->getElasticIndex()->count());
    }

    public function testCanDeleteIndexedModelFromElasticSearch()
    {
        $model = new IndexableTestModel();
        $model->setStrField('foo');

        $this->daoModel->save($model);
        $this->getElasticIndex()->flush();
        $this->assertSame(1, $this->getElasticIndex()->count());

        $this->daoModel->deleteIndexedModel($model);
        $this->getElasticIndex()->flush();
        $this->assertSame(0, $this->getElasticIndex()->count());
    }

    private function getElasticIndex()
    {
        $indexName = defined('DAO_ELASTIC_SEARCH_INDEX') ? DAO_ELASTIC_SEARCH_INDEX : 'daoTest';
        $elastic = DaoFactory::getServiceLayer(DaoFactory::SERVICE_ELASTIC_SEARCH);

        return $elastic->getIndex($indexName);
    }

    private function getDsn()
    {
        $dsn  = 'mysql:host=' . DAO_MYSQL_HOST . ';port=' . DAO_MYSQL_PORT;
        $dsn .= ';dbname=' . DAO_MYSQL_NAME . ';';

        return $dsn;
    }
}
