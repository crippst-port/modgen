# Section HTML Extraction Fix

## Summary

Updated the template reader to correctly extract raw HTML from section descriptions/summaries by querying the `course_sections` table directly instead of relying on processed data from `get_fast_modinfo()`.

## Changes Made

### File: `classes/local/template_reader.php`

**Method: `get_course_html_structure()`** (lines 414-466)

#### Before:
- Used `get_fast_modinfo()` to get sections
- The `$section->summary` from modinfo might be filtered/processed
- Potentially lost raw HTML structure

#### After:
- Queries `course_sections` table directly using `$DB->get_record()`
- Gets raw, unprocessed HTML from `summary` field
- Preserves all Bootstrap classes and HTML structure exactly as stored

### Key Improvements

1. **Direct Database Access**:
   ```php
   // Old approach
   $modinfo = get_fast_modinfo($courseid);
   foreach ($modinfo->get_section_info_all() as $section) {
       $html = $section->summary; // Might be processed
   }

   // New approach
   $sections = $DB->get_records('course_sections', ['course' => $courseid]);
   foreach ($sections as $section) {
       $html = $section->summary; // Raw HTML from database
   }
   ```

2. **Section Filtering**: Fixed section filtering when `$sectionid` is specified:
   ```php
   if ($sectionid) {
       $section = $DB->get_record('course_sections',
           ['course' => $courseid, 'id' => $sectionid]);
   }
   ```

3. **Better Separation**: Added newlines between HTML parts for clarity:
   ```php
   return implode("\n\n", $html_parts);
   ```

## What This Fixes

### Problem
The template system needs to extract the exact HTML and Bootstrap markup from section descriptions to:
- Analyze Bootstrap component usage
- Provide examples to AI for structure replication
- Preserve visual consistency in generated courses

### Solution
By querying the database directly, we ensure:
- ✓ Raw HTML is preserved exactly as entered
- ✓ All Bootstrap classes are captured
- ✓ Custom HTML structure is maintained
- ✓ No filtering or processing of content

## Testing

Created test scripts to verify extraction:

### Test 1: Direct Database Test
**File**: `docs/test_section_html_direct.php`

**Usage**:
```bash
php test_section_html_direct.php <courseid>
```

**Output**:
- Shows raw HTML from each section
- Identifies Bootstrap classes
- Lists HTML tags
- Shows preview of content

### Example Output
```
Course: Tom test 3
Format: topics

SECTIONS FROM DATABASE (raw HTML from course_sections.summary field):
--------------------------------------------------------------------------------

1. Section 0 (Section #0, ID: 333)
   Summary length: 0 chars
   (No summary/description - empty field)

2. Week 1: Understanding the Connected Curriculum (Section #1, ID: 339)
   Summary length: 105 chars
   Bootstrap classes: NONE

   RAW HTML (first 300 chars):
   ----------------------------------------------------------------------------
   Review core concepts through readings and discussions...
   ----------------------------------------------------------------------------
```

## How Template System Uses This

### Extraction Flow

1. **Admin configures template**:
   ```
   Template Name|CourseID
   Example Module|3
   ```

2. **User selects template in form**

3. **`template_reader->extract_curriculum_template()`** calls:
   - `get_course_info()` → Course metadata
   - `get_course_structure()` → Section organization
   - `get_activities_detail()` → Activity patterns
   - **`get_course_html_structure()`** → **Raw HTML from sections** ✨
   - `extract_bootstrap_structure()` → Bootstrap components

4. **AI Service receives**:
   ```php
   $template_data = [
       'course_info' => [...],
       'structure' => [...],
       'activities' => [...],
       'template_html' => '<div class="row">...',  // Raw HTML
       'bootstrap_structure' => ['cards', 'grid']
   ];
   ```

5. **`ai_service->build_template_prompt_guidance()`** uses HTML to:
   - Show AI exact markup examples
   - List Bootstrap classes to use
   - Instruct AI to match structure

### Example AI Guidance Generated
```
HTML STRUCTURE AND BOOTSTRAP COMPONENTS:
The template uses specific HTML and Bootstrap markup. When generating section summaries,
include similar HTML structure and Bootstrap classes. The template HTML includes:

TEMPLATE HTML EXAMPLES:
```html
<div class="row">
  <div class="col-md-6">
    <div class="card">
      <div class="card-body">
        ...
```

Bootstrap classes used in template: container, row, col-md-6, card, card-body...
Use these same Bootstrap classes in your generated section summaries.

IMPORTANT: Your section summaries MUST include HTML markup matching the template's style.
```

## Verification Steps

To verify the fix is working:

1. **Check raw extraction**:
   ```bash
   php docs/test_section_html_direct.php <courseid>
   ```
   Should show raw HTML from section descriptions

2. **Check template reader**:
   - Configure template in plugin settings
   - Use template in generation form
   - Check debug logs for "template_html" length > 0

3. **Verify in generation**:
   - Generate module with template
   - Check that AI output includes Bootstrap markup
   - Verify section summaries have similar structure

## Notes

- **Moodle's `get_fast_modinfo()`**: Returns cached, possibly filtered content optimized for display
- **Direct DB query**: Returns exactly what's stored, no processing
- **Section descriptions**: Stored in `course_sections.summary` field
- **HTML preservation**: Critical for Bootstrap template system to work

## Related Files

- `classes/local/template_reader.php` - Main template extraction (MODIFIED)
- `classes/local/template_structure_parser.php` - HTML structure analysis
- `classes/local/ai_service.php` - Uses template HTML in AI prompts
- `docs/test_section_html_direct.php` - Testing tool (NEW)
- `docs/test_section_html.php` - Comprehensive test (NEW)

## Impact

**Before**: Template HTML extraction might miss Bootstrap classes and structure
**After**: Raw HTML preserved exactly, enabling accurate structure replication

This ensures the AI can properly replicate the visual structure and Bootstrap components from template courses.
