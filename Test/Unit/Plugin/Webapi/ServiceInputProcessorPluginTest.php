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
use Magento\Framework\Webapi\CustomAttributeTypeLocatorInterface;
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Framework\Webapi\ServiceTypeToEntityTypeMap;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real TypeProcessor of the Magento installation the tests are run in.
 *
 * The assertions stay within what every PHPUnit from 6.5 (Magento 2.3.0) to 12 (Magento 2.4.9) offers.
 */
class ServiceInputProcessorPluginTest extends TestCase
{
    /**
     * Every [code, entity type] pair the type locator double received, in order.
     *
     * @var array[]
     */
    private $locatorRequests = [];

    protected function setUp(): void
    {
        if (!class_exists(TypeProcessor::class)) {
            self::markTestSkipped('Magento framework not available (set BCE_MAGENTO_ROOT).');
        }
        $this->locatorRequests = [];
    }

    public function testTheXmlClassesThemselvesAreRefused(): void
    {
        foreach ([\SimpleXMLElement::class, \DOMElement::class, '\\SimpleXMLElement', '\\DOMElement'] as $type) {
            $this->assertRefused(['a' => 'b'], $type);
        }
    }

    public function testSubclassesAreRefused(): void
    {
        foreach ([XmlNode::class, ElementNode::class, '\\' . XmlNode::class] as $type) {
            $this->assertRefused(['a' => 'b'], $type);
        }
    }

