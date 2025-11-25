# Glue Record Refactoring - Step-by-Step TODO

## Overview
Refactor 2 branches to use new `GlueRecord` class instead of arrays. Read `GLUE_RECORD_REFACTORING_GUIDE.md` for full context.

## Setup
```bash
cd /Users/nicolasramirez/Projects/Upmind/provision-workbench/local/provision-provider-domain-names
git fetch origin
```

---

## TASK 1: Refactor feature/synergy-wholesale-glue-records

### Step 1: Prepare Branch
```bash
git checkout feature/synergy-wholesale-glue-records
git pull origin glue_category_functions
# If conflicts, resolve them before continuing
```

### Step 2: Modify SynergyWholesaleApi.php
**File:** `src/SynergyWholesale/Helper/SynergyWholesaleApi.php`

**Action 1:** Add import after line ~20
```php
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;
```

**Action 2:** Find line ~147-151 in getDomainInfo() method, change:
```php
// FROM:
$glueRecords[] = [
    'hostname' => $host['hostName'],
    'ips' => $host['ip'] ?? [],
];

// TO:
$glueRecords[] = GlueRecord::create([
    'hostname' => $host['hostName'],
    'ips' => $host['ip'] ?? [],
]);
```

### Step 3: Modify Provider.php - setGlueRecord()
**File:** `src/SynergyWholesale/Provider.php`

**Action:** Find return statement in setGlueRecord() method (~line 430), change:
```php
// FROM:
return GlueRecordsResult::create()
    ->setHostname($params->hostname)
    ->setIps($hostInfo['ipAddress'] ?? $ips)
    ->setMessage('Glue record created successfully');

// TO:
$glueRecord = GlueRecord::create([
    'hostname' => $params->hostname,
    'ips' => $hostInfo['ipAddress'] ?? $ips,
]);

return GlueRecordsResult::create([
    'glue_records' => [$glueRecord]
])->setMessage('Glue record created successfully');
```

### Step 4: Modify Provider.php - removeGlueRecord()
**File:** `src/SynergyWholesale/Provider.php`

**Action:** Find return statement in removeGlueRecord() method (~line 450), change:
```php
// FROM:
return GlueRecordsResult::create()
    ->setHostname($params->hostname)
    ->setIps([])
    ->setMessage('Glue record deleted successfully');

// TO:
return GlueRecordsResult::create([
    'glue_records' => []
])->setMessage('Glue record deleted successfully');
```

### Step 5: Commit and Push
```bash
git add .
git commit -m "Refactor to use GlueRecord class for blueprint compatibility"
git push origin feature/synergy-wholesale-glue-records
```

**Checklist:**
- [ ] Import added to SynergyWholesaleApi.php
- [ ] getDomainInfo() creates GlueRecord objects
- [ ] setGlueRecord() wraps GlueRecord in GlueRecordsResult
- [ ] removeGlueRecord() returns empty glue_records array
- [ ] No syntax errors
- [ ] Committed and pushed

---

## TASK 2: Refactor implement-glue-record-functions-on-opensrs-provider

### Step 1: Prepare Branch
```bash
git checkout implement-glue-record-functions-on-opensrs-provider
git pull origin glue_category_functions
# If conflicts, resolve them before continuing
```

### Step 2: Modify OpenSrsApi.php
**File:** `src/OpenSRS/Helper/OpenSrsApi.php`

**Action:** Add import after line ~17
```php
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecord;
```

### Step 3: Modify Provider.php - _getInfo()
**File:** `src/OpenSRS/Provider.php`

**Action:** Find line ~558-561 in _getInfo() method, change:
```php
// FROM:
if (!empty($ips)) {
    $glueRecords[] = [
        'hostname' => $ns['name'],
        'ips' => $ips,
    ];
}

// TO:
if (!empty($ips)) {
    $glueRecords[] = GlueRecord::create([
        'hostname' => $ns['name'],
        'ips' => $ips,
    ]);
}
```

### Step 4: Modify Provider.php - setGlueRecord()
**File:** `src/OpenSRS/Provider.php`

**Action:** Find return statement in setGlueRecord() method (~line 921), change:
```php
// FROM:
return GlueRecordsResult::create()
    ->setHostname($params->hostname)
    ->setIps($ips)
    ->setMessage('Glue record created successfully');

// TO:
$glueRecord = GlueRecord::create([
    'hostname' => $params->hostname,
    'ips' => $ips,
]);

return GlueRecordsResult::create([
    'glue_records' => [$glueRecord]
])->setMessage('Glue record created successfully');
```

### Step 5: Modify Provider.php - removeGlueRecord()
**File:** `src/OpenSRS/Provider.php`

**Action:** Find return statement in removeGlueRecord() method (~line 942), change:
```php
// FROM:
return GlueRecordsResult::create()
    ->setHostname($params->hostname)
    ->setIps([])
    ->setMessage('Glue record deleted successfully');

// TO:
return GlueRecordsResult::create([
    'glue_records' => []
])->setMessage('Glue record deleted successfully');
```

### Step 6: Commit and Push
```bash
git add .
git commit -m "Refactor to use GlueRecord class for blueprint compatibility"
git push origin implement-glue-record-functions-on-opensrs-provider
```

**Checklist:**
- [ ] Import added to OpenSrsApi.php
- [ ] _getInfo() creates GlueRecord objects
- [ ] setGlueRecord() wraps GlueRecord in GlueRecordsResult
- [ ] removeGlueRecord() returns empty glue_records array
- [ ] No syntax errors
- [ ] Committed and pushed

---

## Verification

Run these checks after both branches are complete:

```bash
# Check SynergyWholesale branch
git checkout feature/synergy-wholesale-glue-records
git log -1 --oneline
git diff origin/glue_category_functions

# Check OpenSRS branch
git checkout implement-glue-record-functions-on-opensrs-provider
git log -1 --oneline
git diff origin/glue_category_functions
```

Both branches should have:
1. New commit with "Refactor to use GlueRecord class"
2. GlueRecord import in helper files
3. GlueRecord::create() calls instead of arrays
4. GlueRecordsResult with glue_records array

---

## Key Pattern to Remember

**OLD WAY (WRONG):**
```php
return GlueRecordsResult::create()
    ->setHostname(...)
    ->setIps(...);
```

**NEW WAY (CORRECT):**
```php
$glueRecord = GlueRecord::create(['hostname' => ..., 'ips' => ...]);
return GlueRecordsResult::create(['glue_records' => [$glueRecord]]);
```

**For Arrays (WRONG):**
```php
$glueRecords[] = ['hostname' => ..., 'ips' => ...];
```

**For Arrays (CORRECT):**
```php
$glueRecords[] = GlueRecord::create(['hostname' => ..., 'ips' => ...]);
```
