<?php

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

namespace Pimcore\Bundle\AdminBundle\Model;


use Pimcore\Bundle\AdminBundle\CustomView\Config;
use Pimcore\Bundle\AdminBundle\DataObject\GridColumnConfig\Operator\AbstractOperator;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Logger;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Listing;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Model\DataObject\Service as DataObjectService;
use Pimcore\Model\User;

class Service
{
    /**
     * @param string $id
     *
     * @return array|null
     *
     * @internal
     */
    public static function getCustomViewById(string $id): ?array
    {
        $customViews = Config::get();
        if ($customViews) {
            foreach ($customViews as $customView) {
                if ($customView['id'] == $id) {
                    return $customView;
                }
            }
        }

        return null;
    }

    /**
     * Returns the first perspective name
     *
     * @internal
     */
    public static function getFirstAllowedPerspective(User $user): string
    {
        $perspectives = $user->getMergedPerspectives();
        if (!empty($perspectives)) {
            return $perspectives[0];
        } else {
            // all perspectives are allowed
            $perspectives = \Pimcore\Bundle\AdminBundle\Perspective\Config::getAvailablePerspectives($user);

            return $perspectives[0]['name'];
        }
    }

    /**
     * @param string $requestedLanguage
     * @param LocaleServiceInterface $localeService
     * @param Listing $list
     * @param string[] $fields
     * @param bool $addTitles
     * @param array $context
     *
     * @return array
     *
     * @internal
     */
    public static function getCsvData(string $requestedLanguage, LocaleServiceInterface $localeService, Listing $list, array $fields, string $header = '', bool $addTitles = true, array $context = []): array
    {
        $data = [];
        Logger::debug('objects in list:' . count($list->getObjects()));

        $helperDefinitions = DataObjectService::getHelperDefinitions();

        foreach ($list->getObjects() as $object) {
            if ($fields) {
                if ($addTitles && empty($data)) {
                    $tmp = [];
                    $mapped = self::getCsvDataForObject($object, $requestedLanguage, $fields, $helperDefinitions, $localeService, $header, true, $context);
                    foreach ($mapped as $key => $value) {
                        $tmp[] = '"' . $key . '"';
                    }
                    $data[] = $tmp;
                }

                $rowData = self::getCsvDataForObject($object, $requestedLanguage, $fields, $helperDefinitions, $localeService, $header, false, $context);
                $rowData = self::escapeCsvRecord($rowData);
                $data[] = $rowData;
            }
        }

        return $data;
    }

    /**
     * @param Concrete $object
     * @param string $requestedLanguage
     * @param array $fields
     * @param array $helperDefinitions
     * @param LocaleServiceInterface $localeService
     * @param bool $returnMappedFieldNames
     * @param array $context
     *
     * @return array
     *
     * @internal
     */
    public static function getCsvDataForObject(Concrete $object, string $requestedLanguage, array $fields, array $helperDefinitions, LocaleServiceInterface $localeService, string $header, bool $returnMappedFieldNames = false, array $context = []): array
    {
        $objectData = [];
        $mappedFieldnames = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            if (DataObjectService::isHelperGridColumnConfig($key) && $validLanguages = static::expandGridColumnForExport($helperDefinitions, $key)) {
                $currentLocale = $localeService->getLocale();
                $mappedFieldnameBase = self::mapFieldname($field, $helperDefinitions, $header);

                foreach ($validLanguages as $validLanguage) {
                    $localeService->setLocale($validLanguage);
                    $fieldData = self::getCsvFieldData($currentLocale, $key, $object, $validLanguage, $helperDefinitions);
                    $localizedFieldKey = $key . '-' . $validLanguage;
                    if (!isset($mappedFieldnames[$localizedFieldKey])) {
                        $mappedFieldnames[$localizedFieldKey] = $mappedFieldnameBase . '-' . $validLanguage;
                    }
                    $objectData[$localizedFieldKey] = $fieldData;
                }

                $localeService->setLocale($currentLocale);
            } else {
                $fieldData = self::getCsvFieldData($requestedLanguage, $key, $object, $requestedLanguage, $helperDefinitions);
                if (!isset($mappedFieldnames[$key])) {
                    $mappedFieldnames[$key] = self::mapFieldname($field, $helperDefinitions, $header);
                }

                $objectData[$key] = $fieldData;
            }
        }

        if ($returnMappedFieldNames) {
            $tmp = [];
            foreach ($mappedFieldnames as $key => $value) {
                $tmp[$value] = $objectData[$key];
            }
            $objectData = $tmp;
        }

        $event = new DataObjectEvent($object, ['objectData' => $objectData,
            'context' => $context,
            'requestedLanguage' => $requestedLanguage,
            'fields' => $fields,
            'helperDefinitions' => $helperDefinitions,
            'localeService' => $localeService,
            'returnMappedFieldNames' => $returnMappedFieldNames,
        ]);

        \Pimcore::getEventDispatcher()->dispatch($event, DataObjectEvents::POST_CSV_ITEM_EXPORT);
        $objectData = $event->getArgument('objectData');

