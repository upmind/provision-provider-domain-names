<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Ascio\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Ascio configuration
 *
 * @property-read string $username Account
 * @property-read string $password Password
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string', 'min:6'],
            'password' => ['required', 'string', 'min:3'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
