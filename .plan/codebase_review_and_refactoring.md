# Codebase Review and Refactoring Plan
## AI Placement Module Generator - Quality Improvements

---

## Executive Summary

This document outlines a comprehensive review of the `aiplacement_modgen` plugin codebase, identifying problematic areas, inefficiencies, and technical debt. The plan maintains all existing functionality while improving code quality, consistency, and maintainability.

---

## 1. CRITICAL ISSUES

### 1.1 JavaScript Module Loading Architecture (CRITICAL)

**Problem**: Inconsistent AMD module patterns causing loading failures

**Current State**:
- `course_toolbar.js` uses ES6 `import/export` syntax
- Babel transpilation creates nested AMD `define()` causing RequireJS errors
- Manual bypass currently in place (copying source directly to build)
- lib.php line 71 has double-wrapped array parameter `[[config]]` instead of `[config]`

**Impact**:
- Module loading failures
- "Mismatched anonymous define()" errors
- Maintenance complexity

**Recommendation**:
```javascript
// CONVERT FROM (ES6):
import Fragment from 'core/fragment';
export const init = (config) => { ... }

// TO (Native AMD):
define(['core/fragment'], function(Fragment) {
    const init = (config) => { ... };
    return { init: init };
});
```

**Benefits**:
- No Babel transpilation needed
- Consistent with Moodle standards
- Matches existing modules (`embedded_prompt.js`, `suggest.js`)
- Eliminates build step issues

**Files to update**:
- `amd/src/course_toolbar.js` - Convert to native AMD
- `lib.php` line 71 - Fix `[[config]]` → `[config]`
- `Gruntfile.js` - Remove course_toolbar from Babel task (or keep for consistency)

---

### 1.2 Duplicate Suggest Activities Implementation (CRITICAL)

**Problem**: Two separate implementations of activity suggestion feature exist

**Current Implementations**:

1. **Working Implementation** (`ajax/suggest.php` + `amd/src/suggest.js`):
   - 470 lines of complex JavaScript
   - Full Laurillard learning types analysis
   - Chart.js integration for visualization
   - Section scanning and suggestion approval flow
   - AJAX create endpoint at `ajax/suggest_create.php`
   - Accessed via toolbar button using reactive modal system

2. **Abandoned Implementation** (rolled back):
   - `ajax/suggest_activities.php` (created then removed)
   - `classes/local/week_analyzer.php` (created then removed)
   - `amd/src/suggest_activities.js` (stub file)
   - Never fully integrated, caused errors

**Impact**:
- Code confusion
- Wasted implementation effort
- Staging area contains orphaned files

**Recommendation**:
- **Keep**: `suggest.js` and `suggest.php` (working implementation)
- **Remove**: All `suggest_activities` files from staging area
- **Document**: Existing suggest feature capabilities
- **Clean up**: Remove `suggest_activities.js` stub

---

## 2. CODE QUALITY ISSUES

### 2.1 Debugging Code Left in Production

**Problem**: Debug logging scattered throughout codebase

**Examples**:
```php
// lib.php lines 260, 267, 288
error_log('Fragment form_add_theme called with args: ...');
error_log("Fragment form_add_theme - courseid: $courseid");
error_log("Fragment form_add_theme - rendered successfully...");

// ajax/suggest.php line 100
file_put_contents('/tmp/modgen_suggest_template_reader_error.log', ...);

// classes/activitytype/registry.php lines 64-78
file_put_contents('/tmp/modgen_debug.log', "=== CREATE ACTIVITIES DEBUG ===\n", ...);
```

**Impact**:
- Performance overhead
- Disk I/O on every request
- Security concerns (exposing internal paths)
- Log file accumulation

**Recommendation**:
- Remove all `error_log()` calls from production code
- Remove all `file_put_contents()` debug logging
- Implement proper debugging framework:
  ```php
  if (defined('AIPLACEMENT_MODGEN_DEBUG')) {
      debugging('Message here', DEBUG_DEVELOPER);
  }
  ```

---

### 2.2 Inconsistent Error Handling

**Problem**: Mixed error handling approaches across AJAX endpoints

