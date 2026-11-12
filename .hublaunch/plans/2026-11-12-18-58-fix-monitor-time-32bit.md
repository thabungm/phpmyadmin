# Fix #20399 - Avoid int overflow of millisecond timestamps in server monitor

## Plan Summary

- **What/why**: The server monitor casts a millisecond timestamp (about 1.78e12) to `int`. On 32-bit PHP that is larger than `PHP_INT_MAX` (2147483647), so PHP 8.5 emits "The float … is not representable as an int, cast occurred", and older PHP versions silently produce a wrong value. Replace both casts with `floor()` so the value stays an exact whole-millisecond float on every platform.
- **Key decision**: Use `floor()` the same way on every server (no 32-bit special case) — smallest, easiest change to review; a float represents whole milliseconds exactly up to 2^53.
- **Most important files**: `libraries/classes/Server/Status/Monitor.php` and `libraries/classes/Controllers/Server/Status/MonitorController.php`; new test `test/classes/Server/Status/MonitorTest.php`.
- **Priority/complexity**: Medium priority, Simple complexity.

## Source

Issue #20399 — https://github.com/phpmyadmin/phpmyadmin/issues/20399

## Problem Statement

phpMyAdmin's server monitor (Server → Status → Monitor) computes the current server time in milliseconds with `(int) (microtime(true) * 1000)` in two places. On 32-bit PHP builds the result does not fit into an `int`, so PHP 8.5 raises a warning (reported through phpMyAdmin's error reporting as incident 2747136) and PHP versions before 8.5 silently return a wrong integer, which breaks the monitor's server/client clock offset and chart time axis.

#### Planning Context

> **Note**: This section captures key points from the planning discussion to provide complete context for implementation.

**Key Requirements Discussed:**

- The fix targets the **`QA_5_2`** branch (bug fix for the released 5.2 line). The reporter explicitly asked for the fix on `QA_5_2`; maintainers merge `QA_5_2` up into `master` themselves.
- `QA_5_2` supports PHP `^7.2.5 || ^8.0` (`composer.json`). All code must be valid PHP 7.2 syntax: no arrow functions, no typed properties, no `match`, no named arguments, no nullsafe operator, no constructor promotion.
- Use `floor()` on every platform (not only on 32-bit).
- Tests: tighten the existing monitor page test AND add one new small unit test for the live chart data.
- No ChangeLog entry (maintainers add ChangeLog entries when merging, e.g. commit `28a671db4c` "Add change log entry for #20587").

**Decisions Made:**

- `floor()` over `round()`: `floor()` keeps the old truncation semantics of the `(int)` cast for positive values.
- `floor()` over a 32-bit-only branch (`PHP_INT_SIZE === 4 ? … : …`): one code path, smaller diff, same observable value on 64-bit (a whole number of milliseconds).
- Keep the value numeric (float) rather than converting to a string, because `js/src/server/status/monitor.js` does arithmetic on it and the JSON response should keep a number.

**Out of Scope:**

- Any other PHP 8.5 deprecation or warning elsewhere in the codebase.
- The `master` branch (maintainers merge `QA_5_2` into `master`).
- JavaScript changes (consumers already treat the value as a number).
- ChangeLog, documentation, translations.

#### Background & Context

- `libraries/classes/Server/Status/Monitor.php:94` sets `$ret['x'] = (int) (microtime(true) * 1000);` at the end of `Monitor::getJsonForChartingData()` (method starts at line 52). `libraries/classes/Controllers/Server/Status/Monitor/ChartingDataController.php` returns that array as JSON for the live charts.
- `libraries/classes/Controllers/Server/Status/MonitorController.php:57` sets `'server_time' => (int) (microtime(true) * 1000),` in the `$form` array. `templates/server/status/monitor/index.twig:270` renders every `$form` entry as `<input type="hidden" name="{{ name }}" value="{{ value }}">`, and `js/src/server/status/monitor.js:83` computes `serverTimeDiff = new Date().getTime() - $jsDataForm.find('input[name=server_time]').val();`.
- PHP 8.5 emits "The float %s is not representable as an int, cast occurred" only when the float is outside the `int` range (or NaN/INF). The reported value 1782811406433.493 fits in a 64-bit `int`, so the reporter runs a 32-bit PHP build.
- These are the only two millisecond `int` casts in `libraries/` on `QA_5_2`.

