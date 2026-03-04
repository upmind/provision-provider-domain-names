# OpusDNS Provider - Usage Notes

## Account Setup

1. **Create an account** at [opusdns.com](https://app.opusdns.com/signup)
2. **Generate API credentials** from the OpusDNS Dashboard → Developer Settings
   - You will need a **Client ID** and **Client Secret** (OAuth2)
3. **Sandbox testing**: Enable "Test Mode" in the dashboard for a sandbox API key, or use a separate sandbox account

## Environments

| Environment | Base URL                      | Purpose             |
| ----------- | ----------------------------- | ------------------- |
| Production  | `https://api.opusdns.com`     | Live operations     |
| Sandbox     | `https://sandbox.opusdns.com` | Testing/integration |

## Configuration Parameters

| Parameter       | Type     | Required | Description                                                     |
| --------------- | -------- | -------- | --------------------------------------------------------------- |
| `client_id`     | `string` | ✅       | OAuth2 Client ID from OpusDNS Dashboard                         |
| `client_secret` | `string` | ✅       | OAuth2 Client Secret from OpusDNS Dashboard                     |
| `sandbox`       | `bool`   | ❌       | Set to `true` to use the sandbox environment (default: `false`) |

## Authentication

OpusDNS uses **OAuth2 client credentials** authentication:

- The provider automatically obtains a bearer token via `POST /v1/auth/token`
- Tokens are cached in-memory and refreshed automatically when expired
- On `401 Unauthorized` responses, the token is refreshed and the request retried once

## Supported Features

| Feature                   | Supported | Notes                            |
| ------------------------- | --------- | -------------------------------- |
| Domain Availability Check | ✅        |                                  |
| Domain Registration       | ✅        |                                  |
| Domain Transfer           | ✅        |                                  |
| Domain Renewal            | ✅        |                                  |
| Domain Info               | ✅        |                                  |
| Update Registrant Contact | ✅        |                                  |
| Update Contact            | ✅        | Supports all contact types       |
| Update Nameservers        | ✅        |                                  |
| Set Lock                  | ✅        |                                  |
| Set Auto Renew            | ✅        |                                  |
| Get EPP Code              | ✅        |                                  |
| Update IPS Tag            | ❌        | Not applicable (UK-only feature) |
| Poll                      | ❌        | Not supported by provider        |
| Get Verification Status   | ✅        | ICANN verification status        |
| Resend Verification Email | ✅        |                                  |
| Set Glue Record           | ✅        |                                  |
| Remove Glue Record        | ✅        |                                  |
| Get Status                | ✅        | Returns normalized status        |

## IP Whitelisting

OpusDNS supports IP whitelisting for API access. If enabled on your account, ensure the server IP running this provider is whitelisted in the OpusDNS Dashboard.

## Example Configuration

```php
$configuration = [
    'client_id' => 'your_client_id_here',
    'client_secret' => 'your_client_secret_here',
    'sandbox' => true, // Set to false for production
];
```