**Current Approaches**:
```php
// ajax/suggest.php - Complex try/catch with output buffering
@ob_start();
try { ... } catch (\Throwable $e) {
    $unwanted = @ob_get_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ajax/suggest_create.php - Similar pattern
@ob_start();

// ajax/create_sections.php - Basic error handling
// ajax/explore_ajax.php - Different pattern
```

**Impact**:
- Inconsistent error responses
- Difficult to debug
- Client-side code must handle different formats

**Recommendation**:
Create centralized AJAX response handler:
```php
// classes/local/ajax_response.php
class ajax_response {
    public static function success($data) {
        self::send(['success' => true, 'data' => $data]);
    }

    public static function error($message, $code = null) {
        self::send(['success' => false, 'error' => $message, 'code' => $code]);
    }

    private static function send($data) {
        @ob_clean();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
```

---

### 2.3 Class Namespace Inconsistency

**Problem**: `ai_service` class has inconsistent namespace detection

**Current Code** (`ajax/suggest.php` lines 17-32):
```php
$serviceClass = null;
if (class_exists('\\aiplacement_modgen\\ai_service')) {
    $serviceClass = '\\aiplacement_modgen\\ai_service';
} elseif (class_exists('\\aiplacement_modgen\\local\\ai_service')) {
    $serviceClass = '\\aiplacement_modgen\\local\\ai_service';
} else {
    $aisvcpath = __DIR__ . '/../classes/local/ai_service.php';
    if (file_exists($aisvcpath)) {
        require_once($aisvcpath);
        // ... more checks
    }
}
```

**Actual Namespace** (`classes/local/ai_service.php` line 26):
```php
namespace aiplacement_modgen;  // NOT aiplacement_modgen\local
```

**Impact**:
- Confusing code
- Unnecessary complexity
- Maintenance overhead

**Recommendation**:
```php
// FIX Option 1: Move class to correct namespace
namespace aiplacement_modgen\local;

// FIX Option 2: Use simple autoloading
use aiplacement_modgen\ai_service;
$service = new ai_service();
```

---

### 2.4 Template Structure Issues

**Problem**: Inconsistent modal width management in `suggest.js`

**Current Approach** (lines 19-37, 256-261, 320-329, 358-363):
- Manually adds/removes CSS classes
- Uses inline `style.setProperty('max-width', '1200px', 'important')`
- Multiple try/catch blocks for DOM manipulation
- Removes styles in multiple places

**Impact**:
- Fragile CSS manipulation
- Difficult to maintain
- Style leaks between modal instances

**Recommendation**:
```javascript
// Use CSS classes instead of inline styles
const $dialog = root.closest('.modal-dialog');
$dialog.addClass('suggest-wide-modal');  // Add
$dialog.removeClass('suggest-wide-modal');  // Remove

// In CSS file:
.suggest-wide-modal {
    max-width: 1200px !important;
}
```

---

## 3. ARCHITECTURE IMPROVEMENTS

### 3.1 Build System Simplification

**Current State**:
- Babel transpiles AMD to AMD (unnecessary)
- Grunt runs on all JS files
- Manual copy workaround for `course_toolbar.js`
- Inconsistent module patterns

**Recommendation**:
**Option A**: Native AMD for all modules (recommended)
- Remove Babel dependency
- Write all JS in AMD format
- Simple uglify minification only
- Faster builds, simpler pipeline

**Option B**: ES6 for all modules
- Fix Babel configuration
- Ensure proper AMD output
- All modules use same pattern
- More modern syntax

**Preferred**: Option A - Matches Moodle core patterns

---

### 3.2 Fragment API Consistency

**Problem**: Mixed approaches to fragment output

**Current Patterns**:
```php
// lib.php line 238 - Uses core renderer
return $PAGE->get_renderer('core')->render_from_template(...);

// lib.php line 140 - Uses plugin renderer
$renderer = $PAGE->get_renderer('aiplacement_modgen');
return $renderer->render($toolbar);

// lib.php line 287 - Uses moodleform
return $form->render();
```

**Recommendation**:
Standardize on renderable pattern:
```php
// All fragments should:
1. Validate parameters
2. Check permissions
3. Create renderable object
4. Use plugin renderer
5. Return rendered HTML

function aiplacement_modgen_output_fragment_X($args) {
    $courseid = clean_param($args['courseid'], PARAM_INT);
    $context = context_course::instance($courseid);
    require_capability('moodle/course:update', $context);

    $renderable = new \aiplacement_modgen\output\X($courseid);
    $renderer = $PAGE->get_renderer('aiplacement_modgen');
    return $renderer->render($renderable);
}
```

