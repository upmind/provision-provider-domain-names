# SynergyWholesale Glue Record Implementation

## Resume Instructions

When resuming this task, read this file first. It contains all context needed to implement glue record functions for SynergyWholesale provider.

**Status**: Planning complete, ready for implementation

---

## Checklist

- [ ] **API Helper Methods** (`src/SynergyWholesale/Helper/SynergyWholesaleApi.php`)
  - [ ] Add `addRegistryHost(string $domainName, string $host, array $ips): array`
  - [ ] Add `deleteRegistryHost(string $domainName, string $host): array`
  - [ ] Add `listRegistryHost(string $domainName, string $host): array`
  - [ ] Add `listAllRegistryHosts(string $domainName): array`
  - [ ] Update `getDomainInfo()` to call `listAllHosts` and include `glue_records` in return

- [ ] **Provider Methods** (`src/SynergyWholesale/Provider.php`)
  - [ ] Implement `setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult`
  - [ ] Implement `removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult`

- [ ] **Testing**
  - [ ] Test setGlueRecord with single IP
  - [ ] Test setGlueRecord with multiple IPs
  - [ ] Test removeGlueRecord
  - [ ] Verify getDomainInfo returns glue_records

---

## API Reference

### Synergy Wholesale SOAP Commands

```
addHost
  - domainName: string (full domain, e.g., "example.com")
  - host: string (prefix only, e.g., "ns1")
  - ipAddress: array (IPv4/IPv6 addresses)
  - Returns: status, errorMessage

deleteHost
  - domainName: string
  - host: string (prefix only)
  - Returns: status, errorMessage

listHost
  - domainName: string
  - host: string (prefix only)
  - Returns: status, host, domainName, ipAddress[]

listAllHosts
  - domainName: string
  - Returns: status, hosts[] where each host has hostName and ip[]
```

---

## Data Structures

### Input Parameters

```php
// SetGlueRecordParams
$params->sld        // "example"
$params->tld        // "com"
$params->hostname   // "ns1.example.com" (full hostname)
$params->ip_1       // required, e.g., "192.0.2.1"
$params->ip_2       // nullable
$params->ip_3       // nullable
$params->ip_4       // nullable

// RemoveGlueRecordParams
$params->sld        // "example"
$params->tld        // "com"
$params->hostname   // "ns1.example.com"
```

### Output

```php
// GlueRecordsResult
GlueRecordsResult::create()
    ->setHostname('ns1.example.com')  // full hostname
    ->setIps(['192.0.2.1', '2001:db8::1'])
    ->setMessage('...');
```

---

## Implementation Code

### SynergyWholesaleApi.php - Add These Methods

```php
public function addRegistryHost(string $domainName, string $host, array $ips): array
{
    return $this->makeRequest('addHost', [
        'domainName' => $domainName,
        'host' => $host,
        'ipAddress' => $ips,
    ]);
}

public function deleteRegistryHost(string $domainName, string $host): array
{
    return $this->makeRequest('deleteHost', [
        'domainName' => $domainName,
        'host' => $host,
    ]);
}

public function listRegistryHost(string $domainName, string $host): array
{
    return $this->makeRequest('listHost', [
        'domainName' => $domainName,
        'host' => $host,
    ]);
}

public function listAllRegistryHosts(string $domainName): array
{
    return $this->makeRequest('listAllHosts', [
        'domainName' => $domainName,
    ]);
}
```

### SynergyWholesaleApi.php - Update getDomainInfo()

Add before the return statement in `getDomainInfo()`:

```php
// Fetch glue records
$glueRecords = [];
try {
    $hostsResponse = $this->makeRequest('listAllHosts', [
        'domainName' => $domainName,
    ]);
    foreach ($hostsResponse['hosts'] ?? [] as $host) {
        $glueRecords[] = [
            'hostname' => $host['hostName'],
            'ips' => $host['ip'] ?? [],
        ];
    }
} catch (Throwable $e) {
    // Domain may not have hosts - ignore
}

// Add to return array:
'glue_records' => $glueRecords,
```

### Provider.php - setGlueRecord Implementation

```php
public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
{
    $domainName = Utils::getDomain($params->sld, $params->tld);

    // Extract host prefix: "ns1.example.com" -> "ns1"
    $host = str_replace('.' . $domainName, '', $params->hostname);

    // Collect non-null IPs
    $ips = array_values(array_filter([
        $params->ip_1,
        $params->ip_2,
        $params->ip_3,
        $params->ip_4,
    ]));

    try {
        // Delete existing (ignore if not exists)
        try {
            $this->api()->deleteRegistryHost($domainName, $host);
        } catch (Throwable $e) {
            // Ignore
        }

        // Create host
        $this->api()->addRegistryHost($domainName, $host, $ips);

        // Get created host info
        $hostInfo = $this->api()->listRegistryHost($domainName, $host);

        return GlueRecordsResult::create()
            ->setHostname($params->hostname)
            ->setIps($hostInfo['ipAddress'] ?? $ips)
            ->setMessage('Glue record created successfully');
    } catch (Throwable $e) {
        $this->handleException($e);
    }
}
```

### Provider.php - removeGlueRecord Implementation

```php
public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
{
    $domainName = Utils::getDomain($params->sld, $params->tld);
    $host = str_replace('.' . $domainName, '', $params->hostname);

    try {
        $this->api()->deleteRegistryHost($domainName, $host);

        return GlueRecordsResult::create()
            ->setHostname($params->hostname)
            ->setIps([])
            ->setMessage('Glue record deleted successfully');
    } catch (Throwable $e) {
        $this->handleException($e);
    }
}
```

---

## Existing Code Patterns

### API Request Pattern
```php
$this->makeRequest($command, $params);  // Returns parsed array, throws on error
```

### Provider Method Pattern
```php
try {
    // API calls
    return ResultType::create()->setField($value);
} catch (Throwable $e) {
    $this->handleException($e);
}
```

### Domain Name Construction
```php
$domainName = Utils::getDomain($params->sld, $params->tld);  // "example.com"
```

---

## File Locations

- Provider: `src/SynergyWholesale/Provider.php`
- API Helper: `src/SynergyWholesale/Helper/SynergyWholesaleApi.php`
- GlueRecordsResult: `src/Data/GlueRecordsResult.php`
- SetGlueRecordParams: `src/Data/SetGlueRecordParams.php`
- RemoveGlueRecordParams: `src/Data/RemoveGlueRecordParams.php`

---

## Notes

- Hostname conversion is critical: params have full hostname, API wants prefix only
- Delete-then-create strategy for setGlueRecord (simpler than updating individual IPs)
- listAllHosts may fail if domain has no hosts - wrap in try/catch for getDomainInfo
- GlueRecordsResult already has setHostname() and setIps() methods