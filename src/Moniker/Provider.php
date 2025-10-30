<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Moniker;

use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper\CentralNicResellerApi;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Provider as CentralNicResellerProvider;
use Upmind\ProvisionProviders\DomainNames\Moniker\Data\Configuration;

use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusResult;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationParams;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationResult;

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

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Moniker')
            ->setDescription(
                'Moniker, Built For Domain Investors. '
                . 'Buy, sell, manage, and monetize domain names.'
            )
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/moniker-logo_2x.png');
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getVerificationStatus(VerificationStatusParams $params): VerificationStatusResult
    {
        $this->errorResult('Operation not supported', $params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function resendVerificationEmail(ResendVerificationParams $params): ResendVerificationResult
    {
        $this->errorResult('Operation not supported', $params);
    }

}
