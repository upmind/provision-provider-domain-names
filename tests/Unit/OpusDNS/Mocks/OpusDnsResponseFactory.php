<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\OpusDNS\Mocks;

use GuzzleHttp\Psr7\Response;

/**
 * Factory for creating mock OpusDNS API responses based on the OpenAPI spec.
 *
 * All response structures match api-1.json specification.
 */
class OpusDnsResponseFactory
{
    /**
     * Generate a TypeID in the format: {prefix}_{26-char base32}.
     */
    public static function typeId(string $prefix, ?string $suffix = null): string
    {
        $suffix = $suffix ?? '01h45ytscbebyvny4gc8cr8ma2';
        return $prefix . '_' . $suffix;
    }

    /**
     * OAuth2 token response.
     */
    public static function tokenResponse(int $expiresIn = 3600): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'test_access_token_' . bin2hex(random_bytes(16)),
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
        ]));
    }

    /**
     * Domain response matching DomainResponse schema.
     */
    public static function domainResponse(array $overrides = []): array
    {
        $domain = $overrides['name'] ?? 'example.com';
        $parts = explode('.', $domain, 2);
        $sld = $parts[0];
        $tld = $parts[1] ?? 'com';

        $defaults = [
            'domain_id' => self::typeId('domain'),
            'name' => $domain,
            'sld' => $sld,
            'tld' => $tld,
            'roid' => 'D123456789-EXAMPLE',
            'owner_id' => self::typeId('organization'),
            'registry_account_id' => self::typeId('registry_account'),
            'created_on' => '2024-01-15T10:30:00Z',
            'updated_on' => '2024-03-10T15:45:00Z',
            'registered_on' => '2024-01-15T10:30:00Z',
            'expires_on' => '2025-01-15T10:30:00Z',
            'transfer_lock' => false,
            'renewal_mode' => 'renew',
            'registry_statuses' => ['ok'],
            'contacts' => self::domainContactsArray(),
            'nameservers' => self::nameserversArray(),
            'hosts' => [],
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Domain contacts in array format (as returned by API).
     */
    public static function domainContactsArray(?string $contactId = null): array
    {
        $contactId = $contactId ?? self::typeId('contact');

        return [
            ['contact_id' => $contactId, 'contact_type' => 'registrant'],
            ['contact_id' => $contactId, 'contact_type' => 'admin'],
            ['contact_id' => $contactId, 'contact_type' => 'tech'],
            ['contact_id' => $contactId, 'contact_type' => 'billing'],
        ];
    }

    /**
     * Nameservers array matching Nameserver schema.
     */
    public static function nameserversArray(array $hostnames = null): array
    {
        $hostnames = $hostnames ?? ['ns1.example.com', 'ns2.example.com'];

        return array_map(fn($host) => ['hostname' => $host], $hostnames);
    }

    /**
     * Full contact response matching ContactResponse schema.
     */
    public static function contactResponse(array $overrides = []): array
    {
        $defaults = [
            'contact_id' => self::typeId('contact'),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+1.2125552368',
            'street' => '123 Main St',
            'city' => 'New York',
            'postal_code' => '10001',
            'state' => 'NY',
            'country' => 'US',
            'org' => null,
            'disclose' => false,
            'created_on' => '2024-01-15T10:30:00Z',
            'organization_id' => self::typeId('organization'),
            'attribute_sets' => [],
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Domain availability check response matching DomainCheckResponse schema.
     */
    public static function domainCheckResponse(array $domains): array
    {
        $results = [];
        foreach ($domains as $domain => $available) {
            if (is_int($domain)) {
                // Indexed array - domain name is the value, assume available
                $results[] = [
                    'domain' => $available,
                    'available' => true,
                    'reason' => null,
                ];
            } else {
                // Keyed array - domain => available boolean
                $results[] = [
                    'domain' => $domain,
                    'available' => (bool) $available,
                    'reason' => $available ? null : 'registered',
                ];
            }
        }

        return ['results' => $results];
    }

    /**
     * Domain renewal response matching DomainRenewResponse schema.
     */
    public static function domainRenewResponse(string $domain, int $years = 1): array
    {
        $currentExpiry = new \DateTime('2025-01-15T10:30:00Z');
        $newExpiry = (clone $currentExpiry)->modify("+{$years} years");

        return [
            'name' => $domain,
            'new_expiry_date' => $newExpiry->format('Y-m-d\TH:i:s\Z'),
            'period_extended' => [
                'value' => $years,
                'unit' => 'y',
            ],
        ];
    }

    /**
     * Domain transfer response (returns DomainResponse on success).
     */
    public static function domainTransferResponse(string $domain): array
    {
        return self::domainResponse([
            'name' => $domain,
            'registry_statuses' => ['pendingTransfer'],
        ]);
    }

    /**
     * Problem error response matching Problem schema.
     */
    public static function problemResponse(
        int $status,
        string $type,
        string $detail,
        ?string $code = null
    ): array {
        $response = [
            'type' => $type,
            'title' => self::getTitleForType($type),
            'status' => $status,
            'detail' => $detail,
        ];

        if ($code !== null) {
            $response['code'] = $code;
        }

        return $response;
    }

    /**
     * HTTP validation error response matching HTTPValidationError schema.
     */
    public static function validationErrorResponse(array $fieldErrors): array
    {
        $errors = [];
        foreach ($fieldErrors as $field => $message) {
            $errors[] = [
                'loc' => ['body', $field],
                'msg' => $message,
                'type' => 'value_error',
            ];
        }

        return [
            'type' => 'request-validation-failed',
            'title' => 'Validation Error',
            'status' => 422,
            'errors' => $errors,
        ];
    }

    /**
     * Domain not found error.
     */
    public static function domainNotFoundResponse(string $domain): array
    {
        return self::problemResponse(
            404,
            'domain-not-found',
            "Domain '{$domain}' not found",
            'ERROR_DOMAIN_NOT_FOUND'
        );
    }

    /**
     * Contact not found error.
     */
    public static function contactNotFoundResponse(string $contactId): array
    {
        return self::problemResponse(
            404,
            'contact-not-found',
            "Contact '{$contactId}' not found",
            'ERROR_CONTACT_NOT_FOUND'
        );
    }

    /**
     * Domain period in DomainPeriod format.
     */
    public static function domainPeriod(int $value, string $unit = 'y'): array
    {
        return [
            'value' => $value,
            'unit' => $unit,
        ];
    }

    /**
     * Wrap array data as a Guzzle Response.
     */
    public static function jsonResponse(array $data, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data)
        );
    }

    /**
     * Get a human-readable title for error type.
     */
    private static function getTitleForType(string $type): string
    {
        $titles = [
            'domain-not-found' => 'Domain Management Error',
            'contact-not-found' => 'Contact Management Error',
            'domain-exists' => 'Domain Management Error',
            'domain-tld-not-available' => 'Domain Management Error',
            'domain-transfer' => 'Domain Transfer Error',
            'request-validation-failed' => 'Validation Error',
            'policy-validation-error' => 'Policy Validation Error',
        ];

        return $titles[$type] ?? 'API Error';
    }
}
