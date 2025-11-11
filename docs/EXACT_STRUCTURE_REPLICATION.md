# Exact HTML Structure Replication

## Overview

Enhanced the template system to force the AI to copy the exact HTML structure and Bootstrap layout from templates, not just use them as inspiration.

## Problem

**Before**: The AI was given template HTML as examples but would:
- Simplify the structure
- Remove Bootstrap classes
- Create its own layout "inspired by" the template
- Not maintain visual consistency

**User expectation**: Generated sections should look **visually identical** to template sections, just with different content.

## Solution

### Changed Approach

**Old approach** (lines 725-748 in previous version):
```
"The template uses specific HTML and Bootstrap markup.
When generating section summaries, include similar HTML structure..."
```
- Used words like "similar" and "include"
- Showed only first 1000 chars as "example"
- Gave AI creative freedom

**New approach** (lines 725-810):
```
"CRITICAL: EXACT HTML STRUCTURE REPLICATION REQUIRED
You MUST copy the HTML structure below EXACTLY for EVERY section you create.
Do NOT simplify, do NOT modify the structure..."
```
- Uses MUST, EXACTLY, CRITICAL
- Shows FULL template HTML, not excerpt
- Explicit forbidden/required actions
- Step-by-step instructions
- Clear examples of correct vs incorrect

### Key Changes

#### 1. Explicit Language

**Before**: "include similar HTML structure"
**After**: "copy the HTML structure below EXACTLY"

**Before**: "Use these same Bootstrap classes"
**After**: "Keep ALL Bootstrap classes EXACTLY as shown"

#### 2. Full Template HTML

**Before**:
```php
$html_excerpt = substr($template_data['template_html'], 0, 1000);
$guidance .= $html_excerpt;
if (strlen($template_data['template_html']) > 1000) {
    $guidance .= "\n... (additional HTML content)\n";
}
```

**After**:
```php
// Show the FULL template HTML, not just an excerpt
$guidance .= $template_data['template_html'];
```

Now AI sees the complete structure, not truncated.

#### 3. Step-by-Step Instructions (10 steps)

Clear, numbered instructions:
1. Copy ENTIRE HTML structure character-for-character
2. Keep ALL div tags, classes, attributes EXACTLY as shown
3. Keep ALL Bootstrap classes EXACTLY as shown
4. Keep ALL HTML attributes EXACTLY as shown (id, role, data-toggle, href)
5. ONLY change text content between tags
6. If template has tabs, output MUST have tabs with same structure
7. If template has cards, output MUST have cards with same structure
8. If template has badges, output MUST have badges with same structure
9. Maintain SAME nesting depth and tag hierarchy
10. Every section summary MUST use this EXACT structure

#### 4. Concrete Examples

**Added clear before/after example**:

```
EXAMPLE - If template has:
<div class='container my-4'>
  <h5>Introduction</h5>
  <p>This week introduces macronutrients...</p>
</div>

Your output for a different topic MUST be:
<div class='container my-4'>
  <h5>Getting Started</h5>
  <p>This week explores programming basics...</p>
</div>

NOT simplified versions like: <p>This week...</p>
```

#### 5. Forbidden Actions List

Added explicit ❌ list of what NOT to do:
- DO NOT simplify the HTML structure
- DO NOT remove divs or container elements
- DO NOT change Bootstrap class names
- DO NOT remove CSS classes
- DO NOT modify HTML attributes
- DO NOT create your own structure
- DO NOT use plain text without HTML
- DO NOT change the layout or visual structure

#### 6. Required Actions List

Added explicit ✓ list of what TO do:
- Copy the HTML structure EXACTLY
- Use ALL the same Bootstrap classes
- Maintain ALL div containers and wrappers
- Keep ALL HTML attributes unchanged
- Only change the text content inside tags
- Apply this SAME structure to EVERY section/week
- Match the visual layout exactly

#### 7. Fill-in-the-Blank Analogy

**Added final clarification**:
```
Think of it as a fill-in-the-blank exercise where you only fill in the text content,
not as creative freedom to design your own layout.
```

This helps AI understand it's a replacement task, not a creative design task.

## Technical Implementation

### File Modified

**File**: `classes/local/ai_service.php`
**Function**: `build_template_prompt_guidance()`
**Lines**: 719-810 (previously 719-763)

### Changes Summary

| Aspect | Before | After |
|--------|--------|-------|
| Tone | Suggestive ("similar") | Command ("MUST") |
| HTML shown | First 1000 chars | Full template |
| Instructions | General | 10 specific steps |
| Examples | None | Concrete before/after |
| Forbidden actions | Implied | Explicit list with ❌ |
| Required actions | General | Explicit list with ✓ |
| Analogy | None | Fill-in-the-blank |
| Emphasis | Moderate | Strong (CRITICAL, EXACTLY) |