---

### 3.3 Settings Configuration Issues

**Problem**: Repetitive settings checks

**Current Pattern** (lib.php lines 56-60, 83-84):
```php
// Lines 56-60
$ai_generation_enabled = !empty(get_config('aiplacement_modgen', 'enable_ai'));
$explore_enabled = !empty(get_config('aiplacement_modgen', 'enable_exploration'));
$suggest_enabled = !empty(get_config('aiplacement_modgen', 'enable_suggest'));
$showexplore = $ai_generation_enabled && $explore_enabled;
$showsuggest = $ai_generation_enabled && $suggest_enabled;

// Lines 83-84 - DUPLICATE checks
$ai_generation_enabled = !empty(get_config('aiplacement_modgen', 'enable_ai'));
$explore_enabled = !empty(get_config('aiplacement_modgen', 'enable_exploration'));
```

**Impact**:
- Code duplication
- Multiple database queries
- Maintenance burden

**Recommendation**:
```php
// classes/local/settings_helper.php
class settings_helper {
    private static $cache = [];

    public static function is_ai_enabled(): bool {
        return self::get('enable_ai', false);
    }

    public static function is_explore_enabled(): bool {
        return self::is_ai_enabled() && self::get('enable_exploration', false);
    }

    public static function is_suggest_enabled(): bool {
        return self::is_ai_enabled() && self::get('enable_suggest', false);
    }

    private static function get(string $name, $default) {
        if (!isset(self::$cache[$name])) {
            self::$cache[$name] = get_config('aiplacement_modgen', $name);
        }
        return !empty(self::$cache[$name]) ? self::$cache[$name] : $default;
    }
}

// Usage:
use aiplacement_modgen\local\settings_helper;
$showexplore = settings_helper::is_explore_enabled();
$showsuggest = settings_helper::is_suggest_enabled();
```

---

## 4. CODE ORGANIZATION

### 4.1 File Structure Clarity

**Current Issues**:
```
ajax/
  suggest.php          (279 lines - main suggest endpoint)
  suggest_create.php   (activity creation)
  suggest_activities.php (REMOVED - was duplicate)

amd/src/
  suggest.js           (470 lines - complex suggest UI)
  suggest_activities.js (empty stub - should remove)

classes/local/
  week_analyzer.php    (REMOVED - was for abandoned feature)
```

**Recommendation**:
Remove orphaned files:
```bash
rm amd/src/suggest_activities.js
```

---

### 4.2 Magic Numbers and Hard-coded Values

**Problem**: Hard-coded values throughout codebase

**Examples**:
```javascript
// suggest.js line 324
this.style.setProperty('max-width', '1200px', 'important');

// suggest.js line 338
setTimeout(() => { ... }, 150);  // Why 150ms?

// ai_service.php line 66
if ($count >= 2 && $count <= 20) {  // Why 2-20?
```

**Recommendation**:
```javascript
// Define constants
const MODAL_WIDTHS = {
    NORMAL: '600px',
    WIDE: '900px',
    EXTRA_WIDE: '1200px'
};

const DEBOUNCE_DELAY = 150; // milliseconds

// Use constants
this.style.setProperty('max-width', MODAL_WIDTHS.EXTRA_WIDE, 'important');
setTimeout(() => { ... }, DEBOUNCE_DELAY);
```

---

## 5. SECURITY IMPROVEMENTS

### 5.1 Session Key Validation Inconsistency

**Current State**:
```php
// ajax/suggest.php (line 60)
if (!confirm_sesskey($sesskey)) {
    throw new \moodle_exception('invalidsesskey', 'error');
}

// ajax/suggest_create.php (line 56)
if (!confirm_sesskey($sesskey)) {
    throw new \moodle_exception('invalidsesskey', 'error');
}

// ajax/create_sections.php - No sesskey validation
// ajax/explore_ajax.php - No sesskey validation
```

**Recommendation**:
Ensure ALL AJAX endpoints validate sesskey:
```php
// Standard pattern for all AJAX files
$sesskey = required_param('sesskey', PARAM_RAW);
require_sesskey();  // Validates against session
```

---

