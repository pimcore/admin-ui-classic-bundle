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

namespace Pimcore\Bundle\AdminBundle\Controller\Admin;

use Pimcore\Bundle\AdminBundle\Controller\AdminAbstractController;
use Pimcore\Bundle\AdminBundle\Helper\User as UserHelper;
use Pimcore\Bundle\AdminBundle\Perspective\Config;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Logger;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Element;
use Pimcore\Model\User;
use Pimcore\SystemSettingsConfig;
use Pimcore\Tool;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
class UserController extends AdminAbstractController implements KernelControllerEventInterface
{
    /**
     * @Route("/user/tree-get-children-by-id", name="pimcore_admin_user_treegetchildrenbyid", methods={"GET"})
     */
    public function treeGetChildrenByIdAction(Request $request): JsonResponse
    {
        $list = new User\Listing();
        $list->setCondition('parentId = ?', (int)$request->get('node'));
        $list->setOrder('ASC');
        $list->setOrderKey('name');
        $list->load();

        $users = [];
        if (is_array($list->getUsers())) {
            foreach ($list->getUsers() as $user) {
                if ($user->getId() && $user->getName() != 'system') {
                    $users[] = $this->getTreeNodeConfig($user);
                }
            }
        }

        return $this->adminJson($users);
    }

    protected function getTreeNodeConfig(User|User\Folder $user): array
    {
        $tmpUser = [
            'id' => $user->getId(),
            'text' => $user->getName(),
            'elementType' => 'user',
            'type' => $user->getType(),
            'qtipCfg' => [
                'title' => 'ID: ' . $user->getId(),
            ],
        ];

        // set type specific settings
        if ($user instanceof User\Folder) {
            $tmpUser['leaf'] = false;
            $tmpUser['iconCls'] = 'pimcore_icon_folder';
            $tmpUser['expanded'] = true;
            $tmpUser['allowChildren'] = true;

            if ($user->hasChildren()) {
                $tmpUser['expanded'] = false;
            } else {
                $tmpUser['loaded'] = true;
            }
        } else {
            $tmpUser['leaf'] = true;
            $tmpUser['iconCls'] = 'pimcore_icon_user';
            if (!$user->getActive()) {
                $tmpUser['cls'] = ' pimcore_unpublished';
            }
            $tmpUser['allowChildren'] = false;
            $tmpUser['admin'] = $user->isAdmin();
        }

        return $tmpUser;
    }

