<?php
/**
 * Copyright (c) Big Cart Energy. All rights reserved.
 * SPDX-License-Identifier: OSL-3.0
 */
declare(strict_types=1);

namespace BigCartEnergy\CosmicStingPatch\Plugin\Webapi;

use BigCartEnergy\CosmicStingPatch\Model\RequestLogger;
use Magento\Framework\Exception\SerializationException;
use Magento\Framework\Phrase;
use Magento\Framework\Reflection\TypeProcessor;
use Magento\Framework\Webapi\ServiceInputProcessor;

/**
 * Guard for CVE-2024-34102 on the Web API input processor.
 *
 * Before the processor converts a value, the type it is asked to produce (or the item type of an array type)
 * is checked against the two XML classes the official fix refuses, SimpleXMLElement and DOMElement. The class
 * itself and every subclass are refused with the official message "Invalid data type". Simple types and
 * "mixed" are never checked. Nothing else about the request changes: on the pass path the plugin returns
 * null, so the processor receives its arguments untouched.
 */
class ServiceInputProcessorPlugin
{
    /**
     * The classes the processor must never be asked to create, with their subclasses.
     */
    private const REFUSED_CLASSES = [\SimpleXMLElement::class, \DOMElement::class];

    /**
     * @var TypeProcessor
     */
    private $typeProcessor;

    /**
     * @var RequestLogger
     */
    private $requestLogger;

    /**
     * @var bool
     */
    private $enableLog;

    /**
     * @param TypeProcessor $typeProcessor
     * @param RequestLogger $requestLogger
     * @param bool $enableLog Whether a refusal writes a line to the module's log file
     */
    public function __construct(TypeProcessor $typeProcessor, RequestLogger $requestLogger, bool $enableLog = true)
    {
        $this->typeProcessor = $typeProcessor;
        $this->requestLogger = $requestLogger;
        $this->enableLog = $enableLog;
    }

    /**
     * Refuses a conversion to an XML class before the processor starts it.
     *
     * @param ServiceInputProcessor $subject
     * @param mixed $data
     * @param string $type
     * @return null The arguments are never changed
     * @throws SerializationException When the type is, or is an array of, a refused class
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeConvertValue(ServiceInputProcessor $subject, $data, $type)
    {
        $class = $this->classOf($type);
        if ($class !== null && $this->isRefused($class)) {
            if ($this->enableLog) {
                $this->requestLogger->logRefusal((string)$type, $class);
            }
            throw new SerializationException(new Phrase('Invalid data type'));
        }
        return null;
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
