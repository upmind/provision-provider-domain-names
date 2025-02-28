<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\TwentyI\Helper;

use Psr\Log\LoggerInterface;
use Throwable;
use TwentyI\API\Exception;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\TwentyI\Data\Configuration;

/**
 * TwentyI Domains API client.
 */
class TwentyIApi
{
    /**
     * Contact Types
     */
    public const CONTACT_TYPE_REGISTRANT = 'registrant';
    public const CONTACT_TYPE_TECH = 'tech';
    public const CONTACT_TYPE_ADMIN = 'admin';
    public const CONTACT_TYPE_BILLING = 'billing';


    protected Configuration $configuration;

    protected LoggerInterface $logger;


    /**
     * @var Services $services
     */
    protected $services;

    public function __construct(Configuration $configuration, ?LoggerInterface $logger = null)
    {
        $this->configuration = $configuration;

        $this->services = new Services($configuration->general_api_key);
        $this->services->setLogger($logger);
    }


    public function getDomainInfo(string $domainName): array
    {
        $response = $this->services->getWithFields("/domain/$domainName");
        $verification = $this->services->getWithFields("/domainVerification");
        $id = 'N/A';

        foreach ($verification as $domain) {
            if ($domain->name == $domainName) {
                $id = (string)$domain->id;
            }
        }

        $contacts = $this->services->getWithFields("/domain/$domainName/contacts");
        $expiry = $this->services->getWithFields("/domain/$domainName/upstreamExpiryDate");
        $canTransfer = $this->services->getWithFields("/domain/$domainName/canTransfer");

        return [
            'id' => $id,
            'domain' => (string)$response->name,
            'statuses' => [],
            'locked' => !$canTransfer,
            'registrant' => isset($contacts->registrant) ? $this->parseContact($contacts->registrant) : null,
            'billing' => isset($contacts->billing) ? $this->parseContact($contacts->billing) : null,
            'tech' => isset($contacts->tech) ? $this->parseContact($contacts->tech) : null,
            'admin' => isset($contacts->admin) ? $this->parseContact($contacts->admin) : null,
            'ns' => NameserversResult::create($this->parseNameservers($response->nameservers)),
            'created_at' => null,
            'updated_at' => null,
            'expires_at' => Utils::formatDate($expiry) ?? null,
        ];
    }

    private function parseContact(\stdClass $contact): ContactData
    {
        return ContactData::create([
            'organisation' => (string)$contact->postalInfo->org ?: '-',
            'name' => $contact->postalInfo->name ?: '-',
            'address1' => implode(", ", array_filter($contact->postalInfo->addr->street)),
            'city' => (string)$contact->postalInfo->addr->city ?: '-',
            'state' => (string)$contact->postalInfo->addr->sp ?: '-',
            'postcode' => (string)$contact->postalInfo->addr->pc ?: '-',
            'country_code' => Utils::normalizeCountryCode((string)$contact->postalInfo->addr->cc) ?: '-',
            'email' => (string)$contact->email ?: '-',
            'phone' => (string)$contact->voice ?: '-',
        ]);
    }


    private function parseNameservers(array $nameservers): array
    {
        $result = [];
        $i = 1;

        foreach ($nameservers as $ns) {
            $result['ns' . $i] = ['host' => (string)$ns];
            $i++;
        }

        return $result;
    }


    public function checkMultipleDomains(string $sld, array $domains): array
    {
        $response = $this->services->getWithFields("/domain-search/{$sld}");
        $dacDomains = [];

        $supportedTLDs = $response[0]->header->names;

        $premiumTLDs = $this->services->getWithFields("/domainPremiumType");

        unset($response[0]);

        foreach ($response as $result) {
            $domainName = $sld . $result->name;

            if (!in_array($domainName, $domains)) {
                continue;
            }

            foreach ($premiumTLDs as $premiumTLD => $value) {
                if ($premiumTLD == $result->name) {
                    if ($value != null) {
                        $premium = true;
                    }

                    break;
                }
            }

            $available = $result->can == "register";

            $dacDomains[] = DacDomain::create([
                'domain' => $domainName,
                'description' => sprintf(
                    'Domain is %s to register',
                    $available ? 'available' : 'not available'
                ),
                'tld' => Utils::getTld($domainName),
                'can_register' => $available,
                'can_transfer' => !$available && $result->can != "transfer?",
                'is_premium' => $premium ?? false,
            ]);
        }

        foreach ($domains as $domain) {
            if (!in_array($domain, array_column($dacDomains, 'domain'))) {
                $description = 'Domain is not available to register.';
                if (!in_array("." . Utils::getTld($domain), $supportedTLDs)) {
                    $description = "TLD not supported.";
                }

                foreach ($premiumTLDs as $premiumTLD => $value) {
                    if ($premiumTLD == "." . Utils::getTld($domain)) {
                        if ($value != null) {
                            $premium = true;
                        }

                        break;
                    }
                }

                $dacDomains[] = DacDomain::create([
                    'domain' => $domain,
                    'description' => $description,
                    'tld' => Utils::getTld($domain),
                    'can_register' => false,
                    'can_transfer' => false,
                    'is_premium' => $premium ?? false,
                ]);
            }
        }

        return $dacDomains;
    }