        return $objectData;
    }


    /**
     * @internal
     */
    protected static function getCsvFieldData(string $fallbackLanguage, string $field, Concrete $object, string $requestedLanguage, array $helperDefinitions): string
    {
        //check if field is systemfield
        $systemFieldMap = [
            'id' => 'getId',
            'fullpath' => 'getRealFullPath',
            'published' => 'getPublished',
            'creationDate' => 'getCreationDate',
            'modificationDate' => 'getModificationDate',
            'filename' => 'getKey',
            'key' => 'getKey',
            'classname' => 'getClassname',
        ];
        if (in_array($field, array_keys($systemFieldMap))) {
            $getter = $systemFieldMap[$field];

            return (string) $object->$getter();
        } else {
            //check if field is standard object field
            $fieldDefinition = $object->getClass()->getFieldDefinition($field);
            if ($fieldDefinition) {
                return $fieldDefinition->getForCsvExport($object);
            } else {
                $fieldParts = explode('~', $field);

                // check for objects bricks and localized fields
                if (static::isHelperGridColumnConfig($field)) {
                    if ($helperDefinitions[$field]) {
                        $cellValue = static::calculateCellValue($object, $helperDefinitions, $field, ['language' => $requestedLanguage]);

                        // Mimic grid concatenation behavior
                        if (is_array($cellValue)) {
                            $cellValue = implode(',', $cellValue);
                        }

                        return (string) $cellValue;
                    }
                } elseif (substr($field, 0, 1) == '~') {
                    $type = $fieldParts[1];

                    if ($type == 'classificationstore') {
                        $fieldname = $fieldParts[2];
                        $groupKeyId = explode('-', $fieldParts[3]);
                        $groupId = (int) $groupKeyId[0];
                        $keyId = (int) $groupKeyId[1];
                        $getter = 'get' . ucfirst($fieldname);
                        if (method_exists($object, $getter)) {
                            $keyConfig = DataObject\Classificationstore\KeyConfig::getById($keyId);
                            $type = $keyConfig->getType();
                            $definition = json_decode($keyConfig->getDefinition(), true);
                            $fieldDefinition = \Pimcore\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($definition, $type);

                            /** @var DataObject\ClassDefinition\Data\Classificationstore $csFieldDefinition */
                            $csFieldDefinition = $object->getClass()->getFieldDefinition($fieldname);
                            $csLanguage = $requestedLanguage;
                            if (!$csFieldDefinition->isLocalized()) {
                                $csLanguage = 'default';
                            }

                            return $fieldDefinition->getForCsvExport(
                                $object,
                                ['context' => [
                                    'containerType' => 'classificationstore',
                                    'fieldname' => $fieldname,
                                    'groupId' => $groupId,
                                    'keyId' => $keyId,
                                    'language' => $csLanguage,
                                ]]
                            );
                        }
                    }
                    //key value store - ignore for now
                } elseif (count($fieldParts) > 1) {
                    // brick
                    $brickType = $fieldParts[0];
                    $brickDescriptor = null;
                    $innerContainer = null;

                    if (strpos($brickType, '?') !== false) {
                        $brickDescriptor = substr($brickType, 1);
                        $brickDescriptor = json_decode($brickDescriptor, true);
                        $innerContainer = $brickDescriptor['innerContainer'] ?? 'localizedfields';
                        $brickType = $brickDescriptor['containerKey'];
                    }
                    $brickKey = $fieldParts[1];

                    $key = static::getFieldForBrickType($object->getClass(), $brickType);

                    $brickClass = DataObject\Objectbrick\Definition::getByKey($brickType);

                    if ($brickDescriptor) {
                        /** @var DataObject\ClassDefinition\Data\Localizedfields $localizedFields */
                        $localizedFields = $brickClass->getFieldDefinition($innerContainer);
                        $fieldDefinition = $localizedFields->getFieldDefinition($brickDescriptor['brickfield']);
                    } else {
                        $fieldDefinition = $brickClass->getFieldDefinition($brickKey);
                    }

                    if ($fieldDefinition) {
                        $brickContainer = $object->{'get' . ucfirst($key)}();
                        if ($brickContainer && !empty($brickKey)) {
                            $brick = $brickContainer->{'get' . ucfirst($brickType)}();
                            if ($brick) {
                                $params = [
                                    'context' => [
                                        'containerType' => 'objectbrick',
                                        'containerKey' => $brickType,
                                        'fieldname' => $brickKey,
                                    ],

                                ];

                                $value = $brick;

                                if ($brickDescriptor) {
                                    $innerContainer = $brickDescriptor['innerContainer'] ?? 'localizedfields';
                                    $value = $brick->{'get' . ucfirst($innerContainer)}();

                                    if ($value instanceof Localizedfield) {
                                        $params['language'] = $requestedLanguage;
                                    }
                                }

                                return $fieldDefinition->getForCsvExport($value, $params);
                            }
                        }
                    }
                } else {
                    // if the definition is not set try to get the definition from localized fields
                    /** @var DataObject\ClassDefinition\Data\Localizedfields|null $locFields */
                    $locFields = $object->getClass()->getFieldDefinition('localizedfields');

                    if ($locFields) {
                        $fieldDefinition = $locFields->getFieldDefinition($field);
                        if ($fieldDefinition) {
                            return $fieldDefinition->getForCsvExport($object->get('localizedFields'), ['language' => $fallbackLanguage]);
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param array $helperDefinitions
     * @param string $key
     *
     * @return string[]|null
     *
     * @internal
     */
    public static function expandGridColumnForExport(array $helperDefinitions, string $key): ?array
    {
        $config = DataObjectService::getConfigForHelperDefinition($helperDefinitions, $key);
        if ($config instanceof AbstractOperator && $config->expandLocales()) {
            return $config->getValidLanguages();
        }

        return null;
    }

}
