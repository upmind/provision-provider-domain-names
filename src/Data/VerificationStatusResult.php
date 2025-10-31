<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Domain verification status result.
 *
 * @property-read string|null $icann_verification_status ICANN verification status
 * @property-read string|null $cctld_verification_status ccTLD verification status (e.g., AU eligibility)
 * @property-read string|null $verification_deadline Deadline for verification
 * @property-read int|null $days_to_suspend Days until domain suspension
 * @property-read bool|null $email_bounced Whether verification email bounced
 * @property-read array|null $provider_specific_data Additional provider-specific data
 */
class VerificationStatusResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'icann_verification_status' => ['nullable', 'string', 'required_without:cctld_verification_status'],
            'cctld_verification_status' => ['nullable', 'string', 'required_without:icann_verification_status'],
            'verification_deadline' => ['nullable', 'string'],
            'days_to_suspend' => ['nullable', 'integer'],
            'email_bounced' => ['nullable', 'boolean'],
            'provider_specific_data' => ['nullable', 'array'],
        ]);
    }

    /**
     * @return static $this
     */
    public function setIcannVerificationStatus(?string $status): self
    {
        $this->setValue('icann_verification_status', $status);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setVerificationDeadline(?string $deadline): self
    {
        $this->setValue('verification_deadline', $deadline);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setDaysToSuspend(?int $days): self
    {
        $this->setValue('days_to_suspend', $days);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setEmailBounced(?bool $bounced): self
    {
        $this->setValue('email_bounced', $bounced);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setCctldVerificationStatus(?string $status): self
    {
        $this->setValue('cctld_verification_status', $status);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setProviderSpecificData(?array $data): self
    {
        $this->setValue('provider_specific_data', $data);
        return $this;
    }
}
