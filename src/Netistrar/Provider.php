<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netistrar;

use GuzzleHttp\Client;
use Throwable;
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
    protected ?NetistrarApi $api = null;
    protected Configuration $configuration;

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
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/netistrar-logo.png')
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
        $sld = $params->sld;

        $dacDomains = [];

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
        $this->api()->createDomain($params);

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
        $this->api()->transferDomain($params);

        $domainName = Utils::getDomain($params->sld, $params->tld);
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage('Domain transferred');
    }

    /**
     * @inheritDoc
     */
    public function renew(RenewParams $params): DomainResult
    {
        $this->api()->renewDomain($params);

        $domainName = Utils::getDomain($params->sld, $params->tld);
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
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (Throwable $e) {
            $this->errorResult($e->getMessage(), [], $params->toArray());
        }
    }

    /**
     * @inheritDoc
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
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
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $this->api()->updateDomainNameservers($domainName, $params);

        $domainInfo = $this->api()->getDomainInfo($domainName);

        return NameserversResult::create($domainInfo->ns)->setMessage('Netistrar domain nameservers updated');
    }

    /**
     * @inheritDoc
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $this->api()->updateDomainLock($domainName, (bool) $params->lock);

        return $this->_getInfo($domainName, 'Domain lock updated');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $this->api()->updateAutoRenew($domainName, (bool) $params->auto_renew);

        return $this->_getInfo($domainName, 'Domain autorenew updated');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
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
        $domainName = Utils::getDomain($params->sld, $params->tld);
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
}
