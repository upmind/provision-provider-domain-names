# Glue Records Implementation Checklist

## Overview
Add glue record management functions to the provision-provider-domain-names category. These functions allow setting and removing glue records (child nameservers) for domains.

## Reference Repository
https://github.com/upmind-automation/provision-provider-domain-names/

## Implementation Requirements

### New Category Functions
1. `setGlueRecord()` - Set/update a glue record for a domain
2. `removeGlueRecord()` - Remove a glue record from a domain

Both functions should return the domain's complete updated set of glue records after the operation.

---

## 1. Create New Data Classes

### ☐ Create `src/Data/SetGlueRecordParams.php`

**Properties:**
- `sld` (string, required) - Second-level domain
- `tld` (string, required) - Top-level domain
- `hostname` (string, required) - Glue record hostname
- `ip_1` (string, required) - First IP address
- `ip_2` (string, nullable) - Second IP address
- `ip_3` (string, nullable) - Third IP address
- `ip_4` (string, nullable) - Fourth IP address

**Template:**
```php
<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Set glue record parameters.
 *
 * @property-read string $sld Domain SLD
 * @property-read string $tld Domain TLD
 * @property-read string $hostname Glue record hostname
 * @property-read string $ip_1 First IP address
 * @property-read string|null $ip_2 Second IP address
 * @property-read string|null $ip_3 Third IP address
 * @property-read string|null $ip_4 Fourth IP address
 */
class SetGlueRecordParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
            'hostname' => ['required', 'string'],
            'ip_1' => ['required', 'ip'],
            'ip_2' => ['nullable', 'ip'],
            'ip_3' => ['nullable', 'ip'],
            'ip_4' => ['nullable', 'ip'],
        ]);
    }
}
```

---

### ☐ Create `src/Data/RemoveGlueRecordParams.php`

**Properties:**
- `sld` (string, required) - Second-level domain
- `tld` (string, required) - Top-level domain
- `hostname` (string, required) - Glue record hostname to remove

**Template:**
```php
<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Remove glue record parameters.
 *
 * @property-read string $sld Domain SLD
 * @property-read string $tld Domain TLD
 * @property-read string $hostname Glue record hostname to remove
 */
class RemoveGlueRecordParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'sld' => ['required', 'alpha-dash'],
            'tld' => ['required', 'alpha-dash-dot'],
            'hostname' => ['required', 'string'],
        ]);
    }
}
```

---

### ☐ Create `src/Data/GlueRecordsResult.php`

**Properties:**
- `hostname` (string, required) - Glue record hostname
- `ips` (array of strings, required) - IP addresses associated with the hostname

**Template:**
```php
<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Glue record result data.
 *
 * @property-read string $hostname Glue record hostname
 * @property-read string[] $ips IP addresses
 */
class GlueRecordsResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'string'],
            'ips' => ['required', 'array'],
            'ips.*' => ['ip'],
        ]);
    }
}
```

---

## 2. Update Existing Data Classes

### ☐ Modify `src/Data/DomainResult.php`

**Changes needed:**

1. Add import at top (after existing imports):
```php
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecordsResult;
```

2. Add to PHPDoc block (around line 28, after `@property-read NameserversParams $ns`):
```php
 * @property-read GlueRecordsResult[]|null $glue_records Glue records
```

3. Add to validation rules in `rules()` method (around line 50, after `'expires_at'`):
```php
            'glue_records' => ['nullable', 'array'],
            'glue_records.*' => [GlueRecordsResult::class],
```

4. Add setter method at end of class (after `setExpiresAt()` method):
```php
    /**
     * @param GlueRecordsResult[]|array[]|null $glueRecords
     *
     * @return static $this
     */
    public function setGlueRecords($glueRecords)
    {
        $this->setValue('glue_records', $glueRecords);
        return $this;
    }
```

---

## 3. Update Category Class

### ☐ Modify `src/Category.php`

**Changes needed:**

