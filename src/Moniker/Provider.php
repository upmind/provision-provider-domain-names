<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Moniker;

use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper\CentralNicResellerApi;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Provider as CentralNicResellerProvider;
use Upmind\ProvisionProviders\DomainNames\Moniker\Data\Configuration;

/**
 * Moniker provider.
 *
 * Moniker is using the same API as CentralNic Reseller, so it extends it, but passing 'MONIKER' as the registrar.
 */
class Provider extends CentralNicResellerProvider
{
    public function __construct(Configuration $configuration)
    {
        parent::__construct($configuration);
    }

    /**
     * @throws \Exception
     */
    protected function api(): CentralNicResellerApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $this->api = new CentralNicResellerApi($this->configuration, $this->getLogger(), 'MONIKER');

        return $this->api;
    }
}