**Current Behavior**:

- 32-bit PHP 8.5: warning "The float 1782811406433.493 is not representable as an int, cast occurred" when opening the monitor page and on every chart refresh.
- 32-bit PHP < 8.5: no warning, but `server_time` and chart `x` values are wrong, so the monitor's time offset and chart time axis are wrong.
- 64-bit PHP: works; value is an `int`.

**Desired Behavior**:

- No warning on any platform; `server_time` and `x` are the current server time as a whole number of milliseconds (a float with no fractional part) on 32-bit and 64-bit PHP alike.

## Detailed Requirements

#### Functional Requirements

1. **Monitor chart data timestamp**
   - `Monitor::getJsonForChartingData()` sets `$ret['x']` to `floor(microtime(true) * 1000)`.
   - The value is a `float` with no fractional part, within the time window of the call.
2. **Monitor page server time**
   - `MonitorController::__invoke()` sets `'server_time'` to `floor(microtime(true) * 1000)`.
   - The rendered hidden input is `name="server_time" value="<13 or more digits>"` (no decimal point, no exponent).

#### Technical Requirements

- **Technology/Framework**: PHP (must parse on PHP 7.2.5 through 8.5), PHPUnit `^8.5.16 || ^9.6`, phpcs with `phpmyadmin/coding-standard` `^3.1`, PHPStan `^1.12`, Psalm `^4.30`.
- **Location**: `libraries/classes/Server/Status/Monitor.php`, `libraries/classes/Controllers/Server/Status/MonitorController.php`, `test/classes/Controllers/Server/Status/MonitorControllerTest.php`, new `test/classes/Server/Status/MonitorTest.php`.
- **Dependencies**: none new.
- **Constraints**: `use function` imports must stay alphabetically sorted (phpcs/Slevomat rule enforced by `composer phpcs`).

#### Non-Functional Requirements

- **Performance**: unchanged.
- **Security**: no new inputs or outputs.
- **Backwards Compatibility**: JSON and HTML still carry a whole-millisecond number; JavaScript consumers (`js/src/server/status/monitor.js:83`, `:1538-1540`, `:1585`) only do arithmetic on it.
- **Error Handling**: none needed.

## Proposed Solution

**High-level approach**: Replace the two `(int) (microtime(true) * 1000)` expressions with `floor(microtime(true) * 1000)`, import `floor`, and add tests that pin the value's type and format.

#### Key Components

1. **`libraries/classes/Server/Status/Monitor.php`**
   - Line 94: `$ret['x'] = (int) (microtime(true) * 1000);` → `$ret['x'] = floor(microtime(true) * 1000);`
   - Add `use function floor;` to the `use function` block (lines 15-27). Alphabetical order puts it after `use function count;` (line 16) and before `use function implode;` (line 17).
2. **`libraries/classes/Controllers/Server/Status/MonitorController.php`**
   - Line 57: `'server_time' => (int) (microtime(true) * 1000),` → `'server_time' => floor(microtime(true) * 1000),`
   - Add `use function floor;` to the `use function` block (lines 14-16). Alphabetical order puts it before `use function is_numeric;` (line 14).
3. **Tests** (see Testing Requirements).

#### Files Likely to Change

- `libraries/classes/Server/Status/Monitor.php` — replace cast at line 94, add `floor` import.
- `libraries/classes/Controllers/Server/Status/MonitorController.php` — replace cast at line 57, add `floor` import.
- `test/classes/Controllers/Server/Status/MonitorControllerTest.php` — tighten the `server_time` assertion at line 77.
- `test/classes/Server/Status/MonitorTest.php` — **new** file (the directory `test/classes/Server/Status/` does not exist yet; create it).
- `phpstan-baseline.neon` / `psalm-baseline.xml` — only if `composer phpstan` / `composer psalm` report a baseline entry that no longer matches (remove the stale entry; do not add new baseline entries).

