<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpenSRS;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\DataSet;
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
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
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
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Data\OpenSrsConfiguration;
use Upmind\ProvisionProviders\DomainNames\OpenSRS\Helper\OpenSrsApi;

/**
 * ⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⣀⣤⣴⣾⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣶⣤⣀⠄⠄⠄
 * ⡄⠄⠄⠄⠄⠄⠄⠄⣠⣾⣿⣿⣿⡿⠛⠋⠉⠛⠻⢿⣿⣿⣿⣿⣿⣿⣿⣧⠄⠄
 * ⡇⠄⠄⠄⠄⠄⣴⣾⣿⣿⣿⣿⣿⣾⣿⣿⣷⣤⣄⣈⣻⣿⣿⣿⣿⣿⣿⣿⣧⠄
 * ⠄⠄⠄⠄⠄⢰⣿⣿⣿⣿⣿⣿⡟⠉⣠⣤⢀⠄⠙⢿⣿⣿⣿⣿⣿⡟⠉⠉⠙⠂
 * ⠄⠄⠄⠄⠄⣈⣿⣿⣿⣿⣿⣿⣇⠸⠿⠁⠄⠧⠄⠾⣿⣿⣿⣿⡿⠷⠤⢤⣄⠄
 * ⠄⠄⠄⠄⠄⣿⣿⣿⣿⣿⣿⣿⣿⣷⣦⣤⣤⣤⣶⡆⣿⣿⣿⡗⠂⣤⠄⠄⠈⠆
 * ⠄⠄⠄⠄⠄⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⡿⢃⢿⣿⣿⣷⠸⠁⠄⠄⠄⠄
 * ⠄⠄⠄⠄⢀⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⢰⡾⠁⠈⠉⠝⢀⡀⠄⡀⡰⠃
 * ⠄⠄⠄⠄⢸⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⠦⠄⠄⠐⠄⢀⣼⣿⣿⣿⣿⠁
 * ⠄⠄⠄⢠⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⣿⠟⠋⠄⠄⠄⠄⠄⠸⣿⣿⣿⣿⣿⠁
 * ⠄⠄⢀⣾⣿⣿⣿⣿⣿⣿⣿⣿⣿⡿⠋⠁⢀⣀⣤⣤⣤⣄⠄⠄⣿⣿⣿⣿⡿⠄
 * ⠄⠄⢼⣿⣿⣿⣿⣿⣿⣿⣿⣿⣟⠄⢀⣼⣿⣟⠛⠛⠛⢿⣷⠄⢸⣿⣿⣿⠃⠄
 * ⠄⠄⠄⠄⠈⠻⠿⠿⠿⠿⠟⠋⠻⣿⣿⣿⣿⣿⣿⣷⣦⡌⣿⡀⢸⣿⣿⣿⠄⠄
 * ⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⣿⣿⣇⠄⠄⠄⢻⣿⣿⣧⣿⣿⣿⡇⠄⠄
 * ⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠄⠈⠙⢻⣿⣆⠄⠸⣿⣿⣿⣿⠉⠛⠄⠄⠄
 *
 * Class Provider
 * @package Upmind\ProvisionProviders\DomainNames\OpenSRS
 */
