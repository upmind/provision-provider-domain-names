<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netistrar;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecordsResult;
use Upmind\ProvisionProviders\DomainNames\Data\RemoveGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationParams;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationResult;
use Upmind\ProvisionProviders\DomainNames\Data\SetGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusResult;
use Upmind\ProvisionProviders\DomainNames\Data\StatusResult;
use Upmind\ProvisionProviders\DomainNames\Netistrar\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Netistrar\Helper\NetistrarApi;
use Upmind\ProvisionProviders\DomainNames\Netistrar\Helper\NetistrarUtils;

/**
 * Netistrar domain provider
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected ?NetistrarApi $api = null;
    protected Configuration $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Netistrar')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/netistrar-logo.png')
            ->setDescription('Netistrar provider for domain names');
    }

    /**
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $promises = array_map(function (string $tld) use ($params) {
            $domain = Utils::getDomain($params->sld, $tld);

            return $this->api()
                ->liveAvailability($domain)
                ->then(function ($result) use ($domain, $tld) {
                    $canRegister = isset($result['availability']) && $result['availability'] === 'AVAILABLE';

                    return DacDomain::create()
                        ->setDomain($domain)
                        ->setTld($tld)
                        ->setCanRegister($canRegister)
                        ->setCanTransfer(
                            !$canRegister
                            && isset($result['additionalData']['transferType'])
                            && $result['additionalData']['transferType'] === 'pull'
                        )
                        ->setIsPremium($result['premiumSupported'] ?? false)
                        ->setDescription(sprintf(
                            'Domain is %s to register',
                            $canRegister ? 'available' : 'not available',
                        ));
                })
                ->otherwise(function (Throwable $e) use ($domain, $tld): DacDomain {
                    if ($e instanceof ProvisionFunctionError) {
                        return DacDomain::create()
                            ->setDomain($domain)
                            ->setTld($tld)
                            ->setCanRegister(false)
                            ->setCanTransfer(false)
                            ->setIsPremium(false)
                            ->setDescription($e->getMessage());
                    }

                    throw $e;
                });
        }, $params->tlds);

        return new DacResult([
            'domains' => PromiseUtils::all($promises)->wait(),
        ]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        if (!isset($params->registrant->register)) {
            $this->errorResult('Registrant details are required.');
        }

        // Validate the different contact types for required fields.
        $this->validateContactParam($params->registrant->register, 'registrant');

        if (isset($params->admin->register)) {
            $this->validateContactParam($params->admin->register, 'admin');
        }

        if (isset($params->tech->register)) {
            $this->validateContactParam($params->tech->register, 'technical');
        }

        if (isset($params->billing->register)) {
            $this->validateContactParam($params->tech->register, 'billing');
        }

        try {
            $this->api()->createDomain($params);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult(
                'Failed to register domain: ' . $e->getMessage(),
                $e->getData(),
                $params->toArray(),
                $e
            );
        }

        return $this->getInfo(DomainInfoParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
        ]))->setMessage('Domain registered');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function transfer(TransferParams $params): DomainResult
    {
        if (!isset($params->registrant->register)) {
            $this->errorResult('Registrant details are required.');
        }

        // Validate the different contact types for required fields.
        $this->validateContactParam($params->registrant->register, 'registrant');

        if (isset($params->admin->register)) {
            $this->validateContactParam($params->admin->register, 'admin');
        }

        if (isset($params->tech->register)) {
            $this->validateContactParam($params->tech->register, 'technical');
        }

        if (isset($params->billing->register)) {
            $this->validateContactParam($params->tech->register, 'billing');
        }

        try {
            $this->api()->transferDomain($params);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult(
                'Failed to transfer domain: ' . $e->getMessage(),
                $e->getData(),
                $params->toArray(),
                $e
            );
        }

        $domainInfo = $this->api()->getDomainInfo(Utils::getDomain($params->sld, $params->tld));

        return DomainResult::create($domainInfo)->setMessage('Domain transferred');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function renew(RenewParams $params): DomainResult
    {
        try {
            $this->api()->renewDomain($params);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult(
                'Failed to renew domain: ' . $e->getMessage(),
                $e->getData(),
                $params->toArray(),
                $e
            );
        }

        $domainName = Utils::getDomain($params->sld, $params->tld);
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage('Domain renewed');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (Throwable $e) {
            $this->errorResult('Failed to get domain information: ' . $e->getMessage(), [], $params->toArray());
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $this->validateContactParam($params->contact, 'registrant');

        try {
            $this->api()->updateRegistrantContact($domainName, $params);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult(
                'Failed to update registrant contact: ' . $e->getMessage(),
                $e->getData(),
                $params->toArray(),
                $e
            );
        }

        $domainInfo = $this->api()->getDomainInfo($domainName, [], true);

        // If the contact update is pending
        if ($domainInfo->registrant->get('status') === NetistrarApi::CONTACT_STATUS_PENDING) {
            return ContactResult::create($domainInfo->registrant)->setMessage('Registrant Contact update is pending');
        }

        return ContactResult::create($domainInfo->registrant)->setMessage('Registrant Contact updated');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateContact(UpdateContactParams $params): ContactResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $this->api()->updateDomainNameservers($domainName, $params);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult(
                'Failed to update domain nameservers: ' . $e->getMessage(),
                $e->getData(),
                $params->toArray(),
                $e
            );
        }

        $domainInfo = $this->api()->getDomainInfo($domainName);

        return NameserversResult::create($domainInfo->ns)->setMessage('Netistrar domain nameservers updated');
    }

    /**
    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $this->api()->updateDomainLock($domainName, (bool) $params->lock);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult(
                'Failed to lock/unlock domain: ' . $e->getMessage(),
                $e->getData(),
                $params->toArray(),
                $e
            );
        }

        return $this->_getInfo($domainName, 'Domain lock updated');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $this->api()->updateAutoRenew($domainName, (bool) $params->auto_renew);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult(
                'Failed to update auto-renew: ' . $e->getMessage(),
                $e->getData(),
                $params->toArray(),
                $e
            );
        }

        return $this->_getInfo($domainName, 'Domain auto-renew updated');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        if (!NetistrarUtils::isUkTld($params->tld)) {
            $this->errorResult('Operation not available for this TLD');
        }

        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->api()->getEppCode($domainName);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult(
                'Failed to get EPP Code: ' . $e->getMessage(),
                $e->getData(),
                $params->toArray(),
                $e
            );
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        if (NetistrarUtils::isUkTld($params->tld)) {
            $this->errorResult('Operation not available for this TLD');
        }

        $domainName = Utils::getDomain($params->sld, $params->tld);

        $domainInfo = $this->api()->getDomainInfo($domainName, ['tags']);

        $updatedTags = array_map('trim', explode(',', $params->ips_tag));
        $existingTags = $domainInfo['tags'] ?? [];

        sort($updatedTags);
        sort($existingTags);

        if ($updatedTags === $existingTags) {
            return ResultData::create()->setMessage("IPS Tag already set");
        }

        try {
            $this->api()->updateIpsTag($domainName, $updatedTags, $existingTags);
        } catch (ProvisionFunctionError $e) {
            $this->errorResult(
                'Failed to update IP Tags: ' . $e->getMessage(),
                $e->getData(),
                $params->toArray(),
                $e
            );
        }

        return ResultData::create()->setMessage('Domain IPS tag updated');
    }

    public function getVerificationStatus(VerificationStatusParams $params): VerificationStatusResult
    {
        $this->errorResult('Operation not available');
    }

    public function resendVerificationEmail(ResendVerificationParams $params): ResendVerificationResult
    {
        $this->errorResult('Operation not available');
    }

    public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        // Extract host prefix: "ns1.example.com" -> "ns1"
        $host = str_replace('.' . $domainName, '', $params->hostname);

        // Collect non-null IPs
        $ips = array_values(array_filter([
            $params->ip_1,
            $params->ip_2,
            $params->ip_3,
            $params->ip_4,
        ]));

        try {
            $this->api()->glueRecordsSet($domainName, $host, $ips);
        } catch (Throwable $e) {
            $this->errorResult('Failed to add Glue Record', [], [], $e);
        }

        try {
            return GlueRecordsResult::create([
                'glue_records' => $this->api()->glueRecordsList($domainName),
            ])->setMessage('Glue record created successfully');
        } catch (Throwable $e) {
            return GlueRecordsResult::create()->setMessage('Glue Record added, but could not retrieve updated list');
        }
    }

    public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $host = str_replace('.' . $domainName, '', $params->hostname);

        try {
            $this->api()->glueRecordsRemove($domainName, [$host]);
        } catch (Throwable $e) {
            $this->errorResult('Failed to remove Glue Record', [], [], $e);
        }

        try {
            return GlueRecordsResult::create([
                'glue_records' => $this->api()->glueRecordsList($domainName),
            ])->setMessage('Glue record deleted successfully');
        } catch (Throwable $e) {
            return GlueRecordsResult::create()
                ->setMessage('Glue Record deleted, but could not retrieve updated list');
        }
    }

    /**
     * Get a Guzzle HTTP client instance.
     */
    protected function api(): NetistrarApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->configuration->isSandbox()
                ? 'https://restapi.netistrar-ote.uk/'
                : 'https://restapi.netistrar.com/',
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/Netistrar'
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->isSandbox(),
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        return $this->api = new NetistrarApi($client, $this->configuration);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function _getInfo(string $domainName, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domainName);
        return DomainResult::create($domainInfo)->setMessage($message);
    }

    private function validateContactParam(ContactParams $params, string $contactType): void
    {
        if (!isset($params->state)) {
            $this->errorResult('State/County is required for ' . $contactType . ' contact details');
        }
    }

    /**
     * @inheritDoc
     */
    public function getStatus(DomainInfoParams $params): StatusResult
    {
        return StatusResult::create()
            ->setStatus(StatusResult::STATUS_UNKNOWN)
            ->setExpiresAt(null)
            ->setRawStatuses(null);
    }
}
