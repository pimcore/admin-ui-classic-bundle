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

namespace Pimcore\Bundle\AdminBundle\Service\GridData;

use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Model\DataObject\Service;
use Pimcore\Tool\Admin as AdminTool;
use Pimcore\Tool\Session;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;

/**
 *
 * @internal
 */
class DataObject extends Element
{
    /**
     * Language only user for classification store !!!
     */
    public static function getData(AbstractObject $object, array $fields = null, string $requestedLanguage = null, array $params = []): array
    {
        $data = self::gridElementData($object);
        $csvMode = $params['csvMode'] ?? false;

        if ($object instanceof Concrete) {

            $user = AdminTool::getCurrentUser();

            $context = ['object' => $object,
                'purpose' => 'gridview',
                'language' => $requestedLanguage, ];
            $data['classname'] = $object->getClassName();
            $data['idPath'] = Service::getIdPath($object);
            $data['inheritedFields'] = [];
            $data['permissions'] = $object->getUserPermissions($user);
            $data['locked'] = $object->isLocked();

            if (is_null($fields)) {
                $fields = array_keys($object->getclass()->getFieldDefinitions());
            }

            $haveHelperDefinition = false;

            foreach ($fields as $key) {
                $brickDescriptor = null;
                $brickKey = null;
                $brickType = null;
                $brickGetter = null;
                $dataKey = $key;
                $keyParts = explode('~', $key);

                $def = $object->getClass()->getFieldDefinition($key, $context);

                if (str_starts_with($key, '#')) {
                    if (!$haveHelperDefinition) {
                        $helperDefinitions = self::getHelperDefinitions();
                        $haveHelperDefinition = true;
                    }
                    if (!empty($helperDefinitions[$key])) {
                        $context['fieldname'] = $key;
                        $data[$key] = \Pimcore\Model\DataObject\Service::calculateCellValue($object, $helperDefinitions, $key, $context);
                    }
                } elseif (str_starts_with($key, '~')) {
                    $type = $keyParts[1];
                    if ($type === 'classificationstore') {
                        $data[$key] = self::getStoreValueForObject($object, $key, $requestedLanguage);
                    }
                } elseif (count($keyParts) > 1) {
                    // brick
                    $brickType = $keyParts[0];
                    if (str_contains($brickType, '?')) {
                        $brickDescriptor = substr($brickType, 1);
                        $brickDescriptor = json_decode($brickDescriptor, true);
                        $brickType = $brickDescriptor['containerKey'];
                    }

                    $brickKey = $keyParts[1];

                    $key = Service::getFieldForBrickType($object->getclass(), $brickType);

                    $brickClass = Objectbrick\Definition::getByKey($brickType);
                    $context['outerFieldname'] = $key;

                    if ($brickDescriptor) {
                        $innerContainer = $brickDescriptor['innerContainer'] ?? 'localizedfields';
                        /** @var Model\DataObject\ClassDefinition\Data\Localizedfields $localizedFields */
                        $localizedFields = $brickClass->getFieldDefinition($innerContainer);
                        $def = $localizedFields->getFieldDefinition($brickDescriptor['brickfield']);
                    } elseif ($brickClass instanceof Objectbrick\Definition) {
                        $def = $brickClass->getFieldDefinition($brickKey, $context);
                    }
                }

                if (!empty($key)) {
                    // some of the not editable field require a special response
                    $getter = 'get' . ucfirst($key);
                    $needLocalizedPermissions = false;

                    // if the definition is not set try to get the definition from localized fields
                    if (!$def) {
                        /** @var Model\DataObject\ClassDefinition\Data\Localizedfields|null $locFields */
                        $locFields = $object->getClass()->getFieldDefinition('localizedfields');
                        if ($locFields) {
                            $def = $locFields->getFieldDefinition($key, $context);
                            if ($def) {
                                $needLocalizedPermissions = true;
                            }
                        }
                    }

                    //relation type fields with remote owner do not have a getter
                    if (method_exists($object, $getter)) {
                        //system columns must not be inherited
                        if (in_array($key, Concrete::SYSTEM_COLUMN_NAMES)) {
                            $data[$dataKey] = $object->$getter();
                        } else {
                            $valueObject = self::getValueForObject($object, $key, $brickType, $brickKey, $def, $context, $brickDescriptor, $requestedLanguage);
                            $data['inheritedFields'][$dataKey] = ['inherited' => $valueObject->objectid != $object->getId(), 'objectid' => $valueObject->objectid];

                            if ($csvMode || method_exists($def, 'getDataForGrid')) {
                                if ($brickKey) {
                                    $context['containerType'] = 'objectbrick';
                                    $context['containerKey'] = $brickType;
                                    $context['outerFieldname'] = $key;
                                }

                                $params = array_merge($params, ['context' => $context]);
                                if (!isset($params['purpose'])) {
                                    $params['purpose'] = 'gridview';
                                }

                                if ($csvMode) {
                                    $getterParams = ['language' => $requestedLanguage];
                                    $tempData = $def->getForCsvExport($object, $getterParams);
                                } elseif (method_exists($def, 'getDataForGrid')) {
                                    $tempData = $def->getDataForGrid($valueObject->value, $object, $params);
                                } else {
                                    continue;
                                }

                                if ($def instanceof ClassDefinition\Data\Localizedfields) {
                                    $needLocalizedPermissions = true;
                                    foreach ($tempData as $tempKey => $tempValue) {
                                        $data[$tempKey] = $tempValue;
                                    }
                                } else {
                                    $data[$dataKey] = $tempData;
                                    if (
                                        $def instanceof Model\DataObject\ClassDefinition\Data\Select
                                        && !$def->useConfiguredOptions()
                                        && $def->getOptionsProviderClass()
                                    ) {
                                        $data[$dataKey . '%options'] = $def->getOptions();
                                    }

                                    // to prevent malforded grids in case of empty fieldcollections
                                    if ($def instanceof ClassDefinition\Data\Fieldcollections) {
                                        $data[$dataKey] ??= '';
                                    }
                                }
                            } else {
                                $data[$dataKey] = $valueObject->value;
                            }
                        }
                    }

                    // because the key for the classification store has not a direct getter, you have to check separately if the data is inheritable
                    if (str_starts_with($key, '~')) {
                        $curClassAttributeValue = $data[$key] ?? null;
                        $isValueEmpty = is_array($curClassAttributeValue) ? empty($curClassAttributeValue['value'] ?? null) : empty($curClassAttributeValue);

                        if ($isValueEmpty) {
                            $type = $keyParts[1];

                            if ($type === 'classificationstore') {
                                if (!empty($inheritedData = self::getInheritedData($object, $key, $requestedLanguage))) {
                                    $data[$dataKey] = $inheritedData['value'];
                                    $data['inheritedFields'][$dataKey] = ['inherited' => $inheritedData['parent']->getId() != $object->getId(), 'objectid' => $inheritedData['parent']->getId()];
                                }
                            }
                        }
                    }
                    if ($needLocalizedPermissions) {
                        if (!$user->isAdmin()) {
                            $locale = \Pimcore::getContainer()->get(LocaleServiceInterface::class)->findLocale();

                            $permissionTypes = ['View', 'Edit'];
                            foreach ($permissionTypes as $permissionType) {
                                //TODO, this needs refactoring! Ideally, call it only once!
                                $languagesAllowed = Service::getLanguagePermissions($object, $user, 'l' . $permissionType);

                                if ($languagesAllowed) {
                                    $languagesAllowed = array_keys($languagesAllowed);

                                    if (!in_array($locale, $languagesAllowed)) {
                                        $data['metadata']['permission'][$key]['no' . $permissionType] = 1;
                                        if ($permissionType === 'View') {
                                            $data[$key] = null;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $data;
    }

    public static function getHelperDefinitions(): array
    {
        $stack = \Pimcore::getContainer()->get('request_stack');
        if ($stack->getMainRequest()?->hasSession()) {
            $session = $stack->getSession();

            return Session::useBag($session, function (AttributeBagInterface $session) {
                return $session->get('helpercolumns', []);
            }, 'pimcore_gridconfig');
        }

        return [];
    }

    /**
     * gets value for given object and getter, including inherited values
     *
     * @return \stdClass value and objectid where the value comes from
     */
    private static function getValueForObject(Concrete $object, string $key, string $brickType = null, string $brickKey = null, ClassDefinition\Data $fieldDefinition = null, array $context = [], array $brickDescriptor = null, string $requestedLanguage = null): \stdClass
    {
        $getter = 'get' . ucfirst($key);
        $value = null;

        try {
            $value = $object->$getter($requestedLanguage ?? AdminTool::getCurrentUser()?->getLanguage());
        } catch (\Throwable) {
        }

        if (empty($value)) {
            $value = $object->$getter();
        }
        if (!empty($value) && !empty($brickType)) {
            $getBrickType = 'get' . ucfirst($brickType);
            $value = $value->$getBrickType();
            if (!empty($value) && !empty($brickKey)) {
                if ($brickDescriptor) {
                    $innerContainer = $brickDescriptor['innerContainer'] ?? 'localizedfields';
                    $localizedFields = $value->{'get' . ucfirst($innerContainer)}();
                    $brickDefinition = Model\DataObject\Objectbrick\Definition::getByKey($brickType);
                    /** @var Model\DataObject\ClassDefinition\Data\Localizedfields $fieldDefinitionLocalizedFields */
                    $fieldDefinitionLocalizedFields = $brickDefinition->getFieldDefinition('localizedfields');
                    $fieldDefinition = $fieldDefinitionLocalizedFields->getFieldDefinition($brickKey);
                    $value = $localizedFields->getLocalizedValue($brickDescriptor['brickfield']);
                } else {
                    $brickFieldGetter = 'get' . ucfirst($brickKey);
                    $value = $value->$brickFieldGetter();
                }
            }
        }

        if (!$fieldDefinition) {
            $fieldDefinition = $object->getClass()->getFieldDefinition($key, $context);
        }

        if (!empty($brickType) && !empty($brickKey) && !$brickDescriptor) {
            $brickClass = Objectbrick\Definition::getByKey($brickType);
            $context = ['object' => $object, 'outerFieldname' => $key];
            $fieldDefinition = $brickClass->getFieldDefinition($brickKey, $context);
        }

        if ($fieldDefinition->isEmpty($value) && $fieldDefinition->supportsInheritance()) {
            $parent = Service::hasInheritableParentObject($object);
            if (!empty($parent)) {
                return self::getValueForObject($parent, $key, $brickType, $brickKey, $fieldDefinition, $context, $brickDescriptor);
            }
        }

        $result = new \stdClass();
        $result->value = $value;
        $result->objectid = $object->getId();

        return $result;
    }

    /**
     * gets store value for given object and key
     */
    private static function getStoreValueForObject(Concrete $object, string $key, ?string $requestedLanguage): mixed
    {
        $keyParts = explode('~', $key);

        if (str_starts_with($key, '~')) {
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

                    /** @var Model\DataObject\ClassDefinition\Data\Classificationstore $csFieldDefinition */
                    $csFieldDefinition = $object->getClass()->getFieldDefinition($field);
                    $csLanguage = $requestedLanguage;

                    if (!$csFieldDefinition->isLocalized()) {
                        $csLanguage = 'default';
                    }

                    $fielddata = $classificationStoreData->getLocalizedKeyValue($groupId, $keyid, $csLanguage, true, true);

                    $keyConfig = Model\DataObject\Classificationstore\KeyConfig::getById($keyid);
                    $type = $keyConfig->getType();
                    $definition = json_decode($keyConfig->getDefinition(), true);
                    $definition = \Pimcore\Model\DataObject\Classificationstore\Service::getFieldDefinitionFromJson($definition, $type);

                    if (method_exists($definition, 'getDataForGrid')) {
                        $fielddata = $definition->getDataForGrid($fielddata, $object);
                    }

                    return $fielddata;
                }
            }
        }

        return null;
    }

    protected static function getInheritedData(Concrete $object, string $key, string $requestedLanguage): array
    {
        if (!$parent = Service::hasInheritableParentObject($object)) {
            return [];
        }

        $inheritedValue = self::getStoreValueForObject($parent, $key, $requestedLanguage);
        if (
            (!is_array($inheritedValue) && $inheritedValue !== null) ||
            (
                is_array($inheritedValue) &&
                (
                    array_is_list($inheritedValue) || //for table field types
                    !empty($inheritedValue['value'] ?? null)
                )
            )
        ) {
            return [
                'parent' => $parent,
                'value' => $inheritedValue,
            ];
        }

        return self::getInheritedData($parent, $key, $requestedLanguage);
    }
}
