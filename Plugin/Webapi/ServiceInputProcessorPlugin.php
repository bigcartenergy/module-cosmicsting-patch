<?php
/**
 * Copyright (c) Big Cart Energy. All rights reserved.
 * SPDX-License-Identifier: OSL-3.0
 */
declare(strict_types=1);

namespace BigCartEnergy\CosmicStingPatch\Plugin\Webapi;

use BigCartEnergy\CosmicStingPatch\Model\RequestLogger;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\Api\ExtensionAttributesInterface;
use Magento\Framework\Api\SimpleDataObjectConverter;
use Magento\Framework\Exception\SerializationException;
use Magento\Framework\Phrase;
use Magento\Framework\Reflection\TypeProcessor;
use Magento\Framework\Webapi\CustomAttributeTypeLocatorInterface;
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Framework\Webapi\ServiceTypeToEntityTypeMap;

/**
 * Guard for CVE-2024-34102 on the Web API input processor.
 *
 * Before the processor converts a value, the type it is asked to produce (or the item type of an array type)
 * is checked against the two XML classes the official fix refuses, SimpleXMLElement and DOMElement. The class
 * itself and every subclass are refused with the official message "Invalid data type". Simple types and
 * "mixed" are never checked.
 *
 * The same check covers the object-typed custom attributes of the value, because the processor builds those
 * without passing through convertValue(): each attribute's type is resolved the way the processor resolves it
 * (the entity type of the owning class, then the custom attribute type locator) and refused when it is, or is
 * an array of, a refused class. Nothing else about the request changes: on the pass path the plugin returns
 * null, so the processor receives its arguments untouched.
 */
class ServiceInputProcessorPlugin
{
    /**
     * The classes the processor must never be asked to create, with their subclasses.
     */
    private const REFUSED_CLASSES = [\SimpleXMLElement::class, \DOMElement::class];

    /**
     * The key the SOAP server wraps array values in; the processor removes it after this plugin has run.
     */
    private const SOAP_ITEM_KEY = 'item';

    /**
     * The property the processor treats as a data object's custom attributes: it routes a key there when
     * SimpleDataObjectConverter::snakeCaseToUpperCamelCase() of the key equals this, which covers "custom_attributes",
     * "customAttributes" and every other spelling that normalises to it, so this plugin recognises the same keys.
     */
    private const CUSTOM_ATTRIBUTES_PROPERTY = 'CustomAttributes';

    /**
     * The keys that carry a custom attribute's code: REST (snake case) and SOAP (camel case), as the processor
     * reads them.
     */
    private const ATTRIBUTE_CODE_KEYS = [AttributeValue::ATTRIBUTE_CODE, 'attributeCode'];

    /**
     * @var TypeProcessor
     */
    private $typeProcessor;

    /**
     * @var RequestLogger
     */
    private $requestLogger;

    /**
     * @var CustomAttributeTypeLocatorInterface
     */
    private $customAttributeTypeLocator;

    /**
     * @var ServiceTypeToEntityTypeMap
     */
    private $serviceTypeToEntityTypeMap;

    /**
     * @var bool
     */
    private $enableLog;

    /**
     * @param TypeProcessor $typeProcessor
     * @param RequestLogger $requestLogger
     * @param CustomAttributeTypeLocatorInterface $customAttributeTypeLocator The processor's own type locator
     * @param ServiceTypeToEntityTypeMap $serviceTypeToEntityTypeMap The processor's own class to entity type map
     * @param bool $enableLog Whether a refusal writes a line to the module's log file
     */
    public function __construct(
        TypeProcessor $typeProcessor,
        RequestLogger $requestLogger,
        CustomAttributeTypeLocatorInterface $customAttributeTypeLocator,
        ServiceTypeToEntityTypeMap $serviceTypeToEntityTypeMap,
        bool $enableLog = true
    ) {
        $this->typeProcessor = $typeProcessor;
        $this->requestLogger = $requestLogger;
        $this->customAttributeTypeLocator = $customAttributeTypeLocator;
        $this->serviceTypeToEntityTypeMap = $serviceTypeToEntityTypeMap;
        $this->enableLog = $enableLog;
    }

    /**
     * Refuses a conversion to an XML class before the processor starts it.
     *
     * @param ServiceInputProcessor $subject
     * @param mixed $data
     * @param string $type
     * @return null The arguments are never changed
     * @throws SerializationException When the type, an array item type or a custom attribute type is refused
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeConvertValue(ServiceInputProcessor $subject, $data, $type)
    {
        $class = $this->classOf($type);
        if ($class === null) {
            return null;
        }
        $this->refuse($class, (string)$type);
        if (is_array($data)) {
            $this->refuseCustomAttributes($data, $class, (bool)$this->typeProcessor->isArrayType($type));
        }
        return null;
    }

    /**
     * Throws the official refusal when the class is one of the refused classes or a subclass of one.
     *
     * @param string $class
     * @param string $type The type as the processor received it, for the log
     * @return void
     * @throws SerializationException
     */
    private function refuse(string $class, string $type): void
    {
        if (!$this->isRefused($class)) {
            return;
        }
        if ($this->enableLog) {
            $this->requestLogger->logRefusal($type, $class);
        }
        throw new SerializationException(new Phrase('Invalid data type'));
    }

