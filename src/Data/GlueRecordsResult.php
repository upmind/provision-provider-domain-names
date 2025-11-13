<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Glue record result data.
 *
 * @property-read string $hostname Glue record hostname
 * @property-read string[] $ips IP addresses
 */
class GlueRecordsResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'string'],
            'ips' => ['required', 'array'],
            'ips.*' => ['ip'],
        ]);
    }
}