    /**
     * @throws Throwable
     * @throws Exception
     */
    public function renew(string $domainName, int $period): void
    {
        $this->services->postWithFields("/reseller/*/renewDomain", [
            "name" => $domainName,
            "years" => $period,
        ]);
    }


    /**
     * @param string[] $nameservers
     */
    public function updateNameservers(string $domainName, array $nameservers): array
    {
        $oldNameservers = $this->services->getWithFields("/domain/$domainName/nameservers");

        $this->services->postWithFields("/domain/$domainName/nameservers", [
            "ns" => $nameservers,
            "old-ns" => $oldNameservers,
        ]);

        $newNameservers = $this->services->getWithFields("/domain/$domainName/nameservers");

        return $this->parseNameservers($newNameservers);
    }


    public function getRegistrarLockStatus(string $domainName): bool
    {
        return !$this->services->getWithFields("/domain/$domainName/canTransfer");
    }


    /**
     * @throws Throwable
     * @throws Exception
     */
    public function setRegistrarLock(string $domainName, bool $lock): bool
    {
        return $this->services->postWithFields("/domain/$domainName/canTransfer", [
            "enable" => !$lock
        ]);
    }


    public function getDomainEppCode(string $domainName): string
    {
        return $this->services->getWithFields("/domain/$domainName/authCode");
    }


    /**
     * @throws Throwable
     * @throws Exception
     */
    public function register(string $domainName, int $years, array $contacts, array $nameServers, bool $privacy): void
    {
        $this->services->postWithFields("/reseller/*/addDomain", [
            "name" => $domainName,
            "years" => $years,
            "contact" => $this->setRegisterContactParams($contacts[self::CONTACT_TYPE_REGISTRANT]),
            "otherContacts" => [
                self::CONTACT_TYPE_TECH => $this->setRegisterContactParams($contacts[self::CONTACT_TYPE_TECH]),
                self::CONTACT_TYPE_BILLING => $this->setRegisterContactParams($contacts[self::CONTACT_TYPE_BILLING]),
                self::CONTACT_TYPE_ADMIN => $this->setRegisterContactParams($contacts[self::CONTACT_TYPE_ADMIN])
            ],
            "nameservers" => $nameServers,
            "privacyService" => $privacy,
        ]);
    }


    private function setRegisterContactParams(ContactParams|null $contactParams): array
    {
        if (!$contactParams) {
            return [];
        }

        return
            [
                "organisation" => $contactParams->organisation ?: '',
                "name" => $contactParams->name ?? $contactParams->organisation,
                "address" => $contactParams->address1,
                "telephone" => Utils::internationalPhoneToEpp($contactParams->phone),
                "email" => $contactParams->email,
                "cc" => Utils::normalizeCountryCode($contactParams->country_code),
                "pc" => $contactParams->postcode,
                "sp" => $contactParams->state ?: $contactParams->city,
                "city" => $contactParams->city,
            ];
    }


    private function setContactParams(ContactParams $contactParams): array
    {
        return [
            "postalInfo" => [
                "name" => $contactParams->name ?? $contactParams->organisation,
                "org" => $contactParams->organisation ?: '',
                "addr" => [
                    "street" => [$contactParams->address1],
                    "sp" => $contactParams->state ?: $contactParams->city,
                    "cc" => Utils::normalizeCountryCode($contactParams->country_code),
                    "pc" => $contactParams->postcode,
                ]
            ],
            "voice" => Utils::internationalPhoneToEpp($contactParams->phone),
            "email" => $contactParams->email,
        ];
    }


    /**
     * @throws Throwable
     * @throws Exception
     */
    public function updateRegistrantContact(string $domainName, ContactParams $registrantParams): ContactData
    {
        $contacts = $this->services->getWithFields("/domain/$domainName/contacts");

        $contacts->registrant = $this->setContactParams($registrantParams);

        $this->services->postWithFields("/domain/$domainName/contacts", $contacts);

        return $this->getDomainInfo($domainName)['registrant'];
    }


    /**
     * @throws Throwable
     * @throws Exception
     */
    public function initiateTransfer(string $domainName, int $years, string $eppCode, array $contacts, bool $privacy): void
    {
        $this->services->postWithFields("/reseller/*/transferDomain", [
            "name" => $domainName,
            "years" => $years,
            "contact" => $this->setRegisterContactParams($contacts[self::CONTACT_TYPE_REGISTRANT]),
            "otherContacts" => [
                self::CONTACT_TYPE_TECH => $this->setRegisterContactParams($contacts[self::CONTACT_TYPE_TECH]),
                self::CONTACT_TYPE_BILLING => $this->setRegisterContactParams($contacts[self::CONTACT_TYPE_BILLING]),
                self::CONTACT_TYPE_ADMIN => $this->setRegisterContactParams($contacts[self::CONTACT_TYPE_ADMIN])
            ],
            "authcode" => $eppCode,
            "privacyService" => $privacy,
        ]);
    }


    /**
     * @throws Throwable
     * @throws Exception
     */
    public function updateIpsTag(string $domainName, string $ips_tag): bool
    {
        return $this->services->postWithFields("/domain/$domainName/tag", [
            "new-tag" => $ips_tag
        ]);
    }

}
