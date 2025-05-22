<?php

declare(strict_types=1);

/**
 * This source file is available under the terms of the
 * Pimcore Open Core License (POCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (https://www.pimcore.com)
 *  @license    Pimcore Open Core License (POCL)
 */

namespace Pimcore\Bundle\AdminBundle\GDPR\DataProvider;

/**
 * @internal
 */
class SentMail implements DataProviderInterface
{
    public function getName(): string
    {
        return 'sentMail';
    }

    public function getJsClassName(): string
    {
        return 'pimcore.settings.gdpr.dataproviders.sentMail';
    }

    public function getSortPriority(): int
    {
        return 20;
    }
}
