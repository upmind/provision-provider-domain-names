# Ascio Provider Integration - Usage Guide

This document outlines how to use the Ascio Domain Names provider implemented in this branch (`jamesbooth/atbe-770-aws-ascio-integration`).

## 1. Requirements

Before using this provider, ensure you have the following:

### External Account

- **Ascio Account**: A valid Ascio reseller account is required.
  - **Username**: Your Ascio account username.
  - **Password**: Your Ascio account password.
- **Environment**: You should have access to either the:
  - **Sandbox (OTE)** environment for testing (`https://aws.demo.ascio.com/`).
  - **Live** environment for production (`https://aws.ascio.com/`).
  - **IP Whitelisting**: Ensure your server's IP is whitelisted in the Ascio portal.

### System Requirements

- **PHP Extensions**: The `soap` and `curl` extensions must be enabled in your PHP installation.

## 2. Configuration

The provider expects a configuration array with the following keys. This is defined in `src/Ascio/Data/Configuration.php`.

| Key        | Type    | Required | Description                                                                   |
| :--------- | :------ | :------- | :---------------------------------------------------------------------------- |
| `account`  | String  | Yes      | Your Ascio account username.                                                  |
| `password` | String  | Yes      | Your Ascio account password.                                                  |
| `sandbox`  | Boolean | No       | Set to `true` to use the OTE/Sandbox environment. Defaults to `false` (Live). |

### Example Configuration Array

```php
$config = [
    'account'  => 'your-username',
    'password' => 'your-secure-password',
    'sandbox'  => true, // Set to true for testing
];
```

## 3. Usage

To use the provider, instantiate it with the configuration data.

```php
use Upmind\ProvisionProviders\DomainNames\Ascio\Provider;
use Upmind\ProvisionProviders\DomainNames\Ascio\Data\Configuration;

// 1. Initialize Configuration
$configData = new Configuration([
    'account'  => 'my_ascio_user',
    'password' => 'my_ascio_pass',
    'sandbox'  => true,
]);

// 2. Instantiate Provider
$provider = new Provider($configData);

// 3. Perform Operations
// Example: Check Availability
$dacParams = new \Upmind\ProvisionProviders\DomainNames\Data\DacParams();
$dacParams->sld = 'example-domain-check';
$dacParams->tlds = ['com', 'net'];

$result = $provider->domainAvailabilityCheck($dacParams);
```

## 4. Supported Operations

The following operations are fully supported in this integration:

- **Availability Check**: `domainAvailabilityCheck()`
- **Registration**: `register()` (Contacts: Registrant, Admin, Tech, Billing required)
- **Transfer**: `transfer()` (EPP Code required)
- **Renewal**: `renew()`
- **Get Info**: `getInfo()`
- **Get Status**: `getStatus()`
- **Update Contacts**: `updateRegistrantContact()`
- **Update Nameservers**: `updateNameservers()`
- **Locking**: `setLock()`
- **EPP Code**: `getEppCode()`
- **Poll Queue**: `poll()`

## 5. Notes

- **WSDL URLs**:
  - Sandbox: `https://aws.demo.ascio.com/v3/aws.wsdl`
  - Live: `https://aws.ascio.com/v3/aws.wsdl`
- **Unsupported**: Auto-renew management and IP Tag updates are not supported by this API implementation.
