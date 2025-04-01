<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TwentyI\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * 20i Reseller API credentials.
 *
 * @property-read string $general_api_key 20i reseller general api key
 */

class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'general_api_key' => ['required', 'string'],
        ]);
    }
}
