<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\ResellerClub;

use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;
use Upmind\ProvisionProviders\DomainNames\LogicBoxes\Provider as LogicBoxesProvider;

class Provider extends LogicBoxesProvider
{
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('ResellerClub')
            ->setDescription(
                'ResellerClub offers a comprehensive solution to register and '
                . 'manage 500+ gTLDs, ccTLDs and new domains.'
            )
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/resellerclub-logo_2x.png');
    }

    /**
     * Check domain availability for multiple TLDs.
     *
     * @link https://resellerclub.webpropanel.com/kb/check-domain-availability-api
     *
     * @throws \Throwable
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);
        $domains = [];

        // Build array of full domain names to check
        foreach ($params->tlds as $tld) {
            $domains[] = $sld . '.' . Utils::normalizeTld($tld);
        }

        $dacDomains = [];

        // Check each domain
        foreach ($domains as $domain) {
            $parts = explode('.', $domain, 2);
            $tld = $parts[1] ?? '';

            try {
                // Call the API to check domain availability
                $response = $this->_callApi(
                    ['domain-name' => [$domain]],
                    'domains/available.json',
                    'GET'
                );

                // Parse the response
                $isAvailable = false;
                $description = 'Unknown';

                if (isset($response[$domain])) {
                    $status = strtolower($response[$domain]['status'] ?? '');
                    
                    if ($status === 'available') {
                        $isAvailable = true;
                        $description = 'Domain is available for registration';
                    } elseif ($status === 'regthroughus') {
                        $isAvailable = false;
                        $description = 'Domain is registered through this registrar';
                    } elseif ($status === 'regthroughothers') {
                        $isAvailable = false;
                        $description = 'Domain is registered through another registrar';
                    } else {
                        $description = ucfirst($status);
                    }
                }

                $dacDomains[] = DacDomain::create([
                    'domain' => $domain,
                    'tld' => $tld,
                    'can_register' => $isAvailable,
                    'can_transfer' => !$isAvailable,
                    'is_premium' => false,
                    'description' => $description,
                ]);
            } catch (\Throwable $e) {
                // If there's an error checking this domain, add it with error status
                $dacDomains[] = DacDomain::create([
                    'domain' => $domain,
                    'tld' => $tld,
                    'can_register' => false,
                    'can_transfer' => false,
                    'is_premium' => false,
                    'description' => 'Error checking availability: ' . $e->getMessage(),
                ]);
            }
        }

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }
}
