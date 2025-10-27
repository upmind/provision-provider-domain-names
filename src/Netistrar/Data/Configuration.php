<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netistrar\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Example configuration.
 *
 * @property-read string $username Login id
 * @property-read string $api_token API token
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_key' => ['required', 'string', 'min:10'],
            'api_secret' => ['required', 'string', 'min:10'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }
}
