# Template System Debugging Guide

## Quick Start

### 1. **Comprehensive Diagnosis** (Start here!)
Visit: `http://your-moodle.local/ai/placement/modgen/debug_template_flow.php`

This will:
- ✓ Verify new code is loaded (checks for recent changes)
- ✓ Reset PHP OPcache
- ✓ Show plugin configuration
- ✓ Parse all configured templates
- ✓ Test template extraction
- ✓ Show if data is ready for AI
- ✓ Display recent error logs

**Look for these indicators:**
- `✓` means that component is working
- `✗` means there's an issue

### 2. **Visual Form Debug** (During testing)
When testing template selection:
1. Add `?debug=1` to your prompt.php URL
2. Select a template and submit
3. A yellow debug box will appear showing:
   - Whether `curriculum_template` was received
   - What value it has
   - Whether it's empty

Example: `http://your-moodle.local/course/view.php?id=123&debug=1`

### 3. **Check Error Logs** (Real-time tracking)
After testing, run:
```bash
tail -50 /Users/tom/moodledata45/modgen_logs/debug.log | grep -E "DEBUG:|Template|curriculum"
```

Look for this sequence:
```
DEBUG: $pdata->curriculum_template exists: YES
DEBUG: $pdata->curriculum_template value: "3" (or similar)
DEBUG: $curriculum_template after assignment: "3"
Checking curriculum_template: empty=0, value: "3"
Template selected: 3
Template data extracted, keys: course_info, structure, activities, template_html
=== generate_module_with_template called ===
template_data is PRESENT (at ai_service.php)
```

## What Each Component Tells You

### debug_template_flow.php Results

| Check | Meaning |
|-------|---------|
| `✓ CODE VERIFICATION` | New debugging code is loaded in files |
| `✓ Templates enabled` | Plugin config has template feature turned ON |
| `✓ Templates config set` | Admin has configured at least one template |
| `✓ Templates found: N` | N templates were successfully parsed |
| `✓ Extraction successful` | Template data was extracted from course |
| `✓ Course info available` | Template has course metadata |
| `✓ Structure available: N` | Template has N sections |
| `✓ Activities available: N` | Template has N activities |
| `✓ HTML available: XXXX chars` | Template HTML was extracted |

### If Something Shows `✗`

**Code verification failed:**
- Clear your browser cache (Cmd+Shift+R or Ctrl+Shift+F5)
- PHP OPcache may need resetting: `php -r 'opcache_reset();'`

**Templates enabled: NO:**
- Go to Moodle: **Site administration → Plugins → AI placement plugins → Module Generator**
- Enable "Enable curriculum templates" checkbox
- Save

**No templates found:**
- Go to Moodle settings (same location as above)
- Add template in format: `TemplateName|CourseID` or `TemplateName|CourseID|SectionID`
- Example: `Basic Biology Module|15` or `Week 1|15|3`
- Each template on a new line

**Extraction failed:**
- Check that course ID exists and user has access
- Verify the course has sections and activities
- Check Moodle capabilities

## Troubleshooting Steps

### Step 1: Verify Configuration
Run `debug_template_flow.php` and check:
- [ ] Code verification shows all ✓
- [ ] Templates enabled: YES
- [ ] Templates found: N > 0

### Step 2: Test Template Extraction
Run `debug_template_flow.php` and check:
- [ ] "Extraction successful" appears
- [ ] Shows number of sections and activities

### Step 3: Test Form Submission
1. Add `?debug=1` to prompt page URL
2. Select a template
3. Submit the form
4. Check if yellow debug box shows `curriculum_template value: "3"` (or similar)

### Step 4: Check Error Logs
Run: `tail -50 /Users/tom/moodledata45/modgen_logs/debug.log`
- [ ] Look for `DEBUG: $pdata->curriculum_template exists: YES`
- [ ] Look for `Template selected: ...`
- [ ] Look for `template_data is PRESENT` (at ai_service level)