1. Add imports at top (after existing Data imports, around line 27):
```php
use Upmind\ProvisionProviders\DomainNames\Data\SetGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\RemoveGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecordsResult;
```

2. Add abstract method declarations at end of class (after `updateIpsTag()` method, before closing brace):
```php
    /**
     * Set a glue record for a domain.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    abstract public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult;

    /**
     * Remove a glue record from a domain.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    abstract public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult;
```

---

## 4. Implement Stub Functions in All Providers

For each provider, add the two new methods with error stubs.

### Provider List (32 total):

- ☐ `src/NetEarthOne/Provider.php`
- ☐ `src/CentralNic/Provider.php`
- ☐ `src/CentralNicReseller/Provider.php`
- ☐ `src/CoccaEpp/Provider.php`
- ☐ `src/ConnectReseller/Provider.php`
- ☐ `src/Demo/Provider.php`
- ☐ `src/DomainNameApi/Provider.php`
- ☐ `src/EURID/Provider.php`
- ☐ `src/Enom/Provider.php`
- ☐ `src/EuroDNS/Provider.php`
- ☐ `src/Example/Provider.php`
- ☐ `src/GoDaddy/Provider.php`
- ☐ `src/HRS/Provider.php`
- ☐ `src/Hexonet/Provider.php`
- ☐ `src/InternetBS/Provider.php`
- ☐ `src/InternetX/Provider.php`
- ☐ `src/LogicBoxes/Provider.php`
- ☐ `src/Moniker/Provider.php`
- ☐ `src/NameSilo/Provider.php`
- ☐ `src/Namecheap/Provider.php`
- ☐ `src/Netim/Provider.php`
- ☐ `src/Nira/Provider.php`
- ☐ `src/Nominet/Provider.php`
- ☐ `src/OpenProvider/Provider.php`
- ☐ `src/OpenSRS/Provider.php`
- ☐ `src/RealtimeRegister/Provider.php`
- ☐ `src/ResellBiz/Provider.php`
- ☐ `src/ResellerClub/Provider.php`
- ☐ `src/Ricta/Provider.php`
- ☐ `src/SynergyWholesale/Provider.php`
- ☐ `src/TPPWholesale/Provider.php`
- ☐ `src/UGRegistry/Provider.php`

### Changes for Each Provider:

1. **Add imports** at top (after existing use statements):
```php
use Upmind\ProvisionProviders\DomainNames\Data\SetGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\RemoveGlueRecordParams;
use Upmind\ProvisionProviders\DomainNames\Data\GlueRecordsResult;
```

2. **Add stub implementations** (typically at the end of the class, before closing brace):
```php
    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function setGlueRecord(SetGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported', $params);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    public function removeGlueRecord(RemoveGlueRecordParams $params): GlueRecordsResult
    {
        $this->errorResult('Operation not supported', $params);
    }
```

---

## 5. Update Documentation Files

### ☐ Modify `CHANGELOG.md`

**Changes needed:**

Add entry to the UNRELEASED section at the top of the file:

```markdown
## UNRELEASED

- Add setGlueRecord() and removeGlueRecord() functions
```

**Note:** If UNRELEASED section doesn't exist, create it above the most recent version.

---

### ☐ Modify `README.md`

**Changes needed:**

Add two new rows to the functions table (after the `updateIpsTag()` row):

```markdown
| setGlueRecord() | [_SetGlueRecordParams_](src/Data/SetGlueRecordParams.php) | [_GlueRecordsResult_](src/Data/GlueRecordsResult.php) | Set a glue record for a domain |
| removeGlueRecord() | [_RemoveGlueRecordParams_](src/Data/RemoveGlueRecordParams.php) | [_GlueRecordsResult_](src/Data/GlueRecordsResult.php) | Remove a glue record from a domain |
```

---

## Implementation Notes

### Validation Rules
- `alpha-dash`: Letters, numbers, dashes, underscores
- `alpha-dash-dot`: Letters, numbers, dashes, underscores, dots
- `ip`: Valid IPv4 or IPv6 address
- `nullable`: Field is optional
- `required`: Field is mandatory
- `array`: Field must be an array
- `string`: Field must be a string

