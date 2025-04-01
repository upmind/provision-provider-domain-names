<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netistrar;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use GuzzleHttp\Client;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
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
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Netistrar\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Netistrar\Helper\NetistrarApi;

/**
 * Netistrar domain provider
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected NetistrarApi $api;
    protected Configuration $configuration;
    protected APIProvider $provider;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Netistrar')
            ->setLogoUrl('https://netistrar.com/images/v2/netistrar_logo_full.png')
            ->setDescription('Netistrar provider for domain names');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        //TESTED - http://localhost:8000/provision_requests/324
        $sld = $params->sld;
        
        foreach ($params->tlds as $tld) {
            $result = $this->api()->liveAvailability($sld . "." . $tld);

            $canRegister = $result->availability === "AVAILABLE";
            $dacDomains[] = DacDomain::create([
                'domain' => $sld . "." . $tld,
                'description' => sprintf(
                    'Domain is %s to register',
                    $canRegister ? 'available' : 'not available',
                ),
                'tld' => $tld,
                'can_register' => $canRegister,
                'can_transfer' => !$canRegister 
                    && isset($result->additionalData->transferType) 
                    && $result->additionalData->transferType === 'pull',
                'is_premium' => $result->premiumSupported ?? false,
            ]);
        }

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        //TODO: verify "password" has no implementation
        //TODO: if premium domain, handle premium registration code? request #335 was "premiumSupported"
        //TODO: Leave auto renew to default to false?

        //TESTED (UK): http://localhost:8000/provision_requests/334
        //TESTED (NON-UK): http://localhost:8000/provision_requests/335
        //TESTED FAILURE: http://localhost:8000/provision_requests/331
        $result = $this->api()->createDomain($params);
        return $this->getInfo(DomainInfoParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
        ]))->setMessage('Domain registered');
    }

    /**
     * @inheritDoc
     */
    public function transfer(TransferParams $params): DomainResult
    {
        //TESTED - http://localhost:8000/provision_requests/421
        //TESTED FAILURE (domain already in account - transfer in create) - http://localhost:8000/provision_requests/412

        $result = $this->api()->transferDomain($params);
        
        $domainName = self::getDomainName($params);
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage('Domain transferred');
    }

    /**
     * @inheritDoc
     */
    public function renew(RenewParams $params): DomainResult
    {
        //TESTED - http://localhost:8000/provision_requests/337
        //TESTED FAILURE - http://localhost:8000/provision_requests/340

        $this->api()->renewDomain($params);
        
        $domainName = self::getDomainName($params);
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage('Domain renewed');
    }
    
    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function _getInfo(string $domainName, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domainName);
        return DomainResult::create($domainInfo)->setMessage($message);
    }

    /**
     * @inheritDoc
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        //TESTED - http://localhost:8000/provision_requests/348
        //TESTED FAILURE (Not owned domain) - TODO: handle error? http://localhost:8000/provision_requests/345 (internal provision provider error)
        $domainName = self::getDomainName($params);

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (Throwable $e) {
            $this->handleException($e, $params);
        }
    }

    /**
     * @inheritDoc
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        //TESTED - http://localhost:8000/provision_requests/364
        //TESTED FAILURE (Not owned domain) - http://localhost:8000/provision_requests/365
        $domainName = self::getDomainName($params);
        $updateResults = $this->api()->updateRegistrantContact($domainName, $params);
        $domainInfo = $this->api()->getDomainInfo($domainName);
        $logger = $this->getLogger();
        $logger->debug(
            'Registrant contact update results',
            [
                'domain' => $domainName,
                'updateResults' => $updateResults,
                'domainInfo' => $domainInfo,
            ]
        );
        return ContactResult::create($domainInfo->registrant)->setMessage('Registrant Contact updated');
    }

    /**
     * @inheritDoc
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        //TESTED - http://localhost:8000/provision_requests/366
        //TESTED FAILURE (Not owned domain) - http://localhost:8000/provision_requests/368
        //TESTED FAILURE (Invalid nameserver) - http://localhost:8000/provision_requests/367
        //TESTED FAILURE (Duplicate nameservers) - http://localhost:8000/provision_requests/369
        $domainName = self::getDomainName($params);
        $this->api()->updateDomainNameservers($domainName, $params);

        $domainInfo = $this->api()->getDomainInfo($domainName);
        
        return NameserversResult::create($domainInfo->ns)->setMessage('Netistrar domain nameservers updated');
    }

    /**
     * @inheritDoc
     */
    public function setLock(LockParams $params): DomainResult
    {
        //TESTED - Unlock - http://localhost:8000/provision_requests/371
        //TESTED - Lock - http://localhost:8000/provision_requests/372
        //TESTEDD FAILURE (Not owned domain) - http://localhost:8000/provision_requests/373
        $domainName = self::getDomainName($params);
        $this->api()->updateDomainLock($domainName, ($params->lock==="1"));
        return $this->_getInfo($domainName, 'Domain lock updated');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        //TESTED - AutoRenew Enabled - http://localhost:8000/provision_requests/376
        //TESTED - AutoRenew Disabled - http://localhost:8000/provision_requests/377
        //TESTED FAILURE (Not owned domain) - http://localhost:8000/provision_requests/378
        $domainName = self::getDomainName($params);
        $this->api()->updateAutoRenew($domainName, ($params->auto_renew==="1"));
        return $this->_getInfo($domainName, 'Domain autorenew updated');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        //TESTED (UK Domain) - http://localhost:8000/provision_requests/380
        //TESTED FAILURE (Not owned domain) - http://localhost:8000/provision_requests/383
        //TESTED FAILURE (non-UK domain) - http://localhost:8000/provision_requests/381
        $domainName = self::getDomainName($params);
        if (!NetistrarApi::is_uk_domain($domainName)) {
            $this->errorResult('Operation not available for this TLD');
        }
        return $this->api()->getEppCode($domainName);
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        //TESTED (Non-UK Domain): http://localhost:8000/provision_requests/394 - kitchenhubredo3.site; Says it succeeds but "tags" property on $domainInfo is not updated.
        //TESTED FAILURE (UK Domain): http://localhost:8000/provision_requests/395
        $domainName = self::getDomainName($params);
        if (NetistrarApi::is_uk_domain($domainName)) {
            $this->errorResult('Operation not available for this TLD');
        }

        $domainInfo = $this->api()->getDomainInfo($domainName, ['tags']);

        if (in_array($params->ips_tag, $domainInfo->tags)) {
            return DomainResult::create($domainInfo)->setMessage("IPS Tag already set");
        }

        $this->api()->updateIpsTag($domainName, $params->ips_tag, $domainInfo->tags);
        
        $domainInfo = $this->api()->getDomainInfo($domainName, ['tags']);
        return ResultData::create()->setMessage('Domain IPS tag updated');
    }

     /**
     * Get a Guzzle HTTP client instance.
     */
    protected function api(): NetistrarApi
    {
        if (isset($this->api) && is_a($this->api, NetistrarApi::class)) {
            return $this->api;
        }

        $client = new Client([
            'base_uri' => $this->configuration->sandbox
                ? 'https://restapi.netistrar-ote.uk/'
                : 'https://restapi.netistrar.com/',
            'headers' => [
                'User-Agent' => 'Upmind/ProvisionProviders/DomainNames/Netistrar'
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        return $this->api = new NetistrarApi($client, $this->configuration, $this->getLogger());
    }

    public static function getDomainName(object $params) : string 
    {
        if (!isset($params->sld) || !isset($params->tld)) {
            $this->errorResult('Domain SLD or TLD not found');        
        }

        return Utils::getDomain(
            Utils::normalizeSld($params->sld),
            Utils::normalizeTld($params->tld)
        );
    }
}
