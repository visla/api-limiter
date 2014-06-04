<?php

require_once '../ApiLimiter.php';

/**
 * Description of ApiLimiterTest
 */
class ApiLimiterTest extends PHPUnit_Framework_TestCase
{

    protected $ipArray = array(
            '127.0.0.1',
            '192.168.0.1',
            '192.168.0.2',
            '192.168.0.3'
        );

    /**
     * Called before each test.
     */
    public function setUp()
    {
        parent::setUp();
    }

    /**
     * Called after each test.
     */
    public function tearDown()
    {
         // Clear the keys.
        $ApiLimiter = \AxiomCoders\ApiLimiter::getInstance();
        $ApiLimiter->getMemcached()->delete($ApiLimiter->getKey('functionTest1', '', '127.0.0.1'));
        $ApiLimiter->getMemcached()->delete($ApiLimiter->getSourcesCountKey('functionTest1', ''));

        $ApiLimiter->getMemcached()->delete($ApiLimiter->getSourcesCountKey('functionTest2', ''));
        foreach($this->ipArray as $ip)
        {
            $ApiLimiter->getMemcached()->delete($ApiLimiter->getKey('functionTest2', '', $ip));
        }
        
        parent::tearDown();
    }

    public function mockApiLimiter()
    {
        $ApiLimiter = $this->getMock('\AxiomCoders\ApiLimiter', array('getClientIP'));
        $ApiLimiter->expects($this->any())
                ->method('getClientIP')
                ->will($this->returnValue("127.0.0.1"));

        \AxiomCoders\ApiLimiter::setInstance($ApiLimiter);
    }

    /**
     * Test checkLimit function with invalid function name.
     * Test required memcached extension.
     */
    public function testCheckLimitInvalidFunction()
    {
        // Mock getHeader.
        $this->mockApiLimiter();

        $ApiLimiter = \AxiomCoders\ApiLimiter::getInstance();
        $ApiLimiter->loadOptionsJSON(dirname(__FILE__) . '/' . 'options.json');
        
        // Test invalid function name.
        $this->assertEquals($ApiLimiter->checkLimit('wrongFunctionName'), \AxiomCoders\ApiLimiter::STATUS_FUNCTION_NOT_FOUND);
        $this->assertEquals($ApiLimiter->checkLimit(''), \AxiomCoders\ApiLimiter::STATUS_FUNCTION_NOT_FOUND);
    }

    /**
     * Test check limit the normal way.
     */
    public function testCheckLimit()
    {
        // Mock so our IP is 127.0.0.1
        $this->mockApiLimiter();

        $ApiLimiter = \AxiomCoders\ApiLimiter::getInstance();
        $ApiLimiter->loadOptionsJSON(dirname(__FILE__) . '/' . 'options.json');

        // Test function calls like with no sleep.
        for($i = 0; $i < 2; $i++)
        {
            $this->assertEquals($ApiLimiter->checkLimit('functionTest1'), \AxiomCoders\ApiLimiter::STATUS_OK);
        }

        // 3rd call should fail
        $this->assertEquals($ApiLimiter->checkLimit('functionTest1'), \AxiomCoders\ApiLimiter::STATUS_LIMIT_REACHED);

        // Sleep 3 seconds so entries could expire.
        sleep(3);

        // Repeat the same test.
        for($i = 0; $i < 2; $i++)
        {
            $this->assertEquals($ApiLimiter->checkLimit('functionTest1'), \AxiomCoders\ApiLimiter::STATUS_OK);
        }

        // 3rd call should fail
        $this->assertEquals($ApiLimiter->checkLimit('functionTest1'), \AxiomCoders\ApiLimiter::STATUS_LIMIT_REACHED);

        // Check if another IP is fine with the call
        $this->assertEquals($ApiLimiter->checkLimit('functionTest1', '', '192.168.0.1'), \AxiomCoders\ApiLimiter::STATUS_OK);
        $this->assertEquals($ApiLimiter->checkLimit('functionTest2'), \AxiomCoders\ApiLimiter::STATUS_OK);

    }

    /**
     * Test check limit the with sources way.
     */
    public function testCheckLimitSources()
    {
        // Mock so our IP is 127.0.0.1
        $ApiLimiter = \AxiomCoders\ApiLimiter::getInstance();
        $ApiLimiter->loadOptionsJSON(dirname(__FILE__) . '/' . 'options.json');

        for($i = 0; $i < 2; $i++)
        {
            $result = $ApiLimiter->checkLimit('functionTest2', '', $this->ipArray[$i]);
            $this->assertEquals($result, \AxiomCoders\ApiLimiter::STATUS_OK);
        }

        // Try 3rd entry which should fail.
        $result = $ApiLimiter->checkLimit('functionTest2', '', $this->ipArray[2]);
        $this->assertEquals($result, \AxiomCoders\ApiLimiter::STATUS_LIMIT_REACHED);

        sleep(3);
        // Try the same thing now as cache expired.

        for($i = 0; $i < 2; $i++)
        {
            $result = $ApiLimiter->checkLimit('functionTest2', '', $this->ipArray[$i]);
            $this->assertEquals($result, \AxiomCoders\ApiLimiter::STATUS_OK);
        }

        // Try 3rd entry which should fail.
        $result = $ApiLimiter->checkLimit('functionTest2', '', $this->ipArray[2]);
        $this->assertEquals($result, \AxiomCoders\ApiLimiter::STATUS_LIMIT_REACHED);

        // try calling same function from single IP multiple times.
        for($i = 0; $i < 99; $i++)
        {
            $result = $ApiLimiter->checkLimit('functionTest2', '', $this->ipArray[0]);
            $this->assertEquals($result, \AxiomCoders\ApiLimiter::STATUS_OK);
        }

        $result = $ApiLimiter->checkLimit('functionTest2', '', $this->ipArray[0]);
        $this->assertEquals($result, \AxiomCoders\ApiLimiter::STATUS_LIMIT_REACHED);
    }
}
