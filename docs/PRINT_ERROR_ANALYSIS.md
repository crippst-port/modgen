# print_error() Analysis Report

## Issue
User reports: `Call to undefined function print_error()` when trying to add 'template from file'.

## Summary
Found **2 occurrences** of `print_error()` calls. All are in `prompt.php` which IS properly initialized with Moodle. However, the "template from file" feature flow likely involves the file upload processing pipeline, which needs investigation.

---

## 1. All print_error() Calls Found

| File | Line | Context | Status |
|------|------|---------|--------|
| `prompt.php` | 39 | Missing course ID check | ✅ Properly initialized |
| `prompt.php` | 549 | AI policy not accepted check | ✅ Properly initialized |

### Details

#### prompt.php:39
```php
if (!$courseid) {
    print_error('missingcourseid', 'aiplacement_modgen');
}
```
- **Location**: Early in execution, immediately after `require_once(__DIR__ . '/../../../config.php')`
- **Initialization**: ✅ **CORRECT** - config.php is required before this line
- **Risk Level**: None

#### prompt.php:549
```php
// For regular requests, show error.
print_error('aipolicynotaccepted', 'aiplacement_modgen');
```
- **Location**: Inside policy check logic (~line 520-560)
- **Initialization**: ✅ **CORRECT** - Proper Moodle initialization and $PAGE setup already complete by this point
- **Risk Level**: None

---

## 2. AJAX File Initialization Analysis

All AJAX handlers properly initialize Moodle BEFORE processing:

### ✅ Good Patterns Found

**ajax/suggest_create.php** (Lines 1-25)
```php
$configpath = __DIR__ . '/../../../../config.php';
if (!file_exists($configpath)) {
    @header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'config.php not found']);
    exit(0);
}
require_once($configpath);
require_once(__DIR__ . '/../lib.php');
defined('MOODLE_INTERNAL') || die();
```
✅ Proper error handling before requiring config.php

**ajax/explore_ajax.php** (Lines 27-48)
```php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
$courseid = required_param('courseid', PARAM_INT);
```
✅ Sets AJAX_SCRIPT constant first, then requires config.php

**ajax/create_sections.php** (Lines 24-34)
```php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_login();
require_sesskey();
```
✅ Proper initialization order

**ajax/download_report_pdf.php** (Lines 24-38)
```php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
```
✅ Proper initialization

**ajax/suggest.php** (Lines 1-40)
```php
$configpath = __DIR__ . '/../../../../config.php';
if (!file_exists($configpath)) {
    @header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'config.php not found']);
    exit(0);
}
require_once($configpath);
defined('MOODLE_INTERNAL') || die();
```
✅ Proper file existence check before requiring

---

## 3. File Processing Classes Analysis

### classes/local/filehandler/file_processor.php
**Status**: ✅ **PROPERLY INITIALIZED**
- Namespace: `aiplacement_modgen\local\filehandler\file_processor`
- No direct `print_error()` calls found in this class
- Has try-catch blocks (lines 133, 163, 196, 228, 240, 272, 304, 345) but they log/return gracefully, not call `print_error()`
- File inclusion point: `prompt.php:570` - BEFORE any user-facing operations
- Class is instantiated in `prompt.php` context where Moodle is fully initialized

### classes/form/upload_form.php
**Status**: ✅ **PROPERLY INITIALIZED**
- Extends `moodleform` (line 36)
- Requires: `defined('MOODLE_INTERNAL') || die()` (line 26)
- Requires: `require_once($CFG->libdir . '/formslib.php')` (line 27)
- Form is instantiated in `prompt.php` context where Moodle is fully initialized
- No `print_error()` calls in the form itself

---

## 4. Template from File Feature Flow

The "template from file" feature has this call chain:

```
prompt.php (main entry point)
    ├── config.php required (line 29) ✅
    ├── require_login() called (line 30) ✅
    ├── form classes required (lines 46-47) ✅
    ├── file_processor.php required (line 570) ✅
    │
    └── File upload handling (lines 1500+)
        ├── $_FILES handling (line 1563)
        ├── filemanager draft area (line 1691)
        └── content extraction (ZipArchive, etc.)
```

**All initialization is done in prompt.php context BEFORE file handling occurs.**

---

## 5. Potential Issues (Not print_error related)

While all `print_error()` calls are properly initialized, the file upload flow could fail due to:

1. **Missing ZipArchive for .docx/.odt extraction** - Lines 1690+ use ZipArchive class
2. **Missing temporary directory permissions** - Lines 1630, 1694 use `sys_get_temp_dir()`
3. **Missing pdftotext system command** - Line 1675 uses shell_exec for PDF extraction
4. **File size limits** - No explicit file size validation before processing
5. **Encoding issues** - Content is treated as strings but not validated for encoding

---

## 6. Exception Handling in Classes

### file_processor.php Try-Catch Blocks (8 total)
All properly handle exceptions:
- Line 133: `try` for Moodle conversion API
- Line 163: Catches `\Exception` and logs
- Line 196: `try` for direct conversion
- Line 228: Catches `\Exception` and logs
- Line 240: `try` for text extraction
- Line 272: Catches `\Exception` and logs
- Line 304: `try` for zip extraction
- Line 345: Catches `\Exception` and logs

**None call `print_error()` in catch blocks** - they return error arrays instead.

---

## Diagnosis: Where is "Call to undefined function print_error()" Coming From?

### Possible Causes (in order of likelihood):

1. **Class Instantiation Outside Moodle Context** ⚠️
   - If `file_processor` or upload form is instantiated in an external script that didn't properly include config.php
   - Check: Is there an AJAX handler specifically for file uploads that might be missing initialization?

2. **Late Static Binding Issue**
   - Some method being called statically from a class before Moodle is initialized
   - Would show as "Call to undefined function" rather than "Cannot access protected/private method"

3. **Autoloader Timing Issue**
   - PSR-4 autoloader triggering a static method from a class before config.php is required
   - Most likely if `print_error()` is called during class definition or static initialization

4. **Missing AJAX Handler** ⚠️
   - The file upload might be handled by an AJAX endpoint that doesn't have proper Moodle initialization
   - Current AJAX files all have proper initialization, but check if there's a separate handler

5. **Conditional Class Loading**
   - If a class with a static property initializer is loaded conditionally before Moodle init
   - Rare but possible with plugin architecture

---

## Recommended Next Steps

1. **Check for hidden AJAX handlers** in `/ajax/` directory
   - Verify all have `require_once(__DIR__ . '/../../../../config.php')` FIRST
   - Verify all have `defined('MOODLE_INTERNAL') || die()` AFTER config.php

2. **Search for static initializers** in classes
   - Look for `static function __construct()` or static property initialization
   - These run during class definition, before any script initialization

3. **Check JavaScript-triggered endpoints**
   - The browser Network tab will show what AJAX URL is being hit
   - Verify that endpoint exists and has proper initialization

4. **Enable Moodle debugging**
   - Set `$CFG->debug = DEBUG_ALL` in config.php
   - Get full backtrace of where `print_error()` call originated
   - Check `/tmp/modgen_debug.log` if it exists

---

## Files Checked

✅ Verified proper initialization:
- `prompt.php` (main entry point)
- `ajax/suggest_create.php`
- `ajax/explore_ajax.php`
- `ajax/create_sections.php`
- `ajax/download_report_pdf.php`
- `ajax/suggest.php`
- `classes/local/filehandler/file_processor.php`
- `classes/form/upload_form.php`

**No files found missing Moodle initialization for print_error() calls.**