### 5.2 Input Sanitization Gaps

**Problem**: Inconsistent parameter cleaning

**Example** (`ajax/suggest_create.php` lines 57-61):
```php
$courseid = required_param('courseid', PARAM_INT);
$section = required_param('section', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_RAW);
$selected = required_param('selected', PARAM_RAW);  // JSON string - not sanitized
$selectedData = json_decode($selected, true);  // Direct use without validation
```

**Recommendation**:
```php
$selected_raw = required_param('selected', PARAM_RAW);
$selected_data = json_decode($selected_raw, true);

if (!is_array($selected_data)) {
    throw new \moodle_exception('invalidjson', 'aiplacement_modgen');
}

// Validate each activity in the array
foreach ($selected_data as $activity) {
    if (!isset($activity->type) || !isset($activity->activity)) {
        throw new \moodle_exception('invalidactivity', 'aiplacement_modgen');
    }
    // Clean activity type
    $activity->type = clean_param($activity->type, PARAM_ALPHANUMEXT);
}
```

---

## 6. PERFORMANCE OPTIMIZATIONS

### 6.1 Redundant Database Queries

**Problem**: `lib.php` queries settings multiple times

**Impact**:
- 2-4 database queries per page load
- Settings fetched but not cached

**Recommendation**: Use settings_helper (see 3.3)

---

### 6.2 Template Reader Performance

**Problem** (`ajax/suggest.php` lines 84-100):
```php
if ($templatereaderavailable) {
    try {
        $reader = new $classname();
        $template = $reader->extract_curriculum_template($courseid . '|' . $section);
        // ... process template
    } catch (\Throwable $e) {
        // Fall back to modinfo
        file_put_contents('/tmp/modgen_suggest_template_reader_error.log', ...);
    }
}
```

**Issues**:
- Creates new instance every request
- Falls back but logs to file (I/O overhead)
- Error swallowed silently

**Recommendation**:
```php
// Cache the reader instance
static $reader = null;
if ($reader === null) {
    $reader = new template_reader();
}

try {
    $template = $reader->extract_curriculum_template($courseid . '|' . $section);
} catch (\Throwable $e) {
    debugging('Template reader failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    // Fall back to modinfo
}
```

---

## 7. DOCUMENTATION GAPS

### 7.1 Missing PHPDoc

**Problem**: Many methods lack proper documentation

**Examples**:
```php
// lib.php line 187 - Undefined $draftitemid variable
$formdata = [
    'supportingfiles' => $draftitemid,  // Where does this come from?
];
```

**Recommendation**:
Add comprehensive PHPDoc to all public methods:
```php
/**
 * Fragment callback to render the generator form in a modal.
 *
 * Initializes file manager with proper draft area for file uploads.
 * Users must accept AI policy before accessing the form.
 *
 * @param array $args Fragment arguments
 *   - courseid (int): The course ID
 * @return string Rendered form HTML or policy acceptance template
 * @throws moodle_exception If user lacks course:update capability
 */
function aiplacement_modgen_output_fragment_generator_form(array $args): string {
    ...
}
```

---

### 7.2 Inline Comments for Complex Logic

**Problem**: Complex algorithms lack explanation

**Example** (`suggest.js` lines 169-191):
```javascript
// Map common activity types to Laurillard types
const mapping = {
    'page': 'acquisition', 'book': 'acquisition',
    'forum': 'discussion', 'chat': 'discussion',
    // ... more mappings
};
```

**Why these mappings?** No explanation.

**Recommendation**:
```javascript
/**
 * Map Moodle activity types to Laurillard's Conversational Framework learning types.
 *
 * Acquisition: Passive content consumption (reading, watching)
 * Discussion: Two-way communication (forums, chat)
 * Inquiry: Student-led investigation (choice, survey)
 * Practice: Repeated exercises with feedback (lesson)
 * Production: Creating artifacts (assignment, quiz)
 * Collaboration: Group work activities (workshop, wiki)
 *
 * Reference: Laurillard, D. (2012). Teaching as a Design Science
 */
const ACTIVITY_TO_LAURILLARD_MAP = { ... };
```

---

## 8. TESTING GAPS

### 8.1 No Unit Tests

**Current State**: Zero automated tests

**Impact**:
- Regression risks
- Difficult to refactor safely
- No CI/CD pipeline

