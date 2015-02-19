<?php
namespace Vaffel\DaoTest\Exceptions\Dao;

use Vaffel\Dao\Exceptions\ServiceNotFoundException;

class ServiceNotFoundExceptionTest extends \PHPUnit_Framework_TestCase
{

    public function testIsException()
    {
        $exception = new ServiceNotFoundException();
        $this->assertInstanceOf('Exception', $exception);
    }
}
