<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\RealtimeRegister\Helper\Tlds;

use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\DomainNames\RealtimeRegister\Helper\Tlds\AllowsOrganisationTechnicalContact;

class AllowsOrganisationTechnicalContactTest extends TestCase
{
    private array $unsupportedTlds = [
        '.si',
    ];

    public function test_list_of_domains_do_not_support_organisation_contact(): void
    {
        foreach ($this->unsupportedTlds as $tld) {
            $this->assertFalse(
                AllowsOrganisationTechnicalContact::tldIsSupported($tld),
                'Failed asserting that `' . $tld . '` does not support organisation contact.'
            );
        }
    }

    public function test_com_tld_supports_organisation_contact(): void
    {
        $this->assertTrue(AllowsOrganisationTechnicalContact::tldIsSupported('.com'));
    }

    public function test_si_cctld_without_dot_does_not_support_organisation_contact(): void
    {
        $this->assertFalse(AllowsOrganisationTechnicalContact::tldIsSupported('si'));
    }

    public function test_si_cctld_uppercase_does_not_support_organisation_contact(): void
    {
        $this->assertFalse(AllowsOrganisationTechnicalContact::tldIsSupported('.SI'));
    }

    public function test_si_second_level_tld_does_not_support_organisation_contact(): void
    {
        $this->assertFalse(AllowsOrganisationTechnicalContact::tldIsSupported('.com.si'));
    }

    public function test_tld_ending_in_si_supports_organisation_contact(): void
    {
        $this->assertTrue(AllowsOrganisationTechnicalContact::tldIsSupported('.asi'));
    }
}