**Recommendation**:
Create test structure:
```
tests/
  ajax/
    suggest_test.php
  classes/
    activitytype/
      registry_test.php
    local/
      ai_service_test.php
      template_reader_test.php
```

---

## 9. PROPOSED REFACTORING PHASES

### Phase 1: Critical Fixes (High Priority)
**Estimated effort**: 2-3 hours

1. Fix `course_toolbar.js` AMD module pattern
2. Fix `lib.php` line 71 double-wrapped array
3. Remove debug logging from all files
4. Remove orphaned `suggest_activities.js` file
5. Test suggest feature end-to-end

**Success Criteria**:
- No JavaScript errors in console
- Suggest feature works correctly
- No file I/O debug logging

---

### Phase 2: Code Quality (Medium Priority)
**Estimated effort**: 4-5 hours

1. Create `ajax_response.php` helper class
2. Update all AJAX endpoints to use centralized error handling
3. Fix `ai_service` namespace confusion
4. Create `settings_helper.php`
5. Replace all settings checks with helper methods
6. Add comprehensive PHPDoc to all public methods

**Success Criteria**:
- Consistent error responses across all AJAX endpoints
- Single source of truth for settings
- All public APIs documented

---

### Phase 3: Architecture Improvements (Medium Priority)
**Estimated effort**: 6-8 hours

1. Standardize all fragment callbacks on renderable pattern
2. Convert all JavaScript to native AMD (or all to ES6)
3. Create constants file for magic numbers
4. Refactor suggest.js modal width management to use CSS classes
5. Add sesskey validation to all AJAX endpoints

**Success Criteria**:
- Consistent code patterns
- Simplified build process
- Improved security

---

### Phase 4: Performance & Testing (Lower Priority)
**Estimated effort**: 8-10 hours

1. Implement settings caching
2. Optimize template reader instantiation
3. Create unit test framework
4. Write tests for critical paths
5. Document complex algorithms

**Success Criteria**:
- Reduced database queries
- Automated test coverage >60%
- Code maintainability improved

---

## 10. RISKS AND MITIGATION

### Risk 1: Breaking Existing Functionality
**Mitigation**:
- Implement changes incrementally
- Test each change thoroughly
- Keep rollback plan ready
- Use feature flags for major changes

### Risk 2: Build System Changes
**Mitigation**:
- Test on development environment first
- Document build process changes
- Keep Babel as fallback option

### Risk 3: Database Query Performance
**Mitigation**:
- Monitor query counts before/after
- Use Moodle's debugging tools
- Implement caching carefully

---

## 11. SUMMARY OF FILES REQUIRING CHANGES

### High Priority:
- `amd/src/course_toolbar.js` - Convert to AMD
- `lib.php` - Fix parameter wrapping, remove debug logs
- `ajax/suggest.php` - Remove debug logging
- `classes/activitytype/registry.php` - Remove debug logging
- `amd/src/suggest_activities.js` - DELETE (orphaned)

### Medium Priority:
- Create `classes/local/ajax_response.php` - New helper
- Create `classes/local/settings_helper.php` - New helper
- `ajax/create_sections.php` - Add sesskey validation
- `ajax/explore_ajax.php` - Add sesskey validation
- `ajax/suggest_create.php` - Improve input validation
- `classes/local/ai_service.php` - Fix namespace or usage

### Lower Priority:
- All fragment callbacks in `lib.php` - Standardize patterns
- `amd/src/suggest.js` - Refactor modal width management
- `Gruntfile.js` - Simplify build process
- Create test files

---

## 12. CONCLUSION

The codebase is functional but has accumulated technical debt that should be addressed to ensure long-term maintainability. The most critical issues are:

1. **JavaScript module loading** - Causes runtime errors
2. **Debug logging in production** - Performance and security impact
3. **Duplicate implementations** - Code confusion and wasted effort

By following the phased approach, these issues can be resolved while maintaining all existing functionality. The refactoring will result in:

- More maintainable code
- Better performance
- Improved security
- Easier onboarding for new developers
- Reduced technical debt

**Recommended Next Steps**:
1. Review this plan with stakeholders
2. Prioritize phases based on business needs
3. Begin Phase 1 implementation
4. Establish testing procedures
5. Document changes as they're made