    /**
     * Refuses an object-typed custom attribute whose type is an XML class.
     *
     * The processor builds such a value directly, without convertValue(), so the check happens here, on the
     * data of the object (or of each item of an array type) the processor is about to build. The type is
     * resolved exactly as the processor resolves it: the entity type of the owning class, then the custom
     * attribute type locator. Entries the processor would reject itself, values that are not arrays, simple
     * and "mixed" types, owners without an entity type and a locator failure leave the data to the processor.
     *
     * @param array $data
     * @param string $className The class the processor builds from each object
     * @param bool $isArrayType Whether $data holds a list of objects (or the SOAP "item" wrapper around one)
     * @return void
     * @throws SerializationException
     */
    private function refuseCustomAttributes(array $data, string $className, bool $isArrayType): void
    {
        $entityType = null;
        foreach ($isArrayType ? $this->items($data) : [$data] as $object) {
            if (!is_array($object)) {
                continue;
            }
            foreach (array_keys($object) as $key) {
                if (!is_array($object[$key]) || !$this->isCustomAttributesKey((string)$key)) {
                    continue;
                }
                if ($entityType === null) {
                    $entityType = $this->entityType($className);
                }
                if ($entityType === false) {
                    return;
                }
                foreach ($object[$key] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $code = $this->customAttributeCode($entry);
                    if ($code === null
                        || !array_key_exists(AttributeValue::VALUE, $entry)
                        || !is_array($entry[AttributeValue::VALUE])
                    ) {
                        continue;
                    }
                    $attributeType = $this->customAttributeType($code, $entityType);
                    $attributeClass = $attributeType === null ? null : $this->classOf($attributeType);
                    if ($attributeClass !== null) {
                        $this->refuse($attributeClass, $attributeType);
                    }
                }
            }
        }
    }

    /**
     * The objects of an array-typed value.
     *
     * The shapes the processor accepts are a list, or the SOAP "item" wrapper around a list or around a single
     * object.
     *
     * @param array $data
     * @return array
     */
    private function items(array $data): array
    {
        if (!isset($data[self::SOAP_ITEM_KEY])) {
            return array_values($data);
        }
        $items = $data[self::SOAP_ITEM_KEY];
        if (!is_array($items)) {
            return [];
        }
        return array_keys($items) !== range(0, count($items) - 1) ? [$items] : array_values($items);
    }

    /**
     * Whether the processor treats a key as the custom attributes property, matched as the processor matches it.
     *
     * @param string $key
     * @return bool
     */
    private function isCustomAttributesKey(string $key): bool
    {
        return SimpleDataObjectConverter::snakeCaseToUpperCamelCase($key) === self::CUSTOM_ATTRIBUTES_PROPERTY;
    }

    /**
     * The entity type the processor maps a data object class to, or false when there is none.
     *
     * Without an entity type every custom attribute is "mixed" for the processor and goes through convertValue().
     *
     * @param string $className
     * @return string|false
     */
    private function entityType(string $className)
    {
        $owner = $className;
        if (is_subclass_of($owner, ExtensionAttributesInterface::class)) {
            $owner = substr($owner, 0, -strlen('Interface'));
        }
        $owner = ltrim($owner, '\\');
        try {
            $entityType = $this->serviceTypeToEntityTypeMap->getEntityType($owner);
        } catch (\Throwable $e) {
            return false;
        }
        return is_string($entityType) && $entityType !== '' ? $entityType : false;
    }

    /**
     * The code of a custom attribute entry, read as the processor reads it; null when there is none.
     *
     * @param array $entry
     * @return string|null
     */
    private function customAttributeCode(array $entry): ?string
    {
        foreach (self::ATTRIBUTE_CODE_KEYS as $key) {
            if (isset($entry[$key]) && is_scalar($entry[$key]) && (string)$entry[$key] !== '') {
                return (string)$entry[$key];
            }
        }
        return null;
    }

    /**
     * The type of a custom attribute when the processor would build it directly, else null.
     *
     * Null for a simple or "mixed" type (converted through convertValue(), where the plugin already acts) and
     * when the locator fails (the processor raises its own error later).
     *
     * @param string $code
     * @param string $entityType
     * @return string|null
     */
    private function customAttributeType(string $code, string $entityType): ?string
    {
        try {
            $type = $this->customAttributeTypeLocator->getType($code, $entityType);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($type)
            || $type === ''
            || $this->typeProcessor->isTypeAny($type)
            || $this->typeProcessor->isTypeSimple($type)
        ) {
            return null;
        }
        return $type;
    }

    /**
     * The class a type resolves to: the type itself, or the item type of an array type.
     *
     * Null for anything that is not a class name: simple types, "mixed", an empty or non-string type.
     *
     * @param mixed $type
     * @return string|null
     */
    private function classOf($type): ?string
    {
        if (!is_string($type) || $type === '') {
            return null;
        }
        if ($this->typeProcessor->isTypeSimple($type) || $this->typeProcessor->isTypeAny($type)) {
            return null;
        }
        if ($this->typeProcessor->isArrayType($type)) {
            $type = (string)$this->typeProcessor->getArrayItemType($type);
        }
        $class = ltrim($type, '\\');
        return $class === '' ? null : $class;
    }

    /**
     * Whether a class is one of the refused classes or a subclass of one.
     *
     * @param string $class
     * @return bool
     */
    private function isRefused(string $class): bool
    {
        foreach (self::REFUSED_CLASSES as $refused) {
            if (is_a($class, $refused, true)) {
                return true;
            }
        }
        return false;
    }
}
