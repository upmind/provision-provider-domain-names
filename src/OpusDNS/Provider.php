<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\OpusDNS;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Throwable;
use UnexpectedValueException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\DomainNames\Category as DomainNames;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainNotification;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\Enums\ContactType;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\IpsTagParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\PollParams;
use Upmind\ProvisionProviders\DomainNames\Data\PollResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\AutoRenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateDomainContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusParams;
use Upmind\ProvisionProviders\DomainNames\Data\VerificationStatusResult;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationParams;
use Upmind\ProvisionProviders\DomainNames\Data\ResendVerificationResult;
use Upmind\ProvisionProviders\DomainNames\Data\SetGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\RemoveGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecordsResult;
use Upmind\ProvisionProviders\DomainNames\Data\StatusResult;
use Upmind\ProvisionProviders\DomainNames\OpusDNS\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\OpusDNS\Helper\OpusDnsApi;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class Provider extends DomainNames implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var OpusDnsApi|null
     */
    protected $api;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('OpusDNS')
            ->setDescription('Register, transfer, renew and manage domains')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/opus-dns-logo.png');
    }

    public function domainAvailabilityCheck(DacParams $params): DacResult
    {
        $sld = Utils::normalizeSld($params->sld);

        $domains = array_map(
            function ($tld) use ($sld) {
                return $sld . '.' . Utils::normalizeTld($tld);
            },
            $params->tlds
        );

        $dacDomains = $this->api()->checkDomains($domains);

        return DacResult::create([
            'domains' => $dacDomains,
        ]);
    }

    public function poll(PollParams $params): PollResult
    {
        $since = $params->after_date ? Carbon::parse($params->after_date) : null;
        $limit = intval($params->limit) ?: 1000;

        try {
            $result = $this->api()->getEvents($limit, $since);

            $notifications = [];
            foreach ($result['events'] as $event) {
                $notification = $this->mapEventToNotification($event);
                if ($notification !== null) {
                    $notifications[] = $notification;
                }
            }

            return PollResult::create([
                'count_remaining' => $result['count_remaining'],
                'notifications' => $notifications,
            ]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Map an API event to a DomainNotification.
     *
     * @return DomainNotification|null Returns null if event type is not mappable
     */
    protected function mapEventToNotification(array $event): ?DomainNotification
    {
        $eventType = $event['type'] ?? null;
        $eventSubtype = $event['subtype'] ?? null;
        $eventData = $event['event_data'] ?? [];

        // Map API event types to DomainNotification types
        $typeMapping = [
            'RENEWAL' => DomainNotification::TYPE_RENEWED,
            'DELETION' => DomainNotification::TYPE_DELETED,
            'INBOUND_TRANSFER' => DomainNotification::TYPE_TRANSFER_IN,
            'OUTBOUND_TRANSFER' => DomainNotification::TYPE_TRANSFER_OUT,
            'VERIFICATION' => DomainNotification::TYPE_DATA_QUALITY,
        ];

        $notificationType = $typeMapping[$eventType] ?? null;

        // Skip events without a mappable type
        if ($notificationType === null) {
            return null;
        }

        // Extract domain name from event data
        $domainName = $eventData['name']
            ?? $eventData['domain']
            ?? $eventData['domain_name']
            ?? null;

        // If no domain name, we can't create a valid notification
        if (!$domainName) {
            return null;
        }

        $createdAt = Carbon::parse($event['created_on'] ?? 'now');

        // Build message based on event type and subtype
        $message = $this->buildEventMessage($eventType, $eventSubtype, $domainName);

        return DomainNotification::create()
            ->setId($event['event_id'] ?? uniqid('event_'))
            ->setType($notificationType)
            ->setMessage($message)
            ->setDomains([$domainName])
            ->setCreatedAt($createdAt)
            ->setExtra([
                'event_type' => $eventType,
                'event_subtype' => $eventSubtype,
                'event_data' => $eventData,
            ]);
    }

    /**
     * Build a human-readable message for an event.
     */
    protected function buildEventMessage(string $type, ?string $subtype, string $domain): string
    {
        $messages = [
            'RENEWAL' => [
                'SUCCESS' => "Domain {$domain} was successfully renewed",
                'FAILURE' => "Domain {$domain} renewal failed",
                'NOTIFICATION' => "Domain {$domain} renewal notification",
            ],
            'DELETION' => [
                'SUCCESS' => "Domain {$domain} was deleted",
                'NOTIFICATION' => "Domain {$domain} deletion notification",
            ],
            'INBOUND_TRANSFER' => [
                'SUCCESS' => "Domain {$domain} was successfully transferred in",
                'FAILURE' => "Domain {$domain} transfer in failed",
                'NOTIFICATION' => "Domain {$domain} transfer in notification",
            ],
            'OUTBOUND_TRANSFER' => [
                'SUCCESS' => "Domain {$domain} was transferred out",
                'NOTIFICATION' => "Domain {$domain} transfer out notification",
            ],
            'VERIFICATION' => [
                'NOTIFICATION' => "Domain {$domain} requires verification",
            ],
        ];

        return $messages[$type][$subtype]
            ?? $messages[$type]['NOTIFICATION']
            ?? "Domain {$domain}: {$type} event";
    }

    public function register(RegisterDomainParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        if (!Arr::has($params, 'registrant.register')) {
            $this->errorResult('Registrant contact data is required!');
        }

        $contacts = [
            OpusDnsApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register,
            OpusDnsApi::CONTACT_TYPE_ADMIN => $params->admin->register ?? $params->registrant->register,
            OpusDnsApi::CONTACT_TYPE_TECH => $params->tech->register ?? $params->registrant->register,
            OpusDnsApi::CONTACT_TYPE_BILLING => $params->billing->register ?? $params->registrant->register,
        ];

        try {
            $this->api()->registerDomain(
                $domainName,
                intval($params->renew_years),
                $contacts,
                $params->nameservers->pluckHosts()
            );

            return $this->_getInfo($domainName, sprintf('Domain %s was registered successfully!', $domainName));
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function transfer(TransferParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $eppCode = $params->epp_code ?: '0000';

        try {
            return $this->_getInfo($domainName, 'Domain active in registrar account');
        } catch (Throwable $e) {
            // domain not active - continue below
        }

        if (!Arr::has($params, 'registrant.register')) {
            $this->errorResult('Registrant contact data is required!');
        }

        $contacts = [
            OpusDnsApi::CONTACT_TYPE_REGISTRANT => $params->registrant->register,
            OpusDnsApi::CONTACT_TYPE_ADMIN => $params->admin->register ?? $params->registrant->register,
            OpusDnsApi::CONTACT_TYPE_TECH => $params->tech->register ?? $params->registrant->register,
            OpusDnsApi::CONTACT_TYPE_BILLING => $params->billing->register ?? $params->registrant->register,
        ];

        try {
            $this->api()->transferDomain(
                $domainName,
                $eppCode,
                $contacts
            );

            $this->errorResult(sprintf('Transfer for %s domain successfully created!', $domainName), [
                'transaction_id' => null,
            ]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function renew(RenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $period = intval($params->renew_years);

        try {
            // API requires current expiry date - fetch domain info first
            $domainInfo = $this->api()->getDomainInfo($domainName, true);
            $currentExpiry = $domainInfo['expires_at'] ?? null;

            $this->api()->renewDomain($domainName, $period, $currentExpiry);
            return $this->_getInfo($domainName, sprintf('Renewal for %s domain was successful!', $domainName));
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getInfo(DomainInfoParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            return $this->_getInfo($domainName, 'Domain data obtained');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    private function _getInfo(string $domainName, string $message): DomainResult
    {
        $domainInfo = $this->api()->getDomainInfo($domainName);

        return DomainResult::create($domainInfo)->setMessage($message);
    }

    /**
     * @throws ProvisionFunctionError
     * @throws Throwable
     */
    public function updateRegistrantContact(UpdateDomainContactParams $params): ContactResult
    {
        return $this->updateContact(UpdateContactParams::create([
            'sld' => $params->sld,
            'tld' => $params->tld,
            'contact' => $params->contact,
            'contact_type' => ContactType::REGISTRANT,
        ]));
    }

    /**
     * @throws ProvisionFunctionError
     * @throws Throwable
     */
    public function updateContact(UpdateContactParams $params): ContactResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $contactType = ContactType::from($params->contact_type);
            $providerContactType = $this->api()->getProviderContactTypeValue($contactType);

            $contact = $this->api()->updateDomainContact(
                $domainName,
                $params->contact,
                $providerContactType
            );

            return ContactResult::create($contact->toArray());
        } catch (UnexpectedValueException $ex) {
            $this->errorResult('Invalid contact type: ' . $params->contact_type);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateNameservers(UpdateNameserversParams $params): NameserversResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $result = $this->api()->updateDomainNameservers(
                $domainName,
                $params->pluckHosts()
            );

            return NameserversResult::create($result)
                ->setMessage(sprintf('Name servers for %s domain were updated!', $domainName));
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function setLock(LockParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);
        $lock = !!$params->lock;

        try {
            $currentLockStatus = $this->api()->getDomainLockStatus($domainName);
            if (!$lock && !$currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already unlocked', $domainName));
            }

            if ($lock && $currentLockStatus) {
                return $this->_getInfo($domainName, sprintf('Domain %s already locked', $domainName));
            }

            $this->api()->setDomainLock($domainName, $lock);

            return $this->_getInfo($domainName, sprintf('Lock %s!', $lock ? 'enabled' : 'disabled'));
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function setAutoRenew(AutoRenewParams $params): DomainResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        $autoRenew = !!$params->auto_renew;

        try {
            $this->api()->setDomainAutoRenew($domainName, $autoRenew);

            return $this->_getInfo($domainName, 'Auto-renew mode updated');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getEppCode(EppParams $params): EppCodeResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $eppCode = $this->api()->getDomainEppCode($domainName);

            return EppCodeResult::create([
                'epp_code' => $eppCode,
            ])->setMessage('EPP/Auth code obtained');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function updateIpsTag(IpsTagParams $params): ResultData
    {
        $this->errorResult('Operation not supported');
    }

    /**
     * @throws Throwable
     * @throws ProvisionFunctionError
     */
    public function getVerificationStatus(VerificationStatusParams $params): VerificationStatusResult
    {
        try {
            $domain = Utils::getDomain($params->sld, $params->tld);
            $status = $this->api()->getDomainVerificationInfo($domain);

            return VerificationStatusResult::create($status);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws Throwable
     * @throws ProvisionFunctionError
     */
    public function resendVerificationEmail(ResendVerificationParams $params): ResendVerificationResult
    {
        try {
            $domain = Utils::getDomain($params->sld, $params->tld);
            $result = $this->api()->resendVerificationEmail($domain);

            return ResendVerificationResult::create([
                'success' => true,
                'message' => $result['message'] ?? 'Verification email sent successfully',
            ]);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws ProvisionFunctionError
     */
    public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported', $params);
    }

    /**
     * @throws ProvisionFunctionError
     */
    public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported', $params);
    }

    /**
     * @inheritDoc
     */
    public function getStatus(DomainInfoParams $params): StatusResult
    {
        $domainName = Utils::getDomain($params->sld, $params->tld);

        try {
            $domainInfo = $this->api()->getDomainInfo($domainName, true);

            $expiresAt = isset($domainInfo['expires_at'])
                ? Carbon::parse($domainInfo['expires_at'])
                : null;

            if ($expiresAt !== null && $expiresAt->isPast()) {
                return StatusResult::create()
                    ->setStatus(StatusResult::STATUS_EXPIRED)
                    ->setExpiresAt($expiresAt)
                    ->setRawStatuses($domainInfo['statuses'] ?? null);
            }

            return StatusResult::create()
                ->setStatus(StatusResult::STATUS_ACTIVE)
                ->setExpiresAt($expiresAt)
                ->setRawStatuses($domainInfo['statuses'] ?? null);
        } catch (ProvisionFunctionError $e) {
            // Domain not found - check registry availability
            try {
                $availability = $this->checkDomainAvailableAtRegistry($domainName);

                if ($availability['available']) {
                    return StatusResult::create()
                        ->setStatus(StatusResult::STATUS_CANCELLED)
                        ->setExpiresAt(null)
                        ->setExtra(['availability_check' => $availability]);
                }

                return StatusResult::create()
                    ->setStatus(StatusResult::STATUS_TRANSFERRED_AWAY)
                    ->setExpiresAt(null)
                    ->setExtra(['availability_check' => $availability]);
            } catch (Throwable $checkException) {
                throw $e;
            }
        }
    }

    /**
     * Check if a domain is available for registration at the registry.
     *
     * @return array{available: bool, raw_result: array}
     */
    protected function checkDomainAvailableAtRegistry(string $domainName): array
    {
        $results = $this->api()->checkDomains([$domainName]);
        $result = $results[0] ?? null;

        return [
            'available' => $result ? $result->can_register : false,
            'raw_result' => $result ? $result->toArray() : [],
        ];
    }

    /**
     * @throws Throwable
     *
     * @return no-return
     * @return never
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof ProvisionFunctionError) {
            throw $e;
        }

        if ($e instanceof RequestException) {
            $response = $e->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 0;
            $responseBody = $response ? json_decode((string) $response->getBody(), true) : null;

            $errorMessage = 'Provider API Error';
            if (is_array($responseBody)) {
                $errorMessage = $responseBody['message']
                    ?? $responseBody['error']['message']
                    ?? $responseBody['error']
                    ?? $errorMessage;
            }

            $this->errorResult(
                sprintf('Provider API Error [%d]: %s', $statusCode, $errorMessage),
                [
                    'error' => [
                        'status_code' => $statusCode,
                        'response' => $responseBody,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                ],
                [],
                $e
            );
        }

        throw $e;
    }

    protected function api(): OpusDnsApi
    {
        if ($this->api !== null) {
            return $this->api;
        }

        return $this->api = new OpusDnsApi($this->configuration, $this->getLogger());
    }
}
