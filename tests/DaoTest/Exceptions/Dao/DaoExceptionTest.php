<?php
namespace Vaffel\DaoTest\Exceptions\Dao;

use Vaffel\Dao\Exceptions\DaoException;

class DaoExceptionTest extends \PHPUnit_Framework_TestCase
{

    public function testIsException()
    {
        $exception = new DaoException();
        $this->assertInstanceOf('Exception', $exception);
    }
}
