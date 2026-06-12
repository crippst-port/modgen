# Codebase Review and Refactoring Plan
## AI Placement Module Generator - Quality Improvements

---

## Status Key
- ✅ **DONE** — resolved in codebase
- ⚠️ **PARTIAL** — started but incomplete
- ❌ **OUTSTANDING** — still needed

Last reviewed against branch: `feature/cleanup-polish` (2026-04-22)

---

## Executive Summary

This document outlines a comprehensive review of the `aiplacement_modgen` plugin codebase, identifying problematic areas, inefficiencies, and technical debt. The plan maintains all existing functionality while improving code quality, consistency, and maintainability.

---

## 1. CRITICAL ISSUES

### 1.1 JavaScript Module Loading Architecture ❌ OUTSTANDING (partial)

**Problem**: Inconsistent AMD module patterns causing loading failures

**Current State** ✅ DONE — No action needed:
- `course_toolbar.js` uses ES6 `import/export` — this **is** Moodle standard practice. Grunt/Babel transpiles it to AMD correctly. The build file is clean with no nested `define()` issues.
- `lib.php` `[[config]]` double-wrapping — already fixed ✅
- No build workaround in effect; Grunt transpilation works correctly for this file.

**Conclusion**: Converting to "pure/native AMD" would be going backwards. ES6 + Grunt is the recommended modern Moodle pattern. All other modules in this plugin also use ES6 imports.

---

### 1.2 Duplicate Suggest Activities Implementation ✅ DONE

**Problem**: Two separate implementations of activity suggestion feature exist

**Current Implementations**:

1. **Working Implementation** (`ajax/suggest.php` + `amd/src/suggest.js`):
   - Full Laurillard learning types analysis
   - Chart.js integration for visualization
   - Section scanning and suggestion approval flow
   - AJAX create endpoint at `ajax/suggest_create.php`
   - Accessed via toolbar button using reactive modal system

2. **Abandoned Implementation** — all removed ✅:
   - `ajax/suggest_activities.php` — deleted
   - `classes/local/week_analyzer.php` — deleted
   - `amd/src/suggest_activities.js` — deleted

---

## 2. CODE QUALITY ISSUES

### 2.1 Debugging Code Left in Production ✅ DONE

All `error_log()` and `file_put_contents()` debug calls have been removed from production code.

---

### 2.2 Inconsistent Error Handling ✅ DONE

`classes/local/ajax_response.php` now exists and is used across all AJAX endpoints. `suggest.php` and `suggest_create.php` both use `ajax_response::error()` / `ajax_response::success()`. `explore_ajax.php` was deleted with the explore feature.

---

### 2.3 Class Namespace Inconsistency ❌ OUTSTANDING

**Problem**: `ai_service` class has inconsistent namespace detection

**Current Code** (`ajax/suggest.php` ~line 42): still contains 3-way namespace detection block:
```php
$serviceClass = null;
if (class_exists('\\aiplacement_modgen\\ai_service')) { ... }
elseif (class_exists('\\aiplacement_modgen\\local\\ai_service')) { ... }
else { require_once + check again }
```

**Actual Namespace** (`classes/local/ai_service.php` line 26):
```php
namespace aiplacement_modgen;  // NOT aiplacement_modgen\local
```

**Fix**: Either move class to `aiplacement_modgen\local` namespace (preferred — matches file location) or simplify `suggest.php` to `use \aiplacement_modgen\ai_service;` directly.

---

### 2.4 Template Structure Issues ✅ DONE

`suggest.js` now uses CSS class `suggest-wide-modal` add/remove rather than inline `setProperty`. No inline `1200px` style manipulation remains.

---

## 3. ARCHITECTURE IMPROVEMENTS

### 3.1 Build System Simplification ✅ DONE

`course_toolbar.js` transpiles correctly via Grunt/Babel — no manual copy workaround needed. All modules use ES6 consistently.

---

### 3.2 Fragment API Consistency ⚠️ LOW PRIORITY

Mixed renderer approaches in `lib.php` fragments (core renderer, plugin renderer, raw form render). Not causing bugs but inconsistent. Lower priority than 1.1 / 2.3.

---

