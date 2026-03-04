<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpusDNS\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * OpusDNS configuration.
 *
 * @property-read string $client_id OAuth2 Client ID
 * @property-read string $client_secret OAuth2 Client Secret
 * @property-read bool|null $sandbox Use sandbox environment
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'client_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
            'sandbox' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Whether to use the sandbox environment.
     */
    public function isSandbox(): bool
    {
        return boolval($this->sandbox);
    }

    /**
     * Get the API base URL based on the environment.
     */
    public function getBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://sandbox.opusdns.com'
            : 'https://api.opusdns.com';
    }
}