### Step 5: If Template Data Still NULL

Run this detailed trace:
```bash
grep -E "curriculum_template|Template data|template_data" /Users/tom/moodledata45/modgen_logs/debug.log | tail -20
```

Looking for:
1. Is `curriculum_template` being submitted? (should see "Template selected:")
2. Is extraction being called? (should see "Template data extracted")
3. Is it being passed to AI? (should see "template_data is PRESENT")

If any step is missing, that's where the problem is.

## Common Issues & Solutions

### Issue: "template_data is EMPTY/NULL" in logs

**Cause:** Template data not being passed to `generate_module`

**Solution:**
1. Check if `generate_module_with_template` is being called
   - Look for: `=== generate_module_with_template called ===`
   - If missing: Template might not be selected in form
2. Check the `curriculum_template` value
   - Should be a number like `"3"` or `"3|5"`
   - If empty: Form not submitting it

### Issue: "No templates found"

**Cause:** Templates not configured or inaccessible

**Solution:**
1. Check admin settings - go to plugin settings
2. Ensure at least one template is entered in format: `Name|CourseID`
3. Ensure user has `view` capability on template course
4. Run `debug_template_flow.php` to see parsed templates

### Issue: "Templates enabled: NO"

**Cause:** Feature disabled in config

**Solution:**
1. Go to Moodle admin settings
2. Find "Module Generator" plugin settings
3. Check "Enable curriculum templates"
4. Save

## Monitoring Real-Time Logs

Terminal 1 - Watch logs:
```bash
tail -f /Users/tom/moodledata45/modgen_logs/debug.log
```

Terminal 2 - Test:
1. Visit prompt.php
2. Select template
3. Watch Terminal 1 for DEBUG messages

## Key Files

- `prompt.php` - Form handling and template extraction (lines 880-970)
- `classes/local/ai_service.php` - Template guidance building (lines 385-495)
- `classes/local/template_reader.php` - Template data extraction (lines 77-200)
- `debug_template_flow.php` - Diagnostic tool (this directory)

## HTML Integration with Templates

**Updated:** Template HTML is now explicitly included in the AI prompt with concrete examples!

### What's New
1. ✅ **HTML Examples**: First 1000 characters of template HTML included as code block
2. ✅ **Bootstrap Classes**: All Bootstrap classes extracted and listed for AI
3. ✅ **Explicit Instructions**: Format instruction now explicitly requires HTML in template mode
4. ✅ **Visual Guidance**: AI sees actual HTML markup to emulate

### Expected Output
When using a template, generated section summaries should now be HTML-formatted:
```html
<div class="card mb-3">
  <div class="card-body">
    <h5>Section Title</h5>
    <p>Content with HTML markup...</p>
  </div>
</div>
```

Instead of plain text:
```
Section content as plain text...
```

### Checking HTML Integration
```bash
# Look for template HTML examples in prompt
grep -A 10 "TEMPLATE HTML EXAMPLES:" /Users/tom/moodledata45/modgen_logs/debug.log

# Check if Bootstrap classes are listed
grep "Bootstrap classes used" /Users/tom/moodledata45/modgen_logs/debug.log

# Verify final prompt includes template mode instruction
tail -300 /Users/tom/moodledata45/modgen_logs/debug.log | grep "TEMPLATE MODE:"
```

### If Generated Content is Still Plain Text
1. Check the error logs show "TEMPLATE HTML EXAMPLES:" section
2. Verify Bootstrap classes are listed
3. Check final prompt includes "TEMPLATE MODE: Each section summary MUST be valid HTML"
4. If these are present but AI still generates plain text:
   - The AI model may need more specific guidance
   - Consider creating a template with simpler, clearer HTML patterns
   - Try a different AI model/backend if available

## Questions?

Check the error log immediately after testing - the DEBUG logs will tell you exactly what happened:
```bash
tail -20 /Users/tom/moodledata45/modgen_logs/debug.log
```
