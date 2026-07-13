<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainNameApi;

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Throwable;
use Upmind\DomainNameApiSdk\ClientFactory;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\Enums\ContactType as DomainContactType;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Data\StatusResult;
use Upmind\ProvisionProviders\DomainNames\DomainNameApi\Data\DomainNameApiConfiguration;
use Upmind\ProvisionProviders\DomainNames\DomainNameApi\Helper\DomainNameApiInterface;
use Upmind\ProvisionProviders\DomainNames\DomainNameApi\Helper\DomainNameApiRestApi;
use Upmind\ProvisionProviders\DomainNames\DomainNameApi\Helper\DomainNameApiSoapApi;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusResult;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationParams;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationResult;
use Upmind\ProvisionProviders\DomainNames\Data\SetGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\RemoveGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecordsResult;

class Provider extends DomainNames implements ProviderInterface
{
    protected DomainNameApiConfiguration $configuration;
    protected ?DomainNameApiSoapApi $apiClient = null;
    protected ?DomainNameApiRestApi $restApiClient = null;

    /**
     * Max positions for nameservers
     */
    public const MAX_CUSTOM_NAMESERVERS = 4;

    /**
     * Common nameservers for DomainNameApi
     */
    public const NAMESERVERS = [
        ['host' => 'ns1.domainnameapi.com'],
        ['host' => 'ns2.domainnameapi.com']
    ];

    public const ERR_REGISTRANT_NOT_SET = 'Registrant contact details not set';

    public function __construct(DomainNameApiConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Domain Name Api')
            ->setDescription('Register, transfer, renew and manage domains, with over 700+ TLDs available')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/domainnameapi-logo.png');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        return $this->api()->checkAvailability($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        return $this->api()->registerWithContactInfo($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        return $this->api()->transfer($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        return $this->api()->renew($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        return $this->api()->getDetails($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        return $this->api()->modifyNameServer($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        return $this->api()->getEppCode($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        return $this->updateContact(UpdateContactParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
            'contact' => $params->contact,
            'contact_type' => DomainContactType::REGISTRANT(),
        ]));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateContact(UpdateContactParams $params): ContactResult
    {
        return $this->api()->saveContacts($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        return $this->api()->toggleTheftProtectionLock($params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Operation not supported');
    }

    protected function api(): DomainNameApiInterface
    {
        // If username is a UUID, that's the Reseller ID that points to the Rest API requirement.
        return Str::isUuid($this->configuration->username) ? $this->getRestApi() : $this->getSoapApi();
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported', $params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported', $params);
    }

    /**
     * @inheritDoc
     */
    public function getStatus(DomainInfoParams $params): StatusResult
    {
        return StatusResult::create()
            ->setStatus(StatusResult::STATUS_NOT_IMPLEMENTED)
            ->setExpiresAt(null)
            ->setRawStatuses(null);
    }

    private function getSoapApi(): DomainNameApiSoapApi
    {
        if (isset($this->apiClient)) {
            return $this->apiClient;
        }

        try {
            $client = (new ClientFactory())->create(
                $this->configuration->username,
                $this->configuration->password,
                $this->configuration->sandbox ? ClientFactory::ENV_TEST : ClientFactory::ENV_LIVE,
                $this->getLogger(),
                [
                    'keep_alive' => false
                ]
            );

            $this->apiClient = new DomainNameApiSoapApi($client);

            return $this->apiClient;
        } catch (Throwable $e) {
            $this->errorResult('Failed to connect to API', ['exception' => $e->getMessage()], [], $e);
        }
    }

    private function getRestApi(): DomainNameApiRestApi
    {
        if ($this->restApiClient !== null) {
            return $this->restApiClient;
        }

        $client = new Client([
            'base_uri' => $this->configuration->isSandbox()
                ? DomainNameApiRestApi::OTE_URL
                : DomainNameApiRestApi::PRODUCTION_URL,
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/DomainNameApi',
                'Authorization' => "sso-key {$this->configuration->api_key}:{$this->configuration->api_secret}",
                'Content-Type' => 'application/json',
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->isSandbox(),
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        $this->restApiClient = new DomainNameApiRestApi($client, $this->configuration);

        return $this->restApiClient;
    }
}