class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var OpenSrsConfiguration
     */
    protected $configuration;

    /**
     * @var OpenSrsApi|null
     */
    protected $apiClient;

    /**
     * Max count of name servers that we can expect in a request
     */
    private const MAX_CUSTOM_NAMESERVERS = 5;

    public function __construct(OpenSrsConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('OpenSRS')
            ->setDescription('Register, transfer, renew and manage OpenSRS domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/opensrs-logo@2x.png');
    }

    /**
     * @throws \Throwable
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $tlds = array_map(function (string $tld) {
            return Utils::normalizeTld($tld);
        }, $params->tlds);

        $result = $this->api()
            ->makeRequest([
                'protocol' => 'XCP',
                'action' => 'name_suggest',
                'object' => 'domain',
                'attributes' => [
                    'services' => ['lookup', 'premium'],
                    'searchstring' => Utils::normalizeSld($params->sld),
                    'tlds' => $tlds,
                ]
            ], [
                'timeout' => 15, // Set a reduced timeout for the request
            ]);

        $dacDomains = [];
        foreach ($result['attributes']['premium']['items'] ?? [] as $item) {
            [$itemSld, $itemTld] = explode('.', $item['domain'], 2);
            if (Utils::normalizeSld($itemSld) !== Utils::normalizeSld($params->sld)) {
                continue;
            }
            if (!in_array(Utils::normalizeTld($itemTld), $tlds)) {
                continue;
            }

            $description = $item['status'];
            if (isset($item['reason'])) {
                $description .= ' (' . $item['reason'] . ')';
            }

            $dacDomains[] = DacDomain::create()
                ->setDomain($item['domain'])
                ->setTld($itemTld)
                ->setCanRegister($item['status'] === 'available')
                ->setCanTransfer($item['status'] === 'taken')
                ->setIsPremium(true)
                ->setDescription($description);
        }

        foreach ($result['attributes']['lookup']['items'] ?? [] as $item) {
            [$itemSld, $itemTld] = explode('.', $item['domain'], 2);

            $premium = false;
            $description = $item['status'];
            if (isset($item['reason'])) {
                if (Str::contains(strtolower($item['reason']), 'premium')) {
                    $premium = true;
                }

                $description .= ' (' . $item['reason'] . ')';
            }

            $dacDomains[] = DacDomain::create()
                ->setDomain($item['domain'])
                ->setTld($itemTld)
                ->setCanRegister($item['status'] === 'available')
                ->setCanTransfer($item['status'] === 'taken')
                ->setIsPremium($premium)
                ->setDescription($description);
        }

        return new DacResult([
            'domains' => $dacDomains,
        ]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        // Get Params
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');
        $domain = Utils::getDomain($sld, $tld);

        try {
            if (!Arr::has($params, 'registrant.register')) {
                $this->errorResult('Registrant contact data is required!');
            }

            if (!Arr::has($params, 'tech.register')) {
                $this->errorResult('Tech contact data is required!');
            }

            if (!Arr::has($params, 'admin.register')) {
                $this->errorResult('Admin contact data is required!');
            }

            if (!Arr::has($params, 'billing.register')) {
                $this->errorResult('Billing contact data is required!');
            }

            // Register the domain with the registrant contact data
            $nameServers = [];

            for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
                if (Arr::has($params, 'nameservers.ns' . $i)) {
                    $nameServers[] = [
                        'name' => Arr::get($params, 'nameservers.ns' . $i)->host,
                        'sortorder' => $i
                    ];
                }
            }

            $contactData = [
                OpenSrsApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register,
                OpenSrsApi::CONTACT_TYPE_TECH => $params->tech->register,
                OpenSrsApi::CONTACT_TYPE_ADMIN => $params->admin->register,
                OpenSrsApi::CONTACT_TYPE_BILLING => $params->billing->register
            ];

            $contacts = [];

            foreach ($contactData as $type => $contactParams) {
                $nameParts = OpenSrsApi::getNameParts($contactParams->name ?? $contactParams->organisation);

                $contacts[$type] = array_filter([
                    'country' => Utils::normalizeCountryCode($contactParams->country_code),
                    'org_name' => $contactParams->organisation ?: $contactParams->name,
                    'phone' => Utils::internationalPhoneToEpp($contactParams->phone),
                    'postal_code' => $contactParams->postcode,
                    'city' => $contactParams->city,
                    'email' => $contactParams->email,
                    'address1' => $contactParams->address1,
                    'first_name' => $nameParts['firstName'],
                    'last_name' => $nameParts['lastName'],
                    'state' => Utils::stateNameToCode($contactParams->country_code, $contactParams->state),
                ]);
            }

            $result = $this->api()->makeRequest([
                'action' => 'SW_REGISTER',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'f_whois_privacy' => Utils::tldSupportsWhoisPrivacy($tld) && $params->whois_privacy,
                    'domain' => $domain,
                    'reg_username' => bin2hex(random_bytes(6)),
                    'reg_password' => bin2hex(random_bytes(6)),
                    'handle' => 'process',
                    'period' => Arr::get($params, 'renew_years', 1),
                    'reg_type' => 'new',
                    'custom_nameservers' => 1,
                    'contact_set' => $contacts,
                    'custom_tech_contact' => 0,
                    'nameserver_list' => $nameServers
                ]
            ]);

            if (!empty($result['attributes']['forced_pending'])) {
                // domain could not be registered at this time
                $this->errorResult('Domain registration pending approval', $result);
            }

            // Return newly fetched data for the domain
            return $this->_getInfo($sld, $tld, sprintf('Domain %s was registered successfully!', $domain));
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        $domain = Utils::getDomain($sld, $tld);

        try {
            $checkPendingResult = $this->api()->makeRequest([
                'action' => 'get_transfers_in',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domain,
                ],
            ]);
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }

        foreach ($checkPendingResult['attributes']['transfers'] ?? [] as $transfer) {
            if (!empty($transfer['completed_date'])) {
                continue; // if transfer is completed, great
            }

            $initiated = Carbon::createFromTimestamp($transfer['order_date_epoch'])
                ->diffForHumans([
                    'parts' => 1,
                    'options' => CarbonInterface::ROUND,
                ]); // X days ago

            switch ($transfer['status']) {
                case 'completed':
                case 'cancelled':
                    continue 2;
                case 'pending_owner':
                    $this->errorResult(
                        sprintf('Transfer initiated %s is pending domain owner approval', $initiated),
                        ['transfer' => $transfer]
                    );
                case 'pending_registry':
                    $this->errorResult(
                        sprintf('Transfer initiated %s is pending registry approval', $initiated),
                        ['transfer' => $transfer]
                    );
                default:
                    $this->errorResult(
                        sprintf('Transfer initiated %s is in progress', $initiated),
                        ['transfer' => $transfer]
                    );
            }
        }

        try {
            return $this->_getInfo($sld, $tld, 'Domain active in registrar account!');
        } catch (Throwable $e) {
            // ignore error and attempt to initiate transfer
        }

        // OpenSRS requires 0 years renewal period as default, if the TLD has free transfer
        $period = Utils::tldSupportsFreeTransfer($tld) ? 0 : Arr::get($params, 'renew_years', 1);
        $eppCode = Arr::get($params, 'epp_code', "");

        $contacts = [];

        if (!Arr::has($params, 'registrant.register')) {
            $this->errorResult('Registrant contact data is required!');
        }

        $contactData = [
            OpenSrsApi::CONTACT_TYPE_REGISTRANT => Arr::get($params, 'registrant.register'),
            OpenSrsApi::CONTACT_TYPE_TECH => Arr::get($params, 'tech.register') ?? Arr::get($params, 'registrant.register'),
            OpenSrsApi::CONTACT_TYPE_ADMIN => Arr::get($params, 'admin.register') ?? Arr::get($params, 'registrant.register'),
            OpenSrsApi::CONTACT_TYPE_BILLING => Arr::get($params, 'billing.register') ?? Arr::get($params, 'registrant.register'),
        ];

        foreach ($contactData as $type => $contactParams) {
            /** @var ContactParams $contactParams */
            $nameParts = OpenSrsApi::getNameParts($contactParams->name ?: $contactParams->organisation);

            $contacts[$type] = [
                'country' => Utils::normalizeCountryCode($contactParams->country_code),
                'state' => Utils::stateNameToCode($contactParams->country_code, $contactParams->state),
                'org_name' => $contactParams->organisation ?: $contactParams->name,
                'phone' => Utils::internationalPhoneToEpp($contactParams->phone),
                'postal_code' => $contactParams->postcode,
                'city' => $contactParams->city,
                'email' => $contactParams->email,
                'address1' => $contactParams->address1,
                'first_name' => $nameParts['firstName'],
                'last_name' => $nameParts['lastName'],
            ];
        }

        try {
            $username = substr(str_replace(['.', '-'], '', $sld), 0, 16)
                . substr(str_replace(['.', '-'], '', $tld), 0, 4);

            $this->api()->makeRequest([
                'action' => 'SW_REGISTER',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domain,
                    'reg_username' => $username,
                    'reg_password' => bin2hex(random_bytes(6)),
                    'auth_info' => $eppCode,
                    'change_contact' => 0,
                    'handle' => 'process',
                    'period' => $period,
                    'f_whois_privacy' => Utils::tldSupportsWhoisPrivacy($tld) && $params->whois_privacy,
                    'reg_type' => 'transfer',
                    'custom_tech_contact' => 0,
                    'custom_nameservers' => 0,
                    'link_domains' => 0,
                    'contact_set' => $contacts
                ]
            ]);

            $this->errorResult('Domain transfer initiated');

            /*return DomainResult::create([
                'id' => $domain,
                'domain' => $domain,
                'statuses' => [], // nothing relevant here right now
                'registrant' => DomainContactInfo::create($contactData[OpenSrsApi::CONTACT_TYPE_REGISTRANT]),
                'ns' => [],
                'created_at' => Carbon::today()->toDateString(),
                'updated_at' => Carbon::today()->toDateString(),
                'expires_at' => Carbon::today()->toDateString()
            ])->setMessage('Domain transfer has been initiated!');*/
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        $domain = Utils::getDomain($sld, $tld);
        $period = Arr::get($params, 'renew_years', 1);

        try {
            // We need to know the current expiration year
            $domainRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domain,
                    'type' => 'all_info',
                    'clean_ca_subset' => 1,
                    //'active_contacts_only' => 1
                ]
            ]);

            $expiryDate = Carbon::parse($domainRaw['attributes']['expiredate']);

            // Set renewal data
            $domainRaw = $this->api()->makeRequest([
                'action' => 'RENEW',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'handle' => 'process',
                    'period' => $period,
                    'currentexpirationyear' => $expiryDate->year
                    //'premium_price_to_verify' => 'PREMIUM-DOMAIN-PRICE'
                ]
            ]);

            // Get Domain Info (again)
            return $this->_getInfo(
                $sld,
                $tld,
                sprintf('Renewal for %s domain was successful!', $domain)
            );
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        try {
            return $this->_getInfo(Arr::get($params, 'sld'), Arr::get($params, 'tld'), 'Domain data obtained');
        } catch (\Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @param string $sld
     * @param string $tld
     * @param string $message
     * @return DomainResult
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function _getInfo(string $sld, string $tld, string $message): DomainResult
    {
        $domainName = Utils::getDomain($sld, $tld);

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domainName,
                    'type' => 'all_info',
                    'clean_ca_subset' => 1,
                    // 'active_contacts_only' => 1
                ]
            ]);

            $statusRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domainName,
                    'type' => 'status',
                    // 'clean_ca_subset' => 1,
                    // 'active_contacts_only' => 1
                ]
            ]);

            $privacyRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domainName,
                    'type' => 'whois_privacy_state',
                ]
            ]);
        } catch (ProvisionFunctionError $e) {
            if (Str::contains($e->getMessage(), 'Registrant (end-user) authentication error')) {
                // this actually means domain not found
                $this->errorResult('Domain name not found', $e->getData(), $e->getDebug(), $e);
            }

            throw $e;
        }

        $privacyState = $privacyRaw['attributes']['state'] ?? null;
        if (in_array($privacyState, ['enabled', 'enabling'])) {
            $privacy = true;
        }
        if (in_array($privacyState, ['disabled', 'disabling'])) {
            $privacy = false;
        }

        $glueRecords = $this->listGlueRecords($domainName);

        $domainInfo = [
            'id' => (string) $domainName,
            'domain' => (string) $domainName,
            'statuses' => array_map(function ($status) {
                return $status === '' ? 'n/a' : (string)$status;
            }, $statusRaw['attributes']),
            'registrant' => OpenSrsApi::parseContact($domainRaw['attributes']['contact_set'], OpenSrsApi::CONTACT_TYPE_REGISTRANT),
            'ns' => OpenSrsApi::parseNameServers($domainRaw['attributes']['nameserver_list'] ?? []),
            'created_at' => $domainRaw['attributes']['registry_createdate'],
            'updated_at' => $domainRaw['attributes']['registry_updatedate'] ?? $domainRaw['attributes']['registry_createdate'],
            'expires_at' => $domainRaw['attributes']['expiredate'],
            'locked' => boolval($statusRaw['attributes']['lock_state']),
            'whois_privacy' => $privacy ?? null,
            'glue_records' => $glueRecords,
        ];

        return DomainResult::create($domainInfo)->setMessage($message);
    }

    private function listGlueRecords(string $domainName): array
    {
        $glueRecords = [];
        try {
            $nameservers = $this->api()->getNameservers($domainName);
            foreach ($nameservers as $ns) {
                $ips = [];
                if (isset($ns['ipaddress'])) {
                    $ips[] = $ns['ipaddress'];
                }
                if (isset($ns['ipv6'])) {
                    $ips[] = $ns['ipv6'];
                }

                if (!empty($ips)) {
                    $glueRecords[] = GlueRecord::create([
                        'hostname' => $ns['name'],
                        'ips' => $ips,
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Domain may not have hosts - ignore
        }

        return $glueRecords;
    }

    /**
     * @param UpdateNameserversParams $params
     * @return NameserversResult
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        // Get Domain Name and NameServers
        $domain = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        $nameServers = [];
        $currentNameServers = [];
        $nameServersForResponse = [];

        for ($i = 1; $i <= self::MAX_CUSTOM_NAMESERVERS; $i++) {
            if (Arr::has($params, 'ns' . $i)) {
                $nameServer = Arr::get($params, 'ns' . $i);
                $nameServers[] = $nameServer->toArray()['host'];
                $nameServersForResponse['ns' . $i] = ['host' => $nameServer->toArray()['host'], 'ip' => null];
            }
        }

        try {
            // Get current nameservers, which will be removed
            $currentNameServersRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domain,
                    'type' => 'nameservers'
                ]
            ]);

            foreach ($currentNameServersRaw['attributes']['nameserver_list'] as $ns) {
                $currentNameServers[] = $ns['name'];
            }

            // Make sure the new naneservers exist in the registry
            foreach ($nameServers as $ns) {
                $existsData = $this->api()->makeRequest([
                    'action' => 'REGISTRY_CHECK_NAMESERVER',
                    'object' => 'NAMESERVER',
                    'protocol' => 'XCP',
                    'attributes' => [
                        'tld' => Arr::get($params, 'tld'),
                        'fqdn' => $ns
                    ]
                ]);

                if ((int) $existsData['response_code'] == 212) {
                    // NameServer doesn't exists in the registry so we need to add it.
                    $this->api()->makeRequest([
                       'action' => 'REGISTRY_ADD_NS',
                       'object' => 'NAMESERVER',
                       'protocol' => 'XCP',
                       'attributes' => [
                           'tld' => Arr::get($params, 'tld'),
                           'fqdn' => $ns,
                           'all' => 0
                       ]
                    ]);
                }
            }

            // Prepare params
            $requestParams = [
                'action' => 'ADVANCED_UPDATE_NAMESERVERS',
                'object' => 'NAMESERVER',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domain,
                    'op_type' => 'add_remove',
                    'add_ns' => $nameServers
                ]
            ];

            // Remove old
            $toRemove = array_values(array_diff($currentNameServers, $nameServers));

            if (count($toRemove) > 0) {
                $requestParams['attributes']['remove_ns'] = $toRemove;
            }

            // Update nameservers
            $nameServersRaw = $this->api()->makeRequest($requestParams);

            return NameserversResult::create($nameServersForResponse)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domain));
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * Emails EPP code to the registrant's email address.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'type' => 'domain_auth_info'
                ]
            ]);

            $eppCode = $domainRaw['attributes']['domain_auth_info'] ?? null;

            if (empty($eppCode)) {
                $eppCode = $this->resetEppCode($sld, $tld);
            }

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    private function resetEppCode(string $sld, string $tld): string
    {
        $eppCode = Helper::generateStrictPassword(16, true, true, false);

        $this->api()->makeRequest([
            'action' => 'modify',
            'object' => 'DOMAIN',
            'protocol' => 'XCP',
            'attributes' => [
                'domain' => Utils::getDomain($sld, $tld),
                'affect_domains' => 0,
                'data' => 'domain_auth_info',
                'domain_auth_info' => $eppCode,
            ],
        ]);

        return $eppCode;
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        return $this->updateContact(UpdateContactParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
            'contact' => $params->contact,
            'contact_type' => ContactType::REGISTRANT,
        ]));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function updateContact(UpdateContactParams $params): ContactResult
    {
        try {
            $contactType = $params->getContactTypeEnum();
        } catch (UnexpectedValueException $ex) {
            $this->errorResult('Invalid contact type: ' . $params->contact_type);
        }

        $type = $this->getProviderContactTypeValue($contactType);

        try {
            $nameParts = OpenSrsApi::getNameParts($params->contact->name ?? $params->contact->organisation);

            $this->api()->makeRequest([
                'action' => 'UPDATE_CONTACTS',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($params->sld, $params->tld),
                    'types' => [$type],
                    'contact_set' => [
                        $type => [
                            'country' => Utils::normalizeCountryCode($params->contact->country_code),
                            'state' => Utils::stateNameToCode($params->contact->country_code, $params->contact->state),
                            'org_name' => $params->contact->organisation,
                            'phone' => Utils::internationalPhoneToEpp($params->contact->phone),
                            'postal_code' => $params->contact->postcode,
                            'city' => $params->contact->city,
                            'email' => $params->contact->email,
                            'address1' => $params->contact->address1,
                            'first_name' => $nameParts['firstName'],
                            'last_name' => $nameParts['lastName'],
                        ]
                    ]
                ]
            ]);

            return ContactResult::create([
                'contact_id' => strtolower($type),
                'name' => $params->contact->name,
                'email' => $params->contact->email,
                'phone' => $params->contact->phone,
                'organisation' => $params->contact->organisation,
                'address1' => $params->contact->address1,
                'city' => $params->contact->city,
                'postcode' => $params->contact->postcode,
                'country_code' => $params->contact->country_code,
                'state' => Utils::stateNameToCode($params->contact->country_code, $params->contact->state),
            ])->setMessage('Contact details updated');
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');
        $lock = (bool) Arr::get($params, 'lock', false);

        $domain = Utils::getDomain($sld, $tld);

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'MODIFY',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'data' => 'status',
                    'lock_state' => (int) $lock
                ]
            ]);

            return $this->_getInfo($sld, $tld, sprintf("Lock %s!", $lock ? 'enabled' : 'disabled'));
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \RuntimeException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        $domain = Utils::getDomain($sld, $tld);
        $autoRenew = (bool) $params->auto_renew;

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'MODIFY',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => Utils::getDomain($sld, $tld),
                    'data' => 'expire_action',
                    'auto_renew' => (int) $autoRenew,
                    'let_expire' => 0
                ]
            ]);

            return $this->_getInfo($sld, $tld, 'Domain auto-renew mode updated');
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        // Get the domain name
        $sld = Arr::get($params, 'sld');
        $tld = Arr::get($params, 'tld');

        $domain = Utils::getDomain($sld, $tld);
        $ipsTag = Arr::get($params, 'ips_tag');

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'MODIFY',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'affect_domains' => 0,
                    'change_tag_all' => 0,
                    'domain' => Utils::getDomain($sld, $tld),
                    'data' => 'change_ips_tag',
                    'gaining_registrar_tag' => $ipsTag
                ]
            ]);

            return $this->okResult(sprintf("IPS tag for domain %s has been changed!", $domain));
        } catch (\Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getVerificationStatus(VerificationStatusParams $params): VerificationStatusResult
    {
        try {
            $domain = Utils::getDomain($params->sld, $params->tld);
            $status = $this->api()->getRegistrantVerificationStatus($domain);

            return VerificationStatusResult::create($status);
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function resendVerificationEmail(ResendVerificationParams $params): ResendVerificationResult
    {
        try {
            $domain = Utils::getDomain($params->sld, $params->tld);
            $result = $this->api()->sendRegistrantVerificationEmail($domain);

            return ResendVerificationResult::create($result);
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $nsHost = strtolower($params->hostname);
        if (!Str::endsWith($nsHost, '.' . $domainName)) {
            $nsHost .= '.' . $domainName;
        }

        // Collect non-null IPs
        $ips = array_values(array_filter([
            $params->ip_1,
            $params->ip_2,
            $params->ip_3,
            $params->ip_4,
        ]));

        // Separate IPv4 and IPv6 addresses
        $ipv4 = null;
        $ipv6 = null;

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipv4 = $ipv4 ?? $ip;
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ipv6 = $ipv6 ?? $ip;
            }
        }

        try {
            // Delete existing host (ignore if not exists)
            try {
                $this->api()->deleteNameserver($nsHost, $domainName);
            } catch (Throwable $e) {
                // Ignore - host may not exist
            }

            // Create new host with IPs
            $this->api()->createNameserver($nsHost, $domainName, $ipv4, $ipv6);

            return GlueRecordsResult::create([
                'glue_records' => $this->listGlueRecords($domainName),
            ])->setMessage('Glue record created successfully');
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $nsHost = strtolower($params->hostname);
        if (!Str::endsWith($nsHost, '.' . $domainName)) {
            $nsHost .= '.' . $domainName;
        }

        try {
            $this->api()->deleteNameserver($nsHost, $domainName);

            return GlueRecordsResult::create([
                'glue_records' => $this->listGlueRecords($domainName),
            ])->setMessage('Glue record deleted successfully');
        } catch (Throwable $e) {
            $this->handleError($e, $params);
        }
    }

    /**
     * @param \Throwable $e Encountered error
     * @param DataSet|mixed[] $params
     *
     * @return no-return
     * @return never
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function handleError(Throwable $e, $params): void
    {
        if ($e instanceof ProvisionFunctionError) {
            throw $e;
        }

        if ($e instanceof TransferException) {
            $this->errorResult('Provider API Connection Failed', [
                'exception' => get_class($e),
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ], [], $e);
        }

        throw $e; // i dont want to just blindly copy any unknown error message into a the result
    }

    protected function api(): OpenSrsApi
    {
        if (isset($this->apiClient)) {
            return $this->apiClient;
        }

        $client = new Client([
            'connect_timeout' => 10,
            'timeout' => 60,
            'verify' => !$this->configuration->sandbox,
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        return $this->apiClient = new OpenSrsApi($client, $this->configuration);
    }

    /**
     * @inheritDoc
     */
    public function getStatus(DomainInfoParams $params): StatusResult
    {
        $domainName = Utils::getDomain(Arr::get($params, 'sld'), Arr::get($params, 'tld'));

        try {
            $domainRaw = $this->api()->makeRequest([
                'action' => 'GET',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domainName,
                    'type' => 'all_info',
                    'clean_ca_subset' => 1,
                    // 'active_contacts_only' => 1
                ]
            ]);

            $expiryDate = CarbonImmutable::parse($domainRaw['attributes']['expiredate']);

            if ($expiryDate->isPast()) {
                return StatusResult::create()
                    ->setStatus(StatusResult::STATUS_EXPIRED)
                    ->setExpiresAt($expiryDate);
            }

            return StatusResult::create()
                ->setStatus(StatusResult::STATUS_ACTIVE)
                ->setExpiresAt($expiryDate);
        } catch (ProvisionFunctionError $e) {
            $result = $this->api()->makeRequest([
                'action' => 'GET_DELETED_DOMAINS',
                'object' => 'DOMAIN',
                'protocol' => 'XCP',
                'attributes' => [
                    'domain' => $domainName,
                ]
            ]);

            $deletedDomain = $result['attributes']['del_domains'][0] ?? null;

            if (isset($deletedDomain)) {
                switch ($deletedDomain['reason']) {
                    case 'Transfered':
                        return StatusResult::create()
                            ->setStatus(StatusResult::STATUS_TRANSFERRED_AWAY)
                            ->setExpiresAt(null)
                            ->setExtra([
                                'delete_info' => $deletedDomain,
                            ]);
                    case 'Expired':
                        return StatusResult::create()
                            ->setStatus(StatusResult::STATUS_EXPIRED)
                            ->setExpiresAt(Carbon::createFromTimeString($deletedDomain['expiredate_epoch']))
                            ->setExtra([
                                'delete_info' => $deletedDomain,
                            ]);
                    default:
                        return StatusResult::create()
                            ->setStatus(StatusResult::STATUS_CANCELLED)
                            ->setExpiresAt(null)
                            ->setExtra([
                                'delete_info' => $deletedDomain,
                            ]);
                }
            }

            if (Str::contains($e->getMessage(), 'Registrant (end-user) authentication error')) {
                // this actually means domain not found
                $this->errorResult('Domain name not found', $e->getData(), $e->getDebug(), $e);
            }

            throw $e;
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function getProviderContactTypeValue(ContactType $contactType): string
    {
        switch ($contactType) {
            case $contactType->equals(ContactType::REGISTRANT()):
                return OpenSrsApi::CONTACT_TYPE_REGISTRANT;
            case $contactType->equals(ContactType::ADMIN()):
                return OpenSrsApi::CONTACT_TYPE_ADMIN;
            case $contactType->equals(ContactType::BILLING()):
                return OpenSrsApi::CONTACT_TYPE_BILLING;
            case $contactType->equals(ContactType::TECH()):
                return OpenSrsApi::CONTACT_TYPE_TECH;
            default:
                throw ProvisionFunctionError::create('Invalid contact type: ' . $contactType->getValue());
        }
    }
}
