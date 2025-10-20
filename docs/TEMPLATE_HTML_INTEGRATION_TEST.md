# Quick Test: Template HTML Integration

## What's Been Fixed
The AI now receives **actual HTML code snippets** from your template and **explicit instructions** to use HTML in section summaries. Previously, it was just suggesting HTML should be used.

## 5-Minute Test

### Step 1: Reset Cache (30 seconds)
```bash
php -r 'opcache_reset();'
```

### Step 2: Open Monitoring Window (1 minute)
In a separate browser tab, open:
```
http://your-moodle/ai/placement/modgen/view_logs.php
```
This will show real-time logs as you test.

### Step 3: Test Template Selection (2 minutes)
1. Go to your prompt page
2. Select a template from the dropdown
3. Submit the form
4. **Don't wait for generation** - go to the logs window

### Step 4: Check Logs (1 minute)
In the `view_logs.php` window, filter for: `TEMPLATE HTML EXAMPLES`

You should see:
```
[timestamp] TEMPLATE HTML EXAMPLES:
```html
<div class="...">
... actual HTML from your template ...
```
```

### Step 5: Verify Bootstrap Classes (30 seconds)
Still in logs, search for: `Bootstrap classes used`

You should see:
```
Bootstrap classes used in template: container, row, col-md-6, card, card-body, alert, ...
Use these same Bootstrap classes in your generated section summaries.
```

### Step 6: Verify Template Mode Instruction (30 seconds)
Search logs for: `TEMPLATE MODE:`

You should see:
```
TEMPLATE MODE: Each section summary MUST be valid HTML content.
Use HTML markup with Bootstrap 4/5 classes to structure the section summaries.
```

## What Success Looks Like

### In Error Logs
✅ See actual HTML code from template
✅ See list of Bootstrap classes
✅ See explicit "MUST be valid HTML" instruction
✅ See HTML example in format instruction

### In Generated Module
✅ Section summaries contain HTML markup
✅ Section summaries use Bootstrap classes
✅ Content is visually formatted, not plain text
✅ Layout matches template's visual style

## What to Do If It's Not Working

1. **No "TEMPLATE HTML EXAMPLES" in logs?**
   - Template HTML extraction failed
   - Go back to `debug_template_flow.php`
   - Check if "Extraction successful" shows
   - If not, template may have no content to extract

2. **"TEMPLATE HTML EXAMPLES" shows but AI still generates plain text?**
   - AI model may not be understanding the instruction
   - Try using a simpler template with clearer HTML
   - Check if template HTML includes recognizable Bootstrap classes
   - May need to adjust AI model or provider settings

3. **Logs show everything but generated sections don't have HTML?**
   - AI understood but generated plain JSON
   - The JSON is being processed but HTML stripped somewhere
   - Check if HTML is being preserved in module creation process

## Command Reference

### Quick status check
```bash
# See if template guidance is being built
grep "Template guidance built" /Users/tom/moodledata45/modgen_logs/debug.log | tail -3

# See HTML examples being sent to AI
grep -A 5 "TEMPLATE HTML EXAMPLES:" /Users/tom/moodledata45/modgen_logs/debug.log | head -20

# See Bootstrap classes recognized
grep "Bootstrap classes used" /Users/tom/moodledata45/modgen_logs/debug.log | tail -3

# See full prompt (very long - last 400 lines of logs)
tail -400 /Users/tom/moodledata45/modgen_logs/debug.log | grep -A 200 "Final prompt"
```

### Monitor in real-time
```bash
tail -f /Users/tom/moodledata45/modgen_logs/debug.log | grep -E "Template|HTML|Bootstrap|TEMPLATE MODE"
```

## Expected Flow

```
1. Form submitted with template selected
                    ↓
2. template_reader extracts:
   - Course info
   - Section structure
   - Activities
   - ✨ HTML from sections (NEW!)
                    ↓
3. AI service receives template_data with HTML
                    ↓
4. build_template_prompt_guidance() creates guidance with:
   - ✨ Actual HTML code examples (NEW!)
   - ✨ Bootstrap class list (NEW!)
   - ✨ Explicit HTML requirement (NEW!)
                    ↓
5. Final prompt to AI includes:
   - User request
   - ✨ Template HTML examples (NEW!)
   - ✨ "MUST be valid HTML" instruction (NEW!)
   - Schema/format instructions
                    ↓
6. AI generates JSON with:
   - ✨ HTML-formatted section summaries (EXPECTED!)
   - Activities matching template
   - Structure matching template
                    ↓
7. Module created with HTML sections visible
```

---

## Still Not Working?

Check the **DEBUGGING_TEMPLATE_FLOW.md** file for the comprehensive troubleshooting guide, or check the logs using:

```bash
grep -E "ERROR|EXCEPTION" /Users/tom/moodledata45/modgen_logs/debug.log | tail -10
```
