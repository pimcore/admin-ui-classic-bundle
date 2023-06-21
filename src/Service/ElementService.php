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

namespace Pimcore\Bundle\AdminBundle\Service;

use Pimcore\Bundle\AdminBundle\CustomView\Config;
use Pimcore\Bundle\AdminBundle\Event\ElementAdminStyleEvent;
use Pimcore\Logger;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Document;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Site;
use Pimcore\Model\User;
use Pimcore\Security\User\User as UserProxy;
use Pimcore\Tool\Admin;
use Pimcore\Tool\Frontend;
use Pimcore\Bundle\AdminBundle\Controller\Traits\AdminStyleTrait;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal
 */
class ElementService
{
    use AdminStyleTrait;

    public function getElementTreeNodeConfig(ElementInterface $element, UserProxy|User|null $user): array
    {
        $permissions = $element->getUserPermissions($user);
        if($element instanceof Asset) {
            $text = htmlspecialchars($element->getFilename());
            $elementType = 'asset';
        }
        if($element instanceof DataObject) {
            $text = htmlspecialchars($element->getKey());
            $elementType = 'object';
            $allowedTypes = [DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER];
            if($element instanceof DataObject\Concrete && $element->getClass()->getShowVariants()) {
                $allowedTypes[] = DataObject::OBJECT_TYPE_VARIANT;
            }
        }
        if($element instanceof Document) {
            $text = $element->getKey();
            $elementType = 'document';
        }

        $tmpNode = [
            'id' => $element->getId(),
            'key' => $element->getKey(),
            'text' => $text,
            'path' => $element->getRealFullPath(),
            'basePath' => $element->getRealPath(),
            'locked' => $element->isLocked(),
            'lockOwner' => (bool)$element->getLocked(),
            'elementType' => $elementType,
        ];

        $hasChildren = match ($elementType) {
            'document' => $element->getDao()->hasChildren(null, Admin::getCurrentUser()),
            'asset' => $element->getDao()->hasChildren($user),
            'object' => $element->getDao()->hasChildren($allowedTypes, null, $user)
        };

        //Asset
        // set type specific settings
        if($element instanceof Asset) {
            $tmpNode['permissions'] = [
                'remove' => $permissions['delete'],
                'settings' => $permissions['settings'],
                'rename' => $permissions['rename'],
                'publish' => $permissions['publish'],
                'view' => $permissions['view'],
                'list' => $permissions['list'],
            ];

            if($element instanceof Asset\Folder) {
                $tmpNode['leaf'] = false;
                $tmpNode['expanded'] = !$hasChildren;
                $tmpNode['loaded'] = !$hasChildren;
                $tmpNode['permissions']['create'] = $permissions['create'];
                $tmpNode['thumbnail'] = $this->getThumbnailUrl($element, ['origin' => 'treeNode']);
            } else {
                $tmpNode['leaf'] = true;
                $tmpNode['expandable'] = false;
                $tmpNode['expanded'] = false;
            }
        }
        //Document
        if($element instanceof Document) {
            $treeNodePermissionTypes = [
                'view',
                'remove' => 'delete',
                'settings',
                'rename',
                'publish',
                'unpublish',
                'create',
                'list',
            ];

            foreach($treeNodePermissionTypes as $key => $permissionType) {
                $permissionKey = is_string($key) ? $key : $permissionType;
                $tmpDocument['permissions'][$permissionKey] = $permissions[$permissionType];
            }

            $tmpNode['expandable'] = $hasChildren;
            $tmpNode['loaded'] = !$hasChildren;

            // set type specific settings
            if($element->getType() == 'page') {
                $tmpNode['leaf'] = false;
                $tmpNode['expanded'] = !$hasChildren;

                // test for a site
                if($site = Site::getByRootId($element->getId())) {
                    $site->setRootDocument(null);
                    $tmpNode['site'] = $site->getObjectVars();
                }
            } elseif($element->getType() == 'folder' || $element->getType() == 'link' || $element->getType() == 'hardlink') {
                $tmpNode['leaf'] = false;
                $tmpNode['expanded'] = !$hasChildren;
            } elseif(method_exists($element, 'getDocumentTreeNodeConfig')) {
                $tmp = $element->getDocumentTreeNodeConfig();
                $tmpNode = array_merge($tmpNode, $tmp);
            }


        }
        //DataObject
        if($element instanceof DataObject) {
            $tmpNode['allowDrop'] = false;

            $tmpNode['isTarget'] = true;
            if($tmpNode['type'] != DataObject::OBJECT_TYPE_VARIANT) {
                $tmpNode['allowDrop'] = true;
            }

            $tmpNode['allowChildren'] = true;
            $tmpNode['leaf'] = !$hasChildren;
            $tmpNode['cls'] = 'pimcore_class_icon ';

            if($element instanceof DataObject\Concrete) {
                $tmpNode['published'] = $element->isPublished();
                $tmpNode['className'] = $element->getClass()->getName();

                if(!$element->isPublished()) {
                    $tmpNode['cls'] .= 'pimcore_unpublished ';
                }

                $tmpNode['allowVariants'] = $element->getClass()->getAllowVariants();
            }

            $tmpObject['expanded'] = !$hasChildren;
            $tmpObject['permissions'] = $permissions;
        }

        $this->addAdminStyle($element, ElementAdminStyleEvent::CONTEXT_TREE, $tmpNode);

        if($element instanceof Asset) {
            $this->getAssetSpecificSettings($element, $tmpNode);
        }
        if($element instanceof Document) {
            $this->getDocumentSpecificSettings($element, $tmpNode);
        }
        if($element instanceof DataObject) {
            $this->getDataObjectSpecificSettings($element, $tmpNode);
        }

        if($element->isLocked()) {
            $tmpNode['cls'] .= 'pimcore_treenode_locked ';
        }
        if($element->getLocked()) {
            $tmpNode['cls'] .= 'pimcore_treenode_lockOwner ';
        }
        return $tmpNode;
    }

