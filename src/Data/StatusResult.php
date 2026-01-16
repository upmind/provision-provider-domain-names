<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use DateTimeInterface;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Domain status response data.
 *
 * @property-read string $status Normalized domain status
 * @property-read string|null $expires_at Expiry date in format Y-m-d H:i:s
 * @property-read string[]|null $raw_statuses Raw status strings from provider
 * @property-read array|null $extra Extra data
 */
class StatusResult extends ResultData
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_TRANSFERRED_AWAY = 'transferred_away';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_UNKNOWN = 'unknown';

    public static function rules(): Rules
    {
        return new Rules([
            'status' => ['required', 'string', 'in:active,expired,transferred_away,cancelled,unknown'],
            'expires_at' => ['present', 'nullable', 'date_format:Y-m-d H:i:s'],
            'raw_statuses' => ['nullable', 'array'],
            'raw_statuses.*' => ['string'],
            'extra' => ['nullable', 'array'],
        ]);
    }

    /**
     * @return static $this
     */
    public function setStatus(string $status): self
    {
        $this->setValue('status', $status);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setExpiresAt(?DateTimeInterface $expiresAt): self
    {
        $this->setValue('expires_at', $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null);
        return $this;
    }

    /**
     * @param string[]|null $rawStatuses
     *
     * @return static $this
     */
    public function setRawStatuses(?array $rawStatuses): self
    {
        $this->setValue('raw_statuses', $rawStatuses);
        return $this;
    }

    /**
     * @param array|null $extra
     *
     * @return static $this
     */
    public function setExtra(?array $extra): self
    {
        $this->setValue('extra', $extra);
        return $this;
    }
}
