<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\BDReseller\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * BD Reseller configuration.
 *
 * @property-read string $username API key
 * @property-read string $password Password
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:6'],
            'password' => ['required', 'string', 'min:3'],
        ]);
    }
}
