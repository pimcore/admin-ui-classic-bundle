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
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\Service;

use Pimcore\Bundle\AdminBundle\Controller\Traits\AdminStyleTrait;
use Pimcore\Bundle\AdminBundle\Event\ElementAdminStyleEvent;
use Pimcore\Logger;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\User;
use Pimcore\Security\User\User as UserProxy;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @method \Pimcore\Model\Asset\Dao getDao()
 */
class AssetService
{
    use AdminStyleTrait;

    public function __construct(
        protected UrlGeneratorInterface $urlGenerator
    )
    {
    }
    public   function getTreeNodeConfig(ElementInterface $element, UserProxy|User|null $user): array {

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
}
