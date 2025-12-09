# Layout Detection and Smart Date Application

## Overview

The Module Generator now intelligently detects course layout types and applies dates **only to weeks**, never to themes, regardless of the course structure.

## Layout Types

### 1. Theme-Based Layout (3 levels)
**Structure:** Theme → Week → Session

**Example:**
```
📂 Cats (theme)
  📅 Week 1: Harlow (week)
      🔹 Pre-session
      🔹 Session
      🔹 Post-session
```

**Date Application:**
- ✅ Weeks receive dates
- ❌ Themes do NOT receive dates (they're containers)
- Themes appear in the form but with empty date fields

### 2. Week-Based Layout (2 levels)
**Structure:** Theme (treated as week) → Session

**Example:**
```
📂 Week 1 Theme (acts as a week)
  🔹 Pre-session
  🔹 Session
  🔹 Post-session
```

**Date Application:**
- ✅ Top-level sections (themes) receive dates
- They're marked as `is_parent=true` but still get dates
- This layout treats themes AS weeks

### 3. Flat Layout (1 level)
**Structure:** Standalone weeks/topics

**Example:**
```
📅 Week 1
📅 Week 2
📅 Week 3
```

**Date Application:**
- ✅ All top-level sections receive dates
- No hierarchy, simple list structure

## Detection Method

The system uses `date_calculator::detect_course_layout($courseid)` which analyzes:

1. **Parent-child relationships** from flexsections format
2. **Session names** (Pre-session, Session, Post-session)
3. **Hierarchy depth** (3-level, 2-level, or flat)

Returns:
```php
[
    'type' => 'theme_based|week_based|flat',
    'description' => 'Human-readable description',
    'details' => [
        'has_themes' => bool,
        'has_weeks_under_themes' => bool,
        'top_level_sections' => int,
        'hierarchy_levels' => 1|2|3
    ]
]
```

## Date Calculation Logic

The `calculate_section_dates()` method now:

1. **Detects layout type** using `detect_course_layout()`
2. **Applies dates based on type:**
   - Theme-based: Only weeks (child sections)
   - Week-based: Top-level sections (themes acting as weeks)
   - Flat: All top-level sections
3. **Never applies dates to themes** in theme-based layouts

### Code Example

```php
switch ($layout['type']) {
    case 'theme_based':
        // Only weeks (sections with parents that aren't sessions) get dates
        if ($hasparent && !$issession) {
            $shouldgetdates = true;
        }
        break;

    case 'week_based':
        // Top-level sections (themes treated as weeks) get dates
        if ($istoplevel && $isparent) {
            $shouldgetdates = true;
        }
        break;

    case 'flat':
        // All top-level sections get dates
        if ($istoplevel) {
            $shouldgetdates = true;
        }
        break;
}
```

## Testing Tools

### 1. Layout Detection Test
```bash
php docs/test_layout_detection.php <courseid>
```

Shows:
- Layout type and description
- Section structure with visual markers
- Hierarchy details

### 2. Date Application Test
```bash
php docs/test_layout_dates.php <courseid>
```

Shows:
- Which sections receive dates
- Which sections appear without dates
- Validation checks for correct behavior

### 3. Demo All Layout Types
```bash
php docs/demo_layout_types.php
```

Creates test courses for all three layout types and shows detection results.

## Test Results

### Theme-Based (Course 4)
✅ **PASSED**
- 6 weeks with dates
- 4 themes without dates
- Themes appear in form but won't be updated

### Week-Based (Course 27)
✅ **PASSED**
- 1 theme (acting as week) with dates
- Marked as `is_parent=true` for display

### Flat (Course 28)
✅ **PASSED**
- 3 standalone weeks with dates
- No hierarchy

## Form Behavior

The dates form (`dates_for_sections_form.php`) now:

1. Shows ALL eligible sections (including themes in theme-based layouts)
2. Sections with dates show **Current** vs **Proposed** dates
3. Themes in theme-based layouts show in list but have no proposed dates
4. User can uncheck any section to exclude from update

## User Experience

**Before:**
- Dates applied inconsistently based on structure
- Themes sometimes got dates when they shouldn't
- Unclear which sections would be updated

**After:**
- Smart detection of course structure
- Dates only on weeks, never on themes (in theme-based)
- Clear preview showing exactly what will be updated
- Layout-aware, consistent behavior

## API Changes

### New Method
```php
\aiplacement_modgen\local\date_calculator::detect_course_layout($courseid)
```

### Enhanced Method
```php
\aiplacement_modgen\local\date_calculator::calculate_section_dates(
    $courseid, 
    $excludedsectionids = [], 
    $includeparents = false  // Now ignored for theme-based layouts
)
```

Returns array with additional `layout_type` field:
```php
[
    'id' => int,
    'section' => int,
    'name' => string,
    'formatted_date' => string,  // Empty for themes in theme-based
    'week_number' => int|null,
    'is_parent' => bool,
    'start_timestamp' => int,
    'end_timestamp' => int,
    'parent_id' => int,
    'layout_type' => 'theme_based|week_based|flat'
]
```

## Files Modified

1. `/classes/local/date_calculator.php`
   - Added `detect_course_layout()` method (lines 33-141)
   - Updated `calculate_section_dates()` to use layout detection (lines 143-330)
   - Removed old complex detection logic
   - Simplified with layout-aware switch statement

2. `/docs/test_layout_detection.php` (NEW)
   - CLI tool to test layout detection

3. `/docs/test_layout_dates.php` (NEW)
   - CLI tool to validate date application

4. `/docs/demo_layout_types.php` (NEW)
   - Creates test courses for all layout types

## Migration Notes

**No breaking changes** - existing code continues to work:
- `calculate_section_dates()` signature unchanged
- Return structure enhanced (added `layout_type` field)
- Forms automatically benefit from improved logic
- No database changes required

## Future Enhancements

Potential improvements:
1. Add layout type indicator in the form UI
2. Allow users to override detected layout type
3. Support mixed layouts (some themes with weeks, some without)
4. Add layout conversion tools

## Summary

The layout detection system provides:
✅ Automatic detection of course structure
✅ Smart date application (weeks only, never themes)
✅ Consistent behavior across all layout types
✅ Clear validation and testing tools
✅ No breaking changes to existing code
