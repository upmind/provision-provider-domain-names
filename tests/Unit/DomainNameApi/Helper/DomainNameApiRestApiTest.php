<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\DomainNameApi\Helper;

use GuzzleHttp\Client;
use ReflectionMethod;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\Enums\ContactType;
use Upmind\ProvisionProviders\DomainNames\DomainNameApi\Helper\DomainNameApiRestApi;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class DomainNameApiRestApiTest extends TestCase
{
    /**
     * A single-word `name` splits into a first name only; the provider requires a
     * non-null last name, so it must fall back to the first name rather than trim null.
     */
    public function test_single_word_name_uses_name_for_both_first_and_last_name(): void
    {
        $contact = $this->mapContact($this->contactParams([
            'name' => 'Acme',
            'organisation' => null,
        ]));

        $this->assertSame('Acme', $contact['firstName']);
        $this->assertSame('Acme', $contact['lastName']);
    }

    /**
     * When `name` is absent, the mapping falls back to `organisation`. A single-word
     * organisation previously caused a TypeError (trim(null)); it must now map cleanly.
     */
    public function test_single_word_organisation_without_name_uses_organisation_for_both_names(): void
    {
        $contact = $this->mapContact($this->contactParams([
            'name' => null,
            'organisation' => 'Acme',
        ]));

        $this->assertSame('Acme', $contact['firstName']);
        $this->assertSame('Acme', $contact['lastName']);
        $this->assertSame('Acme', $contact['companyName']);
    }

    /**
     * A regular multi-word name must still split into distinct first and last names.
     */
    public function test_multi_word_name_is_split_into_first_and_last_name(): void
    {
        $contact = $this->mapContact($this->contactParams([
            'name' => 'John Doe',
            'organisation' => null,
        ]));

        $this->assertSame('John', $contact['firstName']);
        $this->assertSame('Doe', $contact['lastName']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function contactParams(array $overrides = []): ContactParams
    {
        // Validation is disabled: the mapping method under test performs no validation
        // and this keeps the test free of the Laravel container.
        return ContactParams::create(array_merge([
            'name' => 'John Doe',
            'organisation' => null,
            'email' => 'test@example.com',
            'phone' => '+14155552671',
            'address1' => '123 Example St',
            'city' => 'Exampleville',
            'state' => 'CA',
            'postcode' => '90001',
            'country_code' => 'US',
        ], $overrides), false);
    }

    /**
     * Invoke the private mapContactParamsToProviderContact() via reflection.
     *
     * @return array<string, mixed>
     * @throws \ReflectionException
     */
    private function mapContact(ContactParams $params): array
    {
        $api = new DomainNameApiRestApi(new Client());

        $method = new ReflectionMethod($api, 'mapContactParamsToProviderContact');
        $method->setAccessible(true);

        return $method->invoke($api, $params, ContactType::REGISTRANT());
    }
}
