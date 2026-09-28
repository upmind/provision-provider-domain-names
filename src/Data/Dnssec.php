<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * DNSSEC Delegation Signer (DS) record data.
 *
 * @property-read int $ds_key_tag DS key tag
 * @property-read int $ds_algorithm DNSSEC algorithm identifier
 * @property-read int $ds_digest_type DS digest type identifier
 * @property-read string $ds_digest DS digest
 */
class Dnssec extends DataSet
{
    public const ALGORITHMS = [
        1,
        2,
        3,
        5,
        6,
        7,
        8,
        10,
        12,
        13,
        14,
        15,
        16,
        17,
        23,
    ];

    public const ALGORITHM_NAMES = [
        1 => 'RSA/MD5',
        2 => 'Diffie-Hellman',
        3 => 'DSA/SHA-1',
        5 => 'RSA/SHA-1',
        6 => 'DSA-NSEC3-SHA1',
        7 => 'RSASHA1-NSEC3-SHA',
        8 => 'RSA/SHA-256',
        10 => 'RSA/SHA-512',
        12 => 'GOST R 34.10-2001',
        13 => 'ECDSA Curve P-256 with SHA-256',
        14 => 'ECDSA Curve P-384 with SHA-384',
        15 => 'Ed25519',
        16 => 'Ed448',
        17 => 'SM2 signing algorithm with SM3 hashing algorithm',
        23 => 'GOST R 34.10-2012',
    ];

    public const DIGEST_TYPES = [
        1,
        2,
        3,
        4,
        5,
        6,
    ];

    public const DIGEST_TYPE_NAMES = [
        1 => 'SHA-1',
        2 => 'SHA-256',
        3 => 'GOST R 34.11-94',
        4 => 'SHA-384',
        5 => 'GOST R 34.11-2012',
        6 => 'SM3',
    ];

    public static function rules(): Rules
    {
        return self::recordRules();
    }

    public static function recordRules(): Rules
    {
        return new Rules([
            'ds_key_tag' => ['required', 'integer', 'min:0', 'max:65534'],
            'ds_algorithm' => ['required', 'integer', 'in:' . implode(',', self::ALGORITHMS)],
            'ds_digest_type' => ['required', 'integer', 'in:' . implode(',', self::DIGEST_TYPES)],
            'ds_digest' => ['required', 'string'],
        ]);
    }
}
