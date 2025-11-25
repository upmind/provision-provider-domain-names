# Glue Record Refactoring Guide - Complete Implementation Instructions

## Context

Three branches exist with glue record implementations that need to be synchronized:

1. **glue_category_functions** (BASE) - Contains blueprint mapping fix (commit 64c6f23)
2. **feature/synergy-wholesale-glue-records** - SynergyWholesale implementation (needs refactoring)
3. **implement-glue-record-functions-on-opensrs-provider** - OpenSRS implementation (needs refactoring)

## What Changed in the Blueprint (commit 64c6f23)

### OLD Structure:
- `GlueRecordsResult` had `setHostname()` and `setIps()` methods for individual records
- `DomainResult.glue_records` expected arrays like `[['hostname' => ..., 'ips' => [...]]]`

### NEW Structure:
- `GlueRecord` class - Represents individual glue record with `hostname` and `ips` properties
- `GlueRecordsResult` class - Container/wrapper that holds `glue_records` array of `GlueRecord` objects
- `DomainResult.glue_records` expects `GlueRecord[]|null` (array of GlueRecord objects)

### Class Definitions:

```php
// src/Data/GlueRecord.php
class GlueRecord extends DataSet
{
    /** @var string */
    public $hostname;

    /** @var array */
    public $ips;

    public function setHostname(string $hostname): self;
    public function setIps(array $ips): self;
}

// src/Data/GlueRecordsResult.php
class GlueRecordsResult extends ResultData
{
    /** @var GlueRecord[]|null */
    public $glue_records;

    public function setGlueRecords(?array $glueRecords): self;
}

// src/Data/DomainResult.php
class DomainResult extends ResultData
{
    /** @var GlueRecord[]|null */
    public $glue_records;

    public function setGlueRecords($glueRecords);
}
```

### How to Create GlueRecord Objects:

```php
// Method 1: Array syntax
$glueRecord = GlueRecord::create([
    'hostname' => 'ns1.example.com',
    'ips' => ['192.0.2.1', '2001:db8::1']
]);

// Method 2: Fluent setters
$glueRecord = GlueRecord::create()
    ->setHostname('ns1.example.com')
    ->setIps(['192.0.2.1', '2001:db8::1']);
```

### Example from Example Provider (lines 138-145):

```php
->setGlueRecords([
    GlueRecord::create()
        ->setHostname('ns1.' . $domain)
        ->setIps(['192.0.2.1', '2001:db8::1']),
    GlueRecord::create()
        ->setHostname('ns2.' . $domain)
        ->setIps(['192.0.2.2']),
]);
```

---

## BRANCH 1: feature/synergy-wholesale-glue-records

### Files to Modify:
1. `src/SynergyWholesale/Helper/SynergyWholesaleApi.php`
2. `src/SynergyWholesale/Provider.php`

### Change 1: Add Import to SynergyWholesaleApi.php

**Location:** Top of file after existing imports

**ADD THIS LINE:**
```php
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;
```

**After imports section should look like:**
```php
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\ContactParams;
use Upmind\ProvisionProviders\DomainNames\Data\DacDomain;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;  // <-- ADD THIS
use Upmind\ProvisionProviders\DomainNames\Data\Nameserver;
```

### Change 2: Refactor getDomainInfo() Method in SynergyWholesaleApi.php

**Location:** Lines 141-155 (in getDomainInfo method)

**FIND THIS CODE:**
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
```

**REPLACE WITH:**
```php
// Fetch glue records
$glueRecords = [];
try {
    $hostsResponse = $this->makeRequest('listAllHosts', [
        'domainName' => $domainName,
    ]);
    foreach ($hostsResponse['hosts'] ?? [] as $host) {
        $glueRecords[] = GlueRecord::create([
            'hostname' => $host['hostName'],
            'ips' => $host['ip'] ?? [],
        ]);
    }
} catch (Throwable $e) {
    // Domain may not have hosts - ignore
}
```

**KEY CHANGE:** Line 147-151 changes from creating array to creating GlueRecord object.

### Change 3: Refactor setGlueRecord() Method in Provider.php

**Location:** Lines 401-436 (approximately, in setGlueRecord method)

**FIND THE RETURN STATEMENT:**
```php
return GlueRecordsResult::create()
    ->setHostname($params->hostname)
    ->setIps($hostInfo['ipAddress'] ?? $ips)
    ->setMessage('Glue record created successfully');
