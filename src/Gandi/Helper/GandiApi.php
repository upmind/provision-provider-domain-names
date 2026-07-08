<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Gandi\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use JsonException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Gandi\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

 /**F
 * Gandi v5 API helper class
 * @package Upmind\ProvisionProviders\DomainNames\Gandi\Helper
 */
class GandiApi
{
    protected Client $client;

    protected Configuration $configuration;

    /**
     * Gandi contact types
     */
    public const CONTACT_TYPE_OWNER = 'owner';
    public const CONTACT_TYPE_ADMIN = 'admin';
    public const CONTACT_TYPE_TECH = 'tech';
    public const CONTACT_TYPE_BILLING = 'bill';
    
    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    /**
     * Send request and return the response
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function makeRequest(array $params, string $path, string $method = 'GET'): ?array
    {
        return $this->makeRequestAsync($params, $path, $method)->wait();
    }

    /**
     * Send an async request; resolves to the decoded response data (null for empty bodies)
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function makeRequestAsync(array $params, string $path, string $method = 'GET'): PromiseInterface
    {
        $paramKey = $method === 'GET' ? 'query' : 'json';
        return $this->client
            ->requestAsync($method, $this->getApiEndpoint() . '/' . ltrim($path, '/'), [$paramKey => $params])
            ->then(function (Response $response) {
                $result = $response->getBody()->getContents();
                $response->getBody()->close();
                if ($result === '') {
                    return null;
                }
                return self::parseResponseData($result);
            });
    }

  /**
     * Check availability of one SLD against multiple TLDs 
     *
     * @param string[] $tlds
     *
     * @return DacDomain[]
     */
    public function checkMultipleDomains(string $sld, array $tlds): array
    {
        $promises = [];
        foreach ($tlds as $tld) {
            $domain = Utils::getDomain($sld, $tld);

            $promises[] = $this->makeRequestAsync(['name' => $domain, 'processes' => 'create'], 'domain/check')
                ->then(function (?array $response) use ($domain) {
                    return $this->parseDacDomain($domain, $response ?? []);
                });
        }

        return PromiseUtils::all($promises)->wait();
    }

    /**
     * Get the correct endpoint for the current configuration, sandbox or production
     */
    protected function getApiEndpoint(): string
    {
        return $this->configuration->sandbox
            ? 'https://api.sandbox.gandi.net/v5'
            : 'https://api.gandi.net/v5';
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected static function parseResponseData(string $result): array
    {
        try {
            return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ProvisionFunctionError::create('Invalid Provider API Response: ' . $e->getMessage())
                ->withData([
                    'response' => $result,
                ]);
        }
    }

    /**
     * Map a Gandi contact into ContactData 
     */
    public static function parseContact(array $contact): ?ContactData
    {
        // Map a Gandi contact into a ContactData object
        if (empty($contact)) {
            return null;
        }
        $name = trim(($contact['given'] ?? '') . ' ' . ($contact['family'] ?? ''));

        $state = $contact['state'] ?? null;
        if ($state !== null && Str::contains($state, '-')) {
            // Gandi returns ISO 3166-2 codes like "FR-IDF" drop the country prefix
            $state = substr($state, strpos($state, '-') + 1);
        }

        return ContactData::create()
            ->setName($name !== '' ? $name : null)
            ->setOrganisation($contact['orgname'] ?? null)
            ->setEmail($contact['email'] ?? null)
            ->setPhone(isset($contact['phone'])
                ? Utils::eppPhoneToInternational($contact['phone'])
                : null)
            ->setAddress1($contact['streetaddr'] ?? null)
            ->setCity($contact['city'] ?? null)
            ->setState($state)
            ->setPostcode($contact['zip'] ?? null)
            ->setCountryCode($contact['country'] ?? null);
    }

    /**
     * Build a Gandi contact from ContactParams 
     */
    public static function buildContact(ContactParams $contact, ?bool $whoisPrivacy = null): array
    {
        // Build a Gandi contact from a ContactParams object
        $name = $contact->name ?? $contact->organisation;
        $parts = explode(' ', trim((string) $name), 2);
        $given = $parts[0];
        $family = $parts[1] ?? $parts[0]; // Gandi requires a non-empty family name
        $country = $contact->country_code;

        $data = [
            'given'      => $given,
            'family'     => $family,
            'email'      => $contact->email,
            'phone'      => Utils::internationalPhoneToEpp($contact->phone),
            'streetaddr' => $contact->address1,
            'city'       => $contact->city,
            'zip'        => $contact->postcode,
            'country'    => $country,
            'type'       => $contact->organisation ? 'company' : 'individual',
        ];

        if ($contact->organisation) {
            $data['orgname'] = $contact->organisation;
        }

        if (!empty($contact->state)) {
            // Gandi wants ISO 3166-2, e.g. "US-CA"
            $code = Utils::stateNameToCode($country, $contact->state);
            $data['state'] = Str::contains((string) $code, '-') ? $code : "{$country}-{$code}";
        }

        if ($whoisPrivacy !== null) {
            $data['data_obfuscated'] = $whoisPrivacy;
        }

        return $data;
    }

    /**
     * Map a Gandi domain/check response to a DacDomain
     */
    protected function parseDacDomain(string $domain, array $response): DacDomain
    {
        // Map a Gandi product into a DacDomain object
        $product = [];
        foreach ($response['products'] ?? [] as $candidate) {
            if (($candidate['process'] ?? null) === 'create') {
                $product = $candidate;
                break;
            }
        }
        if ($product === [] && isset($response['products'][0])) {
            $product = $response['products'][0];
        }

        $status = $product['status'] ?? 'error_unknown';

        $isPremium = $status === 'unavailable_premium';
        foreach ($product['prices'] ?? [] as $price) {
            if (($price['type'] ?? null) === 'premium') {
                $isPremium = true;
                break;
            }
        }

        return DacDomain::create()
            ->setDomain($domain)
            ->setTld(Utils::getTld($domain))
            ->setCanRegister($status === 'available')
            ->setCanTransfer(in_array($status, ['unavailable', 'unavailable_premium'], true))
            ->setIsPremium($isPremium)
            ->setDescription(ucfirst(str_replace('_', ' ', $status)));
    }
}    