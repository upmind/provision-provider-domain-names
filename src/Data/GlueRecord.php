<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Glue record data.
 *
 * @property-read string $hostname Glue record hostname
 * @property-read string[] $ips IP addresses
 */
class GlueRecord extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'string'],
            'ips' => ['required', 'array'],
            'ips.*' => ['ip'],
        ]);
    }

    /**
     * @return $this
     */
    public function setHostname(string $hostname): self
    {
        $this->setValue('hostname', $hostname);
        return $this;
    }

    /**
     * @return $this
     */
    public function setIps(array $ips): self
    {
        $this->setValue('ips', $ips);
        return $this;
    }
}