#### Code Patterns to Follow

- **Test class header**: follow `test/classes/Controllers/Server/Status/MonitorControllerTest.php` lines 1-24 — `declare(strict_types=1);`, namespace under `PhpMyAdmin\Tests\…`, a `@covers` docblock AND a `#[CoversClass(...)]` attribute, `extends AbstractTestCase`.
- **DBI in tests**: `test/classes/AbstractTestCase.php:68-106` `setUp()` calls `setGlobalDbi()` (lines 244-250), which creates `$this->dummyDbi` (a `DbiDummy`) and `$this->dbi` (a `DatabaseInterface`). Pass `$this->dbi` to `new Monitor(...)` (constructor at `libraries/classes/Server/Status/Monitor.php:40`). Call `$this->assertAllQueriesConsumed()` at the end (`AbstractTestCase.php:118-121`).
- **Static assertions**: use `self::assert…` (existing tests use `self::assertStringContainsString`, see `MonitorControllerTest.php:57-80`).

**Anti-Patterns to Avoid:**

- Do not use `round()` (changes truncation semantics) or `intdiv`/`(int)` variants (still overflow on 32-bit).
- Do not convert the value to a string (JS and JSON expect a number).
- Do not use PHP 7.4+/8.x syntax anywhere (this branch runs on PHP 7.2.5).
- Do not touch unrelated code, ChangeLog, or baselines beyond removing an entry that becomes stale.

## Implementation Steps

#### Phase 1: Code change

- [ ] In `libraries/classes/Server/Status/Monitor.php`, change line 94 to `$ret['x'] = floor(microtime(true) * 1000);` and add `use function floor;` between `use function count;` and `use function implode;`.
- [ ] In `libraries/classes/Controllers/Server/Status/MonitorController.php`, change line 57 to `'server_time' => floor(microtime(true) * 1000),` and add `use function floor;` directly above `use function is_numeric;`.

#### Phase 2: Tests

