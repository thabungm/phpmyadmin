# HubLaunch Lessons Learned

This file persists context across agent sessions. Update it as you work.

## Current Status
- Phase: COMPLETE — all phases done, committed as 8d59f44977 (signed off)
- Last action: committed fix; branch = hula plan commit + fix commit (HubLaunch squashes)
- Blockers: None

## Key Discoveries
- Branch fix-monitor-time-32bit is cut from origin/QA_5_2 (merge-base 0e01a17cb1); HEAD has a hula "create plan branch" commit — leave it, HubLaunch squashes.
- PHPUnit 9 only takes ONE path argument; run test files one at a time.
- test/bootstrap-dist.php sets precision=14 → floor(ms) renders as 13 digits w/o decimal in Twig.
- json_encode(floor(x)) → `{"x":1782811406433}` (no ".0" without JSON_PRESERVE_ZERO_FRACTION).
- New MonitorTest fails on old code ("1794917198618 is of type float") → regression guard works.

## Solutions That Worked
- `composer install` (no lock file) works fine, ~1-2 min.

## Things to Avoid
- Don't pass multiple test files to vendor/bin/phpunit.
- Container memory cap is 8 GB (nproc=48): running phpstan + psalm + eslint concurrently gets OOM-killed (exit 137).
  Run them one at a time; phpstan needs a /tmp config including phpstan.neon.dist with
  `parameters.parallel.maximumNumberOfProcesses: 2`; psalm with `--threads=2`.

## Pre-existing environment noise (NOT caused by this change; out of scope)
- `composer phpcs`: 1 error in libraries/classes/Message.php:638 (sprintf argument unpacking) — newer Slevomat resolved since QA_5_2 has no composer.lock. Confirmed present with changes stashed.
- PHPStan (1130 errors) / Psalm (914 errors): almost all "Attribute class PHPUnit\Framework\Attributes\* does not exist" (PHPUnit 9 resolved) + a few dep deprecations. No errors in changed library files; no unmatched baseline entries.
- PHPUnit unit suite: 3248 tests OK, 38 skipped (LanguageTest, needs compiled locales).

## PR body draft (target QA_5_2)
### Description
On 32-bit PHP builds a millisecond timestamp (about 1.78e12) is larger than PHP_INT_MAX (2147483647). The server monitor cast `microtime(true) * 1000` to `int` in two places, so PHP 8.5 emits "The float … is not representable as an int, cast occurred" and older PHP versions silently produce a wrong value, which breaks the monitor's server/client clock offset and chart time axis.

This replaces both casts with `floor()`, so the value stays an exact whole-millisecond number on every platform (a float represents whole milliseconds exactly up to 2^53):
- `Monitor::getJsonForChartingData()` — chart `x` value
- `MonitorController::__invoke()` — hidden `server_time` input

The JSON response and the HTML attribute still carry a plain integer-looking number (no decimal point), and the JavaScript only does arithmetic on it.

Tests: new `MonitorTest::testGetJsonForChartingDataTimestamp` asserts `x` is a whole-number float within the call window; `MonitorControllerTest::testIndex` now asserts `server_time` renders as 13+ digits. CI runs on 64-bit, where the overflow cannot occur, so the tests pin the type/format/range rather than reproducing the 32-bit warning.

Fixes #20399

This contribution was prepared with AI assistance and reviewed by me.

- [x] Make sure you have read our CONTRIBUTING.md document.
- [x] Make sure you are making a pull request against the correct branch. (QA_5_2)
- [x] Every commit has proper `Signed-off-by` line
- [x] Every commit has a descriptive commit message.
- [x] Every commit is needed on its own
- [x] Any new functionality is covered by tests.

## Files Modified
- libraries/classes/Server/Status/Monitor.php (floor + import)
- libraries/classes/Controllers/Server/Status/MonitorController.php (floor + import)
- test/classes/Controllers/Server/Status/MonitorControllerTest.php (regex assertion)
- test/classes/Server/Status/MonitorTest.php (new)

## Open Questions
- None

## Next Steps
- None. HubLaunch to squash (drop plan commit) and open PR against QA_5_2 with the body above.