    public function testArraysOfXmlClassesAreRefused(): void
    {
        $types = ['SimpleXMLElement[]', 'DOMElement[]', XmlNode::class . '[]', '\\' . ElementNode::class . '[]'];
        foreach ($types as $type) {
            $this->assertRefused([['a' => 'b']], $type);
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
        $plugin = $this->plugin($logger, true);
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
        $plugin = $this->plugin($logger, false);
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
        $plugin = $this->plugin($logger, true);
        self::assertNull($plugin->beforeConvertValue($this->subject(), ['x' => 'y'], SearchCriteriaInterface::class));
    }

    public function testAnXmlTypedCustomAttributeIsRefused(): void
    {
        $owner = SearchCriteriaInterface::class;
        $entityTypes = [$owner => 'bench_entity'];
        $data = ['custom_attributes' => [['attribute_code' => 'node', 'value' => ['name' => 'x']]]];

        $this->assertRefused($data, $owner, $entityTypes, ['node' => XmlNode::class]);
        $this->assertRefused($data, $owner, $entityTypes, ['node' => \SimpleXMLElement::class]);
        $this->assertRefused($data, $owner, $entityTypes, ['node' => ElementNode::class . '[]']);
        self::assertSame([['node', 'bench_entity']], array_unique($this->locatorRequests, SORT_REGULAR));

        $soap = ['customAttributes' => [['attributeCode' => 'node', 'value' => ['name' => 'x']]]];
        $this->assertRefused($soap, $owner, $entityTypes, ['node' => XmlNode::class]);

        $list = [['custom_attributes' => [['attribute_code' => 'node', 'value' => ['name' => 'x']]]]];
        $this->assertRefused($list, $owner . '[]', $entityTypes, ['node' => XmlNode::class]);
        $this->assertRefused(['item' => $list], $owner . '[]', $entityTypes, ['node' => XmlNode::class]);
        $this->assertRefused(['item' => $list[0]], $owner . '[]', $entityTypes, ['node' => XmlNode::class]);
    }

    public function testAnXmlTypedCustomAttributeRefusalIsLoggedWithItsType(): void
    {
        $logger = $this->createMock(RequestLogger::class);
        $logger->expects(self::once())
            ->method('logRefusal')
            ->with(XmlNode::class . '[]', XmlNode::class);
        $plugin = $this->plugin(
            $logger,
            true,
            [SearchCriteriaInterface::class => 'bench_entity'],
            ['node' => XmlNode::class . '[]']
        );
        $data = ['custom_attributes' => [['attribute_code' => 'node', 'value' => [['name' => 'x']]]]];
        $caught = null;
        try {
            $plugin->beforeConvertValue($this->subject(), $data, SearchCriteriaInterface::class);
        } catch (SerializationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
    }

    public function testCustomAttributesThatTheProcessorConvertsOrRejectsItselfPass(): void
    {
        $owner = SearchCriteriaInterface::class;
        $entityTypes = [$owner => 'bench_entity'];
        $types = [
            'node' => XmlNode::class,
            'sizes' => 'string[]',
            'free' => 'mixed',
            'thing' => AttributeInterface::class,
        ];

        $cases = [
            'simple type' => ['custom_attributes' => [['attribute_code' => 'sizes', 'value' => ['s', 'm']]]],
            'any type' => ['custom_attributes' => [['attribute_code' => 'free', 'value' => ['name' => 'x']]]],
            'data object type' => ['custom_attributes' => [['attribute_code' => 'thing', 'value' => ['a' => 'b']]]],
            'unknown code' => ['custom_attributes' => [['attribute_code' => 'nope', 'value' => ['name' => 'x']]]],
            'scalar value' => ['custom_attributes' => [['attribute_code' => 'node', 'value' => 'x']]],
            'no code' => ['custom_attributes' => [['value' => ['name' => 'x']]]],
            'no value' => ['custom_attributes' => [['attribute_code' => 'node']]],
            'scalar entry' => ['custom_attributes' => ['x']],
            'not a list' => ['custom_attributes' => 'x'],
        ];
        foreach ($cases as $label => $data) {
            self::assertNull($this->convert($data, $owner, $entityTypes, $types), $label . ' must pass');
        }
    }

    public function testAnOwnerWithoutAnEntityTypeNeverConsultsTheLocator(): void
    {
        $data = ['custom_attributes' => [['attribute_code' => 'node', 'value' => ['name' => 'x']]]];
        self::assertNull($this->convert($data, SearchCriteriaInterface::class, [], ['node' => XmlNode::class]));
        self::assertSame([], $this->locatorRequests);
    }

    public function testALocatorFailureLeavesTheDataToTheProcessor(): void
    {
        $data = ['custom_attributes' => [['attribute_code' => 'node', 'value' => ['name' => 'x']]]];
        $plugin = $this->plugin(
            $this->stub(RequestLogger::class),
            true,
            [SearchCriteriaInterface::class => 'bench_entity'],
            null
        );
        self::assertNull($plugin->beforeConvertValue($this->subject(), $data, SearchCriteriaInterface::class));
    }

    /**
     * Converts with a plugin whose logger accepts anything; returns the plugin's result.
     *
     * @param mixed $data
     * @param mixed $type
     * @param string[] $entityTypes Class to entity type, as the processor's map
     * @param string[] $attributeTypes Attribute code to type, as the processor's locator
     * @return array|null
     */
    private function convert($data, $type, array $entityTypes = [], array $attributeTypes = []): ?array
    {
        return $this->plugin($this->stub(RequestLogger::class), true, $entityTypes, $attributeTypes)
            ->beforeConvertValue($this->subject(), $data, $type);
    }

    /**
     * @param mixed $data
     * @param string $type
     * @param string[] $entityTypes
     * @param string[] $attributeTypes
     */
    private function assertRefused($data, string $type, array $entityTypes = [], array $attributeTypes = []): void
    {
        $caught = null;
        try {
            $this->convert($data, $type, $entityTypes, $attributeTypes);
        } catch (SerializationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, $type . ' must be refused');
        self::assertSame('Invalid data type', $caught->getMessage(), $type . ' must carry the official message');
    }

    /**
     * @param RequestLogger $logger
     * @param bool $enableLog
     * @param string[] $entityTypes
     * @param string[]|null $attributeTypes Null for a locator that fails
     * @return ServiceInputProcessorPlugin
     */
    private function plugin(
        RequestLogger $logger,
        bool $enableLog,
        array $entityTypes = [],
        ?array $attributeTypes = []
    ): ServiceInputProcessorPlugin {
        $locator = $this->stub(CustomAttributeTypeLocatorInterface::class);
        $locator->method('getType')->willReturnCallback(function ($code, $entityType) use ($attributeTypes): string {
            $this->locatorRequests[] = [(string)$code, (string)$entityType];
            if ($attributeTypes === null) {
                throw new \RuntimeException('locator failure');
            }
            return $attributeTypes[$code] ?? TypeProcessor::NORMALIZED_ANY_TYPE;
        });
        return new ServiceInputProcessorPlugin(
            new TypeProcessor(),
            $logger,
            $locator,
            new ServiceTypeToEntityTypeMap($entityTypes),
            $enableLog
        );
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
