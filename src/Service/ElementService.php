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

    public function __construct(
        protected UrlGeneratorInterface $urlGenerator
    )
    {
    }

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

    public function getAssetTreeNodeConfig(ElementInterface $element, UserProxy|User|null $user): array
    {

        /** @var Asset $asset */
        $asset = $element;

        $permissions = $asset->getUserPermissions($user);

        $tmpAsset = [
            'id' => $asset->getId(),
            'key' => $element->getKey(),
            'text' => htmlspecialchars($asset->getFilename()),
            'type' => $asset->getType(),
            'path' => $asset->getRealFullPath(),
            'basePath' => $asset->getRealPath(),
            'locked' => $asset->isLocked(),
            'lockOwner' => $asset->getLocked() ? true : false,
            'elementType' => 'asset',
            'permissions' => [
                'remove' => $permissions['delete'],
                'settings' => $permissions['settings'],
                'rename' => $permissions['rename'],
                'publish' => $permissions['publish'],
                'view' => $permissions['view'],
                'list' => $permissions['list'],
            ],
        ];

        $hasChildren = $asset->getDao()->hasChildren($user);

        // set type specific settings
        if($asset instanceof Asset\Folder) {
            $tmpAsset['leaf'] = false;
            $tmpAsset['expanded'] = !$hasChildren;
            $tmpAsset['loaded'] = !$hasChildren;
            $tmpAsset['permissions']['create'] = $permissions['create'];
            $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);
        } else {
            $tmpAsset['leaf'] = true;
            $tmpAsset['expandable'] = false;
            $tmpAsset['expanded'] = false;
        }

        $this->addAdminStyle($asset, ElementAdminStyleEvent::CONTEXT_TREE, $tmpAsset);

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

        $tmpAsset['cls'] = '';
        if($asset->isLocked()) {
            $tmpAsset['cls'] .= 'pimcore_treenode_locked ';
        }
        if($asset->getLocked()) {
            $tmpAsset['cls'] .= 'pimcore_treenode_lockOwner ';
        }

        return $tmpAsset;
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

    public function getDataObjectTreeNodeConfig(ElementInterface $element, UserProxy|User|null $user): array {
        /** @var DataObject $child */
        $child = $element;

        $tmpObject = [
            'id' => $child->getId(),
            'idx' => $child->getIndex(),
            'key' => $child->getKey(),
            'sortBy' => $child->getChildrenSortBy(),
            'sortOrder' => $child->getChildrenSortOrder(),
            'text' => htmlspecialchars($child->getKey()),
            'type' => $child->getType(),
            'path' => $child->getRealFullPath(),
            'basePath' => $child->getRealPath(),
            'elementType' => 'object',
            'locked' => $child->isLocked(),
            'lockOwner' => $child->getLocked() ? true : false,
        ];

        $allowedTypes = [DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER];
        if($child instanceof DataObject\Concrete && $child->getClass()->getShowVariants()) {
            $allowedTypes[] = DataObject::OBJECT_TYPE_VARIANT;
        }

        $hasChildren = $child->getDao()->hasChildren($allowedTypes, null, $user);

        $tmpObject['allowDrop'] = false;

        $tmpObject['isTarget'] = true;
        if($tmpObject['type'] != DataObject::OBJECT_TYPE_VARIANT) {
            $tmpObject['allowDrop'] = true;
        }

        $tmpObject['allowChildren'] = true;
        $tmpObject['leaf'] = !$hasChildren;
        $tmpObject['cls'] = 'pimcore_class_icon ';

        if($child instanceof DataObject\Concrete) {
            $tmpObject['published'] = $child->isPublished();
            $tmpObject['className'] = $child->getClass()->getName();

            if(!$child->isPublished()) {
                $tmpObject['cls'] .= 'pimcore_unpublished ';
            }

            $tmpObject['allowVariants'] = $child->getClass()->getAllowVariants();
        }

        $this->addAdminStyle($child, ElementAdminStyleEvent::CONTEXT_TREE, $tmpObject);

        $tmpObject['expanded'] = !$hasChildren;
        $tmpObject['permissions'] = $child->getUserPermissions($user);

        if($child->isLocked()) {
            $tmpObject['cls'] .= 'pimcore_treenode_locked ';
        }
        if($child->getLocked()) {
            $tmpObject['cls'] .= 'pimcore_treenode_lockOwner ';
        }

        if($tmpObject['leaf']) {
            $tmpObject['expandable'] = false;
            $tmpObject['leaf'] = false; //this is required to allow drag&drop
            $tmpObject['expanded'] = true;
            $tmpObject['loaded'] = true;
        }

        return $tmpObject;
    }

    public function getDocumentTreeNodeConfig(ElementInterface $element, $user): array {
        $site = null;
        /** @var Document $childDocument */
        $childDocument = $element;
        $container = \Pimcore::getContainer();

        /** @var \Pimcore\Config $config */
        $config = $container->get(Config::class);

        $tmpDocument = [
            'id' => $childDocument->getId(),
            'key' => $childDocument->getKey(),
            'idx' => $childDocument->getIndex(),
            'text' => $childDocument->getKey(),
            'type' => $childDocument->getType(),
            'path' => $childDocument->getRealFullPath(),
            'basePath' => $childDocument->getRealPath(),
            'locked' => $childDocument->isLocked(),
            'lockOwner' => $childDocument->getLocked() ? true : false,
            'published' => $childDocument->isPublished(),
            'elementType' => 'document',
            'leaf' => true,
        ];

        $permissions = $childDocument->getUserPermissions($user);

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

        $hasChildren = $childDocument->getDao()->hasChildren(null, Admin::getCurrentUser());

        // add icon
        $tmpDocument['expandable'] = $hasChildren;
        $tmpDocument['loaded'] = !$hasChildren;

        // set type specific settings
        if($childDocument->getType() == 'page') {
            $tmpDocument['leaf'] = false;
            $tmpDocument['expanded'] = !$hasChildren;

            // test for a site
            if($site = Site::getByRootId($childDocument->getId())) {
                $site->setRootDocument(null);
                $tmpDocument['site'] = $site->getObjectVars();
            }
        } elseif($childDocument->getType() == 'folder' || $childDocument->getType() == 'link' || $childDocument->getType() == 'hardlink') {
            $tmpDocument['leaf'] = false;
            $tmpDocument['expanded'] = !$hasChildren;
        } elseif(method_exists($childDocument, 'getDocumentTreeNodeConfig')) {
            $tmp = $childDocument->getDocumentTreeNodeConfig();
            $tmpDocument = array_merge($tmpDocument, $tmp);
        }

        $this->addAdminStyle($childDocument, ElementAdminStyleEvent::CONTEXT_TREE, $tmpDocument);

        // PREVIEWS temporary disabled, need's to be optimized some time
        if($childDocument instanceof Document\Page && isset($config['documents']['generate_preview'])) {
            $thumbnailFile = $childDocument->getPreviewImageFilesystemPath();
            // only if the thumbnail exists and isn't out of time
            if(file_exists($thumbnailFile) && filemtime($thumbnailFile) > ($childDocument->getModificationDate() - 20)) {
                $tmpDocument['thumbnail'] = $this->urlGenerator->generate('pimcore_admin_document_page_display_preview_image', ['id' => $childDocument->getId()]);
            }
        }

        $tmpDocument['cls'] = '';

        if($childDocument instanceof Document\Page) {
            $tmpDocument['url'] = $childDocument->getFullPath();
            $site = Frontend::getSiteForDocument($childDocument);
            if($site instanceof Site) {
                $tmpDocument['url'] = 'http://' . $site->getMainDomain() . preg_replace('@^' . $site->getRootPath() . '/?@', '/', $childDocument->getRealFullPath());
            }
        }

        if($childDocument->getProperty('navigation_exclude')) {
            $tmpDocument['cls'] .= 'pimcore_navigation_exclude ';
        }

        if(!$childDocument->isPublished()) {
            $tmpDocument['cls'] .= 'pimcore_unpublished ';
        }

        if($childDocument->isLocked()) {
            $tmpDocument['cls'] .= 'pimcore_treenode_locked ';
        }
        if($childDocument->getLocked()) {
            $tmpDocument['cls'] .= 'pimcore_treenode_lockOwner ';
        }

        return $tmpDocument;
    }
}
