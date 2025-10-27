<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\Helper\Tlds;

use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\DomainNames\Helper\Tlds\NoEppCodeTransfer;

class NoEppCodeTransferTest extends TestCase
{
    private array $notRequiredAuthCodeTlds = [
        '.lu'
    ];

    public function test_list_of_domains_support_free_transfer(): void
    {
        foreach ($this->notRequiredAuthCodeTlds as $tld) {
            $this->assertTrue(
                NoEppCodeTransfer::tldIsSupported($tld),
                'Failed asserting that `' . $tld . '` supports transfer without epp code.'
            );
        }
    }

    public function test_com_tld_does_not_support_no_auth_code_transfer(): void
    {
        $this->assertFalse(NoEppCodeTransfer::tldIsSupported('.com'));
    }

    public function test_lu_cctld_supports_no_auth_code_transfer(): void
    {
        $this->assertTrue(NoEppCodeTransfer::tldIsSupported('.lu'));
    }

    public function test_lu_cctld_without_dot_supports_no_auth_code_transfer(): void
    {
        $this->assertTrue(NoEppCodeTransfer::tldIsSupported('lu'));
    }
}
