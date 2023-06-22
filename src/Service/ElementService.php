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

use Pimcore\Config as PimcoreConfig;
use Pimcore\Bundle\AdminBundle\CustomView\Config ;
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
        protected UrlGeneratorInterface $urlGenerator,
        protected PimcoreConfig $config
    ) {
    }

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
        if($customViews) {
            foreach($customViews as $customView) {
                if($customView['id'] == $id) {
                    return $customView;
                }
            }
        }

        return null;
    }

    public function getElementTreeNodeConfig(ElementInterface $element, UserProxy|User|null $user): array
    {
        $tmpNode = [
            'id' => $element->getId(),
            'key' => $element->getKey(),
            'type' => $element->getType(),
            'path' => $element->getRealFullPath(),
            'basePath' => $element->getRealPath(),
            'locked' => $element->isLocked(),
            'lockOwner' => (bool)$element->getLocked()
        ];

        $permissions = $element->getUserPermissions($user);

        //Asset
        if ($element instanceof Asset) {
            $tmpNode['text'] = htmlspecialchars($element->getFilename());
            $tmpNode['elementType'] = 'asset';

            $this->getAssetPermissionsConfig($element, $permissions, $tmpNode, $user);
        }
        //Document
        if ($element instanceof Document) {
            $tmpNode['text'] = $element->getKey();
            $tmpNode['elementType'] = 'document';
            $tmpNode['idx'] = $element->getIndex();
            $tmpNode['published'] = $element->isPublished();

            $this->getDocumentPermissionsAndChildrenConfig($element, $permissions, $tmpNode);
        }
        //DataObject
        if ($element instanceof DataObject) {
            $tmpNode['idx'] = $element->getIndex();
            $tmpNode['sortBy'] = $element->getChildrenSortBy();
            $tmpNode['sortOrder'] = $element->getChildrenSortOrder();
            $tmpNode['text'] = htmlspecialchars($element->getKey());
            $tmpNode['elementType'] = 'object';

           $this->getDataObjectPermissionsAndChildrenConfig($element, $permissions, $tmpNode, $user);
        }

        $this->addAdminStyle($element, ElementAdminStyleEvent::CONTEXT_TREE, $tmpNode);

        if ($element instanceof Asset) {
            $this->getAssetThumbnailConfig($element, $tmpNode);
        }
        if ($element instanceof Document) {
            $this->getDocumentSpecificSettings($element, $tmpNode);
        }
        if ($element instanceof DataObject) {
            $this->getDataObjectSpecificSettings($tmpNode);
        }

        if ($element->isLocked()) {
            $tmpNode['cls'] .= 'pimcore_treenode_locked ';
        }
        if ($element->getLocked()) {
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

        switch ($asset) {
            case $asset instanceof Asset\Image:
                $thumbnailUrl = $this->urlGenerator->generate('pimcore_admin_asset_getimagethumbnail', $params);
                break;
            case $asset instanceof Asset\Folder:
                $thumbnailUrl = $this->urlGenerator->generate('pimcore_admin_asset_getfolderthumbnail', $params);
                break;
            case $asset instanceof Asset\Video && \Pimcore\Video::isAvailable():
                $thumbnailUrl = $this->urlGenerator->generate('pimcore_admin_asset_getvideothumbnail', $params);
                break;
            case $asset instanceof Asset\Document && \Pimcore\Document::isAvailable() && $asset->getPageCount():
                $thumbnailUrl = $this->urlGenerator->generate('pimcore_admin_asset_getdocumentthumbnail', $params);
                break;
            case $asset instanceof Asset\Audio:
                $thumbnailUrl = '/bundles/pimcoreadmin/img/flat-color-icons/speaker.svg';
                break;
            default:
                $thumbnailUrl = '/bundles/pimcoreadmin/img/filetype-not-supported.svg';
        }

        return $thumbnailUrl;
    }

    public function getAssetPermissionsConfig(
        ElementInterface $element,
        array $permissions,
        array &$tmpNode,
        UserProxy|User|null $user
    ): void
    {
        $hasChildren = $element->getDao()->hasChildren($user);

        $tmpNode['permissions'] = [
            'remove' => $permissions['delete'],
            'settings' => $permissions['settings'],
            'rename' => $permissions['rename'],
            'publish' => $permissions['publish'],
            'view' => $permissions['view'],
            'list' => $permissions['list'],
        ];

        if ($element instanceof Asset\Folder) {
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
    public function getDataObjectPermissionsAndChildrenConfig(
        ElementInterface $element,
        array $permissions,
        array &$tmpNode,
        UserProxy|User|null $user
    ): void
    {
        $allowedTypes = [DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER];
        if ($element instanceof DataObject\Concrete && $element->getClass()->getShowVariants()) {
            $allowedTypes[] = DataObject::OBJECT_TYPE_VARIANT;
        }
        $hasChildren = $element->getDao()->hasChildren($allowedTypes, null, $user);

        $tmpNode['allowDrop'] = false;

        $tmpNode['isTarget'] = true;
        if ($tmpNode['type'] != DataObject::OBJECT_TYPE_VARIANT) {
            $tmpNode['allowDrop'] = true;
        }

        $tmpNode['allowChildren'] = true;
        $tmpNode['leaf'] = !$hasChildren;
        $tmpNode['cls'] = 'pimcore_class_icon ';

        if ($element instanceof DataObject\Concrete) {
            $tmpNode['published'] = $element->isPublished();
            $tmpNode['className'] = $element->getClass()->getName();

            if (!$element->isPublished()) {
                $tmpNode['cls'] .= 'pimcore_unpublished ';
            }

            $tmpNode['allowVariants'] = $element->getClass()->getAllowVariants();
        }

        $tmpNode['expanded'] = !$hasChildren;
        $tmpNode['permissions'] = $permissions;
    }

    public function getDocumentPermissionsAndChildrenConfig(
        ElementInterface $element,
        array $permissions,
        array &$tmpNode
    ): void
    {
        $hasChildren = $element->getDao()->hasChildren(null, Admin::getCurrentUser());

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

        foreach ($treeNodePermissionTypes as $key => $permissionType) {
            $permissionKey = is_string($key) ? $key : $permissionType;
            $tmpNode['permissions'][$permissionKey] = $permissions[$permissionType];
        }

        $tmpNode['expandable'] = $hasChildren;
        $tmpNode['loaded'] = !$hasChildren;

        // set type specific settings
        if ($element->getType() == 'page') {
            $tmpNode['leaf'] = false;
            $tmpNode['expanded'] = !$hasChildren;

            // test for a site
            if ($site = Site::getByRootId($element->getId())) {
                $site->setRootDocument(null);
                $tmpNode['site'] = $site->getObjectVars();
            }
        } elseif ($element->getType() == 'folder' ||
            $element->getType() == 'link' ||
            $element->getType() == 'hardlink') {
            $tmpNode['leaf'] = false;
            $tmpNode['expanded'] = !$hasChildren;
        } elseif (method_exists($element, 'getDocumentTreeNodeConfig')) {
            $tmp = $element->getDocumentTreeNodeConfig();
            $tmpNode = array_merge($tmpNode, $tmp);
        }
    }

    public function getAssetThumbnailConfig(Asset $asset, array &$tmpAsset): void
    {
        try {
            switch ($asset) {
                case $asset instanceof Asset\Image:
                    $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);

                    // we need the dimensions for the wysiwyg editors, so that they can resize the image immediately
                    if ($asset->getCustomSetting('imageDimensionsCalculated')) {
                        $tmpAsset['imageWidth'] = $asset->getCustomSetting('imageWidth');
                        $tmpAsset['imageHeight'] = $asset->getCustomSetting('imageHeight');
                    }
                    break;

                case $asset instanceof Asset\Video:
                    if (\Pimcore\Video::isAvailable()) {
                        $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);
                    }
                    break;

                case $asset instanceof Asset\Document:
                    // add the PDF check here, otherwise the preview layer in admin is shown without content
                    if (
                        \Pimcore\Document::isAvailable() &&
                        \Pimcore\Document::isFileTypeSupported($asset->getFilename())
                    ) {
                        $tmpAsset['thumbnail'] = $this->getThumbnailUrl($asset, ['origin' => 'treeNode']);
                    }
                    break;
            }
        } catch (\Exception $e) {
            Logger::error('Cannot get dimensions of asset, seems to be broken. Reason: ' . $e->getMessage());
        }
    }

    public function getDocumentSpecificSettings(Document $document, array &$tmpDocument): void
    {
        // PREVIEWS temporary disabled, need's to be optimized some time
        if ($document instanceof Document\Page && isset($this->config['documents']['generate_preview'])) {
            $thumbnailFile = $document->getPreviewImageFilesystemPath();
            // only if the thumbnail exists and isn't out of time
            if (file_exists($thumbnailFile) && filemtime($thumbnailFile) > ($document->getModificationDate() - 20)) {
                $tmpDocument['thumbnail'] =
                    $this->urlGenerator->generate('pimcore_admin_document_page_display_preview_image',
                    ['id' => $document->getId()]
                );
            }
        }

        $tmpDocument['cls'] = '';

        if ($document instanceof Document\Page) {
            $tmpDocument['url'] = $document->getFullPath();
            $site = Frontend::getSiteForDocument($document);
            if ($site instanceof Site) {
                $tmpDocument['url'] =
                    'http://' .
                    $site->getMainDomain() .
                    preg_replace(
                        '@^' .
                        $site->getRootPath() .
                        '/?@',
                        '/',
                        $document->getRealFullPath()
                    );
            }
        }

        if ($document->getProperty('navigation_exclude')) {
            $tmpDocument['cls'] .= 'pimcore_navigation_exclude ';
        }

        if (!$document->isPublished()) {
            $tmpDocument['cls'] .= 'pimcore_unpublished ';
        }
    }

    public function getDataObjectSpecificSettings(array &$tmpObject): void
    {
        if ($tmpObject['leaf']) {
            $tmpObject['expandable'] = false;
            $tmpObject['leaf'] = false; //this is required to allow drag&drop
            $tmpObject['expanded'] = true;
            $tmpObject['loaded'] = true;
        }
    }

}