    /**
     * @Route("/user/add", name="pimcore_admin_user_add", methods={"POST"})
     */
    public function addAction(Request $request): JsonResponse
    {
        try {
            $type = $request->request->get('type');

            $className = User\Service::getClassNameForType($type);
            $user = $className::create([
                'parentId' => $request->request->getInt('parentId'),
                'name' => trim($request->request->get('name', '')),
                'password' => '',
                'active' => $request->request->getBoolean('active'),
            ]);

            if ($request->get('rid')) {
                $rid = (int)$request->get('rid');
                $rObject = $className::getById($rid);
                if ($rObject) {
                    if ($type == 'user' || $type == 'role') {
                        $user->setParentId($rObject->getParentId());
                        if ($rObject->getClasses()) {
                            $user->setClasses(implode(',', $rObject->getClasses()));
                        }
                        if ($rObject->getDocTypes()) {
                            $user->setDocTypes(implode(',', $rObject->getDocTypes()));
                        }

                        $keys = ['asset', 'document', 'object'];
                        foreach ($keys as $key) {
                            $getter = 'getWorkspaces' . ucfirst($key);
                            $setter = 'setWorkspaces' . ucfirst($key);
                            $workspaces = $rObject->$getter();
                            $clonedWorkspaces = [];
                            if (is_array($workspaces)) {
                                /** @var User\Workspace\AbstractWorkspace $workspace */
                                foreach ($workspaces as $workspace) {
                                    $vars = $workspace->getObjectVars();
                                    if ($key == 'object') {
                                        $workspaceClass = '\\Pimcore\\Model\\User\\Workspace\\DataObject';
                                    } else {
                                        $workspaceClass = '\\Pimcore\\Model\\User\\Workspace\\' . ucfirst($key);
                                    }
                                    $newWorkspace = new $workspaceClass();
                                    foreach ($vars as $varKey => $varValue) {
                                        $newWorkspace->setObjectVar($varKey, $varValue);
                                    }
                                    $newWorkspace->setUserId($user->getId());
                                    $clonedWorkspaces[] = $newWorkspace;
                                }
                            }

                            $user->$setter($clonedWorkspaces);
                        }

                        $user->setPerspectives($rObject->getPerspectives());
                        $user->setPermissions($rObject->getPermissions());

                        if ($type == 'user') {
                            $user->setAdmin(false);
                            if ($this->getAdminUser()->isAdmin()) {
                                $user->setAdmin($rObject->getAdmin());
                            }
                            $user->setActive($rObject->getActive());
                            $user->setRoles($rObject->getRoles());
                            $user->setWelcomeScreen($rObject->getWelcomescreen());
                            $user->setMemorizeTabs($rObject->getMemorizeTabs());
                            $user->setCloseWarning($rObject->getCloseWarning());
                        }

                        $user->setWebsiteTranslationLanguagesView($rObject->getWebsiteTranslationLanguagesView());
                        $user->setWebsiteTranslationLanguagesEdit($rObject->getWebsiteTranslationLanguagesEdit());

                        $user->save();
                    }
                }
            }

            return $this->adminJson([
                'success' => true,
                'id' => $user->getId(),
            ]);
        } catch (\Exception $e) {
            return $this->adminJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @throws \Exception
     */
    protected function populateChildNodes(User\AbstractUser $node, array &$currentList, bool $roleMode): array
    {
        $currentUser = \Pimcore\Tool\Admin::getCurrentUser();

        $list = $roleMode ? new User\Role\Listing() : new User\Listing();
        $list->setCondition('parentId = ?', $node->getId());
        $list->setOrder('ASC');
        $list->setOrderKey('name');
        $list->load();

        $childList = $roleMode ? $list->getRoles() : $list->getUsers();
        if (is_array($childList)) {
            foreach ($childList as $user) {
                if ($user->getId() == $currentUser->getId()) {
                    throw new \Exception('Cannot delete current user');
                }
                if ($user->getId() && $currentUser->getId() && $user->getName() != 'system') {
                    $currentList[] = $user;
                    $this->populateChildNodes($user, $currentList, $roleMode);
                }
            }
        }

        return $currentList;
    }

    /**
     * @Route("/user/delete", name="pimcore_admin_user_delete", methods={"DELETE"})
     *
     * @throws \Exception
     */
    public function deleteAction(Request $request): JsonResponse
    {
        $user = User\AbstractUser::getById((int)$request->get('id'));

        // only admins are allowed to delete admins and folders
        // because a folder might contain an admin user, so it is simply not allowed for users with the "users" permission
        if (($user instanceof User\Folder && !$this->getAdminUser()->isAdmin()) || ($user instanceof User && $user->isAdmin() && !$this->getAdminUser()->isAdmin())) {
            throw new \Exception('You are not allowed to delete this user');
        } else {
            if ($user instanceof User\Role\Folder) {
                $list = [$user];
                $this->populateChildNodes($user, $list, $user instanceof User\Role\Folder);
                $listCount = count($list);
                for ($i = $listCount - 1; $i >= 0; $i--) {
                    // iterate over the list from the so that nothing can get "lost"
                    $user = $list[$i];
                    $user->delete();
                }
            } else {
                if ($user->getId()) {
                    $user->delete();
                }
            }
        }

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/user/update", name="pimcore_admin_user_update", methods={"PUT"})
     *
     * @throws \Exception
     */
    public function updateAction(Request $request, TranslatorInterface $translator): JsonResponse
    {
        /** @var User|User\Role|null $user */
        $user = User\UserRole::getById($request->request->getInt('id'));
        $currentUserIsAdmin = $this->getAdminUser()->isAdmin();

        if (!$user) {
            throw $this->createNotFoundException();
        }

        if ($user instanceof User && $user->isAdmin() && !$currentUserIsAdmin) {
            throw $this->createAccessDeniedHttpException('Only admin users are allowed to modify admin users');
        }

        if ($request->get('data')) {
            $values = $this->decodeJson($request->get('data'), true);

            if (!empty($values['password'])) {
                if (strlen($values['password']) < 10) {
                    throw new \Exception('Passwords have to be at least 10 characters long');
                }
                $values['password'] = Tool\Authentication::getPasswordHash($user->getName(), $values['password']);
            }

            // check if there are permissions transmitted, if so reset them all to false (they will be set later)
            foreach ($values as $key => $value) {
                if (strpos($key, 'permission_') === 0) {
                    if (method_exists($user, 'setAllAclToFalse')) {
                        $user->setAllAclToFalse();
                    }

                    break;
                }
            }

            if ($user instanceof User && isset($values['2fa_required'])) {
                $user->setTwoFactorAuthentication('required', (bool) $values['2fa_required']);
            }

            $user->setValues($values);

            // only admins are allowed to create admin users
            // if the logged in user isn't an admin, set admin always to false
            if ($user instanceof User && !$currentUserIsAdmin) {
                $user->setAdmin(false);
            }

            // check for permissions
            $availableUserPermissionsList = new User\Permission\Definition\Listing();
            $availableUserPermissions = $availableUserPermissionsList->load();

            foreach ($availableUserPermissions as $permission) {
                if (isset($values['permission_' . $permission->getKey()])) {
                    $user->setPermission($permission->getKey(), (bool) $values['permission_' . $permission->getKey()]);
                }
            }

            // check for workspaces
            if ($request->get('workspaces')) {
                $processedPaths = ['object' => [], 'asset' => [], 'document' => []]; //array to find if there are multiple entries for a path
                $workspaces = $this->decodeJson($request->get('workspaces'), true);
                foreach ($workspaces as $type => $spaces) {
                    $newWorkspaces = [];
                    foreach ($spaces as $space) {
                        if (in_array($space['path'], $processedPaths[$type])) {
                            throw new \Exception('Error saving workspaces as multiple entries found for path "' . $space['path'] .'" in '.$translator->trans((string)$type, [], 'admin') . 's');
                        }

                        $element = Element\Service::getElementByPath($type, $space['path']);
                        if ($element) {
                            $className = '\\Pimcore\\Model\\User\\Workspace\\' . Element\Service::getBaseClassNameForElement($type);
                            $workspace = new $className();
                            $workspace->setValues($space);

                            $workspace->setCid($element->getId());
                            $workspace->setCpath($element->getRealFullPath());
                            $workspace->setUserId($user->getId());

                            $newWorkspaces[] = $workspace;
                            $processedPaths[$type][] = $space['path'];
                        }
                    }
                    $user->{'setWorkspaces' . ucfirst($type)}($newWorkspaces);
                }
            }
        }

        if ($user instanceof User && $request->get('keyBindings')) {
            $keyBindings = json_decode($request->get('keyBindings'), true);
            $tmpArray = [];
            foreach ($keyBindings as $action => $item) {
                $tmpArray[] = json_decode($item, true);
            }
            $tmpArray = array_values(array_filter($tmpArray));
            $tmpArray = json_encode($tmpArray);

            $user->setKeyBindings($tmpArray);
        }

        $user->save();

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/user/get", name="pimcore_admin_user_get", methods={"GET"})
     *
     * @throws \Exception
     */
    public function getAction(Request $request): JsonResponse
    {
        $userId = (int)$request->get('id');
        if ($userId < 1) {
            throw $this->createNotFoundException();
        }

        $user = User::getById($userId);

        if (!$user) {
            throw $this->createNotFoundException();
        }

        if ($user->isAdmin() && !$this->getAdminUser()->isAdmin()) {
            throw $this->createAccessDeniedHttpException('Only admin users are allowed to modify admin users');
        }

        // workspaces
        $types = ['asset', 'document', 'object'];
        foreach ($types as $type) {
            $workspaces = $user->{'getWorkspaces' . ucfirst($type)}();
            foreach ($workspaces as $wKey => $workspace) {
                $el = Element\Service::getElementById($type, $workspace->getCid());
                if ($el) {
                    // direct injection => not nice but in this case ok ;-)
                    $workspace->path = $el->getRealFullPath();
                    $workspaces[$wKey] = $workspace->getObjectVars();
                }
            }
            $user->{'setWorkspaces' . ucfirst($type)}($workspaces);
        }

        // object <=> user dependencies
        $userObjects = DataObject\Service::getObjectsReferencingUser($user->getId());
        $userObjectData = [];
        $hasHidden = false;

        foreach ($userObjects as $o) {
            if ($o->isAllowed('list')) {
                $userObjectData[] = [
                    'path' => $o->getRealFullPath(),
                    'id' => $o->getId(),
                    'subtype' => $o->getClass()->getName(),
                ];
            } else {
                $hasHidden = true;
            }
        }

        // get available permissions
        $availableUserPermissionsList = new User\Permission\Definition\Listing();
        $availableUserPermissionsList->setOrderKey('category');
        $availableUserPermissions = $availableUserPermissionsList->load();

        $availableUserPermissionsData = [];
        if (is_array($availableUserPermissions)) {
            foreach ($availableUserPermissions as $availableUserPermission) {
                $availableUserPermissionsData[] = $availableUserPermission->getObjectVars();
            }
        }

        // get available roles
        $list = new User\Role\Listing();
        $list->setCondition('`type` = ?', ['role']);
        $list->load();

        $roles = [];
        if (is_array($list->getItems())) {
            foreach ($list->getItems() as $role) {
                $roles[] = [$role->getId(), $role->getName()];
            }
        }

        // unset confidential informations
        $userData = $user->getObjectVars();
        $userData['roles'] =  $user->getRoles();
        $userData['docTypes'] =  $user->getDocTypes();
        $contentLanguages = Tool\Admin::reorderWebsiteLanguages($user, Tool::getValidLanguages());
        $userData['contentLanguages'] = $contentLanguages;
        $userData['twoFactorAuthentication']['isActive'] = ($user->getTwoFactorAuthentication('enabled') || $user->getTwoFactorAuthentication('secret'));
        unset($userData['password']);
        unset($userData['twoFactorAuthentication']['secret']);
        $userData['hasImage'] = $user->hasImage();

        $availablePerspectives = Config::getAvailablePerspectives(null);

        return $this->adminJson([
            'success' => true,
            'user' => $userData,
            'roles' => $roles,
            'permissions' => $user->generatePermissionList(),
            'availablePermissions' => $availableUserPermissionsData,
            'availablePerspectives' => $availablePerspectives,
            'validLanguages' => Tool::getValidLanguages(),
            'validLocales' => Tool::getSupportedJSLocales(),
            'objectDependencies' => [
                'hasHidden' => $hasHidden,
                'dependencies' => $userObjectData,
            ],
        ]);
    }

    /**
     * @Route("/user/get-minimal", name="pimcore_admin_user_getminimal", methods={"GET"})
     */
    public function getMinimalAction(Request $request): JsonResponse
    {
        $user = User::getById((int)$request->get('id'));

        if (!$user) {
            throw $this->createNotFoundException();
        }

        $minimalUserData['id'] = $user->getId();
        $minimalUserData['admin'] = $user->isAdmin();
        $minimalUserData['active'] = $user->isActive();
        $minimalUserData['permissionInfo']['assets'] = $user->isAllowed('assets');
        $minimalUserData['permissionInfo']['documents'] = $user->isAllowed('documents');
        $minimalUserData['permissionInfo']['objects'] = $user->isAllowed('objects');

        return $this->adminJson($minimalUserData);
    }

    /**
     * @Route("/user/upload-current-user-image", name="pimcore_admin_user_uploadcurrentuserimage", methods={"POST"})
     */
    public function uploadCurrentUserImageAction(Request $request): JsonResponse
    {
        $user = $this->getAdminUser();
        if ($user != null) {
            if ($user->getId() == $request->get('id')) {
                return $this->uploadImageAction($request);
            } else {
                Logger::warn('prevented save current user, because ids do not match. ');

                return $this->adminJson(false);
            }
        } else {
            return $this->adminJson(false);
        }
    }

    /**
     * @Route("/user/update-current-user", name="pimcore_admin_user_updatecurrentuser", methods={"PUT"})
     */
    public function updateCurrentUserAction(Request $request, ValidatorInterface $validator): JsonResponse
    {
        //TODO Can be completely validated with Symfony Validator
        $user = $this->getAdminUser();

        $isPasswordReset = Tool\Session::useBag($request->getSession(), function (AttributeBagInterface $adminSession) {
            if ($adminSession->get('password_reset')) {
                return true;
            }

            return false;
        });

        if ($user != null) {
            if ($user->getId() == $request->get('id')) {
                $values = $this->decodeJson($request->get('data'), true);

                unset($values['name']);
                unset($values['id']);
                unset($values['admin']);
                unset($values['permissions']);
                unset($values['roles']);
                unset($values['active']);

                if (!empty($values['new_password'])) {
                    $oldPasswordCheck = false;

                    if ($isPasswordReset) {
                        // if the user want to reset the password, the old password isn't required
                        $oldPasswordCheck = true;
                    } elseif (!empty($values['old_password'])) {
                        $errors = $validator->validate($values['old_password'], [new UserPassword()]);

                        if (count($errors) === 0) {
                            $oldPasswordCheck = true;
                        }
                    }

                    if (strlen($values['new_password']) < 10) {
                        throw new \Exception('Passwords have to be at least 10 characters long');
                    }

                    if ($oldPasswordCheck && $values['new_password'] == $values['retype_password']) {

                        if (Tool\Authentication::verifyPassword($user, $values['new_password'])) {
                            throw new \Exception('The new password cannot be the same as the old one');
                        }

                        $values['password'] = Tool\Authentication::getPasswordHash($user->getName(), $values['new_password']);
                    } else {
                        if (!$oldPasswordCheck) {
                            return $this->adminJson(['success' => false, 'message' => 'incorrect_password']);
                        }

                        return $this->adminJson(['success' => false, 'message' => 'password_cannot_be_changed']);
                    }
                }

                $user->setValues($values);

                if ($request->get('keyBindings')) {
                    $keyBindings = json_decode($request->get('keyBindings'), true);
                    $tmpArray = [];
                    foreach ($keyBindings as $action => $item) {
                        $tmpArray[] = json_decode($item, true);
                    }
                    $tmpArray = array_values(array_filter($tmpArray));
                    $tmpArray = json_encode($tmpArray);

                    $user->setKeyBindings($tmpArray);
                }

                $user->save();

                return $this->adminJson(['success' => true]);
            } else {
                Logger::warn('prevented save current user, because ids do not match. ');

                return $this->adminJson(false);
            }
        } else {
            return $this->adminJson(false);
        }
    }

    /**
     * @Route("/user/get-current-user", name="pimcore_admin_user_getcurrentuser", methods={"GET"})
     */
    public function getCurrentUserAction(Request $request): Response
    {
        $user = $this->getAdminUser();

        $list = new User\Permission\Definition\Listing();
        $definitions = $list->load();

        foreach ($definitions as $definition) {
            $user->setPermission($definition->getKey(), $user->isAllowed($definition->getKey()));
        }

        // unset confidential informations
        $userData = $user->getObjectVars();
        $contentLanguages = Tool\Admin::reorderWebsiteLanguages($user, Tool::getValidLanguages());
        $userData['contentLanguages'] = $contentLanguages;
        $userData['keyBindings'] = UserHelper::getDefaultKeyBindings($user);

        unset($userData['password']);
        $userData['twoFactorAuthentication'] = $user->getTwoFactorAuthentication();
        unset($userData['twoFactorAuthentication']['secret']);
        $userData['twoFactorAuthentication']['isActive'] = $user->getTwoFactorAuthentication('enabled') && $user->getTwoFactorAuthentication('secret');
        $userData['hasImage'] = $user->hasImage();

        $userData['isPasswordReset'] = Tool\Session::useBag($request->getSession(), function (AttributeBagInterface $adminSession) {
            return $adminSession->get('password_reset');
        });

        $userData['validLocales'] = Tool::getSupportedJSLocales();

        $response = new Response('pimcore.currentuser = ' . $this->encodeJson($userData));
        $response->headers->set('Content-Type', 'text/javascript');

        return $response;
    }

    // ROLES

    /**
     * @Route("/user/role-tree-get-children-by-id", name="pimcore_admin_user_roletreegetchildrenbyid", methods={"GET"})
     */
    public function roleTreeGetChildrenByIdAction(Request $request): JsonResponse
    {
        $list = new User\Role\Listing();
        $list->setCondition('parentId = ?', (int)$request->get('node'));
        $list->load();

        $roles = [];
        if (is_array($list->getItems())) {
            foreach ($list->getItems() as $role) {
                $roles[] = $this->getRoleTreeNodeConfig($role);
            }
        }

        return $this->adminJson($roles);
    }

    protected function getRoleTreeNodeConfig(User\Role|User\Role\Folder $role): array
    {
        $tmpUser = [
            'id' => $role->getId(),
            'text' => $role->getName(),
            'elementType' => 'role',
            'qtipCfg' => [
                'title' => 'ID: ' . $role->getId(),
            ],
        ];

        // set type specific settings
        if ($role instanceof User\Role\Folder) {
            $tmpUser['leaf'] = false;
            $tmpUser['iconCls'] = 'pimcore_icon_folder';
            $tmpUser['expanded'] = true;
            $tmpUser['allowChildren'] = true;

            if ($role->hasChildren()) {
                $tmpUser['expanded'] = false;
            } else {
                $tmpUser['loaded'] = true;
            }
        } else {
            $tmpUser['leaf'] = true;
            $tmpUser['iconCls'] = 'pimcore_icon_roles';
            $tmpUser['allowChildren'] = false;
        }

        return $tmpUser;
    }

    /**
     * @Route("/user/role-get", name="pimcore_admin_user_roleget", methods={"GET"})
     */
    public function roleGetAction(Request $request): JsonResponse
    {
        $role = User\Role::getById((int)$request->get('id'));

        if (!$role) {
            throw $this->createNotFoundException();
        }

        // workspaces
        $types = ['asset', 'document', 'object'];
        foreach ($types as $type) {
            $workspaces = $role->{'getWorkspaces' . ucfirst($type)}();
            foreach ($workspaces as $wKey => $workspace) {
                $el = Element\Service::getElementById($type, $workspace->getCid());
                if ($el) {
                    // direct injection => not nice but in this case ok ;-)
                    $workspace->path = $el->getRealFullPath();
                    $workspaces[$wKey] = $workspace->getObjectVars();
                }
            }
            $role->{'setWorkspaces' . ucfirst($type)}($workspaces);
        }

        $replaceFn = function ($value) {
            return $value->getObjectVars();
        };

        // get available permissions
        $availableUserPermissionsList = new User\Permission\Definition\Listing();
        $availableUserPermissionsList->setOrderKey('category');
        $availableUserPermissions = $availableUserPermissionsList->load();
        $availableUserPermissions = array_map($replaceFn, $availableUserPermissions);

        $availablePerspectives = Config::getAvailablePerspectives(null);

        return $this->adminJson([
            'success' => true,
            'role' => $role->getObjectVars(),
            'permissions' => $role->generatePermissionList(),
            'classes' => $role->getClasses(),
            'docTypes' => $role->getDocTypes(),
            'availablePermissions' => $availableUserPermissions,
            'availablePerspectives' => $availablePerspectives,
            'validLanguages' => Tool::getValidLanguages(),
        ]);
    }

    /**
     * @Route("/user/upload-image", name="pimcore_admin_user_uploadimage", methods={"POST"})
     *
     * @throws \Exception
     */
    public function uploadImageAction(Request $request): JsonResponse
    {
        $userObj = User::getById($this->getUserId($request));

        if (!$userObj) {
            throw $this->createNotFoundException();
        }

        if ($userObj->isAdmin() && !$this->getAdminUser()->isAdmin()) {
            throw $this->createAccessDeniedHttpException('Only admin users are allowed to modify admin users');
        }

        //Check if uploaded file is an image
        $avatarFile = $request->files->get('Filedata');

        $assetType = Asset::getTypeFromMimeMapping($avatarFile->getMimeType(), $avatarFile->getFileName());

        if (!$avatarFile instanceof UploadedFile || $assetType !== 'image') {
            throw new \Exception('Unsupported file format.');
        }

        $userObj->setImage($_FILES['Filedata']['tmp_name']);

        // set content-type to text/html, otherwise (when application/json is sent) chrome will complain in
        // Ext.form.Action.Submit and mark the submission as failed

        $response = $this->adminJson(['success' => true]);
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }

    /**
     * @Route("/user/delete-image", name="pimcore_admin_user_deleteimage", methods={"DELETE"})
     *
     * @throws \Exception
     */
    public function deleteImageAction(Request $request): JsonResponse
    {
        $userObj = User::getById($this->getUserId($request));

        if (!$userObj) {
            throw $this->createNotFoundException();
        }

        $adminUser = $this->getAdminUser();

        if (!$adminUser->isAdmin()) {
            if ($userObj->isAdmin()) {
                throw $this->createAccessDeniedHttpException('Only admin users are allowed to modify admin users');
            }

            if ($adminUser->getId() !== $userObj->getId()) {
                throw $this->createAccessDeniedHttpException('Only admin users are allowed to modify users other than themselves');
            }
        }

        $userObj->setImage(null);

        return $this->adminJson(['success' => true]);
    }

    /**
     * @Route("/user/disable-2fa", name="pimcore_admin_user_disable2fasecret", methods={"DELETE"})
     */
    public function disable2FaSecretAction(Request $request): JsonResponse
    {
        $user = $this->getAdminUser();
        $success = false;

        if (!$user->getTwoFactorAuthentication('required')) {
            $user->setTwoFactorAuthentication([]);
            $user->save();

            $success = true;
        }

        return $this->adminJson([
            'success' => $success,
        ]);
    }

    /**
     * @Route("/user/reset-2fa-secret", name="pimcore_admin_user_reset2fasecret", methods={"PUT"})
     */
    public function reset2FaSecretAction(Request $request): JsonResponse
    {
        $user = User::getById((int)$request->get('id'));
        if (!$user) {
            throw $this->createNotFoundException();
        }
        $user->setTwoFactorAuthentication('enabled', false);
        $user->setTwoFactorAuthentication('secret', '');
        $user->save();

        return $this->adminJson([
            'success' => true,
        ]);
    }

    /**
     * @Route("/user/reset-my-2fa-secret", name="pimcore_admin_user_reset_my_2fa_secret", methods={"PUT"})
     */
    public function resetMy2FaSecretAction(Request $request): JsonResponse
    {
        $user = $this->getAdminUser();
        $user->setTwoFactorAuthentication('required', true);
        $user->setTwoFactorAuthentication('enabled', false);
        $user->setTwoFactorAuthentication('secret', '');
        $user->save();

        return $this->adminJson([
            'success' => true,
        ]);
    }

    /**
     * @Route("/user/get-image", name="pimcore_admin_user_getimage", methods={"GET"})
     */
    public function getImageAction(Request $request): StreamedResponse
    {
        $userObj = User::getById($this->getUserId($request));
        if (!$userObj) {
            throw $this->createNotFoundException();
        }
        $stream = $userObj->getImage();

        return new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => 'image/png',
        ]);
    }

    /**
     * @Route("/user/get-token-login-link", name="pimcore_admin_user_gettokenloginlink", methods={"GET"})
     *
     * @throws \Exception
     */
    public function getTokenLoginLinkAction(Request $request, TranslatorInterface $translator): JsonResponse
    {
        $user = User::getById((int) $request->get('id'));

        if (!$user) {
            return $this->adminJson([
                'success' => false,
                'message' => $translator->trans('login_token_invalid_user_error', [], 'admin'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($user->isAdmin() && !$this->getAdminUser()->isAdmin()) {
            return $this->adminJson([
                'success' => false,
                'message' => $translator->trans('login_token_as_admin_non_admin_user_error', [], 'admin'),
            ], Response::HTTP_FORBIDDEN);
        }

        if (empty($user->getPassword())) {
            return $this->adminJson([
                'success' => false,
                'message' => $translator->trans('login_token_no_password_error', [], 'admin'),
            ], Response::HTTP_FORBIDDEN);
        }

        $token = Tool\Authentication::generateTokenByUser($user);
        $link = $this->generateCustomUrl([
            'token' => $token,
        ]);

        return $this->adminJson([
            'success' => true,
            'link' => $link,
        ]);
    }

    /**
     * @Route("/user/search", name="pimcore_admin_user_search", methods={"GET"})
     */
    public function searchAction(Request $request): JsonResponse
    {
        $q = '%' . $request->get('query') . '%';

        $list = new User\Listing();
        $list->setCondition('name LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR email LIKE ? OR id = ?', [$q, $q, $q, $q, (int)$request->get('query')]);
        $list->setOrder('ASC');
        $list->setOrderKey('name');
        $list->load();

        $users = [];
        if (is_array($list->getUsers())) {
            foreach ($list->getUsers() as $user) {
                if ($user instanceof User && $user->getId() && $user->getName() != 'system') {
                    $users[] = [
                        'id' => $user->getId(),
                        'name' => $user->getName(),
                        'email' => $user->getEmail(),
                        'firstname' => $user->getFirstname(),
                        'lastname' => $user->getLastname(),
                    ];
                }
            }
        }

        return $this->adminJson([
            'success' => true,
            'users' => $users,
        ]);
    }

    public function onKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // check permissions
        $unrestrictedActions = [
            'getCurrentUserAction', 'updateCurrentUserAction', 'getAvailablePermissionsAction', 'getMinimalAction',
            'getImageAction', 'uploadCurrentUserImageAction', 'disable2FaSecretAction', 'renew2FaSecretAction',
            'getUsersForSharingAction', 'getRolesForSharingAction', 'deleteImageAction',
        ];

        $this->checkActionPermission($event, 'users', $unrestrictedActions);
    }

    /**
     * @Route("/user/get-users-for-sharing", name="pimcore_admin_user_getusersforsharing", methods={"GET"})
     */
    public function getUsersForSharingAction(Request $request): JsonResponse
    {
        $this->checkPermission('share_configurations');

        return $this->getUsersAction($request);
    }

    /**
     * @Route("/user/get-roles-for-sharing", name="pimcore_admin_user_getrolesforsharing", methods={"GET"}))
     */
    public function getRolesForSharingAction(Request $request): JsonResponse
    {
        $this->checkPermission('share_configurations');

        return $this->getRolesAction($request);
    }

    /**
     * @Route("/user/get-users", name="pimcore_admin_user_getusers", methods={"GET"})
     */
    public function getUsersAction(Request $request): JsonResponse
    {
        $users = [];

        // get available user
        $list = new User\Listing();

        $conditions = [ 'type = "user"' ];

        if (!$request->get('include_current_user')) {
            $conditions[] = 'id != ' . $this->getAdminUser()->getId();
        }

        $list->setCondition(implode(' AND ', $conditions));

        $list->load();
        $userList = $list->getUsers();

        foreach ($userList as $user) {
            if (!$request->get('permission') || $user->isAllowed($request->get('permission'))) {
                $users[] = [
                    'id' => $user->getId(),
                    'label' => $user->getUsername(),
                ];
            }
        }

        return $this->adminJson(['success' => true, 'total' => count($users), 'data' => $users]);
    }

    /**
     * @Route("/user/get-roles", name="pimcore_admin_user_getroles", methods={"GET"})
     */
    public function getRolesAction(Request $request): JsonResponse
    {
        $roles = [];
        $list = new User\Role\Listing();

        $list->setCondition('`type` = "role"');
        $list->load();
        $roleList = $list->getRoles();

        foreach ($roleList as $role) {
            if (!$request->get('permission') || in_array($request->get('permission'), $role->getPermissions())) {
                $roles[] = [
                    'id' => $role->getId(),
                    'label' => $role->getName(),
                ];
            }
        }

        return $this->adminJson(['success' => true, 'total' => count($roles), 'data' => $roles]);
    }

    /**
     * @Route("/user/get-default-key-bindings", name="pimcore_admin_user_getdefaultkeybindings", methods={"GET"})
     */
    public function getDefaultKeyBindingsAction(Request $request): JsonResponse
    {
        return $this->adminJson(['success' => true, 'data' => UserHelper::getDefaultKeyBindings()]);
    }

    /**
     * @Route("/user/invitationlink", name="pimcore_admin_user_invitationlink", methods={"POST"})
     *
     * @throws \Exception
     */
    public function invitationLinkAction(Request $request, TranslatorInterface $translator, RouterInterface $router): JsonResponse
    {
        $success = false;
        $message = '';

        if ($username = $request->get('username')) {
            $user = User::getByName($username);
            if ($user instanceof User) {
                if (!$user->isActive()) {
                    $message .= 'User inactive  <br />';
                }

                if (!$user->getEmail()) {
                    $message .= 'User has no email address <br />';
                }
            } else {
                $message .= 'User unknown <br />';
            }

            if (empty($message)) {
                //generate random password if user has no password
                if (!$user->getPassword()) {
                    $user->setPassword(bin2hex(random_bytes(16)));
                    $user->save();
                }

                $token = Tool\Authentication::generateTokenByUser($user);

                $domain = SystemSettingsConfig::get()['general']['domain'];
                if (!$domain) {
                    throw new \Exception('No main domain set in system settings, unable to generate login invitation link');
                }

                $context = $router->getContext();
                $context->setHost($domain);

                $loginUrl = $this->generateCustomUrl([
                    'token' => $token,
                    'reset' => true,
                ]);

                try {
                    $mail = Tool::getMail([$user->getEmail()], 'Pimcore login invitation for ' . Tool::getHostname());
                    $mail->setIgnoreDebugMode(true);
                    $mail->text("Login to pimcore and change your password using the following link. This temporary login link will expire in  24 hours: \r\n\r\n" . $loginUrl);
                    $mail->send();

                    $success = true;
                    $message = sprintf($translator->trans('invitation_link_sent', [], 'admin_ext'), $user->getEmail());
                } catch (\Exception $e) {
                    $message .= 'could not send email';
                }
            }
        }

        return $this->adminJson([
            'success' => $success,
            'message' => $message,
        ]);
    }

    protected function getUserId(Request $request): int
    {
        if ($request->get('id')) {
            if ($this->getAdminUser()->getId() != $request->get('id')) {
                $this->checkPermission('users');
            }

            return (int) $request->get('id');
        }

        return $this->getAdminUser()->getId();
    }

    /**
     * @param int $referenceType //UrlGeneratorInterface::ABSOLUTE_URL, ABSOLUTE_PATH, RELATIVE_PATH, NETWORK_PATH
     *
     * @return string The generated URL
     */
    private function generateCustomUrl(array $params, string $fallbackUrl = 'pimcore_admin_login_check', int $referenceType = UrlGeneratorInterface::ABSOLUTE_URL): string
    {
        try {
            $adminEntryPointRoute = $this->getParameter('pimcore_admin.custom_admin_route_name');

            //try to generate invitation link for custom admin point
            $loginUrl = $this->generateUrl($adminEntryPointRoute, $params, $referenceType);
        } catch (\Exception $e) {
            //use default login check for invitation link
            $loginUrl = $this->generateUrl($fallbackUrl, $params, $referenceType);
        }

        return $loginUrl;
    }
}