    public function getThumbnailUrl(Asset $asset, array $params = []): ?string
    {
        $defaults = [
            'id' => $asset->getId(),
            'treepreview' => true,
            '_dc' => $asset->getModificationDate(),
        ];

        $params = array_merge($defaults, $params);

        if($asset instanceof Asset\Image) {
            return $this->urlGenerator->generate('pimcore_admin_asset_getimagethumbnail', $params);
        }

        if($asset instanceof Asset\Folder) {
            return $this->urlGenerator->generate('pimcore_admin_asset_getfolderthumbnail', $params);
        }

        if($asset instanceof Asset\Video && \Pimcore\Video::isAvailable()) {
            return $this->urlGenerator->generate('pimcore_admin_asset_getvideothumbnail', $params);
        }

        if($asset instanceof Asset\Document && \Pimcore\Document::isAvailable() && $asset->getPageCount()) {
            return $this->urlGenerator->generate('pimcore_admin_asset_getdocumentthumbnail', $params);
        }

        if($asset instanceof Asset\Audio) {
            return '/bundles/pimcoreadmin/img/flat-color-icons/speaker.svg';
        }

        if($asset instanceof Asset) {
            return '/bundles/pimcoreadmin/img/filetype-not-supported.svg';
        }
    }

    public function getAssetSpecificSettings(Asset $asset, array &$tmpAsset): array
    {

        if($asset instanceof Asset\Image) {
            try {
                $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);

                // we need the dimensions for the wysiwyg editors, so that they can resize the image immediately
                if($asset->getCustomSetting('imageDimensionsCalculated')) {
                    $tmpAsset['imageWidth'] = $asset->getCustomSetting('imageWidth');
                    $tmpAsset['imageHeight'] = $asset->getCustomSetting('imageHeight');
                }
            } catch (\Exception $e) {
                Logger::debug('Cannot get dimensions of image, seems to be broken.');
            }
        } elseif($asset->getType() == 'video') {
            try {
                if(\Pimcore\Video::isAvailable()) {
                    $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);
                }
            } catch (\Exception $e) {
                Logger::debug('Cannot get dimensions of video, seems to be broken.');
            }
        } elseif($asset->getType() == 'document') {
            try {
                // add the PDF check here, otherwise the preview layer in admin is shown without content
                if(\Pimcore\Document::isAvailable() && \Pimcore\Document::isFileTypeSupported($asset->getFilename())) {
                    $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);
                }
            } catch (\Exception $e) {
                Logger::debug('Cannot get dimensions of video, seems to be broken.');
            }
        }
        // return $tmpAsset;
    }

    public function getDocumentSpecificSettings(Document $document, array &$tmpDocument)
    {
        $tmpDocument['idx'] = $document->getIndex();
        $tmpDocument['published'] = $document->isPublished();
        $tmpDocument['cls'] = '';

        if($document instanceof Document\Page) {
            $tmpDocument['url'] = $document->getFullPath();
            $site = Frontend::getSiteForDocument($document);
            if($site instanceof Site) {
                $tmpDocument['url'] = 'http://' . $site->getMainDomain() . preg_replace('@^' . $site->getRootPath() . '/?@', '/', $document->getRealFullPath());
            }
        }

        if($document->getProperty('navigation_exclude')) {
            $tmpDocument['cls'] .= 'pimcore_navigation_exclude ';
        }

        if(!$document->isPublished()) {
            $tmpDocument['cls'] .= 'pimcore_unpublished ';
        }
    }

    public function getDataObjectSpecificSettings(DataObject $dataObject, array &$tmpObject)
    {

        $tmpObject['idx'] = $dataObject->getIndex();
        $tmpObject['sortBy'] = $dataObject->getChildrenSortBy();
        $tmpObject['sortOrder'] = $dataObject->getChildrenSortOrder();

        if($tmpObject['leaf']) {
            $tmpObject['expandable'] = false;
            $tmpObject['leaf'] = false; //this is required to allow drag&drop
            $tmpObject['expanded'] = true;
            $tmpObject['loaded'] = true;
        }
    }

}
