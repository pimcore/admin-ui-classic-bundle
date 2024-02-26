<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Value;

use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\ResultContainer;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Model\DataObject\Service;
use Pimcore\Model\Element\ElementInterface;

/**
 * @internal
 */
final class DefaultValue extends AbstractValue
{
    private LocaleServiceInterface $localeService;

    public function setLocaleService(LocaleServiceInterface $localeService): void
    {
        $this->localeService = $localeService;
    }

    /**
     * @throws \Exception
     */
    private function getValueForObject(Concrete $object, string $key, string $brickType = null, string $brickKey = null): \stdClass
    {
        if (!$key) {
            throw new \Exception('Empty key');
        }

        $fieldDefinition = null;
        if (!empty($brickType)) {
            $getter = 'get' . Service::getFieldForBrickType($object->getClass(), $brickType);
            $value = $object->$getter();

            $getBrickType = 'get' . ucfirst($brickType);
            $value = $value->$getBrickType();
            if (!empty($value) && !empty($brickKey)) {
                $brickGetter = 'get' . ucfirst($brickKey);
                $value = $value->$brickGetter();

                $brickClass = Objectbrick\Definition::getByKey($brickType);
                $context = ['object' => $object, 'outerFieldname' => $key];
                $fieldDefinition = $brickClass->getFieldDefinition($brickKey, $context);
            }
        } else {
            $getter = 'get' . ucfirst($key);
            $value = $object->$getter();

            $fieldDefinition = $object->getClass()->getFieldDefinition($key);

            if (!$fieldDefinition) {
                $localizedFields = $object->getClass()->getFieldDefinition('localizedfields');
                if ($localizedFields instanceof Data\Localizedfields) {
                    $fieldDefinition = $localizedFields->getFieldDefinition($key);
                }
            }
        }

        if (!$fieldDefinition instanceof Data) {
            return $this->getDefaultValue($value);
        }

        if ($fieldDefinition->isEmpty($value)) {
            $parent = Service::hasInheritableParentObject($object);

            if (!empty($parent)) {
                return $this->getValueForObject($parent, $key, $brickType, $brickKey);
            }
        }

        $result = new \stdClass();
        $result->value = $value;
        $result->label = $fieldDefinition->getTitle();
        $result->def = $fieldDefinition;
        $result->empty = $fieldDefinition->isEmpty($value);
        $result->objectid = $object->getId();

        return $result;
    }

    private function getClassificationStoreValueForObject(Concrete $object, string $key): ?\stdClass
    {
        $keyParts = explode('~', $key);

        if (strpos($key, '~') === 0) {
            $type = $keyParts[1];
            if ($type === 'classificationstore') {
                $field = $keyParts[2];
                $groupKeyId = explode('-', $keyParts[3]);

                $groupId = (int) $groupKeyId[0];
                $keyid = (int) $groupKeyId[1];
                $getter = 'get' . ucfirst($field);

                if (method_exists($object, $getter)) {
                    /** @var Classificationstore $classificationStoreData */
                    $classificationStoreData = $object->$getter();

                    /** @var Data\Classificationstore $csFieldDefinition */
                    $csFieldDefinition = $object->getClass()->getFieldDefinition($field);
                    $csLanguage = $this->localeService->getLocale();

                    if (!$csFieldDefinition->isLocalized()) {
                        $csLanguage = 'default';
                    }

                    $fielddata = $classificationStoreData->getLocalizedKeyValue($groupId, $keyid, $csLanguage, true, true);

                    $keyConfig = Classificationstore\KeyConfig::getById($keyid);
                    $type = $keyConfig->getType();
                    $definition = json_decode($keyConfig->getDefinition(), true);
                    $definition = Classificationstore\Service::getFieldDefinitionFromJson($definition, $type);

                    $result = new \stdClass();
                    $result->value = $fielddata;
                    $result->label = $definition->getTitle();
                    $result->def = $definition;
                    $result->empty = $definition->isEmpty($fielddata);
                    $result->objectid = $object->getId();

                    return $result;
                }
            }
        }

        return null;
    }

    private function getDefaultValue(mixed $value): \stdClass
    {
        $result = new \stdClass();
        $result->value = $value;
        $result->label = $this->label;
        $result->def = null;

        if (empty($value) || (is_object($value) && method_exists($value, 'isEmpty') && $value->isEmpty())) {
            $result->empty = true;
        } else {
            $result->empty = false;
        }

        return $result;
    }

    public function getLabeledValue(array|ElementInterface $element): ResultContainer|\stdClass|null
    {
        $attributeParts = explode('~', $this->attribute);

        $getter = 'get' . ucfirst($this->attribute);
        $brickType = null;
        $brickKey = null;

        if ($element instanceof Concrete) {
            if (str_starts_with($this->attribute, '~')) {
                // key value, ignore for now

                return $this->getClassificationStoreValueForObject($element, $this->attribute);
            }
            if (count($attributeParts) > 1) {
                $json = json_decode(trim($attributeParts[0], '?'));
                $brickType = $json ? $json->containerKey : $attributeParts[0];
                $brickKey = $attributeParts[1];

                $getter = 'get' . Service::getFieldForBrickType($element->getClass(), $brickType);
            }
        }

        if ($this->attribute && method_exists($element, $getter)) {
            if ($element instanceof Concrete) {
                try {
                    $result = $this->getValueForObject($element, $this->attribute, $brickType, $brickKey);
                } catch (\Exception $e) {
                    $result = $this->getDefaultValue($element->$getter());
                }
            } else {
                $result = $this->getDefaultValue($element->$getter());
            }

            return $result;
        }

        return null;
    }
}
