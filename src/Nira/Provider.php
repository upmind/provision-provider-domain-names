<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Nira;

use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\DomainNames\CoccaEpp\Client;
use Upmind\ProvisionProviders\DomainNames\CoccaEpp\Provider as CoccaEppProvider;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Nira\Data\Configuration;

use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusResult;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationParams;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationResult;

class Provider extends CoccaEppProvider
{
    /**
     * @var Configuration
     */
    protected $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    protected function getSupportedTlds(): array
    {
        return ['ng'];
    }

    protected function makeClient(): Client
    {
        return new Client(
            $this->configuration->epp_username,
            $this->configuration->epp_password,
            'registry.nic.net.ng',
            700,
            __DIR__ . '/cert.pem',
            $this->getLogger()
        );
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('NIRA')
            ->setDescription('Register, transfer, renew and manage NIRA domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/nira-logo.webp');
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