### 3.3 Settings Configuration Issues ✅ DONE

`classes/local/settings_helper.php` exists with `is_ai_enabled()` and `is_suggest_enabled()`. `lib.php` now uses `settings_helper` for both checks (lines 61, 63). The `is_explore_enabled()` method is no longer needed (explore feature removed).

---

## 4. CODE ORGANIZATION

## 4. CODE ORGANIZATION

### 4.1 File Structure Clarity ✅ DONE

All orphaned files removed. Also note: `amd/src/job_poller.js` exists with no callers and no build artifact — **this should also be deleted** (newly identified).

---

### 4.2 Magic Numbers and Hard-coded Values ✅ DONE (partially)

The inline `1200px` style manipulation in `suggest.js` is resolved (CSS class approach). Remaining `setTimeout` delays are acceptable operational values, not true magic numbers.

---

## 5. SECURITY IMPROVEMENTS

### 5.1 Session Key Validation ✅ DONE

- `ajax/create_sections.php` — has `require_sesskey()` ✅
- `ajax/explore_ajax.php` — deleted ✅
- `ajax/suggest.php` and `suggest_create.php` — validate sesskey ✅

---

### 5.2 Input Sanitization ✅ DONE

`ajax/suggest_create.php` validates JSON decode returns an array and normalizes each activity before passing to the registry.

---

## 6. PERFORMANCE OPTIMIZATIONS

### 6.1 Redundant Database Queries ✅ DONE

`settings_helper.php` caches settings in a static property. `lib.php` uses it.

---

### 6.2 Template Reader Performance ✅ DONE (debug log removed)

The `file_put_contents` debug log on template reader failure has been removed. The instance-per-request concern is low priority — AJAX endpoints are short-lived.

---

## 7. DOCUMENTATION GAPS

### 7.1 Missing PHPDoc ⚠️ LOW PRIORITY

Fragment callbacks in `lib.php` lack comprehensive PHPDoc. Not blocking but worth a pass when touching those functions.

---

### 7.2 Inline Comments for Complex Logic ⚠️ LOW PRIORITY

`suggest.js` Laurillard mapping still lacks a reference comment. Suggest adding one next time the file is touched.

---

## 8. TESTING GAPS

### 8.1 No Unit Tests ⚠️ LOW PRIORITY

Zero automated tests. Not blocking but increases regression risk. A PHPUnit test for `csv_parser` would be a good starting point given it's a pure parsing class.

---

## 9. OUTSTANDING ITEMS SUMMARY

Three things remain from the original review:

| # | Item | File(s) | Priority |
|---|------|---------|----------|
| 1 | ~~Convert `course_toolbar.js` to native AMD~~ — not needed; ES6 + Grunt is Moodle standard | — | ✅ N/A |
| 2 | Simplify namespace detection in suggest.php | `ajax/suggest.php` | ✅ DONE |
| 3 | Delete orphaned `job_poller.js` *(newly identified)* | `amd/src/job_poller.js` | ✅ DONE |

### Newly identified (not in original review):
- `amd/src/job_poller.js` — has no callers and no build artifact → dead code, delete

---

## 10. RISKS AND MITIGATION

### Risk 1: course_toolbar AMD conversion ✅ RESOLVED
No conversion needed — ES6 is Moodle standard. Grunt transpilation produces correct AMD output.

---

## 11. SUMMARY OF FILES REQUIRING CHANGES

### Still outstanding:
- *(none — all items resolved)*

### Already done:
- `lib.php` — debug logs removed, settings_helper used, param wrapping correct ✅
- `ajax/suggest.php` — debug logs removed, ajax_response used ✅
- `ajax/suggest_create.php` — sesskey validation, input sanitization ✅
- `ajax/create_sections.php` — sesskey validation ✅
- `amd/src/suggest_activities.js` — deleted ✅
- `amd/src/suggest.js` — CSS class modal width approach ✅
- `classes/local/ajax_response.php` — created and in use ✅
- `classes/local/settings_helper.php` — created and in use ✅
- All debug logging removed codebase-wide ✅
- Explore feature (explore.php, explore_ajax.php, explore.js, explore_cache.php) — removed ✅