- [ ] In `test/classes/Controllers/Server/Status/MonitorControllerTest.php` line 77, replace
  `self::assertStringContainsString('<input type="hidden" name="server_time"', $html);`
  with
  `self::assertMatchesRegularExpressionCompat('/<input type="hidden" name="server_time" value="\d{13,}">/', $html);`
  (`assertMatchesRegularExpressionCompat()` is the project's PHPUnit 8/9 compatibility helper at `test/classes/AbstractTestCase.php:136`; it calls `assertMatchesRegularExpression` when available and `assertRegExp` otherwise. `MonitorControllerTest` already extends `AbstractTestCase`.)
- [ ] Create `test/classes/Server/Status/MonitorTest.php`:

```php
<?php

declare(strict_types=1);

namespace PhpMyAdmin\Tests\Server\Status;

use PhpMyAdmin\Server\Status\Monitor;
use PhpMyAdmin\Tests\AbstractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function floor;
use function microtime;

/**
 * @covers \PhpMyAdmin\Server\Status\Monitor
 */
#[CoversClass(Monitor::class)]
class MonitorTest extends AbstractTestCase
{
    public function testGetJsonForChartingDataTimestamp(): void
    {
        $monitor = new Monitor($this->dbi);

        $before = floor(microtime(true) * 1000);
        $data = $monitor->getJsonForChartingData('{}');
        $after = floor(microtime(true) * 1000);

        self::assertArrayHasKey('x', $data);
        self::assertIsFloat($data['x']);
        self::assertSame(floor($data['x']), $data['x']);
        self::assertGreaterThanOrEqual($before, $data['x']);
        self::assertLessThanOrEqual($after, $data['x']);
        $this->assertAllQueriesConsumed();
    }
}
```

  `'{}'` decodes to an empty array, so `getJsonForChartingData()` issues no queries (both `count(...)` guards around lines 70-90 are false) and only sets `x`.

#### Phase 3: Verify

- [ ] `composer install` (QA_5_2 has no `composer.lock`; let Composer resolve).
- [ ] `vendor/bin/phpunit test/classes/Server/Status/MonitorTest.php test/classes/Controllers/Server/Status/MonitorControllerTest.php` — all pass.
- [ ] `vendor/bin/phpcs libraries/classes/Server/Status/Monitor.php libraries/classes/Controllers/Server/Status/MonitorController.php test/classes/Server/Status/MonitorTest.php test/classes/Controllers/Server/Status/MonitorControllerTest.php` — no errors.
- [ ] `composer phpstan` and `composer psalm` — no new errors; if either reports an unmatched baseline entry for the two changed lines, delete that entry from `phpstan-baseline.neon` / `psalm-baseline.xml`.
- [ ] `php -l` each changed PHP file; manually confirm no PHP 7.4+ syntax was introduced.

#### Phase 4: Commit / PR text

- [ ] Single commit (HubLaunch squashes), title exactly: `Fix #20399 - Avoid int overflow of millisecond timestamps in server monitor`.
- [ ] Commit body: one short paragraph — "A millisecond timestamp (~1.78e12) exceeds PHP_INT_MAX on 32-bit PHP. PHP 8.5 warns about the out-of-range (int) cast and older versions silently produce a wrong value. Use floor() so the value stays an exact whole-millisecond float."
- [ ] `Signed-off-by:` line required (DCO, see `CONTRIBUTING.md`): commit with `git commit -s`.
- [ ] PR body: follow `.github/PULL_REQUEST_TEMPLATE.md` (Description, `Fixes #20399`, checklist). Describe the root cause in plain words, list the two changed lines and the tests, state that 32-bit cannot be exercised on the 64-bit CI runners so the tests pin type/format/range, and include the generic AI-assistance disclosure line. Target branch `QA_5_2`.

<details>
<summary><b>Implementation Detail</b></summary>

### 6. Edge Cases & Considerations

#### Edge Cases to Handle

1. **64-bit PHP**: value becomes a float instead of an int; still a whole number of milliseconds; Twig prints it without a decimal point (PHP float-to-string with the default `precision=14` prints a 13-digit integral float as digits only).
2. **JSON response**: `json_encode` keeps a number; `JSON.parse` in the browser yields the same number.
3. **Clock values far in the future**: a float represents whole milliseconds exactly up to 2^53 (year ~287,000), so precision is not a concern.

#### Potential Challenges

- ⚠️ **PHPUnit 8 vs 9 assertion names**: the project supports PHPUnit `^8.5.16 || ^9.6`; use `self::assertMatchesRegularExpressionCompat()` from `test/classes/AbstractTestCase.php:136` (do not call `assertMatchesRegularExpression` or `assertRegExp` directly).
- ⚠️ **Static analysis baselines**: changing `(int)` to `floor()` changes the inferred type of `$ret['x']` / `server_time` from `int` to `float`; a baseline entry mentioning those lines may become unmatched. Remove only unmatched entries.

#### Security Considerations

- None — no new inputs, outputs, or dependencies.

### 7. Technical Considerations

#### Dependencies

- None new.

#### Configuration Changes

- None.

#### Environment Variables

- None.

#### API Rate Limiting

- Not applicable.

#### Error Handling Strategies

- Not applicable; the change removes a warning.

### 8. Testing Requirements

#### Unit Tests

- [ ] `MonitorTest::testGetJsonForChartingDataTimestamp` — `x` is a float with no fractional part, within the call's time window, no DB queries.
- [ ] `MonitorControllerTest::testIndex` — hidden `server_time` input has a value of 13+ digits.

#### Integration Tests

- [ ] Existing `test/classes/Controllers/Server/Status/**` tests still pass.

#### Manual Testing Checklist

- Derived from the End-to-End Test Cases below.

#### Test Data Requirements

- None beyond `DbiDummy` from `AbstractTestCase`.

### 9. Documentation Updates

#### User-Facing Documentation

- None.

#### Code Documentation

- None needed; the change is self-explanatory. Do not add comments beyond what the surrounding code uses.

### 11. Dependencies & Related Work

#### Dependencies

- [ ] Branch is cut from `QA_5_2` (launch uses `--base QA_5_2`).

#### Blockers

- [ ] None.

#### Related Issues/PRs

- Fixes #20399 (phpmyadmin/phpmyadmin)

</details>

## Acceptance Criteria

- [ ] **AC1**: `libraries/classes/Server/Status/Monitor.php` sets `$ret['x']` with `floor(microtime(true) * 1000)` and imports `floor`; no `(int)` cast of a millisecond timestamp remains in `libraries/` (`git grep -nE "\(int\) ?\(microtime" -- libraries/` returns nothing).
- [ ] **AC2**: `libraries/classes/Controllers/Server/Status/MonitorController.php` sets `'server_time'` with `floor(microtime(true) * 1000)` and imports `floor`.
- [ ] **AC3**: New `test/classes/Server/Status/MonitorTest.php` passes and asserts `x` is a whole-number float within the call window.
- [ ] **AC4**: `MonitorControllerTest::testIndex` asserts the hidden `server_time` input value matches `\d{13,}` and passes.
- [ ] **AC5**: phpcs, PHPStan and Psalm report no new errors for the changed files; all changed PHP files pass `php -l` and contain no syntax newer than PHP 7.2.
- [ ] **AC6**: The change is a single commit titled `Fix #20399 - Avoid int overflow of millisecond timestamps in server monitor` with a `Signed-off-by:` line, on a branch cut from `QA_5_2`, and no ChangeLog change.

## End-to-End Test Cases

```json
{
  "version": 1,
  "cases": [
    {
      "index": 1,
      "item": "No (int) millisecond cast remains; Monitor.php uses floor() and imports it",
      "driver": "cli",
      "expected": "git grep -nE \"\\(int\\) ?\\(microtime\" -- libraries/ prints nothing; grep shows 'floor(microtime(true) * 1000)' and 'use function floor;' in libraries/classes/Server/Status/Monitor.php",
      "needsRealClaude": false,
      "criterion": "AC1"
    },
    {
      "index": 2,
      "item": "MonitorController.php uses floor() for server_time and imports it",
      "driver": "cli",
      "expected": "grep shows \"'server_time' => floor(microtime(true) * 1000)\" and 'use function floor;' in libraries/classes/Controllers/Server/Status/MonitorController.php",
      "needsRealClaude": false,
      "criterion": "AC2"
    },
    {
      "index": 3,
      "item": "New Monitor unit test passes",
      "driver": "cli",
      "expected": "vendor/bin/phpunit test/classes/Server/Status/MonitorTest.php exits 0 with 1 test passing",
      "needsRealClaude": false,
      "criterion": "AC3",
      "setup": "composer install"
    },
    {
      "index": 4,
      "item": "Monitor controller test passes with the tightened server_time assertion",
      "driver": "cli",
      "expected": "vendor/bin/phpunit test/classes/Controllers/Server/Status/MonitorControllerTest.php exits 0",
      "needsRealClaude": false,
      "criterion": "AC4",
      "setup": "composer install"
    },
    {
      "index": 5,
      "item": "Static checks and PHP 7.2 syntax compatibility on changed files",
      "driver": "cli",
      "expected": "vendor/bin/phpcs on the four changed files exits 0; composer phpstan and composer psalm report no new errors; php -l passes on each changed file",
      "needsRealClaude": false,
      "criterion": "AC5",
      "setup": "composer install"
    },
    {
      "index": 6,
      "item": "Commit shape: single signed-off commit on top of QA_5_2 with the required title, no ChangeLog change",
      "driver": "cli",
      "expected": "git log origin/QA_5_2..HEAD shows exactly one commit titled 'Fix #20399 - Avoid int overflow of millisecond timestamps in server monitor' whose body contains 'Signed-off-by:'; git diff --name-only origin/QA_5_2...HEAD does not list ChangeLog",
      "needsRealClaude": false,
      "criterion": "AC6"
    }
  ]
}
```
