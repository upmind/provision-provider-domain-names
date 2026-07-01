<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Gandi\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Gandi configuration.
 *
 * @property-read string $api_token API token
 * @property-read bool|null $sandbox Make API requests against the sandbox environment
 * @property-read string|null $sharing_id Organization/reseller billing ID
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_token'  => ['required', 'string', 'min:6'],
            'sandbox'    => ['nullable', 'boolean'],
            'sharing_id' => ['nullable', 'string'],
        ]);
    }
}
