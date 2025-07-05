<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\OpenProvider\Helper;

use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\DomainNames\OpenProvider\Helper\AddressUtils;

class AddressUtilsTest extends TestCase
{
    public function test_postcode_sanitisation_does_not_change_alpanumeric_value_with_spaces(): void
    {
        $postCode = '1234 AB 5678';

        $this->assertEquals($postCode, AddressUtils::sanitisePostCode($postCode));
    }

    public function test_postcode_sanitisation_removes_non_alphanumeric_characters_from_value(): void
    {
        $postCode = '1234 AB 5678!@#$%^&*()_+21=23 AB 4567';

        $this->assertEquals('1234 AB 56782123 AB 4567', AddressUtils::sanitisePostCode($postCode));
    }
}
