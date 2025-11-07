# Week Date Calculation from Course Start Date

## Feature

Instead of generic "Week 1", "Week 2", etc., the system now generates week titles with actual dates based on the Moodle course start date. Each week spans 7 consecutive days.

## How It Works

### Course Start Date Detection

The system retrieves the course start date from Moodle's course settings (course.startdate field). This is set by instructors when configuring the course.

### Week Date Calculation

Each week is calculated as 7 days from the previous week:
- **Week 1:** Course start date → +6 days (7 day span)
- **Week 2:** Week 1 end + 1 day → +6 days
- **Week 3:** Week 2 end + 1 day → +6 days
- And so on...

### Date Format

Dates are formatted as: `"Mon Day - Day"` (e.g., `"Jan 6 - 12"`)

This compact format is clean and shows the month and date range for each week.

## Implementation Details

### New Function: `get_week_date_range()`

**Location:** `classes/local/ai_service.php`, lines 46-76

```php
private static function get_week_date_range($weeknumber, $courseid = null)
```

**Parameters:**
- `$weeknumber` (int): 1-based week number
- `$courseid` (int, optional): Course ID to get start date from

**Returns:**
- (string): Formatted date range, e.g., "Jan 6 - 12"

**Logic:**
1. Get course object from `$courseid` or global `$COURSE`
2. Retrieve course start date (fallback to current time if not set)
3. Calculate week start: `startdate + ((weeknumber - 1) * 7 days)`
4. Calculate week end: week start + 6 days
5. Format both dates using `userdate()` for localization
6. Return formatted string

### Updated Function Signatures

**File:** `classes/local/ai_service.php`

**`generate_module()`** (line 400):
```php
public static function generate_module(
    $prompt, 
    $documents = [], 
    $structure = 'weekly', 
    $template_data = null, 
    $courseid = null  // NEW parameter
)
```

**`generate_module_with_template()`** (line 764):
```php
public static function generate_module_with_template(
    $prompt, 
    $template_data, 
    $documents = [], 
    $structure = 'weekly', 
    $courseid = null  // NEW parameter
)
```

### Prompt Enhancement

**File:** `classes/local/ai_service.php`, lines 530-546

When `$courseid` is provided, the prompt includes:

```
WEEK DATES (Based on Course Start Date):
The course has a start date set in Moodle. Each week is 7 days from the previous week.
Include the week date range in each week's title using this format:
Instead of: "Week 1"
Use: "Week 1 (Jan 6 - 12)"
Then: "Week 2 (Jan 13 - 19)"
Then: "Week 3 (Jan 20 - 26)"
And so on for each subsequent week.
IMPORTANT: Use the exact date format shown above (e.g., "Jan 6 - 12").
IMPORTANT: Each week is exactly 7 days after the previous week.
```

**Example dates generated:**
- Week 1: `"Jan 6 - 12"`
- Week 2: `"Jan 13 - 19"`
- Week 3: `"Jan 20 - 26"`

### Updated Calls in prompt.php

**File:** `prompt.php`, lines 1162-1168

The API calls now include the `$courseid` parameter:

```php
// Line 1162 (with template):
$json = ai_service::generate_module_with_template(
    $compositeprompt, 
    $template_data, 
    $supportingfiles, 
    $moduletype, 
    $courseid  // NEW
);

// Line 1165 (fallback):
$json = ai_service::generate_module(
    $compositeprompt, 
    [], 
    $moduletype, 
    null, 
    $courseid  // NEW
);

// Line 1168 (standard):
$json = ai_service::generate_module(
    $compositeprompt, 
    $supportingfiles, 
    $moduletype, 
    null, 
    $courseid  // NEW
);
```

## Example Output

### Before (Generic)
```json
{
  "sections": [
    {
      "title": "Week 1",
      "summary": "Introduction to...",
      "outline": ["Topic 1", "Topic 2"]
    },
    {
      "title": "Week 2",
      "summary": "Deep dive into...",
      "outline": ["Topic 3", "Topic 4"]
    }
  ]
}
```

**After (With Dates):**
```json
{
  "sections": [
    {
      "title": "Week 1 (Jan 6 - 12)",
      "summary": "Introduction to...",
      "outline": ["Topic 1", "Topic 2"]
    },
    {
      "title": "Week 2 (Jan 13 - 19)",
      "summary": "Deep dive into...",
      "outline": ["Topic 3", "Topic 4"]
    }
  ]
}
```

## Localization

The `userdate()` function automatically handles localization:
- Dates format according to user's language and timezone settings
- Month names translated to user's language
- Timezone applied for accurate date calculation

**Example (different locales):**
- English: "Jan 6 - 12"
- French: "6 janv. - 12"
- German: "6. Jan. - 12"

## Fallback Behavior

If course start date is not set in Moodle:
- Uses current timestamp as start date
- Generates dates based on today's date
- Ensures AI still receives date guidance (no null/empty)

## Backward Compatibility

The `$courseid` parameter is optional (defaults to `null`):
- **If provided:** Generates week dates based on course start date
- **If not provided:** Uses generic "Week N" format (original behavior)
- Existing code continues to work without modification

## Benefits

✅ **Realistic timelines:** Students see actual course weeks with real dates
✅ **Better planning:** Instructors can map curriculum to academic calendar
✅ **Automatic calculation:** No manual date entry needed
✅ **Consistency:** All weeks follow 7-day intervals automatically
✅ **Localization-aware:** Respects user's language and timezone
✅ **User-friendly:** More concrete than abstract "Week 1" labels

## Technical Considerations

### Date Calculation Method

Uses PHP timestamp arithmetic:
```php
$weekstartdate = $startdate + (($weeknumber - 1) * 7 * 24 * 60 * 60);
```

- Safe for large week numbers (> 100)
- Handles month/year boundaries automatically
- PHP handles leap years and daylight saving time

### Userdate Function

Uses Moodle's `userdate()` for formatting:
```php
userdate($timestamp, '%b %d, %Y', $timezone);
```

- Built-in localization support
- Respects user's timezone preferences
- Consistent with other Moodle date displays

## Commit Message

```
Feat: Add week date calculation from course start date

- Add get_week_date_range() function to calculate week dates from course start
- Each week is 7 days from previous week
- Date format: "Mon Day - Day" (e.g., "Jan 6 - 12")
- Update generate_module() to accept optional courseid parameter
- Update generate_module_with_template() to accept optional courseid parameter
- Add week date guidance to AI prompt when courseid provided
- Generate example dates in prompt (Week 1, 2, 3 dates shown)
- Update all calls in prompt.php to pass courseid parameter
- Dates localized to user's language and timezone (via userdate())
- Fallback to current time if course start date not set
- Backward compatible: parameter is optional, original behavior if not provided
- Example: "Week 1 (Jan 6 - 12)" instead of "Week 1"
```

## Related Documentation

- `COMPLETE_THEME_GENERATION.md` - Theme generation completeness
- `USER_SPECIFIED_THEME_COUNT.md` - Theme count override feature
- `STREAMLINED_PROMPT_FIX.md` - Prompt clarity improvements
