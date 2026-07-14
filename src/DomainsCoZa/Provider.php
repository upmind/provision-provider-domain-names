<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainsCoZa;

use Carbon\Carbon;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Data\{
    AutoRenewParams,
    ContactResult,
    DacParams,
    DacResult,
    DomainInfoParams,
    DomainResult,
    DomainNotification,
    EppCodeResult,
    EppParams,
    GlueRecord,
    GlueRecordsResult,
    LockParams,
    NameserversResult,
    PollParams,
    PollResult,
    RegisterDomainParams,
    RenewParams,
    RemoveGlueRecordParams,
    SetGlueRecordParams,
    StatusResult,
    TransferParams,
    UpdateContactParams,
    UpdateDomainContactParams,
    UpdateNameserversParams,
    VerificationStatusParams,
    VerificationStatusResult,
    ResendVerificationParams,
    ResendVerificationResult,
    IpsTagParams
};
use Upmind\ProvisionProviders\DomainNames\DomainsCoZa\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\DomainsCoZa\Helper\DomainsCoZaApi;

class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;
    protected ?DomainsCoZaApi $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Domains.co.za')
            ->setDescription('Register, transfer, renew and manage domains via Domains.co.za')
            ->setLogoUrl('https://domains.co.za/assets/media/upmind/domains-logo.svg');
    }

    protected function api(): DomainsCoZaApi
    {
        return $this->api ??= new DomainsCoZaApi(
            $this->configuration->api_key,
            $this->getLogger(),
            (bool) ($this->configuration->sandbox ?? false)
        );
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $tlds = array_map(
            fn ($tld) => ltrim(strtolower((string) $tld), '.'),
            $params->tlds
        );

        $data = $this->api()->request('GET', 'domain/domain/checkTlds', [
            'sld' => $params->sld,
            'tlds' => implode(';', $tlds),
        ]);

        $results = $data['arrTLDs'] ?? [];

        $domains = [];
        foreach ($tlds as $tld) {
            $row = $results[$tld] ?? null;

            $canRegister = isset($row['isAvailable'])
                ? filter_var($row['isAvailable'], FILTER_VALIDATE_BOOLEAN)
                : false;

            $message = $row['message'] ?? null;
            $isSupported = $message === null || !str_contains(strtolower((string) $message), 'unsupported');

            $domains[] = [
                'domain' => $params->sld . '.' . $tld,
                'tld' => $tld,
                'can_register' => $canRegister,
                'can_transfer' => $isSupported && !$canRegister,
                'is_premium' => isset($row['isPremium'])
                    ? filter_var($row['isPremium'], FILTER_VALIDATE_BOOLEAN)
                    : false,
                'description' => $message ?? ($canRegister ? 'Available' : 'Unavailable'),
            ];
        }

        return DacResult::create(['domains' => $domains]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');

        $payload = [
            'sld' => $sld,
            'tld' => $tld,
            'period' => (string) ($params->renew_years ?? 1),
        ];

        $nameservers = self::extractNameservers($params);
        if (!empty($nameservers)) {
            $payload['dns'] = 'custom';
            foreach (array_values($nameservers) as $i => $host) {
                $payload['ns' . ($i + 1)] = $host;
            }
        } else {
            $payload['dns'] = 'managed';
        }

        foreach (['registrant', 'admin', 'billing', 'tech'] as $type) {
            $wrapper = $params->{$type} ?? null;
            if ($wrapper === null) {
                continue;
            }
            if (!empty($wrapper->id)) {
                $payload[$type . 'ContactId'] = $wrapper->id;
                continue;
            }
            $contact = $wrapper->register ?? $wrapper;
            $payload = array_merge($payload, self::flattenContact($contact, $type));
        }

        $this->api()->request('POST', 'domain', $payload);

        try {
            return $this->fetchDomainResult($sld, $tld)
                ->setMessage('Domain registered successfully');
        } catch (ProvisionFunctionError) {
            return DomainResult::create([
                'id' => "{$sld}.{$tld}",
                'domain' => "{$sld}.{$tld}",
                'ns' => [],
                'statuses' => ['pending'],
                'created_at' => null,
                'updated_at' => null,
                'expires_at' => null,
            ])->setMessage('Domain registration submitted successfully');
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');

        // If the domain is already on the account the transfer has completed.
        try {
            return $this->fetchDomainResult($sld, $tld)
                ->setMessage('Domain transfer completed');
        } catch (ProvisionFunctionError) {
            // Not on the account yet - check for or initiate a transfer below.
        }

        // transferCheck lists any in-flight transfer under arrDomains (with a "status");
        // report it rather than submitting a duplicate.
        $existingTransfer = null;
        try {
            $check = $this->api()->request('GET', 'domain/domain/transferCheck', ['sld' => $sld, 'tld' => $tld]);
            $existingTransfer = $check['arrDomains'][0] ?? null;
        } catch (ProvisionFunctionError) {
            // No existing transfer found.
        }

        if ($existingTransfer !== null) {
            $this->errorResult('Domain transfer is already in progress', [
                'status' => $existingTransfer['status'] ?? null,
            ]);
        }

        $payload = ['sld' => $sld, 'tld' => $tld, 'dns' => 'keep'];

        // EPP key is required for ICANN TLDs; .za domains do not use one
        if (!empty($params->epp_code)) {
            $payload['eppKey'] = $params->epp_code;
        }

        foreach (['registrant', 'admin', 'billing', 'tech'] as $type) {
            $wrapper = $params->{$type} ?? null;
            if ($wrapper === null) {
                continue;
            }
            if (!empty($wrapper->id)) {
                $payload[$type . 'ContactId'] = $wrapper->id;
                continue;
            }
            $contact = $wrapper->register ?? $wrapper;
            $payload = array_merge($payload, self::flattenContact($contact, $type));
        }

        $this->api()->request('POST', 'domain/transfer', $payload);

        // The registry processes the transfer asynchronously; a later call returns the
        // domain once it lands on the account.
        $this->errorResult('Domain transfer initiated; it is now in progress');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');

        $this->api()->request('POST', 'domain/renew', [
            'sld' => $sld,
            'tld' => $tld,
            'period' => (string) ($params->renew_years ?? 1),
        ]);

        return $this->fetchDomainResult($sld, $tld)
            ->setMessage('Domain renewed successfully');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');

        $this->api()->request('POST', 'domain/autorenew', [
            'sld' => $sld,
            'tld' => $tld,
            'autorenew' => $params->auto_renew ? 'true' : 'false',
        ]);

        return $this->fetchDomainResult($sld, $tld)
            ->setMessage('Auto-renew ' . ($params->auto_renew ? 'enabled' : 'disabled'));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');

        $data = $this->api()->request('GET', 'domain/eppKey', ['sld' => $sld, 'tld' => $tld]);

        $eppCode = $data['strEPPKey'] ?? null;
        if (empty($eppCode)) {
            $this->errorResult('EPP key is not available for this domain', $data);
        }

        return EppCodeResult::create(['epp_code' => $eppCode])
            ->setMessage('EPP code retrieved successfully');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');
        $action = $params->lock ? 'lock' : 'unlock';

        $this->api()->request('POST', 'domain/lock', [
            'sld' => $sld,
            'tld' => $tld,
            'action' => $action,
        ]);

        return $this->fetchDomainResult($sld, $tld)
            ->setMessage("Domain {$action}ed successfully");
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        return $this->fetchDomainResult($params->sld, ltrim($params->tld, '.'));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getStatus(DomainInfoParams $params): StatusResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');

        $data = $this->api()->request('GET', 'domain', ['sld' => $sld, 'tld' => $tld]);

        $rawStatuses = self::splitStatuses($data['strEppStatus'] ?? $data['strStatus'] ?? '');

        return StatusResult::create([
            'status' => self::normalizeStatus($data['strStatus'] ?? '', $rawStatuses),
            'raw_statuses' => $rawStatuses,
            'expires_at' => isset($data['intExDate'])
                ? Carbon::createFromTimestamp((int) $data['intExDate'])->toDateTimeString()
                : null,
        ]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');
        $nameservers = self::extractNameservers($params);

        if (count($nameservers) < 2) {
            $this->errorResult('At least two nameservers are required');
        }

        $payload = ['sld' => $sld, 'tld' => $tld];
        foreach (array_values($nameservers) as $i => $host) {
            $payload['ns' . ($i + 1)] = $host;
        }
        $this->api()->request('POST', 'domain/ns', $payload, extraOkCodes: [13]);

        $nsResult = [];
        foreach (array_values($nameservers) as $i => $host) {
            $nsResult['ns' . ($i + 1)] = ['host' => $host];
        }

        return NameserversResult::create($nsResult)
            ->setMessage('Nameservers updated successfully');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');

        $this->api()->request('PUT', 'domain', array_merge(
            ['sld' => $sld, 'tld' => $tld],
            self::flattenContact($params->contact, 'registrant')
        ), extraOkCodes: [4, 13]);

        return ContactResult::create(self::contactToArray($params->contact))
            ->setMessage('Registrant contact updated successfully');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateContact(UpdateContactParams $params): ContactResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');
        $type = $params->contact_type;

        $this->api()->request('PUT', 'domain', array_merge(
            ['sld' => $sld, 'tld' => $tld],
            self::flattenContact($params->contact, $type)
        ), extraOkCodes: [4, 13]);

        return ContactResult::create(self::contactToArray($params->contact))
            ->setMessage(ucfirst($type) . ' contact updated successfully');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        $since = $params->after_date ? Carbon::parse($params->after_date) : null;
        $limit = (int) $params->limit;

        $queryParams = [
            'acked' => 'false',
            'order' => 'ascending',
            'limit' => $limit,
        ];

        $data = $this->api()->request('POST', 'domain/poll/pollList', $queryParams);

        $notifications = [];
        $pollIdsToAck = [];

        foreach ($data['arrMessages'] ?? [] as $msg) {
            $pollId = $msg['pollId'] ?? null;
            $pollCode = (int) ($msg['pollCode'] ?? 0);
            $pollMessage = $msg['pollMessage'] ?? '';
            $pollDomain = $msg['pollDomain'] ?? '';
            $createDate = isset($msg['createDate'])
                ? Carbon::createFromTimestamp((int) $msg['createDate'])
                : Carbon::now();

            if ($pollId !== null) {
                $pollIdsToAck[] = $pollId;
            }

            if ($since !== null && $createDate->lessThan($since)) {
                continue;
            }

            $type = self::mapPollCodeToNotificationType($pollCode);
            if ($type === null) {
                continue;
            }

            if (count($notifications) >= $limit) {
                continue;
            }

            if (empty($pollDomain) || !str_contains($pollDomain, '.')) {
                continue;
            }

            $notifications[] = DomainNotification::create()
                ->setId($pollId)
                ->setType($type)
                ->setMessage($pollMessage)
                ->setDomains([$pollDomain])
                ->setCreatedAt($createDate)
                ->setExtra([
                    'poll_code' => $pollCode,
                    'poll_message_type' => $msg['pollMessageType'] ?? '',
                ]);
        }

        foreach ($pollIdsToAck as $id) {
            try {
                $this->api()->request('POST', 'domain/poll/ack', ['pollID' => $id]);
            } catch (ProvisionFunctionError) {
                // Ack failures are non-critical
            }
        }

        $countRemaining = max(
            0,
            ($data['intFilterTotal'] ?? $data['intTotal'] ?? 0) - count($pollIdsToAck)
        );

        return PollResult::create([
            'count_remaining' => $countRemaining,
            'notifications' => $notifications,
        ])->setMessage(sprintf('Retrieved %d notification(s)', count($notifications)));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');

        // .za registry glue records must be submitted as a full set of >= 2 hosts in a
        // single call, which is incompatible with this one-host-at-a-time operation.
        if (self::isZaTld($tld)) {
            $this->errorResult('Glue records are not supported for .za domains');
        }

        $ips = array_values(array_filter([
            $params->ip_1,
            $params->ip_2,
            $params->ip_3,
            $params->ip_4,
        ]));

        $this->api()->request(
            'POST',
            'domain/hostrecords',
            self::buildHostRecordPayload($sld, $tld, $params->hostname, $ips)
        );

        try {
            return $this->fetchGlueRecordsResult($sld, $tld)
                ->setMessage('Glue record set successfully');
        } catch (ProvisionFunctionError) {
            return GlueRecordsResult::create()
                ->setMessage('Glue record set successfully');
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
    {
        $sld = $params->sld;
        $tld = ltrim($params->tld, '.');

        if (self::isZaTld($tld)) {
            $this->errorResult('Glue records are not supported for .za domains');
        }

        $this->api()->request('DELETE', 'domain/hostrecords', [
            'sld' => $sld,
            'tld' => $tld,
            'host' => $params->hostname,
        ]);

        try {
            return $this->fetchGlueRecordsResult($sld, $tld)
                ->setMessage('Glue record removed successfully');
        } catch (ProvisionFunctionError) {
            return GlueRecordsResult::create()
                ->setMessage('Glue record removed successfully');
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getVerificationStatus(VerificationStatusParams $params): VerificationStatusResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function resendVerificationEmail(ResendVerificationParams $params): ResendVerificationResult
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * Fetch domain info and return a fully-populated DomainResult.
     */
    private function fetchDomainResult(string $sld, string $tld): DomainResult
    {
        $data = $this->api()->request('GET', 'domain', ['sld' => $sld, 'tld' => $tld]);
        return self::buildResult($data);
    }

    /**
     * Fetch the domain's host records and return a GlueRecordsResult.
     */
    private function fetchGlueRecordsResult(string $sld, string $tld): GlueRecordsResult
    {
        $glueRecords = [];

        $data = $this->api()->request('GET', 'domain/hostrecords', ['sld' => $sld, 'tld' => $tld]);

        foreach ($data['arrHosts'] ?? [] as $host) {
            try {
                $hostData = $this->api()->request('GET', 'domain/hostrecords', [
                    'sld' => $sld,
                    'tld' => $tld,
                    'host' => $host,
                ]);
                $ips = self::parseHostRecordIps($hostData);
                if (empty($ips)) {
                    continue;
                }
                $glueRecords[] = GlueRecord::create(['hostname' => $host, 'ips' => $ips]);
            } catch (ProvisionFunctionError) {
                // Skip hosts whose detail lookup fails
            }
        }

        return GlueRecordsResult::create(['glue_records' => $glueRecords]);
    }

    /**
     * Build a DomainResult from API response data.
     */
    private static function buildResult(array $data): DomainResult
    {
        $ns = [];
        foreach (array_values($data['arrNameservers'] ?? []) as $i => $host) {
            $ns['ns' . ($i + 1)] = ['host' => $host];
        }

        $eppStatus = strtolower($data['strEppStatus'] ?? '');
        $isLocked = str_contains($eppStatus, 'clienttransferprohibited')
            || str_contains($eppStatus, 'transferprohibited');

        $statuses = self::splitStatuses($data['strEppStatus'] ?? $data['strStatus'] ?? '');

        $domain = $data['strDomainName'] ?? '';

        return DomainResult::create([
            'id' => $domain,
            'domain' => $domain,
            'statuses' => $statuses ?: ['ok'],
            'locked' => $isLocked,
            'auto_renew' => isset($data['autorenew'])
                ? filter_var($data['autorenew'], FILTER_VALIDATE_BOOLEAN)
                : null,
            'whois_privacy' => isset($data['bPrivacy'])
                ? filter_var($data['bPrivacy'], FILTER_VALIDATE_BOOLEAN)
                : null,
            'registrant' => self::parseContact($data['arrRegistrant'] ?? []),
            'admin' => self::parseContact($data['arrAdmin'] ?? []),
            'tech' => self::parseContact($data['arrTech'] ?? []),
            'billing' => self::parseContact($data['arrBilling'] ?? []),
            'ns' => $ns,
            'created_at' => isset($data['intCrDate'])
                ? Carbon::createFromTimestamp((int) $data['intCrDate'])->toDateTimeString()
                : null,
            'updated_at' => isset($data['intUpDate'])
                ? Carbon::createFromTimestamp((int) $data['intUpDate'])->toDateTimeString()
                : null,
            'expires_at' => isset($data['intExDate'])
                ? Carbon::createFromTimestamp((int) $data['intExDate'])->toDateTimeString()
                : null,
        ]);
    }

    /**
     * Split a raw EPP/status string into individual status tokens.
     *
     * @return string[]
     */
    private static function splitStatuses(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($v) => $v !== ''));
    }

    /**
     * Normalize a domain status to the Upmind standard.
     */
    private static function normalizeStatus(string $raw, array $eppStatuses = []): string
    {
        $flags = array_map('strtolower', $eppStatuses);
        $has = fn (string $flag): bool => in_array(strtolower($flag), $flags, true);
        $rawLower = strtolower(trim($raw));

        if ($rawLower === 'expired'
            || $has('pendingdelete')
            || $has('redemptionperiod')) {
            return StatusResult::STATUS_EXPIRED;
        }

        if ($rawLower === 'transferred'
            || str_contains($rawLower, 'transferred away')
            || $has('pendingtransfer')) {
            return StatusResult::STATUS_TRANSFERRED_AWAY;
        }

        if ($rawLower === 'cancelled' || $rawLower === 'canceled') {
            return StatusResult::STATUS_CANCELLED;
        }

        return StatusResult::STATUS_ACTIVE;
    }

    /**
     * Map a Domains.co.za poll code to a DomainNotification type, or null if unsupported.
     *
     * @see https://docs.domains.co.za/#poll-codes
     */
    private static function mapPollCodeToNotificationType(int $code): ?string
    {
        return match ($code) {
            4005 => DomainNotification::TYPE_RENEWED,       // Domain Renew Successful
            4006, 4007 => DomainNotification::TYPE_DELETED,       // Domain Deletion / Release Successful
            4011 => DomainNotification::TYPE_TRANSFER_IN,   // Domain Transfer In Successful
            4016 => DomainNotification::TYPE_SUSPENDED,     // Domain Suspended, Pending Deletion
            4018 => DomainNotification::TYPE_TRANSFER_OUT,  // Domain Transferred Away
            default => null,
        };
    }

    /**
     * Whether a TLD is a .za ZACR TLD (uses glue records) rather than ICANN (host records).
     */
    private static function isZaTld(string $tld): bool
    {
        $tld = strtolower($tld);
        return $tld === 'za' || str_ends_with($tld, '.za');
    }

    /**
     * Extract an ordered list of nameserver hostnames from params.
     *
     * @return string[]
     */
    private static function extractNameservers(object $params): array
    {
        $hosts = [];
        $ns = $params->nameservers ?? $params;

        for ($i = 1; $i <= 5; $i++) {
            $entry = $ns->{'ns' . $i} ?? null;
            $host = is_object($entry) ? ($entry->host ?? null) : (is_string($entry) ? $entry : null);
            if (!empty($host)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * Build the POST payload for setting an ICANN host record.
     *
     * @param string[] $ips
     */
    private static function buildHostRecordPayload(string $sld, string $tld, string $hostname, array $ips): array
    {
        $payload = ['sld' => $sld, 'tld' => $tld, 'host' => $hostname];

        $ipv4Idx = 0;
        $ipv6Idx = 0;
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ipv6Idx++;
                $payload["host{$ipv6Idx}-ip6"] = $ip;
            } else {
                $ipv4Idx++;
                $payload["host{$ipv4Idx}-ip4"] = $ip;
            }
        }

        return $payload;
    }

    /**
     * Parse an ICANN host record info response into an IP list.
     *
     * @return string[]
     */
    private static function parseHostRecordIps(array $data): array
    {
        return array_values(array_filter(array_map(
            fn ($entry) => $entry['ip'] ?? '',
            $data['arrIPs'] ?? []
        )));
    }

    /**
     * Flatten a contact object into the flat API param format for the given type prefix
     * (e.g. prefix 'registrant' yields registrantName, registrantEmail, registrantCountry ...).
     */
    private static function flattenContact(object $contact, string $prefix): array
    {
        $countryCode = $contact->country_code ?? null;

        return array_filter([
            $prefix . 'Name' => $contact->name ?? $contact->organisation ?? null,
            $prefix . 'Company' => $contact->organisation ?? null,
            $prefix . 'Email' => $contact->email ?? null,
            $prefix . 'Country' => $countryCode,
            $prefix . 'Province' => $contact->state ?? null,
            $prefix . 'City' => $contact->city ?? null,
            $prefix . 'PostalCode' => $contact->postcode ?? null,
            $prefix . 'Address1' => $contact->address1 ?? null,
            $prefix . 'Address2' => $contact->address2 ?? null,
            $prefix . 'Address3' => $contact->address3 ?? null,
            $prefix . 'ContactNumber' => Utils::internationalPhoneToEpp($contact->phone ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Parse a raw API contact array into a flat array suitable for ContactResult.
     */
    private static function parseContact(array $contact): array
    {
        if (empty($contact)) {
            return [];
        }

        $address = $contact['strContactAddress'] ?? [];
        if (is_string($address)) {
            $address = [$address];
        }

        return array_filter([
            'id' => $contact['strContactID'] ?? null,
            'name' => $contact['strContactName'] ?? null,
            'email' => $contact['strContactEmail'] ?? null,
            'phone' => $contact['strContactNumber'] ?? null,
            'organisation' => $contact['strContactCompany'] ?? null,
            'address1' => $address[0] ?? null,
            'address2' => $address[1] ?? null,
            'city' => $contact['strContactCity'] ?? null,
            'state' => $contact['strContactProvince'] ?? null,
            'postcode' => $contact['strContactPostalCode'] ?? null,
            'country_code' => $contact['strContactCountry'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Build a flat contact array from an input contact object.
     */
    private static function contactToArray(object $contact): array
    {
        return array_filter([
            'name' => $contact->name ?? null,
            'email' => $contact->email ?? null,
            'phone' => $contact->phone ?? null,
            'organisation' => $contact->organisation ?? null,
            'address1' => $contact->address1 ?? null,
            'address2' => $contact->address2 ?? null,
            'city' => $contact->city ?? null,
            'state' => $contact->state ?? null,
            'postcode' => $contact->postcode ?? null,
            'country_code' => $contact->country_code ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
