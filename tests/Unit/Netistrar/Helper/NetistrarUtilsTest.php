<?php

declare(strict_types = 1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\Netistrar\Helper;

use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\DomainNames\Netistrar\Helper\NetistrarUtils;

class NetistrarUtilsTest extends TestCase
{
    public function test_dot_co_dot_uk_tld_validates_as_uk_tld(): void
    {
        $this->assertTrue(NetistrarUtils::isUkTld('.co.uk'));
    }

    public function test_dot_au_dot_com_does_not_validate_as_uk_tld()
    {
        $this->assertFalse(NetistrarUtils::isUkTld('.au.com'));
    }
}
