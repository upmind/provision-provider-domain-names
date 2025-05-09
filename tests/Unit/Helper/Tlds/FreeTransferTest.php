<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\Helper\Tlds;

use PHPUnit\Framework\TestCase;
use Upmind\ProvisionProviders\DomainNames\Helper\Tlds\FreeTransfer;

class FreeTransferTest extends TestCase
{
    private array $freeTransferTlds = [
        '.uk',
        '.au',
        '.es',
        '.nl',
        '.nu',
        '.ch',
        '.cz',
        '.ee',
        '.fi',
        '.gr',
        '.hr',
        '.no',
        '.am',
        '.at',
        '.fm',
        '.fo',
        '.gd',
        '.gl',
        '.ac.nz',
        '.pw',
        '.vg',
        '.co.com',
        '.br.com',
        '.cn.com',
        '.eu.com',
        '.gb.net',
        '.uk.com',
        '.uk.net',
        '.us.com',
        '.ru.com',
        '.sa.com',
        '.se.net',
        '.za.com',
        '.de.com',
        '.jpn.com',
        '.ae.org',
        '.us.org',
        '.gr.com',
        '.com.de',
        '.jp.net',
        '.hu.net',
        '.in.net',
        '.mex.com',
        '.com.se',
    ];

    public function test_com_tld_does_not_support_free_transfer(): void
    {
        $this->assertFalse(FreeTransfer::tldIsSupported('.com'));
    }

    public function test_com_au_cctld_supports_free_transfer(): void
    {
        $this->assertTrue(FreeTransfer::tldIsSupported('.com.au'));
    }
}
