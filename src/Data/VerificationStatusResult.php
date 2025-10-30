<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Domain verification status result.
 *
 * @property-read string $verification_status Current verification status
 * @property-read string $verification_type Type of verification required
 * @property-read string|null $verification_deadline Deadline for verification
 * @property-read int|null $days_to_suspend Days until domain suspension
 * @property-read bool|null $email_bounced Whether verification email bounced
 * @property-read bool|null $au_eligibility_valid AU domain eligibility status
 * @property-read array|null $provider_specific_data Additional provider-specific data
 */
class VerificationStatusResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'verification_status' => ['required', 'string'],
            'verification_type' => ['required', 'string'],
            'verification_deadline' => ['nullable', 'string'],
            'days_to_suspend' => ['nullable', 'integer'],
            'email_bounced' => ['nullable', 'boolean'],
            'au_eligibility_valid' => ['nullable', 'boolean'],
            'provider_specific_data' => ['nullable', 'array'],
        ]);
    }

    /**
     * @return static $this
     */
    public function setVerificationStatus(string $status): self
    {
        $this->setValue('verification_status', $status);
        return $this;
    }

    /**
     * @return static $this
     */
    public function setVerificationType(string $type): self
    {
        $this->setValue('verification_type', $type);
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
    public function setAuEligibilityValid(?bool $valid): self
    {
        $this->setValue('au_eligibility_valid', $valid);
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
