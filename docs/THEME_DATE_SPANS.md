# Theme Date Spans Feature

## Overview

The "Dates for Sections" form now supports applying date spans to theme sections in theme-based course layouts. Themes show the full span of dates from their first week to their last week.

## How It Works

### Layout Detection

The system automatically detects the course layout type:
- **Theme-based** (3 levels): Theme → Week → Session
- **Week-based** (2 levels): Theme (as week) → Session  
- **Flat** (1 level): Standalone weeks

### Date Span Calculation

For theme-based layouts:
1. **Weeks** get individual week dates (e.g., "Oct 20–26:")
2. **Themes** get date spans covering all their child weeks (e.g., "Oct 27–Nov 9:")

Example:
```
📂 Pigs (theme)          → Oct 27–Nov 9:
  📅 Week 2: Eating...   → Oct 27–Nov 2:
  📅 Week 3: Billy       → Nov 3–9:
```

The theme "Pigs" spans from the start of Week 2 to the end of Week 3.

## User Interface

### Visual Separation

The form now displays two separate tables:

**Themed Sections**
- Shows all theme sections
- Each theme displays its date span
- "Select all themes" checkbox in header

**Week Sections**  
- Shows all week sections
- Each week displays its individual week dates
- "Select all weeks" checkbox in header

### User Control

Users can:
- ✅ Check themes to apply date spans to theme names
- ✅ Check weeks to apply dates to week names
- ✅ Uncheck any section to skip it
- ✅ Use "Select all" for themes or weeks separately

## Example Usage

### Before Applying Dates

```
Theme: Cats
  Week 1: Harlow
  Week 2: Felix

Theme: Dogs  
  Week 3: Buddy
  Week 4: Max
```

### After Applying Dates (All Checked)

```
Theme: Oct 20–Nov 2: Cats
  Week 1: Oct 20–26: Harlow
  Week 2: Oct 27–Nov 2: Felix

Theme: Nov 3–16: Dogs
  Week 3: Nov 3–9: Buddy
  Week 4: Nov 10–16: Max
```

### After Applying Dates (Only Weeks Checked)

```
Theme: Cats
  Week 1: Oct 20–26: Harlow
  Week 2: Oct 27–Nov 2: Felix

Theme: Dogs
  Week 3: Nov 3–9: Buddy
  Week 4: Nov 10–16: Max
```

## Technical Details

### Date Calculator Changes

**File:** `classes/local/date_calculator.php`

Theme sections now receive date spans:
```php
// Calculate date span from child weeks
$themestartts = min($childstartdates);  // First week start
$themeendts = max($childenddates);      // Last week end
$themespan = format_date_range_uk($themestartts, $themeendts);
```

Returns data with:
- `formatted_date`: Full date span (e.g., "Oct 27–Nov 9:")
- `start_timestamp`: Start of first week
- `end_timestamp`: End of last week
- `is_parent`: true (identifies as theme)

### Form Changes

**File:** `classes/form/dates_for_sections_form.php`

Form now:
1. **Separates themes and weeks** into different arrays
2. **Creates two tables** with separate headers
3. **Adds section-type CSS classes** (`theme-section`, `week-section`)
4. **Provides separate "select all"** controls for each table

### Preview Functionality

The real-time preview (in modal footer) updates dynamically as users check/uncheck sections. Both theme spans and week dates are recalculated when sections are excluded.

## Benefits

✅ **Clear organization** - Themes and weeks visually separated  
✅ **Optional theme dates** - Users choose whether to include theme spans  
✅ **Contextual information** - Theme spans show the full scope of each theme  
✅ **Flexible control** - Select all themes, all weeks, or individual sections  
✅ **Backward compatible** - Works seamlessly with existing week-based and flat layouts

## Layout-Specific Behavior

### Theme-Based Layout
- Themes get date spans (first to last week)
- Weeks get individual week dates
- Both displayed in separate tables

### Week-Based Layout
- Top-level sections (acting as weeks) get dates
- No themes to separate
- Single table display

### Flat Layout
- Standalone weeks get dates
- No hierarchy
- Single table display

## Testing

Test with:
```bash
php docs/tests/test_layout_dates.php <courseid>
```

Expected for theme-based course:
```
✅ PASS: N theme(s) have date spans (optional to apply)
✅ PASS: N week(s) correctly have dates
Overall: ✅ ALL CHECKS PASSED
```

## User Scenarios

### Scenario 1: Full Course Structure
**Goal:** Show complete date information on all sections

**Action:** Check all themes and all weeks → Apply Dates

**Result:** Both themes and weeks display dates in their names

### Scenario 2: Weeks Only
**Goal:** Only show dates on individual weeks, not theme spans

**Action:** Uncheck all themes, keep weeks checked → Apply Dates

**Result:** Only week names have dates, themes remain plain

### Scenario 3: Selected Themes
**Goal:** Apply dates to some themes but not others

**Action:** Check specific theme checkboxes → Apply Dates

**Result:** Only checked themes get date spans applied

## Notes

- Theme date spans always reflect the full range of child weeks
- If a week is unchecked, the theme span still includes it (span shows what WOULD be covered if all weeks were enabled)
- Date removal works on both themes and weeks
- Holiday exclusions apply to individual weeks; theme spans adjust automatically