## Expected Result

### Before Enhancement

AI might return:
```html
<div class='container'>
  <p>This week introduces the topic...</p>
</div>
```

Missing tabs, badges, proper structure from template.

### After Enhancement

AI should return:
```html
<div class="container my-4">
  <h5>Week 1: New Topic</h5>
  <p>This week introduces the new topic...</p>
  <p><strong>Learning Outcome(s):</strong> <span class="badge badge-primary">LO1</span></p>
  <!-- Activity Tabs -->
  <ul id="week1Tabs" class="nav nav-tabs mb-0 border-bottom" style="list-style: none;" role="tablist">
    <li class="nav-item">
      <a id="pre-tab" class="nav-link active" role="tab" href="#pre" data-toggle="tab">
        <i class="fa fa-book mr-2" aria-hidden="true"></i> Pre-session
      </a>
    </li>
    ...
  </ul>
  <div class="tab-content border-left border-right p-3">
    ...
  </div>
</div>
```

Exact same structure, just different content text.

## Testing

### Manual Test

1. **Select a template course** with rich Bootstrap HTML (tabs, cards, badges)
2. **Generate new content** with template selected
3. **Compare structure**:
   - Check generated HTML in section descriptions
   - Verify all Bootstrap classes present
   - Confirm div structure matches
   - Check attributes (id, role, data-toggle) match

### Visual Test

1. View template course section
2. View generated course section
3. They should look **visually identical**:
   - Same layout
   - Same components (tabs, cards, badges)
   - Same styling
   - Only text content differs

### Debug Check

Check logs for prompt sent to AI:
```bash
tail -100 /path/to/moodledata/modgen_logs/debug.log | grep -A 50 "CRITICAL: EXACT HTML"
```

Should see the full enhanced guidance with all instructions.

## Troubleshooting

### If AI still simplifies structure:

1. **Check template HTML extraction**:
   ```bash
   php docs/test_section_html_direct.php <templatecourseid>
   ```
   Verify HTML is being extracted

2. **Check logs** to see what prompt was sent:
   ```bash
   grep "Template HTML found" /path/to/moodledata/modgen_logs/debug.log
   ```

3. **Try with simpler template first** (fewer components)

4. **Check AI model** - Some models follow instructions better than others

### If structure is partially copied:

- AI might be hitting token limits
- Try splitting complex templates into smaller sections
- Consider using section-specific templates instead of full course

## Benefits

1. **Visual Consistency**: All generated sections look identical to template
2. **Brand Consistency**: Institutional styling preserved
3. **User Expectation**: Gets what they expect (exact copy with new content)
4. **Less Rework**: Don't need to manually fix HTML after generation
5. **Professional Output**: Maintains polished, designed appearance

## Future Enhancements

Potential improvements:
1. Validate generated HTML matches template structure
2. Automatic HTML structure comparison
3. Warning if AI deviates from template
4. Post-processing to enforce structure if AI fails
5. Multiple template sections (different structures for different section types)

## Related Files

- [ai_service.php](../classes/local/ai_service.php#L719-L810) - Enhanced guidance
- [template_reader.php](../classes/local/template_reader.php) - Template extraction
- [SECTION_HTML_EXTRACTION_FIX.md](SECTION_HTML_EXTRACTION_FIX.md) - Raw HTML extraction
- [VALIDATION_SYSTEM.md](VALIDATION_SYSTEM.md) - Response validation

## Important Addition: Unique IDs

**CRITICAL UPDATE**: Added instructions for making HTML IDs unique per section to prevent Bootstrap component conflicts.

See: [UNIQUE_IDS_FOR_BOOTSTRAP.md](UNIQUE_IDS_FOR_BOOTSTRAP.md)

### Why This Matters

If tabs use `id="week1Tabs"` in template:
- ❌ Week 1, 2, 3 all with `id="week1Tabs"` → Only Week 1 tabs work
- ✅ Week 1: `id="week1Tabs"`, Week 2: `id="week2Tabs"`, Week 3: `id="week3Tabs"` → All work

AI now makes IDs unique by adding suffixes:
- Template: `id="pre-tab"`, `href="#pre"`
- Week 2: `id="pre-tab-w2"`, `href="#pre-w2"`
- Week 3: `id="pre-tab-w3"`, `href="#pre-w3"`

## Summary

The enhancement changes the AI's task from:
- ❌ "Design something similar to this example"

To:
- ✅ "Copy this exact structure, fill in new content, and make IDs unique"

This should result in generated sections that are:
- **Visually identical** to the template
- **Functionally independent** (unique IDs prevent conflicts)
- Maintaining all Bootstrap components and layout
- Only changing text content and ID suffixes to match the new topic
