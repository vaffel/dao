<?php
namespace Vaffel\DaoTest\Dao;

use Vaffel\Dao\DaoFactory;

class FactoryTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @expectedException Vaffel\Dao\Exceptions\ServiceNotFoundException
     */
    public function testThrowsExceptionOnUnknownService()
    {
        DaoFactory::getServiceLayer('unknown-service');
    }

    /**
     * @covers Vaffel\Dao\DaoFactory::setServiceLayer
     * @covers Vaffel\Dao\DaoFactory::getServiceLayer
     */
    public function testCanSetServiceLayer()
    {
        $service = new \stdClass();

        DaoFactory::setServiceLayer('foo', $service);
        $this->assertSame($service, DaoFactory::getServiceLayer('foo'));

        DaoFactory::setServiceLayer('bar', 'moo');
        $this->assertNotEquals($service, 'moo');
    }

    /**
     * @covers Vaffel\Dao\DaoFactory::setServiceLayer
     * @covers Vaffel\Dao\DaoFactory::getServiceLayer
     */
    public function testWillInstantiateCallables()
    {
        $callable = function () {
            return 'pelican';
        };

        DaoFactory::setServiceLayer('pimp', $callable);
        $this->assertEquals('pelican', DaoFactory::getServiceLayer('pimp'));
    }

    /**
     * @covers Vaffel\Dao\DaoFactory::getDao
     */
    public function testCanGetDaoModel()
    {
        $this->assertInstanceOf('Vaffel\DaoTest\Models\Dao\FakeModel', DaoFactory::getDao('Vaffel\DaoTest\Models\FakeModel'));
    }

    /**
     * @covers Vaffel\Dao\DaoFactory::getDao
     */
    public function testGetDaoReusesInstances()
    {
        $dao  = DaoFactory::getDao('Vaffel\DaoTest\Models\FakeModel');
        $dao2 = DaoFactory::getDao('Vaffel\DaoTest\Models\FakeModel');

        $this->assertSame($dao, $dao2);
    }
}
