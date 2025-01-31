<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Netim;

use DateTimeImmutable;
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
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversParams;
use Upmind\ProvisionProviders\DomainNames\Netim\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\Netim\Helper\APIRest;
use Upmind\ProvisionProviders\DomainNames\Netim\Helper\NetimAPIException;
use Upmind\ProvisionProviders\DomainNames\Netim\Helper\NormalizedContact;

/**
 * Netim provider.
 */
class Provider extends DomainNames implements ProviderInterface
{
    protected Configuration $configuration;
    protected ?APIRest $client = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Netim')
            ->setDescription('More than 1000 global extensions (ccTlds, gTlds, ngTlds) with a single registrar module');
    } 

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function poll(PollParams $params): PollResult
    {
        try {
            $poll = $this->client()->queryOpePending();
            $count = count($poll);
            $pollList = [];

            for ($i = 0; $i < min($count, $params->limit); $i++) {
                if (isset($params->after_date) && $poll[$i]->date < $params->after_date) {
                    break;
                }

                $type = '';
                switch ($poll[$i]->code_ope) {
                    case 'domainTransferIn':
                        $type = DomainNotification::TYPE_TRANSFER_IN;
                        break;
                    case 'domainRenew':
                        $type = DomainNotification::TYPE_RENEWED;
                        break;
                    case 'domainDelete':
                        $type = DomainNotification::TYPE_DELETED;
                        break;
                    default:
                        break;
                }

                if ($type === '') {
                    break;
                }

                $notification = [
                    'id' => $poll[$i]->id_ope,
                    'type' => $type,
                    'message' => $poll[$i]->code_ope,
                    'domains' => [$poll[$i]->data_ope],
                    'created_at' => Utils::formatDate($poll[$i]->date_ope),
                ];
                $pollList[] = DomainNotification::create($notification);
                --$count;
            }


            return PollResult::create([
                'notifications' => $pollList,
                'count_remaining' => $count,
            ]);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        try {
            $dacDomains = [];

            foreach ($params->tlds as $tld) {
                $domain = Utils::getDomain($params->sld, $tld);
                $domainCheck = $this->client()->domainCheck($domain);

                $available = strtolower($domainCheck[0]->result) === 'available';
                $transfer = !$available;

                if (
                    strtolower($domainCheck[0]->reason) === 'reserved'
                    || strtolower($domainCheck[0]->reason) === 'pending application'
                ) {
                    $available = false;
                    $transfer = false;
                }

                $premium = strtolower($domainCheck[0]->reason) === 'premium';

                $domain = DacDomain::create([
                    'domain' => $domain,
                    'description' => $data['reason'] ?? 'Domain is ' . $domainCheck[0]->result . ' to register',
                    'tld' => Utils::getTld($domain),
                    'can_register' => $available,
                    'can_transfer' => $transfer,
                    'is_premium' => $premium,
                ]);

                $dacDomains[] = $domain;
            }

            return DacResult::create(['domains' => $dacDomains]);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function register(RegisterDomainParams $params): DomainResult
    {
        $domain = Utils::getDomain(Utils::normalizeSld($params->sld), Utils::normalizeTld($params->tld));

        try {
            $domainCheck = $this->client()->domainCheck($domain);

            if (strtolower($domainCheck[0]->result) !== 'available') {
                $this->errorResult('Domain ' . $domain . ' is not available');
            }

            $registrant = $params->registrant['id'] ?? $this->createContact($params->registrant['register'], 1);
            $admin = $params->admin['id'] ?? $this->createContact($params->admin['register']);
            $tech = $params->tech['id'] ?? $this->createContact($params->tech['register']);
            $billing = $params->billing['id'] ?? $this->createContact($params->billing['register']);

            $ns1 = isset($params->nameservers->ns1) ? $params->nameservers->ns1['host'] : "";
            $ns2 = isset($params->nameservers->ns2) ? $params->nameservers->ns2['host'] : "";
            $ns3 = isset($params->nameservers->ns3) ? $params->nameservers->ns3['host'] : "";
            $ns4 = isset($params->nameservers->ns4) ? $params->nameservers->ns4['host'] : "";
            $ns5 = isset($params->nameservers->ns5) ? $params->nameservers->ns5['host'] : "";

            $result = $this->client()->domainCreate(
                $domain,
                $registrant,
                $admin,
                $tech,
                $billing,
                $ns1,
                $ns2,
                $ns3,
                $ns4,
                $ns5,
                (int) $params->renew_years,
            );

            if ($result->STATUS === 'Done') {
                return $this->getDomainInfo($domain)
                    ->setMessage('Your domain : ' . $domain . ' has been registered successfully');
            }

            if ($result->STATUS === 'Pending') {
                return $this->getDomainInfo($domain)
                    ->setMessage('Your domain : ' . $domain . ' registration is pending');
            }

            $this->errorResult($result->MESSAGE);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult
    {
        $domain = Utils::getDomain(Utils::normalizeSld($params->sld), Utils::normalizeTld($params->tld));

        try {
            // Registrant default placeholder.
            $registrant = '';

            // If param provided, set it.
            if (isset($params->registrant)) {
                $registrant = $params->registrant['id'] ?? $this->createContact($params->registrant['register'], 1);
            }

            // Then set admin, tech, and billing contacts if params provided, using registrant as default placeholder
            $admin = $registrant;

            if (isset($params->admin)) {
                $admin = $params->admin['id'] ?? $this->createContact($params->admin['register']);
            }

            $tech = $registrant;

            if (isset($params->tech)) {
                $tech = $params->tech['id'] ?? $this->createContact($params->tech['register']);
            }

            $billing = $registrant;

            if (isset($params->billing)) {
                $billing = $params->billing['id'] ?? $this->createContact($params->billing['register']);
            }

            $ns1 = isset($params->nameservers->ns1) ? $params->nameservers->ns1['host'] : "";
            $ns2 = isset($params->nameservers->ns2) ? $params->nameservers->ns2['host'] : "";
            $ns3 = isset($params->nameservers->ns3) ? $params->nameservers->ns3['host'] : "";
            $ns4 = isset($params->nameservers->ns4) ? $params->nameservers->ns4['host'] : "";
            $ns5 = isset($params->nameservers->ns5) ? $params->nameservers->ns5['host'] : "";

            $result = $this->client()->domainTransferIn(
                $domain,
                $params->epp_code,
                $registrant,
                $admin,
                $tech,
                $billing,
                $ns1,
                $ns2,
                $ns3,
                $ns4,
                $ns5,
            );

            if ($result->STATUS === 'Done') {
                return $this->getDomainInfo($domain)
                    ->setMessage('Your domain : ' . $domain . ' has been transferred successfully');
            }

            if ($result->STATUS === 'Pending') {
                return $this->getDomainInfo($domain)
                    ->setMessage('Your domain : ' . $domain . ' transfer is pending');
            }

            $this->errorResult($result->MESSAGE);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult
    {
        $domain = Utils::getDomain(Utils::normalizeSld($params->sld), Utils::normalizeTld($params->tld));
        try {
            $renew = $this->client()->domainRenew($domain, (int) $params->renew_years);

            if ($renew->STATUS === 'Done') {
                return $this->getDomainInfo($domain)->setMessage(
                    'Your domain : ' . $domain . ' has been renewed successfully'
                );
            }

            if ($renew->STATUS === 'Pending') {
                return $this->getDomainInfo($domain)->setMessage('Your domain : ' . $domain . ' renew is pending');
            }

            $this->errorResult($renew->MESSAGE);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domain = Utils::getDomain(Utils::normalizeSld($params->sld), Utils::normalizeTld($params->tld));

        try {
            return $this->getDomainInfo($domain);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        $domain = Utils::getDomain(Utils::normalizeSld($params->sld), Utils::normalizeTld($params->tld));

        try {
            // Get the owner contact id
            $domainInfo = $this->client()->domainInfo($domain);
            $owner = $domainInfo->idOwner;

            // Normalize the contact data
            $registrant = $this->normalizeContactToArray($params->contact, 1);

            // Update the contact
            $this->client()->contactOwnerUpdate($owner, $registrant);

            return ContactResult::create($this->getContactInfo($owner))
                ->setMessage('Your domain : ' . $domain . ' registrant has been updated successfully');
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domain = Utils::getDomain(Utils::normalizeSld($params->sld), Utils::normalizeTld($params->tld));

        $ns1 = isset($params->ns1) ? $params->ns1['host'] : "";
        $ns2 = isset($params->ns2) ? $params->ns2['host'] : "";
        $ns3 = isset($params->ns3) ? $params->ns3['host'] : "";
        $ns4 = isset($params->ns4) ? $params->ns4['host'] : "";
        $ns5 = isset($params->ns5) ? $params->ns5['host'] : "";

        try {
            $result = $this->client()->domainChangeDNS($domain, $ns1, $ns2, $ns3, $ns4, $ns5);

            if ($result->STATUS === 'Done') {
                return NameserversResult::create([
                    'ns1' => $params->ns1,
                    'ns2' => $params->ns2,
                    'ns3' => $params->ns3,
                    'ns4' => $params->ns4,
                    'ns5' => $params->ns5,
                ])->setMessage('Your domain : ' . $domain . ' has been updated successfully');
            }

            if ($result->STATUS === 'Pending') {
                return NameserversResult::create([
                    'ns1' => $params->ns1,
                    'ns2' => $params->ns2,
                    'ns3' => $params->ns3,
                    'ns4' => $params->ns4,
                    'ns5' => $params->ns5,
                ])->setMessage('Your domain : ' . $domain . ' update is pending');
            }

            $this->errorResult($result->MESSAGE);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setLock(LockParams $params): DomainResult
    {
        $domain = Utils::getDomain(Utils::normalizeSld($params->sld), Utils::normalizeTld($params->tld));

        try {
            $return = $this->client()->domainSetPreference($domain, 'registrar_lock', $params->lock ? '1' : '0');

            if ($return->STATUS === 'Pending') {
                return $this->getDomainInfo($domain)
                    ->setMessage('Your domain : ' . $domain . ' change lock is pending');
            }

            if ($return->STATUS === 'Done') {
                return $this->getDomainInfo($domain)
                    ->setMessage('Your domain : ' . $domain . ' has been ' . ($params->lock ? 'locked' : 'unlocked') . ' successfully');
            }

            $this->errorResult($return->MESSAGE);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domain = Utils::getDomain(Utils::normalizeSld($params->sld), Utils::normalizeTld($params->tld));

        try {
            $return = $this->client()->domainSetPreference($domain, 'auto_renew', $params->auto_renew ? '1' : '0');

            if ($return->STATUS === 'Pending') {
                return $this->getDomainInfo($domain)
                    ->setMessage('Your domain : ' . $domain . ' change auto renew is pending');
            }

            if ($return->STATUS === 'Done') {
                return $this->getDomainInfo($domain)->setMessage(
                    'Your domain : ' . $domain . ' auto renew has been ' . ($params->auto_renew ? 'enable' : 'disable') . ' successfully'
                );
            }

            $this->errorResult($return->MESSAGE);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult
    {
        try {
            $domain = Utils::getDomain(Utils::normalizeSld($params->sld), Utils::normalizeTld($params->tld));
            $tldinfo = $this->client()->domainTldInfo(Utils::normalizeTld($params->tld));

            if ($tldinfo->HasEppCode) {
                $domInfo = $this->client()->domainInfo($domain);
                return EppCodeResult::create()->setEppCode($domInfo->authID);
            }

            $this->errorResult($this->client()->domainAuthID($domain, 1)->MESSAGE);
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Not implemented');
    }

    /**
     * @throws NetimAPIException
     */
    protected function client(): APIRest
    {
        $url = (bool) $this->configuration->sandbox === true
            ? 'http://oterest.netim.com/1.0/'
            : 'https://rest.netim.com/1.0/';

        if ($this->client === null) {
            $this->client = new APIRest($this->configuration->username, $this->configuration->password, $url);
        }

        return $this->client;
    }


    // Utils function
    protected function nsParser(array $ns): array
    {
        $return = [];
        foreach ($ns as $key => $value) {
            $return['ns' . ($key + 1)] = [
                'host' => $value,
            ];
        }

        return $return;
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionProviders\DomainNames\Netim\Helper\NetimAPIException
     */
    protected function getContact($id): ContactData
    {

        $contact = $this->client()->contactInfo($id);
        return ContactData::create()
            ->setName($contact->firstName . ' ' . $contact->lastName)
            ->setEmail($contact->email)
            ->setOrganisation($contact->bodyName !== '' ? $contact->bodyName : null)
            ->setPhone(Utils::eppPhoneToInternational($contact->phone))
            ->setAddress1($contact->address1 . ' ' . $contact->address2)
            ->setCity($contact->city)
            ->setState($contact->area)
            ->setPostcode($contact->zipCode)
            ->setCountryCode($contact->country);
    }

    /**
     * @throws \libphonenumber\NumberParseException
     * @throws \Upmind\ProvisionProviders\DomainNames\Netim\Helper\NetimAPIException
     */
    protected function getDomainInfo($domain): DomainResult
    {
        $domainInfo = $this->client()->domainInfo($domain);
        $status = explode(',', $domainInfo->status);

        if (isset($domainInfo->ns) && !empty($domainInfo->ns)) {
            $nsGet = $this->nsParser($domainInfo->ns);
            $ns = NameserversParams::create();
            if (isset($nsGet['ns1'])) {
                $ns->setNs1($nsGet['ns1']);
            }
            if (isset($nsGet['ns2'])) {
                $ns->setNs2($nsGet['ns2']);
            }
            if (isset($nsGet['ns3'])) {
                $ns->setNs3($nsGet['ns3']);
            }
            if (isset($nsGet['ns4'])) {
                $ns->setNs4($nsGet['ns4']);
            }
            if (isset($nsGet['ns5'])) {
                $ns->setNs5($nsGet['ns5']);
            }
        } else {
            $ns = null;
        }

        return DomainResult::create()
            ->setId($domainInfo->authID)
            ->setDomain($domain)
            ->setStatuses($status)
            ->setLocked($domainInfo->domainIsLock === 1)
            ->setNs($ns)
            ->setRegistrant(isset($domainInfo->idOwner) ? $this->getContact($domainInfo->idOwner) : null)
            ->setAdmin(isset($domainInfo->idAdmin) ? $this->getContact($domainInfo->idAdmin) : null)
            ->setTech(isset($domainInfo->idTech) ? $this->getContact($domainInfo->idTech) : null)
            ->setBilling(isset($domainInfo->idBilling) ? $this->getContact($domainInfo->idBilling) : null)
            ->setCreatedAt(isset($domainInfo->dateCreate)
                ? DateTimeImmutable::createFromFormat('Y-m-d', $domainInfo->dateCreate)
                : null
            )
            ->setExpiresAt(isset($domainInfo->dateExpiration)
                ? DateTimeImmutable::createFromFormat('Y-m-d', $domainInfo->dateExpiration)
                : null
            )
            ->setUpdatedAt(null);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function createContact($params, $isOwner = 0)
    {
        try {
            $nameArray = $this->splitFullName($params->name);

            $normalizedContact = new NormalizedContact(
                $nameArray['first_name'],
                $nameArray['last_name'],
                $params->organisation ?? '',
                $params->address1 ?? '',
                $params->address2 ?? '',
                $params->postcode ?? '',
                $params->state ?? '',
                $params->country_code ?? '',
                $params->city ?? '',
                $params->phone ?? '',
                $params->email ?? '',
                'en',
                $isOwner
            );
            return $this->client()->contactCreate($normalizedContact->to_array());
        } catch (NetimAPIException $e) {
            $this->errorResult($e->getMessage());
        }
    }

    protected function normalizeContactToArray($params, $isOwner = 0): array
    {
        $nameArray = $this->splitFullName($params->name);

        $normalizedContact = new NormalizedContact(
            $nameArray['first_name'],
            $nameArray['last_name'],
            $params->bodyName ?? '',
            $params->address1 ?? '',
            $params->address2 ?? '',
            $params->postcode ?? '',
            $params->state ?? '',
            $params->country_code ?? '',
            $params->city ?? '',
            $params->phone ?? '',
            $params->email ?? '',
            'en',
            $isOwner
        );

        return $normalizedContact->to_array();
    }

    protected function getContactInfo($idContact): array
    {
        $contact = $this->client()->contactInfo($idContact);

        return [
            'name' => $contact->firstName . ' ' . $contact->lastName,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'address1' => $contact->address1,
            'city' => $contact->city,
            'state' => $contact->area,
            'postcode' => $contact->zipCode,
            'country_code' => $contact->country,
        ];
    }

    /**
     * @return array{
     *     first_name: string,
     *     last_name: string
     * }
     */
    private function splitFullName(string $name): array
    {
        // First, explode the name by spaces
        $exploded = explode(' ', trim($name));

        // Then, trim each element of the array
        $trimmed = array_map('trim', $exploded);

        // Now return an array with the first and last name
        // The first name is the first element of the array
        // The last name is the rest of the elements joined by a space
        return [
            'first_name' => $trimmed[0] ?? '',
            'last_name' => isset($trimmed[1]) ? implode(' ', array_slice($trimmed, 1)) : '',
        ];
    }
}