```

**REPLACE WITH:**
```php
$glueRecord = GlueRecord::create([
    'hostname' => $params->hostname,
    'ips' => $hostInfo['ipAddress'] ?? $ips,
]);

return GlueRecordsResult::create([
    'glue_records' => [$glueRecord]
])->setMessage('Glue record created successfully');
```

**EXPLANATION:**
- Create a GlueRecord object with the hostname and IPs
- Return GlueRecordsResult with the glue_records array containing that one GlueRecord
- GlueRecordsResult no longer has setHostname/setIps methods

### Change 4: Refactor removeGlueRecord() Method in Provider.php

**Location:** Lines 442-456 (approximately, in removeGlueRecord method)

**FIND THE RETURN STATEMENT:**
```php
return GlueRecordsResult::create()
    ->setHostname($params->hostname)
    ->setIps([])
    ->setMessage('Glue record deleted successfully');
```

**REPLACE WITH:**
```php
return GlueRecordsResult::create([
    'glue_records' => []
])->setMessage('Glue record deleted successfully');
```

**EXPLANATION:**
- After deletion, return empty glue_records array
- No need to set hostname/ips as those methods don't exist anymore

---

## BRANCH 2: implement-glue-record-functions-on-opensrs-provider

### Files to Modify:
1. `src/OpenSRS/Helper/OpenSrsApi.php`
2. `src/OpenSRS/Provider.php`

### Change 1: Add Import to OpenSrsApi.php

**Location:** Top of file after existing imports

**ADD THIS LINE:**
```php
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;
```

### Change 2: Refactor _getInfo() Method in Provider.php

**Location:** Lines 544-567 (approximately, in _getInfo method, after fetching glue records)

**FIND THIS CODE:**
```php
// Fetch glue records
$glueRecords = [];
try {
    $nameservers = $this->api()->getNameservers(Utils::getDomain($sld, $tld));
    foreach ($nameservers as $ns) {
        $ips = [];
        if (isset($ns['ipaddress'])) {
            $ips[] = $ns['ipaddress'];
        }
        if (isset($ns['ipv6'])) {
            $ips[] = $ns['ipv6'];
        }

        if (!empty($ips)) {
            $glueRecords[] = [
                'hostname' => $ns['name'],
                'ips' => $ips,
            ];
        }
    }
} catch (Throwable $e) {
    // Domain may not have hosts - ignore
}
```

**REPLACE WITH:**
```php
// Fetch glue records
$glueRecords = [];
try {
    $nameservers = $this->api()->getNameservers(Utils::getDomain($sld, $tld));
    foreach ($nameservers as $ns) {
        $ips = [];
        if (isset($ns['ipaddress'])) {
            $ips[] = $ns['ipaddress'];
        }
        if (isset($ns['ipv6'])) {
            $ips[] = $ns['ipv6'];
        }

        if (!empty($ips)) {
            $glueRecords[] = GlueRecord::create([
                'hostname' => $ns['name'],
                'ips' => $ips,
            ]);
        }
    }
} catch (Throwable $e) {
    // Domain may not have hosts - ignore
}
```

**KEY CHANGE:** Line 558-561 changes from creating array to creating GlueRecord object.

### Change 3: Refactor setGlueRecord() Method in Provider.php

**Location:** Lines 886-927 (approximately)

**FIND THE RETURN STATEMENT (around line 921-924):**
```php
return GlueRecordsResult::create()
    ->setHostname($params->hostname)
    ->setIps($ips)
    ->setMessage('Glue record created successfully');
```

**REPLACE WITH:**
```php
$glueRecord = GlueRecord::create([
    'hostname' => $params->hostname,
    'ips' => $ips,
]);

