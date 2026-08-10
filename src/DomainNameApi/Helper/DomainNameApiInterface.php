<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\DomainNameApi\Helper;

use Upmind\ProvisionProviders\DomainNames\Data\ContactResult;
use Upmind\ProvisionProviders\DomainNames\Data\DacParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacResult;
use Upmind\ProvisionProviders\DomainNames\Data\DomainInfoParams;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppCodeResult;
use Upmind\ProvisionProviders\DomainNames\Data\EppParams;
use Upmind\ProvisionProviders\DomainNames\Data\LockParams;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversResult;
use Upmind\ProvisionProviders\DomainNames\Data\RegisterDomainParams;
use Upmind\ProvisionProviders\DomainNames\Data\RenewParams;
use Upmind\ProvisionProviders\DomainNames\Data\TransferParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\UpdateNameserversParams;

interface DomainNameApiInterface
{
    public const DOMAIN_STATUS_MAP = [
        0  => 'Active',
        1  => 'WaitingForRegistration',
        2  => 'WaitingForDocument',
        3  => 'WaitingForIncomingTransfer',
        4  => 'TransferredOut',
        7  => 'PendingDelete',
        8  => 'Deleted',
        9  => 'ConfirmationEmailSend',
        11 => 'WaitingForOutgoingTransfer',
        12 => 'PendingHold',
        15 => 'ModificationPending',
        18 => 'InCase',
        19 => 'PendingRestore',
    ];

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function checkAvailability(DacParams $params): DacResult;

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function registerWithContactInfo(RegisterDomainParams $params): DomainResult;

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function transfer(TransferParams $params): DomainResult;

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function renew(RenewParams $params): DomainResult;

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getDetails(DomainInfoParams $params): DomainResult;

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function modifyNameServer(UpdateNameserversParams $params): NameserversResult;

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function getEppCode(EppParams $params): EppCodeResult;

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function saveContacts(UpdateContactParams $params): ContactResult;

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function toggleTheftProtectionLock(LockParams $params): DomainResult;
}
