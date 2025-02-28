<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TwentyI;

use ErrorException;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use TwentyI\API\CurlException;
use TwentyI\API\HTTPException;
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
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\TwentyI\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\TwentyI\Helper\TwentyIApi;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected Configuration $configuration;


    /**
     * @var TwentyIApi
     */
    protected TwentyIApi $api;


    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('20i Domains')
            ->setDescription('Register, transfer, renew and manage 20i domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/20i-logo@2x.png');
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);

        $domains = array_map(
            fn($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );

        $dacDomains = $this->api()->checkMultipleDomains($sld, $domains);

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }

    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Operation not supported');
    }

    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $privacy = Utils::tldSupportsWhoisPrivacy($params->tld) && $params->whois_privacy;

        $contacts = [
            TwentyIApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register,
            TwentyIApi::CONTACT_TYPE_ADMIN => $params->admin->register,
            TwentyIApi::CONTACT_TYPE_TECH => $params->tech->register,
            TwentyIApi::CONTACT_TYPE_BILLING => $params->billing->register,
        ];

        try {
            $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $contacts,
                $params->nameservers->pluckHosts(),
                $privacy,
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }


    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $privacy = Utils::tldSupportsWhoisPrivacy($params->tld) && $params->whois_privacy;
        $eppCode = $params->epp_code ?: '0000';

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        $contacts = [
            TwentyIApi::CONTACT_TYPE_REGISTRANT => isset($params->registrant) ? $params->registrant->register : null,
            TwentyIApi::CONTACT_TYPE_ADMIN => isset($params->admin) ? $params->admin->register : null,
            TwentyIApi::CONTACT_TYPE_TECH => isset($params->tech) ? $params->tech->register : null,
            TwentyIApi::CONTACT_TYPE_BILLING => isset($params->billing) ? $params->billing->register : null,
        ];

        try {
            $this->api()->initiateTransfer(
                $domainName,
                intval($params->renew_years),
                $eppCode,
                $contacts,
                $privacy,
            );

            $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), [
                'transaction_id' => null
            ]);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $period = intval($params->renew_years);

        try {
            $this->api()->renew($domainName, $period);
            return $this->_getInfo($domainName, sprintf('Renewal for %s domain was successful!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not get domain info', ['domain' => $domainName]);
        }
    }

    private function _getInfo(string $domainName, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage($message);
    }

    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $contact = $this->api()->updateRegistrantContact($domainName, $params->contact);

            return ContactResult::create($contact);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $result = $this->api()->updateNameservers(
                $domainName,
                $params->pluckHosts(),
            );

            return NameserversResult::create($result)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $lock = !!$params->lock;

        try {
            $currentLockStatus = $this->api()->getRegistrarLockStatus($domainName);
            if (!$lock && !$currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already unlocked', $domainName));
            }

            if ($lock && $currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already locked', $domainName));
            }

            $response = $this->api()->setRegistrarLock($domainName, $lock);
            if (!$response) {
                $this->errorResult('Domain lock status not updated');
            }

            return $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $this->errorResult('Operation not supported');
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $eppCode = $this->api()->getDomainEppCode($domainName);

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        if (!Str::endsWith($params->tld, '.uk')) {
            $this->errorResult('Operation not available for this TLD');
        }

        try {
            $result = $this->api()->updateIpsTag(
                $domainName,
                $params->ips_tag,
            );

            if (!$result) {
                $this->errorResult('Domain IPS tag not updated');
            }

            return ResultData::create($result)
                ->setMessage(sprintf('IPS tag for %s domain was updated!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Wrap StackCP reseller api exceptions in a ProvisionFunctionError with the
     * given message and data, if appropriate. Otherwise re-throws original error.
     *
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(Throwable $e, ?string $errorMessage = null, array $data = [], array $debug = []): void
    {
        $errorMessage = $errorMessage ?? 'StackCP API request failed';

        if ($this->exceptionIs401($e)) {
            $errorMessage = 'API authentication error';
        }

        if ($this->exceptionIs404($e)) {
            $errorMessage .= ' (not found)';
        }

        if ($this->exceptionIs409($e)) {
            $errorMessage .= ' (conflict)';
        }

        if ($this->exceptionIsTimeout($e)) {
            $errorMessage .= ' (request timed out)';
        }

        if ($e instanceof HTTPException) {
            if (!empty($e->decodedBody->error->message)) {
                $errorMessage .= ': ' . $e->decodedBody->error->message;
            }

            $data['request_url'] = $e->fullURL;
            $data['response_data'] = $e->decodedBody;
        }

        if ($e instanceof ProvisionFunctionError) {
            // merge any additional error data / debug data
            $data = array_merge($e->getData(), $data);
            $debug = array_merge($e->getDebug(), $debug);

            $e = $e->withData($data)
                ->withDebug($debug);
        }

        if ($this->shouldWrapException($e)) {
            throw (new ProvisionFunctionError($errorMessage, 0, $e))
                ->withData($data)
                ->withDebug($debug);
        }

        throw $e;
    }

    /**
     * Determine whether the given exception should be wrapped in a
     * ProvisionFunctionError.
     */
    protected function shouldWrapException(Throwable $e): bool
    {
        return $e instanceof HTTPException
            || $this->exceptionIs401($e)
            || $this->exceptionIs404($e)
            || $this->exceptionIs409($e)
            || $this->exceptionIsTimeout($e);
    }

    /**
     * Determine whether the given exception was thrown due to a 401 response
     * from the stack cp api.
     */
    protected function exceptionIs401(Throwable $e): bool
    {
        return $e instanceof HTTPException
            && preg_match('/(^|[^\d])401([^\d]|$)/', $e->getMessage());
    }

    /**
     * Determine whether the given exception was thrown due to a 404 response
     * from the stack cp api.
     */
    protected function exceptionIs404(Throwable $e): bool
    {
        return $e instanceof ErrorException
            && preg_match('/(^|[^\d])404([^\d]|$)/', $e->getMessage());
    }

    /**
     * Determine whether the given exception was thrown due to a 409 response
     * from the stack cp api.
     */
    protected function exceptionIs409(Throwable $e): bool
    {
        return $e instanceof HTTPException
            && preg_match('/(^|[^\d])409([^\d]|$)/', $e->getMessage());
    }

    /**
     * Determine whether the given exception was thrown due to a request timeout.
     */
    protected function exceptionIsTimeout(Throwable $e): bool
    {
        return $e instanceof CurlException
            && preg_match('/(^|[^\w])timed out([^\w]|$)/i', $e->getMessage());
    }

    protected function api(): TwentyIApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        return new TwentyIApi($this->configuration, $this->getLogger());
    }
}
