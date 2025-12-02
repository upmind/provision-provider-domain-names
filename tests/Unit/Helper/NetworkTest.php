<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\Helper;

use Illuminate\Foundation\Testing\WithFaker;
use Upmind\ProvisionProviders\DomainNames\Helper\Network;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;

class NetworkTest extends TestCase
{
    use WithFaker;

    public function test_is_valid_ipv4_address_success_for_ipv4(): void
    {
        $this->assertTrue(Network::isValidIpv4Address($this->faker->ipv4));
    }

    public function test_is_valid_ipv4_address_fails_for_ipv6(): void
    {
        $this->assertFalse(Network::isValidIpv4Address($this->faker->ipv6));
    }

    public function test_is_valid_ipv6_address_success_for_ipv6(): void
    {
        $this->assertTrue(Network::isValidIpv6Address($this->faker->ipv6));
    }

    public function test_is_valid_ipv6_address_fails_for_ipv4(): void
    {
        $this->assertFalse(Network::isValidIpv6Address($this->faker->ipv4));
    }
}