### Error Handling Pattern
All providers should use `$this->errorResult('Operation not supported');` for now. Individual providers can later implement actual functionality by replacing these stubs.

### Return Value
Both `setGlueRecord()` and `removeGlueRecord()` return `GlueRecordsResult` containing the hostname and its associated IPs after the operation completes.

### Testing
After implementation, providers that don't support glue records will throw a proper error, while providers can later implement actual glue record management by replacing the stub implementations.

---

## 6. API Blueprint Configuration

After implementing the provider-side functionality, configure the API blueprints to expose glue record management to users.

**Target File:** `api/database/seeds/data/provision_blueprints.php`

**Target Blueprints:**
- `domain-names`
- `domain-names-alt`
- `domain-names-premium`

---

### ☐ Add Data Storage Return Field

Add a return field to store and display glue records data in the UI.

**Location:** In each blueprint's `'fields'` array

**Template:**
```php
'data_glue_records' => [
    'type' => ProvisionBlueprintField::TYPE_ORDER,
    'field_type' => ProvisionBlueprintField::FIELD_TYPE_TEXTAREA,
    'label' => 'Glue Records',
    'description' => 'Domain glue records (child nameservers)',
    'customer_enabled' => true,  // Set to false for staff-only
    'customer_can_update' => false,
    'copy_return_data' => 'getInfo.glue_records',
],
```

**Notes:**
- `TYPE_ORDER`: Indicates this is order-level data
- `FIELD_TYPE_TEXTAREA`: Data displays as read-only textarea in UI
- `copy_return_data`: Automatically copies data from getInfo function result
- The glue_records object is automatically converted to human-readable string format (similar to usage data in shared-hosting blueprint)

---

### ☐ Update getInfo Function

Modify the existing `getInfo` function to capture and store glue records data.

**Location:** In each blueprint's `'functions'` array, find the `'getInfo'` function

**Changes needed:**

Add mapping to the function's `actions` array, within the `TYPE_DISPLAY_RETURN_FIELDS` action's `mappings`:

```php
[
    'return_field' => 'glue_records',
    'provision_field' => 'data_glue_records'
],
```

**Full context example:**
```php
'getInfo' => [
    'function' => 'getInfo',
    // ... other configuration ...
    'actions' => [
        [
            'type' => ProvisionBlueprintResultAction::TYPE_DISPLAY_RETURN_FIELDS,
            'params' => [
                'mappings' => [
                    // ... existing mappings ...
                    [
                        'return_field' => 'glue_records',
                        'provision_field' => 'data_glue_records'
                    ],
                ],
            ],
        ],
        // ... other actions ...
    ],
],
```

---

### ☐ Add setGlueRecord Blueprint Function

Add the `setGlueRecord` function to allow setting/updating glue records.

**Location:** In each blueprint's `'functions'` array

**Template:**
```php
'setGlueRecord' => [
    'function' => 'setGlueRecord',
    'label' => 'Set Glue Record',
    'customer_enabled' => false,  // Staff-only for now
    'async' => false,
    'is_setup' => false,
    'parameters' => [
        'sld' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_FIELD,
            'blueprint_field' => 'sld',
        ],
        'tld' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_FIELD,
            'blueprint_field' => 'tld',
        ],
        'hostname' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_INPUT,
            'label' => 'Hostname',
            'description' => 'Glue record hostname (e.g., ns1.example.com)',
            'rules' => ['required', 'string'],
        ],
        'ip_1' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_INPUT,
            'label' => 'IP Address 1',
            'description' => 'First IP address',
            'rules' => ['required', 'ip'],
        ],
        'ip_2' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_INPUT,
            'label' => 'IP Address 2',
            'description' => 'Second IP address (optional)',
            'rules' => ['nullable', 'ip'],
        ],
        'ip_3' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_INPUT,
            'label' => 'IP Address 3',
            'description' => 'Third IP address (optional)',
            'rules' => ['nullable', 'ip'],
        ],
        'ip_4' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_INPUT,
            'label' => 'IP Address 4',
            'description' => 'Fourth IP address (optional)',
            'rules' => ['nullable', 'ip'],
        ],
    ],
    'actions' => [
        [
            'type' => ProvisionBlueprintResultAction::TYPE_DISPLAY_RETURN_FIELDS,
            'params' => [
                'mappings' => [
                    [
                        'return_field' => 'glue_records',
                        'provision_field' => 'data_glue_records'
                    ],
                ],
            ],
        ],
    ],
    'rules' => [
        [
            'condition' => DataRule::CONDITION_EQUAL,
            'parameter' => 'provision_result.status',
            'value' => 'ok',
        ]
    ],
],
```

