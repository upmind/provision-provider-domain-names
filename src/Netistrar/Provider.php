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
                    $canRegister = isset($result->availability) && $result->availability === 'AVAILABLE';

                    return DacDomain::create()
                        ->setDomain($domain)
                        ->setTld($tld)
                        ->setCanRegister($canRegister)
                        ->setCanTransfer(
                            !$canRegister
                            && isset($result->additionalData->transferType)
                            && $result->additionalData->transferType === 'pull'
                        )
                        ->setIsPremium($result->premiumSupported ?? false)
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
        $this->api()->createDomain($params);

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
        $this->api()->transferDomain($params);

        $domainName = Utils::getDomain($params->sld, $params->tld);
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage('Domain transferred');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
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
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    private function _getInfo(string $domainName, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domainName);
        return DomainResult::create($domainInfo)->setMessage($message);
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
            $this->errorResult($e->getMessage(), [], $params->toArray());
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $this->api()->updateDomainNameservers($domainName, $params);

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
        $this->api()->updateDomainLock($domainName, (bool) $params->lock);

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
        $this->api()->updateAutoRenew($domainName, (bool) $params->auto_renew);

        return $this->_getInfo($domainName, 'Domain autorenew updated');
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

        return $this->api()->getEppCode($domainName);
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