return GlueRecordsResult::create([
    'glue_records' => [$glueRecord]
])->setMessage('Glue record created successfully');
```

### Change 4: Refactor removeGlueRecord() Method in Provider.php

**Location:** Lines 933-948 (approximately)

**FIND THE RETURN STATEMENT (around line 942-945):**
```php
return GlueRecordsResult::create()
    ->setHostname($params->hostname)
    ->setIps([])
    ->setMessage('Glue record deleted successfully');
```

**REPLACE WITH:**
```php
return GlueRecordsResult::create([
    'glue_records' => []
])->setMessage('Glue record deleted successfully');
```

---

## TODO CHECKLIST

### Pre-requisites:
- [ ] Ensure you're in the correct repository directory
- [ ] Run `git fetch origin` to get latest remote changes
- [ ] Confirm glue_category_functions has commit 64c6f23

### Branch 1: feature/synergy-wholesale-glue-records

- [ ] Checkout branch: `git checkout feature/synergy-wholesale-glue-records`
- [ ] Pull latest from base: `git pull origin glue_category_functions`
- [ ] Resolve any merge conflicts if they occur
- [ ] Add GlueRecord import to `src/SynergyWholesale/Helper/SynergyWholesaleApi.php`
- [ ] Refactor getDomainInfo() in SynergyWholesaleApi.php (line 147-151)
- [ ] Refactor setGlueRecord() return statement in Provider.php
- [ ] Refactor removeGlueRecord() return statement in Provider.php
- [ ] Verify no syntax errors: check IDE or run `php -l` on modified files
- [ ] Commit changes: `git commit -am "Refactor to use GlueRecord class for blueprint compatibility"`
- [ ] Push to remote: `git push origin feature/synergy-wholesale-glue-records`

### Branch 2: implement-glue-record-functions-on-opensrs-provider

- [ ] Checkout branch: `git checkout implement-glue-record-functions-on-opensrs-provider`
- [ ] Pull latest from base: `git pull origin glue_category_functions`
- [ ] Resolve any merge conflicts if they occur
- [ ] Add GlueRecord import to `src/OpenSRS/Helper/OpenSrsApi.php`
- [ ] Refactor _getInfo() in Provider.php (line 558-561)
- [ ] Refactor setGlueRecord() return statement in Provider.php
- [ ] Refactor removeGlueRecord() return statement in Provider.php
- [ ] Verify no syntax errors: check IDE or run `php -l` on modified files
- [ ] Commit changes: `git commit -am "Refactor to use GlueRecord class for blueprint compatibility"`
- [ ] Push to remote: `git push origin implement-glue-record-functions-on-opensrs-provider`

### Final Verification:
- [ ] Both branches pushed successfully
- [ ] No syntax errors in any files
- [ ] All GlueRecord objects created with proper structure
- [ ] All GlueRecordsResult returns contain glue_records array

---

## Common Pitfalls to Avoid

1. **DON'T** use `GlueRecordsResult::create()->setHostname()->setIps()` anymore - these methods don't exist
2. **DO** create `GlueRecord` objects first, then wrap in `GlueRecordsResult`
3. **DON'T** forget to import `GlueRecord` class at the top of files
4. **DO** use array syntax or fluent setters for GlueRecord creation
5. **DON'T** return plain arrays for glue_records - must be GlueRecord objects
6. **DO** return empty array `[]` in glue_records when removing/no records exist

---

## Quick Reference

### Creating a GlueRecord:
```php
GlueRecord::create(['hostname' => 'ns1.example.com', 'ips' => ['192.0.2.1']])
```

### Returning from setGlueRecord:
```php
$glueRecord = GlueRecord::create([...]);
return GlueRecordsResult::create(['glue_records' => [$glueRecord]])->setMessage('...');
```

### Returning from removeGlueRecord:
```php
return GlueRecordsResult::create(['glue_records' => []])->setMessage('...');
```

### In getDomainInfo/getInfo:
```php
$glueRecords[] = GlueRecord::create(['hostname' => ..., 'ips' => ...]);
// Then include in domain info:
'glue_records' => $glueRecords,
```
