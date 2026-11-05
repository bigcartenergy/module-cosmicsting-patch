<?php
/**
 * Copyright (c) Big Cart Energy. All rights reserved.
 * SPDX-License-Identifier: OSL-3.0
 */
declare(strict_types=1);

namespace BigCartEnergy\CosmicStingPatch\Test\Unit\Plugin\Webapi;

use BigCartEnergy\CosmicStingPatch\Model\RequestLogger;
use BigCartEnergy\CosmicStingPatch\Plugin\Webapi\ServiceInputProcessorPlugin;
use BigCartEnergy\CosmicStingPatch\Test\Unit\Fixture\ElementNode;
use BigCartEnergy\CosmicStingPatch\Test\Unit\Fixture\XmlNode;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\SerializationException;
use Magento\Framework\Reflection\TypeProcessor;
use Magento\Framework\Webapi\ServiceInputProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real TypeProcessor of the Magento installation the tests are run in.
 *
 * The assertions stay within what every PHPUnit from 6.5 (Magento 2.3.0) to 12 (Magento 2.4.9) offers.
 */
class ServiceInputProcessorPluginTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TypeProcessor::class)) {
            self::markTestSkipped('Magento framework not available (set BCE_MAGENTO_ROOT).');
        }
    }

    public function testTheXmlClassesThemselvesAreRefused(): void
    {
        foreach ([\SimpleXMLElement::class, \DOMElement::class, '\\SimpleXMLElement', '\\DOMElement'] as $type) {
            $this->assertRefused($type);
        }
    }

    public function testSubclassesAreRefused(): void
    {
        foreach ([XmlNode::class, ElementNode::class, '\\' . XmlNode::class] as $type) {
            $this->assertRefused($type);
        }
    }

    public function testArraysOfXmlClassesAreRefused(): void
    {
        $types = ['SimpleXMLElement[]', 'DOMElement[]', XmlNode::class . '[]', '\\' . ElementNode::class . '[]'];
        foreach ($types as $type) {
            $this->assertRefused($type);
        }
    }

    public function testSimpleAndAnyTypesPass(): void
    {
        $types = ['string', 'str', 'int', 'integer', 'bool', 'boolean', 'float', 'double', 'mixed', 'anyType'];
        $types[] = 'string[]';
        $types[] = 'int[]';
        foreach ($types as $type) {
            self::assertNull($this->convert(['x' => 'y'], $type), $type . ' must pass');
            self::assertNull($this->convert('scalar', $type), $type . ' must pass for scalar data');
        }
    }

    public function testDataObjectTypesAndUnknownTypesPass(): void
    {
        $types = [
            SearchCriteriaInterface::class,
            '\\' . SearchCriteriaInterface::class,
            AttributeInterface::class . '[]',
            'NoSuchClassForTheGuardTest',
            'NoSuchClassForTheGuardTest[]',
            '',
        ];
        if (defined(TypeProcessor::class . '::UNSTRUCTURED_ARRAY')) {
            $types[] = constant(TypeProcessor::class . '::UNSTRUCTURED_ARRAY');
        }
        foreach ($types as $type) {
            self::assertNull($this->convert(['x' => 'y'], $type), $type . ' must pass');
        }
        self::assertNull($this->convert(['x' => 'y'], null), 'a non-string type must pass untouched');
        self::assertNull($this->convert(['x' => 'y'], 42), 'a non-string type must pass untouched');
    }

    public function testARefusalIsLoggedWithTheTypeAndTheClass(): void
    {
        $logger = $this->createMock(RequestLogger::class);
        $logger->expects(self::once())
            ->method('logRefusal')
            ->with(XmlNode::class . '[]', XmlNode::class);
        $plugin = new ServiceInputProcessorPlugin(new TypeProcessor(), $logger, true);
        $caught = null;
        try {
            $plugin->beforeConvertValue($this->subject(), [['a' => 'b']], XmlNode::class . '[]');
        } catch (SerializationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
    }

    public function testLoggingCanBeTurnedOffAndTheRefusalStands(): void
    {
        $logger = $this->createMock(RequestLogger::class);
        $logger->expects(self::never())->method('logRefusal');
        $plugin = new ServiceInputProcessorPlugin(new TypeProcessor(), $logger, false);
        $caught = null;
        try {
            $plugin->beforeConvertValue($this->subject(), ['a' => 'b'], \SimpleXMLElement::class);
        } catch (SerializationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        self::assertSame('Invalid data type', $caught->getMessage());
    }

    public function testThePassPathNeverLogs(): void
    {
        $logger = $this->createMock(RequestLogger::class);
        $logger->expects(self::never())->method('logRefusal');
        $plugin = new ServiceInputProcessorPlugin(new TypeProcessor(), $logger, true);
        self::assertNull($plugin->beforeConvertValue($this->subject(), ['x' => 'y'], SearchCriteriaInterface::class));
    }

    /**
     * Converts with a plugin whose logger accepts anything; returns the plugin's result.
     *
     * @param mixed $data
     * @param mixed $type
     * @return array|null
     */
    private function convert($data, $type): ?array
    {
        $plugin = new ServiceInputProcessorPlugin(new TypeProcessor(), $this->stub(RequestLogger::class), true);
        return $plugin->beforeConvertValue($this->subject(), $data, $type);
    }

    private function assertRefused(string $type): void
    {
        $caught = null;
        try {
            $this->convert(['a' => 'b'], $type);
        } catch (SerializationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, $type . ' must be refused');
        self::assertSame('Invalid data type', $caught->getMessage(), $type . ' must carry the official message');
    }

    private function subject(): ServiceInputProcessor
    {
        return $this->stub(ServiceInputProcessor::class);
    }

    /**
     * A test double without expectations: a stub where the runner offers one, a mock on older runners.
     *
     * @param string $class
     * @return \PHPUnit\Framework\MockObject\MockObject|\PHPUnit\Framework\MockObject\Stub
     */
    private function stub(string $class)
    {
        return method_exists($this, 'createStub') ? $this->createStub($class) : $this->createMock($class);
    }
}
