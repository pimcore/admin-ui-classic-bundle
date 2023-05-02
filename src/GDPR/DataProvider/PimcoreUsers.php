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

namespace Pimcore\Bundle\AdminBundle\GDPR\DataProvider;

use Pimcore\Db;
use Pimcore\Model\User;
use Pimcore\Security\User\TokenStorageUserResolver;

/**
 * @internal
 */
class PimcoreUsers implements DataProviderInterface
{
    protected TokenStorageUserResolver $userResolver;

    private string $logsDir;

    public function __construct(TokenStorageUserResolver $userResolver, string $logsDir)
    {
        $this->userResolver = $userResolver;
        $this->logsDir = $logsDir;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'pimcoreUsers';
    }

    /**
     * {@inheritdoc}
     */
    public function getJsClassName(): string
    {
        return 'pimcore.settings.gdpr.dataproviders.pimcoreUsers';
    }

    /**
     * {@inheritdoc}
     */
    public function getSortPriority(): int
    {
        return 30;
    }

    /**
     * @param int $id
     * @param string $firstname
     * @param string $lastname
     * @param string $email
     * @param int $start
     * @param int $limit
     * @param string|null $sort
     *
     * @return array
     */
    public function searchData(int $id, string $firstname, string $lastname, string $email, int $start, int $limit, string $sort = null): array
    {
        if (empty($id) && empty($firstname) && empty($lastname) && empty($email)) {
            return ['data' => [], 'success' => true, 'total' => 0];
        }

        $userListing = new User\Listing();

        $conditionParams = [];
        $conditionParamData = [];
        if ($id) {
            $conditionParams[] = 'id = ?';
            $conditionParamData[] = $id;
        }
        if ($firstname) {
            $conditionParams[] = 'firstname LIKE ?';
            $conditionParamData[] = '%' . $firstname . '%';
        }
        if ($lastname) {
            $conditionParams[] = 'lastname LIKE ?';
            $conditionParamData[] = '%' . $lastname . '%';
        }
        if ($email) {
            $conditionParams[] = 'email LIKE ?';
            $conditionParamData[] = '%' . $email . '%';
        }

        $userListing->setCondition(implode(' AND ', $conditionParams), $conditionParamData);
        $userListing->setLimit($limit);
        $userListing->setOffset($start);

        $sortingSettings = \Pimcore\Bundle\AdminBundle\Helper\QueryParams::extractSortingSettings(['sort' => $sort]);
        if ($sortingSettings['orderKey']) {
            $userListing->setOrderKey($sortingSettings['orderKey']);
        }
        if ($sortingSettings['order']) {
            $userListing->setOrder($sortingSettings['order']);
        }

        $userListing->load();

        $users = [];

        $currentUser = $this->userResolver->getUser();

        foreach ($userListing->getUsers() as $user) {
            $users[] = [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'firstname' => $user->getFirstname(),
                'lastname' => $user->getLastname(),
                'email' => $user->getEmail(),
                '__gdprIsDeletable' => $user->getId() != $currentUser->getId(),

            ];
        }

        return $users;
    }

    public function getExportData(int $id): array
    {
        $user = User::getById($id);
        $userData = [];
        if ($user) {
            $userData = $user->getObjectVars();
            unset($userData['password']);
            $userData['versions'] = $this->getVersionDataForUser($user);
            $userData['usageLog'] = $this->getUsageLogDataForUser($user);
        }

        return $userData;
    }

    protected function getVersionDataForUser(User\AbstractUser $user): array
    {
        $db = Db::get();
        $versions = $db->fetchAllAssociative("SELECT ctype, cid, note, FROM_UNIXTIME(`date`) AS 'date' FROM versions WHERE userId = ?", [$user->getId()]);

        return $versions;
    }

    protected function getUsageLogDataForUser(User\AbstractUser $user): array
    {
        $pattern = ' [' . $user->getId() . ',';
        $matches = [];

        $handle = @fopen($this->logsDir . '/usage.log', 'r');
        if ($handle) {
            while (!feof($handle)) {
                $buffer = fgets($handle);
                if ($buffer && strpos($buffer, $pattern) !== false) {
                    $matches[] = $buffer;
                }
            }
            fclose($handle);
        }

        $archiveFiles = glob($this->logsDir . '/usage-archive-*.log.gz');
        foreach ($archiveFiles as $archiveFile) {
            $handle = @gzopen($archiveFile, 'r');
            if ($handle) {
                while (!feof($handle)) {
                    $buffer = fgets($handle);
                    if (strpos($buffer, $pattern) !== false) {
                        $matches[] = $buffer;
                    }
                }
                fclose($handle);
            }
        }

        return $matches;
    }
}
