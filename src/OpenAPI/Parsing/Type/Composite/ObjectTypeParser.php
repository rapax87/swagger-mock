<?php
/*
 * This file is part of Swagger Mock.
 *
 * (c) Igor Lazarev <strider2038@yandex.ru>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\OpenAPI\Parsing\Type\Composite;

use App\Mock\Parameters\Schema\Type\Composite\FreeFormObjectType;
use App\Mock\Parameters\Schema\Type\Composite\HashMapType;
use App\Mock\Parameters\Schema\Type\Composite\ObjectType;
use App\Mock\Parameters\Schema\Type\TypeCollection;
use App\Mock\Parameters\Schema\Type\TypeInterface;
use App\OpenAPI\ErrorHandling\ErrorHandlerInterface;
use App\OpenAPI\Parsing\ParserInterface;
use App\OpenAPI\Parsing\SpecificationAccessor;
use App\OpenAPI\Parsing\SpecificationPointer;
use App\OpenAPI\Parsing\Type\FieldParserTrait;
use App\OpenAPI\Parsing\Type\TypeParserInterface;
use App\OpenAPI\SpecificationObjectMarkerInterface;
use App\Utility\StringList;

/**
 * @author Igor Lazarev <strider2038@yandex.ru>
 */
class ObjectTypeParser implements TypeParserInterface
{
    use FieldParserTrait;

    /** @var ParserInterface */
    private $resolvingSchemaParser;

    /** @var ErrorHandlerInterface */
    private $errorHandler;

    public function __construct(ParserInterface $resolvingSchemaParser, ErrorHandlerInterface $errorHandler)
    {
        $this->resolvingSchemaParser = $resolvingSchemaParser;
        $this->errorHandler = $errorHandler;
    }

    public function parsePointedSchema(SpecificationAccessor $specification, SpecificationPointer $pointer): SpecificationObjectMarkerInterface
    {
        $schema = $specification->getSchema($pointer);

        if (array_key_exists('additionalProperties', $schema)) {
            $object = $this->parseFreeFormOrHashMap($specification, $pointer);
        } else {
            $object = $this->parseObjectType($specification, $pointer);
        }

        $this->readFixedFieldsValues($object, $schema);

        return $object;
    }

    private function parseObjectType(SpecificationAccessor $specification, SpecificationPointer $pointer): ObjectType
    {
        $object = new ObjectType();

        $object->properties = $this->parseProperties($specification, $pointer);
        $object->required = $this->parseRequiredProperties($specification, $pointer, $object->properties);

        return $object;
    }

    private function parseFreeFormOrHashMap(SpecificationAccessor $specification, SpecificationPointer $pointer): TypeInterface
    {
        $schema = $specification->getSchema($pointer);

        $additionalProperties = $this->getAdditionalPropertiesFromSchema($schema, $pointer);

        if (0 === \count($additionalProperties)) {
            $object = new FreeFormObjectType();
        } else {
            $object = $this->parseHashMap($specification, $pointer);
        }

        $object->minProperties = $this->readIntegerValue($schema, 'minProperties');
        $object->maxProperties = $this->readIntegerValue($schema, 'maxProperties');

        return $object;
    }

    private function getAdditionalPropertiesFromSchema(array $schema, SpecificationPointer $pointer): array
    {
        $additionalProperties = [];

        if (\is_array($schema['additionalProperties'])) {
            $additionalProperties = $schema['additionalProperties'];
        } elseif (true !== $schema['additionalProperties']) {
            $this->errorHandler->reportError('Invalid value of option "additionalProperties"', $pointer);
        }

        return $additionalProperties;
    }

    private function parseHashMap(SpecificationAccessor $specification, SpecificationPointer $pointer): HashMapType
    {
        $object = new HashMapType();

        $propertyPointer = $pointer->withPathElement('additionalProperties');
        $object->value = $this->resolvingSchemaParser->parsePointedSchema($specification, $propertyPointer);

        $object->properties = $this->parseProperties($specification, $pointer);
        $object->required = $this->parseRequiredProperties($specification, $pointer, $object->properties);

        return $object;
    }

    private function parseProperties(SpecificationAccessor $specification, SpecificationPointer $pointer): TypeCollection
    {
        $properties = new TypeCollection();

        $schema = $specification->getSchema($pointer);
        $schemaProperties = $schema['properties'] ?? [];
        $propertiesPointer = $pointer->withPathElement('properties');

        foreach (array_keys($schemaProperties) as $propertyName) {
            $propertyPointer = $propertiesPointer->withPathElement($propertyName);
            $property = $this->resolvingSchemaParser->parsePointedSchema($specification, $propertyPointer);
            $properties->set($propertyName, $property);
        }

        return $properties;
    }

    private function parseRequiredProperties(SpecificationAccessor $specification, SpecificationPointer $pointer, TypeCollection $properties): StringList
    {
        $requiredProperties = new StringList();
        $schema = $specification->getSchema($pointer);

        $schemaRequiredProperties = $schema['required'] ?? [];
        $requiredPointer = $pointer->withPathElement('required');

        foreach ($schemaRequiredProperties as $propertyName) {
            $isValid = $this->validateProperty($propertyName, $properties, $requiredPointer);

            if ($isValid) {
                $requiredProperties->add($propertyName);
            }
        }

        return $requiredProperties;
    }

    private function validateProperty($propertyName, TypeCollection $properties, SpecificationPointer $pointer): bool
    {
        $isValid = false;

        if (!\is_string($propertyName)) {
            $this->errorHandler->reportError('Invalid required property', $pointer);
        } elseif (!$properties->containsKey($propertyName)) {
            $this->errorHandler->reportError(
                sprintf('Required property "%s" does not exist', $propertyName),
                $pointer
            );
        } else {
            $isValid = true;
        }

        return $isValid;
    }
}