**Parameter Types:**
- `TYPE_FIELD`: Auto-populated from existing order fields (sld, tld)
- `TYPE_INPUT`: User must provide value when executing function

---

### ☐ Add removeGlueRecord Blueprint Function

Add the `removeGlueRecord` function to allow removing glue records.

**Location:** In each blueprint's `'functions'` array

**Template:**
```php
'removeGlueRecord' => [
    'function' => 'removeGlueRecord',
    'label' => 'Remove Glue Record',
    'customer_enabled' => false,  // Staff-only for now
    'async' => false,
    'is_setup' => false,
    'parameters' => [
        'sld' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_FIELD,
            'blueprint_field' => 'sld',
        ],
        'tld' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_FIELD,
            'blueprint_field' => 'tld',
        ],
        'hostname' => [
            'type' => ProvisionBlueprintFunctionParameter::TYPE_INPUT,
            'label' => 'Hostname',
            'description' => 'Glue record hostname to remove',
            'rules' => ['required', 'string'],
        ],
    ],
    'actions' => [
        [
            'type' => ProvisionBlueprintResultAction::TYPE_DISPLAY_RETURN_FIELDS,
            'params' => [
                'mappings' => [
                    [
                        'return_field' => 'glue_records',
                        'provision_field' => 'data_glue_records'
                    ],
                ],
            ],
        ],
    ],
    'rules' => [
        [
            'condition' => DataRule::CONDITION_EQUAL,
            'parameter' => 'provision_result.status',
            'value' => 'ok',
        ]
    ],
],
```

---

### Blueprint Configuration Notes

#### Access Control
- **Staff-only**: Set `customer_enabled => false` on both field and functions
- **Customer access**: Set `customer_enabled => true` (requires business approval)
- Default is staff-only for initial implementation

#### Data Display in UI
The `data_glue_records` field with `FIELD_TYPE_TEXTAREA` automatically formats the glue_records array/object as a human-readable string in the UI, similar to how "usage data" displays in the shared-hosting blueprint.

Example display format:
```
ns1.example.com: 192.0.2.1, 192.0.2.2
ns2.example.com: 198.51.100.1
```

#### Result Actions
The `TYPE_DISPLAY_RETURN_FIELDS` action with mappings:
- Captures data from provider function result
- Stores it in the specified blueprint field
- Automatically updates the field after successful execution
- Only executes when `provision_result.status === 'ok'` (via rules)

#### Parameter Binding
- **TYPE_FIELD**: Values automatically pulled from existing order fields
- **TYPE_INPUT**: User must provide values via UI form when executing function
- All parameters validated according to specified rules before function execution

#### Multiple Blueprint Application
Apply these changes **identically** to all three domain blueprints:
1. `domain-names` (primary)
2. `domain-names-alt` (alternative configuration)
3. `domain-names-premium` (premium domains)

Ensure consistency across all three to maintain feature parity.

---

## Verification Checklist

After implementation, verify:

### Provider Library (provision-provider-domain-names)

