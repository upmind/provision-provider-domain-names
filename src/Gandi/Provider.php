<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Gandi;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\Enums\ContactType;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Gandi\Helper\GandiApi;
use Upmind\ProvisionProviders\DomainNames\Gandi\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusResult;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationParams;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationResult;
use Upmind\ProvisionProviders\DomainNames\Data\SetGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\RemoveGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecordsResult;
use Upmind\ProvisionProviders\DomainNames\Data\StatusResult;
use Illuminate\Support\Str;

/**
 * Gandi provider
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;
    protected ?GandiApi $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public static function aboutProvider(): AboutData
    {
        // Static provider identity, read once when the registry is built
        return AboutData::create()
            ->setName('Gandi Provider')
            ->setDescription('Gandi domain registrar provider')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/gandi-logo.svg');
    }

    /**
     * @inheritDoc
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
        // Check registerability of the SLD against each TLD using Gandi's API check
        $sld = Utils::normalizeSld($params->sld);
            try {
                $dacDomains = $this->api()->checkMultipleDomains($sld, $params->tlds);

                return DacResult::create(['domains'=>$dacDomains]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        // Register a new domain, then return its details
        $domain = Utils::getDomain($params->sld, $params->tld);
        $privacy = $params->whois_privacy === null ? null : (bool) $params->whois_privacy;

        $body = [
            'fqdn'        => $domain,
            'duration'    => (int) $params->renew_years,
            'owner'       => GandiApi::buildContact($params->registrant->register, $privacy),
            'admin'       => GandiApi::buildContact($params->admin->register, $privacy),
            'tech'        => GandiApi::buildContact($params->tech->register, $privacy),
            'bill'        => GandiApi::buildContact($params->billing->register, $privacy),
            'nameservers' => $params->nameservers->pluckHosts(),
        ];

        if (!empty($params->additional_fields)) {
            $body['extra_parameters'] = $params->additional_fields;
        }
            try {
                $this->api()->makeRequest($body, $this->_withSharingId('domain/domains'), 'POST');
            } catch (RequestException $e) {
                $this->handleException($e);
            }
        // Gandi creates domains asynchronously so retry the fetch a few times before giving up
        $attemptsLeft = 5;
        while ($attemptsLeft-- > 0) {
            try {
                return $this->_getDomain($domain, "Domain {$domain} registered");
            } catch (RequestException $e) {
                if ($attemptsLeft > 0) {
                    sleep(2);
                }
            }
        }

        return DomainResult::create([
            'id'         => $domain,
            'domain'     => $domain,
            'statuses'   => ['pending_create'],
            'ns'         => $params->nameservers,
            'created_at' => null,
            'updated_at' => null,
            'expires_at' => null,
        ])->setMessage("Registration accepted for {$domain}; provisioning is still in progress");
    }

    /**
     * @inheritDoc
     */
    public function transfer(TransferParams $params): DomainResult
    {
        // Return the domain if it's already in the account, otherwise initiate the transfer-in
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getDomain($domain, "Domain {$domain} is active");
        } catch (RequestException $e) {
            // domain not in the account yet, fall through to initiate the transfer
        }

        $eppCode = $params->epp_code;
        if (empty($eppCode)) {
            $this->errorResult("EPP code is required for domain transfer of {$domain}");
        }

        $registrant = $params->registrant ? $params->registrant->register : null;
        if (!$registrant) {
            $this->errorResult("Registrant contact details are required for domain transfer of {$domain}");
        }

        $privacy = $params->whois_privacy === null ? null : (bool) $params->whois_privacy;

        $body = [
            'fqdn'        => $domain,
            'authinfo'    => $eppCode,
            'owner'       => GandiApi::buildContact($registrant, $privacy),
        ];

        if (!empty($params->renew_years)) {
            $body['duration'] = (int) $params->renew_years;
        }

        foreach(['admin' => $params->admin, 'tech' => $params->tech, 'bill' => $params->billing] as $key => $contact) {
            if ($contact &&$contact->register) {
                $body[$key] = GandiApi::buildContact($contact->register, $privacy);
            }
        }
        try {
            $this->api()->makeRequest($body, $this->_withSharingId('domain/transferin'), 'POST');
        } catch (RequestException $e) {
            $this->handleException($e);
        }
        $this->errorResult(
            "Transfer initiated for {$domain}, completion is pending registry or owner confirmation",

            ['domain' => $domain]
        );

    }

    /**
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            $this->api()->makeRequest(
                ['duration' => (int) $params->renew_years],
                $this->_withSharingId("domain/domains/{$domain}/renew"),
                'POST'
            );
            return $this->_getDomain($domain, "Domain {$domain} renewed for {$params->renew_years} year(s)");
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
        return $this->_getDomain($domain, "Domain info for {$domain}");
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        // Change the domain's owner, this requires a different endpoint and ICANN contract
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            $body = GandiApi::buildContact($params->contact);
            unset($body['type'], $body['orgname']);
            $body['icann_contract_accept'] = true;

            $this->api()->makeRequest($body, "domain/domains/{$domain}/contacts/owner", 'PUT');
            return ContactResult::create([
                'name' => $params->contact->name,
                'organisation' => $params->contact->organisation,
                'email' => $params->contact->email,
                'phone' => $params->contact->phone,
                'address1' => $params->contact->address1,
                'city' => $params->contact->city,
                'state' => $params->contact->state,
                'postcode' => $params->contact->postcode,
                'country_code' => $params->contact->country_code,
            ]) -> setMessage("Registrant owner contact for domain {$domain} updated");
        }  catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateContact(UpdateContactParams $params): ContactResult
    {
        // Update a contact (admin, tech, or billing)
        $type = $params->getContactTypeEnum();

        if ($type->equals(ContactType::REGISTRANT())) {
            return $this->updateRegistrantContact(UpdateDomainContactParams::create([
                'sld' => $params->sld,
                'tld' => $params->tld,
                'contact' => $params->contact,
            ]));
        }

        switch ($type->getValue()) {
            case ContactType::ADMIN:
                $key = GandiApi::CONTACT_TYPE_ADMIN;
                break;
            case ContactType::TECH:
                $key = GandiApi::CONTACT_TYPE_TECH;
                break;
            case ContactType::BILLING:
                $key = GandiApi::CONTACT_TYPE_BILLING;
                break;
            default:
                $this->errorResult("Unsupported contact type: {$type->getValue()}");
        }

        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            $this->api()->makeRequest(
                [$key => GandiApi::buildContact($params->contact)],
                "domain/domains/{$domain}/contacts",
                'PATCH'
            );
            return ContactResult::create([
                'name' => $params->contact->name,
                'organisation' => $params->contact->organisation,
                'email' => $params->contact->email,
                'phone' => $params->contact->phone,
                'address1' => $params->contact->address1,
                'city' => $params->contact->city,
                'state' => $params->contact->state,
                'postcode' => $params->contact->postcode,
                'country_code' => $params->contact->country_code,
            ]) -> setMessage($type->getValue() . " contact for domain {$domain} updated");
        }  catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        // Replace the domain's nameservers and returns the submitted set, Gandi applies the change asynchronously
        $domain = Utils::getDomain($params->sld, $params->tld);
        $hosts = $params->pluckHosts();

        try {
            $this->api()->makeRequest(
                ['nameservers' => $hosts],
                "domain/domains/{$domain}/nameservers",
                'PUT'
            );
            $ns = [];
            foreach (array_values($hosts) as $i => $host) {
                $ns['ns' . ($i + 1)] = Nameserver::create()->setHost($host);
            }
            return NameserversResult::create($ns)->setMessage("Nameservers for domain {$domain} updated");
        }  catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function setLock(LockParams $params): DomainResult
    {
        // Set or clear the transfer lock (clientTransferProhibited), return the domain's details
        $domain = Utils::getDomain($params->sld, $params->tld);
        
        try {
            $this->api()->makeRequest(
                ['clientTransferProhibited' => (bool) $params->lock],
                "domain/domains/{$domain}/status",
                'PATCH'
            );
            return $this->_getDomain($domain, $params->lock ? "Domain {$domain} locked" : "Domain {$domain} unlocked");
        }  catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        // Toggle automatic renewal, then return the domain's refreshed details
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {  
            $this->api()->makeRequest( 
                ['enabled' => (bool) $params->auto_renew],
                "domain/domains/{$domain}/autorenew",
                'PATCH'
            );
            return $this -> _getDomain($domain, $params->auto_renew ? "Domain {$domain} auto-renew enabled" : "Domain {$domain} auto-renew disabled");
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        // Get the domain's EPP/auth code
        $domain = Utils::getDomain($params->sld, $params->tld);

        try {
            $data = $this->api()->makeRequest([], "domain/domains/{$domain}");

            $authInfo = $data['authinfo'] ?? null;
            if (empty($authInfo)) {
                $this->errorResult("EPP code not available for domain {$domain}", ['domain' => $domain]);
            }
            return EppCodeResult::create(['epp_code' => $authInfo])->setMessage("EPP code for domain {$domain}");
        }  catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getVerificationStatus(VerificationStatusParams $params): VerificationStatusResult
    {
        // Return the registrant's reachability (ICANN) verification status
        $domain = Utils::getDomain($params->sld, $params->tld);

        try{
            $data = $this->api()->makeRequest([], "domain/domains/{$domain}");

            $reachability = $data['reachability'] ?? 'none';

            return VerificationStatusResult::create()
                ->setIcannVerificationStatus($reachability)
                ->setProviderSpecificData(['reachability' => $reachability])
                ->setMessage("Reachability status for {$domain}: {$reachability}");
        }  catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function resendVerificationEmail(ResendVerificationParams $params): ResendVerificationResult
    {
        // Ask Gandi to resend the registrant's reachability (ICANN) verification email
        $domain = Utils::getDomain($params->sld, $params->tld);
        try {  
            $this->api()->makeRequest(
                ['action' => 'resend'],
                "domain/domains/{$domain}/reachability",
                'PATCH'
            );

            return ResendVerificationResult::create()
                ->setSuccess(true)
                ->setMessage("Reachability verification email resent for {$domain}");
        }  catch (Throwable $e) {
            $this->handleException($e);
        }
    }
    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
    {
        // Create a glue record (host + IPs)
        $domain = Utils::getDomain($params->sld, $params->tld);
        
        try {
            $ips = array_values(array_filter([
                $params->ip_1,
                $params->ip_2,
                $params->ip_3,
                $params->ip_4,
            ]));

            $name = $this->_gandiHostName($params->hostname, $domain);

            $this->api()->makeRequest(
                ['name' => $name, 'ips' =>$ips],
                "domain/domains/{$domain}/hosts",
                'POST'
            );

            return GlueRecordsResult::create([
                'glue_records' => [
                    GlueRecord::create()
                        ->setHostname($params->hostname)
                        ->setIps($ips),
                ],
            ])->setMessage("Glue record {$params->hostname} for domain: {$domain}");
        }  catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
    {
        // Delete a glue host record
        $domain = Utils::getDomain($params->sld, $params->tld);
        
        try {
            $name = $this->_gandiHostName($params->hostname, $domain);

            $this->api()->makeRequest(
                [],
                "domain/domains/{$domain}/hosts/{$name}",
                'DELETE'
            );

            return GlueRecordsResult::create([
                'glue_records' => [],
            ])->setMessage("Glue record {$params->hostname} for domain: {$domain} removed");
        }  catch (Throwable $e) {
            $this->handleException($e);
        }
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

    /**
     * @return no-return
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function handleException(Throwable $e): void
    {
        if (($e instanceof RequestException) && $e->hasResponse()) {
            /** @var \Psr\Http\Message\ResponseInterface $response */
            $response = $e->getResponse();

            $responseBody = $response->getBody()->__toString();
            $responseData = json_decode($responseBody, true);

            $errorMessage = $responseData['message'] ?? 'unknown error';

            $this->errorResult(
                sprintf('Provider API Error: %s', $errorMessage),
                ['response_data' => $responseData],
                [],
                $e
            );
        }

        throw $e;
    }




    protected function _getDomain(string $domainName, string $msg = 'domain data'): DomainResult {
        // Fetch a domain and map Gandi's response into a DomainResult
        $data = $this->api()->makeRequest([], "domain/domains/{$domainName}");
        $ns = [];
        foreach ($data['nameservers'] ?? [] as $i => $host) {
            $ns['ns' . ($i + 1)] = Nameserver::create()->setHost($host);
        }
        // read $statuses
        $statuses = $data['status'] ?? [];
        // derive locked and autorenew
        $auto_renew = $data['autorenew']['enabled'] ?? false;
        $locked = in_array('clientTransferProhibited', $statuses) || in_array('serverTransferProhibited', $statuses);
        // build 4 contacts
        $owner = GandiApi::parseContact($data['contacts']['owner'] ?? []);
        $admin = GandiApi::parseContact($data['contacts']['admin'] ?? []);
        $tech = GandiApi::parseContact($data['contacts']['tech'] ?? []);
        $bill = GandiApi::parseContact($data['contacts']['bill'] ?? []);
        // format dates
        $dateCreated = isset($data['dates']['registry_created_at'])
        ? Carbon::parse($data['dates']['registry_created_at']) : null;
        $dateUpdated = isset($data['dates']['updated_at'])
        ? Carbon::parse($data['dates']['updated_at']) : null;
        $dateExpires = isset($data['dates']['registry_ends_at'])
        ? Carbon::parse($data['dates']['registry_ends_at']) : null;
        // create and return result
        return DomainResult::create(['auto_renew' => $auto_renew])
            ->setId($data['id'] ?? $domainName)
            ->setDomain($domainName)
            ->setStatuses($statuses)
            ->setLocked($locked)
            ->setNs($ns)
            ->setRegistrant($owner)
            ->setAdmin($admin)
            ->setTech($tech)
            ->setBilling($bill)
            ->setCreatedAt($dateCreated)
            ->setUpdatedAt($dateUpdated)
            ->setExpiresAt($dateExpires)
            ->setMessage($msg);
        }

  
    protected function _withSharingId(string $path): string
    {
        // Adds the sharing_id to a path
        $sharingId = $this->configuration->sharing_id ?? null;
        if (!$sharingId) {
            return $path;
        }
        return $path . (Str::contains($path, '?') ? '&' : '?') . 'sharing_id=' . urlencode($sharingId);
    }

    protected function _gandiHostName(string $hostname, string $domain): string
    {
        // Convert a glue hostname to Gandi's required label
        $hostname = strtolower(trim($hostname, '.'));
        if ($hostname === $domain) {
            return '@';
        }

        $suffix = '.' . $domain;
        if (Str::endsWith($hostname, $suffix)) {
            return substr($hostname, 0, -strlen($suffix));
        }

        return $hostname;
    }

    protected function api(): GandiApi
    {
        if ($this->api !== null) {
            return $this->api;
        }
        $client = new Client([
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->configuration->api_token,
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        return $this->api = new GandiApi($client, $this->configuration);
    }
}
