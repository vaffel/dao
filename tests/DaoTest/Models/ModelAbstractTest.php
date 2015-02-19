<?php
namespace Vaffel\DaoTest\Models;

use Vaffel\Dao\Models\ModelAbstract;

class ModelAbstractTest extends \PHPUnit_Framework_TestCase
{
    private $model;

    public function setUp()
    {
        $this->model = $this->getMockForAbstractClass('Vaffel\Dao\Models\ModelAbstract');
    }

    public function tearDown()
    {
        $this->model = null;
    }

    public function testCanSetAndGetUndefinedProperties()
    {
        $this->assertSame($this->model, $this->model->setFoo('bar'));
        $this->assertSame('bar', $this->model->getFoo());
    }

    public function testCanAndGetDynamic()
    {
        $prop = 'foo';
        $this->assertSame($this->model, $this->model->set($prop, 'bar'));
        $this->assertSame('bar', $this->model->get($prop));
    }

    /**
     * @expectedException Exception
     */
    public function testThrowsExceptionOnUnknownFunctionInvokation()
    {
        $this->model->somethingElse();
    }

    public function testCanLoadStateFromArray()
    {
        $data = ['foo' => 'bar', 'numeric' => 123, 'nullify' => null];

        $this->assertSame($this->model, $this->model->loadState($data));

        foreach ($data as $key => $value) {
            $this->assertSame($value, $this->model->get($key));
        }
    }

    public function testCanGetStateFromModel()
    {
        $this->model->such  = 'rick';
        $this->model->much  = 'roll';
        $this->model->amaze = true;
        $this->model->wow   = 15;

        $state = $this->model->getState();
        $this->assertSame($this->model->such, $state['such']);
        $this->assertSame($this->model->much, $state['much']);
        $this->assertSame($this->model->amaze, $state['amaze']);
        $this->assertSame($this->model->wow, $state['wow']);
    }

    public function testToArrayAliasesGetState()
    {
        $this->model->setFoo('bar');
        $this->assertSame($this->model->getState(), $this->model->toArray());
    }

    public function testCanSetDisallowUndefinedPropertiesFlag()
    {
        $this->model->disallowUndefinedProperties();

        @$this->model->setSomething('yes');
        $this->assertFalse($this->model->getSomething());

        $this->model->disallowUndefinedProperties(false);

        @$this->model->setSomething('yes');
        $this->assertSame('yes', $this->model->getSomething());
    }

    public function testJsonSerializeWorks()
    {
        $this->model->setFoo('bar');
        $this->assertSame('{"foo":"bar"}', json_encode($this->model));
    }

    public function testCanGetUsingArrayAccess()
    {
        $this->model->setSomething('bar');
        $this->assertSame('bar', $this->model['something']);
    }

    public function testCanSetUsingArrayAccess()
    {
        $this->model['foo'] = 'bar';
        $this->assertSame('bar', $this->model->getFoo());
    }
}