- ☐ All 3 new data classes created with proper validation
- ☐ `DomainResult` updated with nullable `glue_records` property
- ☐ `Category` abstract class has both new methods
- ☐ All 32 providers implement both methods (even as stubs)
- ☐ `CHANGELOG.md` updated with UNRELEASED entry
- ☐ `README.md` functions table updated with new methods
- ☐ PHPDoc annotations are complete and accurate
- ☐ Code follows existing patterns and conventions
- ☐ No syntax errors (run PHP linter if available)

### API Blueprint Configuration

- ☐ `data_glue_records` field added to all 3 domain blueprints
- ☐ `getInfo` function updated with glue_records mapping in all 3 blueprints
- ☐ `setGlueRecord` function added to all 3 blueprints with proper parameters
- ☐ `removeGlueRecord` function added to all 3 blueprints with proper parameters
- ☐ Access control configured (`customer_enabled` set appropriately)
- ☐ Result actions configured with correct field mappings
- ☐ Function rules configured for conditional execution
- ☐ All parameter types and validations set correctly
- ☐ Consistency maintained across all three blueprints
- ☐ Blueprint seeder runs without errors

---

## File Paths Reference

### Provider Library

```
provision-provider-domain-names/
├── CHANGELOG.md                                  [MODIFY]
├── README.md                                     [MODIFY]
├── src/
│   ├── Category.php                              [MODIFY]
│   ├── Data/
│   │   ├── DomainResult.php                      [MODIFY]
│   │   ├── SetGlueRecordParams.php               [CREATE]
│   │   ├── RemoveGlueRecordParams.php            [CREATE]
│   │   └── GlueRecordsResult.php                 [CREATE]
│   └── [Provider]/
│       └── Provider.php                          [MODIFY - 32 files]
```

### API Project

```
api/
└── database/
    └── seeds/
        └── data/
            └── provision_blueprints.php          [MODIFY]
                ├── domain-names blueprint        [MODIFY]
                ├── domain-names-alt blueprint    [MODIFY]
                └── domain-names-premium blueprint [MODIFY]
```

---

## Next Steps After Initial Implementation

1. Identify which providers actually support glue record management
2. Implement real functionality for supported providers (replace stub methods)
3. Test blueprint functions via API with real provider implementations
4. Add integration tests for providers with real implementations
5. Update provider documentation to indicate glue record support
6. Consider enabling customer access (`customer_enabled => true`) after testing
7. Monitor glue record usage and UI display formatting

---

## Historical Patterns & Insights

Based on past implementations of similar category functions, follow these patterns:

### Documentation Conventions
- **@throws annotation**: Category abstract methods and provider implementations use `@throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError` rather than `@inheritDoc`
- **Error handling**: Stub implementations pass `$params` to `errorResult()`: `$this->errorResult('Operation not supported', $params);`
- **CHANGELOG format**: Add concise bullet point to UNRELEASED section listing new functions
- **README format**: Use table format with function name, params link, result link, and description

### Code Organization
- **Import order**: Add new imports after existing Data imports in chronological/alphabetical order
- **Method placement**: Add new abstract methods at end of Category class (after last existing function)
- **Provider stubs**: Add stub implementations at end of provider class before closing brace
- **Data class structure**: Follow existing patterns for PHPDoc, validation rules, and property types

### Implementation Flow
1. Create all Params and Result data classes first
2. Update Category.php with abstract methods
3. Update all provider implementations with stubs (can be done in parallel/batch)
4. Update documentation files (CHANGELOG.md, README.md)
5. Optionally implement real functionality for 1-2 providers that support the feature

### Common Patterns
- Use `nullable` for optional fields in both params and results
- Array properties use `'field' => ['required', 'array']` with `'field.*' => ['type']` for items
- Setter methods follow pattern: `setValue('field_name', $value); return $this;`
- Validation rules align with existing data types (`alpha-dash`, `alpha-dash-dot`, `ip`, etc.)

This implementation guide was enhanced with insights from commits:
- `df328ae8` - Initial verification functions implementation
- `3ec000f6` - Refactoring verification status fields
- `540afcdb` - Merge of verification functionality