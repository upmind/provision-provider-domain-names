<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netistrar\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Netistrar configuration.
 *
 * @property-read string $api_key API Key
 * @property-read string $api_secret API Secret
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

    public function getApiKey(): string
    {
        return $this->api_key;
    }

    public function getApiSecret(): string
    {
        return $this->api_secret;
    }

    public function isSandbox(): bool
    {
        return (bool) $this->sandbox;
    }
}
