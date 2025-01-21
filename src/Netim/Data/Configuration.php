<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netim\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Example configuration.
 *
 * @property-read string $Username Login id
 * @property-read string $Das_Password API token
 * @property-read bool|null $Sandbox Make API requests against the sandbox environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'Username' => ['required', 'string', 'min:3'],
            'Das_Password' => ['required', 'string', 'min:6'],
            'Sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
