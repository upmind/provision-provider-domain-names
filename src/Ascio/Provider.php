<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Ascio;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SoapHeader;
use Throwable;
use SoapClient;
use SoapFault;
use UnexpectedValueException;
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
use Upmind\ProvisionProviders\DomainNames\Data\Enums\ContactType;
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
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusResult;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationParams;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationResult;
use Upmind\ProvisionProviders\DomainNames\Data\SetGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\RemoveGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecordsResult;
use Upmind\ProvisionProviders\DomainNames\Data\StatusResult;
use Upmind\ProvisionProviders\DomainNames\Ascio\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Ascio\Helper\AscioApi;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected Configuration $configuration;

    /**
     * @var AscioApi
     */
    protected AscioApi $api;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Ascio')
            ->setDescription('Register, transfer, renew and manage domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/ascio-logo.png');
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);

        $domains = array_map(
            fn($tld) => $sld . "." . Utils::normalizeTld($tld),
            $params->tlds
        );

        $dacDomains = $this->api()->checkMultipleDomains($domains);

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }

    public function poll(PollParams $params): PollResult
    {
        $since = $params->after_date ? Carbon::parse($params->after_date) : null;

        try {
            $poll = $this->api()->poll(intval($params->limit), $since);
            return PollResult::create($poll);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $contacts = [
            "registrant" => $params->registrant,
            "admin" => $params->admin,
            "tech" => $params->tech,
            "billing" => $params->billing,
        ];

        try {
            $contactsId = $this->getContactsId($contacts);

            $this->api()->register(
                $domainName,
                intval($params->renew_years),
                $contactsId,
                $params->nameservers->pluckHosts(),
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }


    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $eppCode = $params->epp_code ?: '0000';

        if (!Arr::has($params, 'registrant.register')) {
            $this->errorResult('Registrant contact data is required!');
        }

        if (!Arr::has($params, 'admin.register')) {
            $this->errorResult('Registrant contact data is required!');
        }
        if (!Arr::has($params, 'tech.register')) {
            $this->errorResult('Registrant contact data is required!');
        }
        if (!Arr::has($params, 'billing.register')) {
            $this->errorResult('Registrant contact data is required!');
        }

        if (!Arr::has($params, 'nameservers')) {
            $this->errorResult('Nameservers is required!');
        }

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        $contacts = [
            AscioApi::CONTACT_TYPE_REGISTRANT=> $params->registrant->register,
            AscioApi::CONTACT_TYPE_ADMIN => $params->admin->register,
            AscioApi::CONTACT_TYPE_TECH => $params->tech->register,
            AscioApi::CONTACT_TYPE_BILLING => $params->billing->register,
        ];

        try {

            $this->api()->initiateTransfer(
                $domainName,
                $eppCode,
                $contacts,
                $params['nameservers'],
            );

            $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), [
                'transaction_id' => null
            ]);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }


    /**
     * @return array<string,int>|string[]
     *
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getContactsId(array $params): array
    {
        if (Arr::has($params, 'registrant.id')) {
            $registrantID = $params['registrant']['id'];
        } else {
            if (!Arr::has($params, 'registrant.register')) {
                $this->errorResult('Registrant contact data is required!');
            }
            $registrantID = $this->api()->createRegistrant($params['registrant']['register']);
        }

        if (Arr::has($params, 'tech.id')) {
            $techID = $params['tech']['id'];
        } else {
            if (!Arr::has($params, 'tech.register')) {
                $this->errorResult('Tech contact data is required!');
            }
            $techID = $this->api()->createContact($params['tech']['register']);
        }

        if (Arr::has($params, 'admin.id')) {
            $adminID = $params['admin']['id'];
        } else {
            if (!Arr::has($params, 'admin.register')) {
                $this->errorResult('Admin contact data is required!');
            }
            $adminID = $this->api()->createContact($params['admin']['register']);
        }

        if (Arr::has($params, 'billing.id')) {
            $billingID = $params['billing']['id'];
        } else {
            if (!Arr::has($params, 'billing.register')) {
                $this->errorResult('Billing contact data is required!');
            }

            $billingID = $this->api()->createContact($params['billing']['register']);
        }

        return [
            AscioApi::CONTACT_TYPE_REGISTRANT => $registrantID,
            AscioApi::CONTACT_TYPE_ADMIN => $adminID,
            AscioApi::CONTACT_TYPE_TECH => $techID,
            AscioApi::CONTACT_TYPE_BILLING => $billingID,
        ];
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
        } catch (\Throwable $e) {
            $this->handleException($e);
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

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Propaganistas\LaravelPhone\Exceptions\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateContact(UpdateContactParams $params): ContactResult
    {
        try {
            $contactType = $params->getContactTypeEnum();
        } catch (UnexpectedValueException) {
            $this->errorResult('Invalid contact type: ' . $params->contact_type);
        }

        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $contact = $this->api()->updateContact($domainName, $params->contact, $this->getProviderContactTypeValue($contactType));

            return ContactResult::create($contact);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }


    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $nameservers = [];

        for ($i = 1; $i <= AscioApi::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $nameservers["NameServer{$i}"] = [
                    'HostName' => Arr::get($params, 'ns' . $i)->host,
                    'IpAddress' => Arr::get($params, 'ns' . $i)->ip,
                ];
            }
        }

        try {
            $result = $this->api()->updateNameservers(
                $domainName,
                $nameservers,
            );

            return NameserversResult::create($result)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getProviderContactTypeValue(ContactType $contactType): string
    {
        switch ($contactType) {
            case $contactType->equals(ContactType::REGISTRANT()):
                return AscioApi::CONTACT_TYPE_REGISTRANT;
            case $contactType->equals(ContactType::ADMIN()):
                return AscioApi::CONTACT_TYPE_ADMIN;
            case $contactType->equals(ContactType::BILLING()):
                return AscioApi::CONTACT_TYPE_BILLING;
            case $contactType->equals(ContactType::TECH()):
                return AscioApi::CONTACT_TYPE_TECH;
            default:
                throw ProvisionFunctionError::create('Invalid contact type: ' . $contactType->getValue());
        }
    }

    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $lock = !!$params->lock;

        try {
            $domainData = $this->api()->getDomains($domainName);

            if (!$lock && $domainData['TransferLock'] == "Unlock" && $domainData['UpdateLock'] !== "Unlock") {
                return $this->_getInfo($domainName, sprintf('Domain %s already unlocked', $domainName));
            }

            if ($lock && $domainData['TransferLock'] == "Lock" && $domainData['UpdateLock'] !== "Lock") {
                return $this->_getInfo($domainName, sprintf('Domain %s already locked', $domainName));
            }

            $this->api()->setRegistrarLock($domainName, $lock);

            return $this->_getInfo($domainName, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $autoRenew = !!$params->auto_renew;

        if (!$autoRenew) {
            $this->errorResult("Cannot unset domain auto-renew mode", $params);
        }

        try {
            $this->api()->setRenewalMode($domainName);

            return $this->_getInfo($domainName, 'Auto-renew mode updated');
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
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
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getVerificationStatus(VerificationStatusParams $params): VerificationStatusResult
    {
        $this->errorResult('Operation not supported', $params);
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function resendVerificationEmail(ResendVerificationParams $params): ResendVerificationResult
    {
        try {
            $domain = Utils::getDomain($params->sld, $params->tld);
            $result = $this->api()->resendVerificationEmail($domain);

            return ResendVerificationResult::create($result);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }


    /**
     * @return no-return
     * @return never
     * @throws Throwable
     *
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof SoapFault) {
            $errorMessage = sprintf('Provider API Soap Error [%s]', $e->faultcode);
            $errorData = [
                'error' => [
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'soap_fault' => [
                        'code' => $e->faultcode ?? null,
                        'string' => $e->faultstring ?? null,
                        'detail' => $e->detail ?? null,
                        'actor' => $e->faultactor ?? null,
                        'headerfault' => $e->headerfault ?? null,
                    ],
                ],
            ];

            if (Str::contains($e->getMessage(), 'Parsing WSDL')) {
                $errorMessage = 'Provider API Soap Connection Error';
            }

            $this->errorResult($errorMessage, $errorData, [], $e);
        }

        throw $e;
    }

    protected function api(): AscioApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        // apparently the only way to set the actual timeout for SoapClient
        // init_set sets the value and returns the old value.
        $defaultTimeout = ini_set("default_socket_timeout", "5");

        $options = [
            'trace' => 1,
            'soap_version' => SOAP_1_1,
        ];

        try {
            $client = new SoapClient($this->resolveAPIURL(), $options);

            $credentials = ["Account" => $this->configuration->username, "Password" => $this->configuration->password];
            $headers = [];
            $headers[] = new \SoapHeader("http://www.ascio.com/2013/02", "SecurityHeaderDetails", $credentials, false);
            $headers[] = new \SoapHeader("http://www.ascio.com/2013/02", "ImpersonationHeaderDetails", null, false);
            $client->__setSoapHeaders($headers);

            return $this->api = new AscioApi($client, $this->configuration, $this->getLogger());
        } catch (Throwable $e) {
            $this->handleException($e);
        } finally {
            if ($defaultTimeout !== false) {
                // restore the default timeout
                ini_set("default_socket_timeout", $defaultTimeout);
            }
        }
    }


    private function resolveAPIURL(): string
    {
        return $this->configuration->sandbox
            ? 'https://aws.demo.ascio.com/v3/aws.wsdl'
            : 'https://aws.ascio.com/v3/aws.wsdl';
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @inheritDoc
     */
    public function getStatus(DomainInfoParams $params): StatusResult
    {

        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $domainData = $this->_getInfo($domainName, "");
            $status = StatusResult::STATUS_UNKNOWN;
            switch ($domainData->statuses[0]) {
                case "Active":
                    $status = StatusResult::STATUS_ACTIVE;
                    break;
                case "Deleted":
                    $status = StatusResult::STATUS_CANCELLED;

            }

            return StatusResult::create()
                ->setStatus($status)
                ->setExpiresAt($domainData->expires_at
                    ? new \DateTimeImmutable($domainData->expires_at)
                    : null)
                ->setRawStatuses($domainData->statuses);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

}
