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

namespace Pimcore\Bundle\AdminBundle\Controller\Traits;

use Pimcore\Bundle\AdminBundle\Event\ElementAdminStyleEvent;
use Pimcore\Config;
use Pimcore\Model\Document;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Site;
use Pimcore\Tool\Admin;
use Pimcore\Tool\Frontend;

/**
 * @internal
 */
trait DocumentTreeConfigTrait
{
    use AdminStyleTrait;

    /**
     * @param ElementInterface $element
     *
     * @return array
     *
     * @throws \Exception
     */
    public function getTreeNodeConfig(ElementInterface $element): array
    {
        $site = null;
        /** @var Document $childDocument */
        $childDocument = $element;
        $container = \Pimcore::getContainer();

        /** @var Config $config */
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

        $permissions =  $childDocument->getUserPermissions($this->getPimcoreUser());

        $treeNodePermissionTypes = [
            'view',
            'remove'=>'delete',
            'settings',
            'rename',
            'publish',
            'unpublish',
            'create',
            'list',
        ];

        foreach ($treeNodePermissionTypes as $key => $permissionType) {
            $permissionKey = is_string($key) ? $key : $permissionType;
            $tmpDocument['permissions'][$permissionKey] = $permissions[$permissionType];
        }

        $hasChildren = $childDocument->getDao()->hasChildren(null, Admin::getCurrentUser());

        // add icon
        $tmpDocument['expandable'] = $hasChildren;
        $tmpDocument['loaded'] = !$hasChildren;

        // set type specific settings
        if ($childDocument->getType() == 'page') {
            $tmpDocument['leaf'] = false;
            $tmpDocument['expanded'] = !$hasChildren;

            // test for a site
            if ($site = Site::getByRootId($childDocument->getId())) {
                $site->setRootDocument(null);
                $tmpDocument['site'] = $site->getObjectVars();
            }
        } elseif ($childDocument->getType() == 'folder' || $childDocument->getType() == 'link' || $childDocument->getType() == 'hardlink') {
            $tmpDocument['leaf'] = false;
            $tmpDocument['expanded'] = !$hasChildren;
        } elseif (method_exists($childDocument, 'getTreeNodeConfig')) {
            $tmp = $childDocument->getTreeNodeConfig();
            $tmpDocument = array_merge($tmpDocument, $tmp);
        }

        $this->addAdminStyle($childDocument, ElementAdminStyleEvent::CONTEXT_TREE, $tmpDocument);

        // PREVIEWS temporary disabled, need's to be optimized some time
        if ($childDocument instanceof Document\Page && isset($config['documents']['generate_preview'])) {
            $thumbnailFile = $childDocument->getPreviewImageFilesystemPath();
            // only if the thumbnail exists and isn't out of time
            if (file_exists($thumbnailFile) && filemtime($thumbnailFile) > ($childDocument->getModificationDate() - 20)) {
                $tmpDocument['thumbnail'] = $this->generateUrl('pimcore_admin_document_page_display_preview_image', ['id' => $childDocument->getId()]);
            }
        }

        $tmpDocument['cls'] = '';

        if ($childDocument instanceof Document\Page) {
            $tmpDocument['url'] = $childDocument->getFullPath();
            $site = Frontend::getSiteForDocument($childDocument);
            if ($site instanceof Site) {
                $tmpDocument['url'] = 'http://' . $site->getMainDomain() . preg_replace('@^' . $site->getRootPath() . '/?@', '/', $childDocument->getRealFullPath());
            }
        }

        if ($childDocument->getProperty('navigation_exclude')) {
            $tmpDocument['cls'] .= 'pimcore_navigation_exclude ';
        }

        if (!$childDocument->isPublished()) {
            $tmpDocument['cls'] .= 'pimcore_unpublished ';
        }

        if ($childDocument->isLocked()) {
            $tmpDocument['cls'] .= 'pimcore_treenode_locked ';
        }
        if ($childDocument->getLocked()) {
            $tmpDocument['cls'] .= 'pimcore_treenode_lockOwner ';
        }

        return $tmpDocument;
    }
}
