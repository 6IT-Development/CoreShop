<?php

declare(strict_types=1);

/*
 * CoreShop
 *
 * This source file is available under two different licenses:
 *  - GNU General Public License version 3 (GPLv3)
 *  - CoreShop Commercial License (CCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.org)
 * @license    https://www.coreshop.org/license     GPLv3 and CCL
 *
 */

namespace CoreShop\Component\Index\Worker;

interface OpenSearchWorkerInterface extends WorkerInterface
{
    public const string FIELD_TYPE_NULL = 'null';

    public const string FIELD_TYPE_BOOLEAN = 'boolean';

    public const string FIELD_TYPE_FLOAT = 'float';

    public const string FIELD_TYPE_DOUBLE = 'double';

    public const string FIELD_TYPE_INTEGER = 'integer';

    public const string FIELD_TYPE_OBJECT = 'object';

    public const string FIELD_TYPE_ARRAY = 'array';

    public const string FIELD_TYPE_TEXT = 'text';

    public const string FIELD_TYPE_KEYWORD = 'keyword';

    public const string FIELD_TYPE_DATE = 'date';
}
