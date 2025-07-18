<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Hexonet\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Hexonet configuration.
 *
 * @property-read string $username
 * @property-read string $password
 * @property-read string|null $migrated
 * @property-read bool|null $sandbox
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6', 'max:16'],
            'migrated' => ['nullable', 'string', 'in:not-migrated,centralnic-reseller,moniker'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
