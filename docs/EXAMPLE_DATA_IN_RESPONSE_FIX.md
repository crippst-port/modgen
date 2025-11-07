# Fix: AI Including Example Data in Response

## Problem Discovered

The AI was including literal example data from the format instruction in its JSON response. For example:

**User saw this being sent in `debugprompt`:**
```json
"weeks": [
  {
    "title": "Week 1",
    "summary": "This week you'll delve into the concept of sustainability...",
    "sessions": {
      "presession": { "activities": [...] },
      "session": { "activities": [...] },
      "postsession": { "activities": [...] }
    }
  },
  {
    "title": "Week 2",
    "summary": "Explore the state of our planet...",
    ...
  }
]
```

This was coming from the **format instruction examples**, not from the actual curriculum file!

## Root Cause

**Location:** `classes/local/ai_service.php` lines 401-428 (original)

The format instruction included JSON examples that were supposed to be just STRUCTURE references:

```php
$formatinstruction = "OUTPUT FORMAT (Theme-Based):\n" .
    "{\n" .
    "  \"themes\": [\n" .
    "    {\"title\": \"Theme Name\", \"summary\": \"2-3 sentence intro\", \"weeks\": [\n" .
    "      {\"title\": \"Week X\", \"summary\": \"Overview\", \"sessions\": {\n" .
    "        \"presession\": {...}, \"session\": {...}, \"postsession\": {...}\n" .
    "      }}\n" .
    "    ]}\n" .
    "  ]\n" .
    "}\n"
```

**The Issue:** LLMs can misinterpret example data as literal data that should be included in responses, especially when examples contain placeholder text like "Week X", "Theme Name", etc.

## Solution Applied

### Change 1: Enhanced Role Instruction (lines 360-373)

**Before:**
```php
"Example: {\"themes\": [{\"title\": \"...\", \"summary\": \"...\", \"weeks\": [...]}]}\n\n"
```

**After:**
```php
"IMPORTANT: Do NOT include any example data or placeholder text like 'Week X', 'Theme Name', '...', 'Overview', etc.\n" .
"Every field MUST contain actual content from the curriculum provided, NEVER from format examples.\n" .
"Example structure ONLY (do not copy this data): {\"themes\": [{\"title\": \"REAL THEME\", \"summary\": \"REAL SUMMARY\", \"weeks\": [...]}]}\n\n"
```

**Impact:** Makes it unmistakably clear that AI should NEVER copy placeholder text from examples.

### Change 2: Simplified Minimal Example in Format Instruction (lines 395-410)

**Before:**
```php
$formatinstruction = "OUTPUT FORMAT (Theme-Based):\n" .
    "{\n" .
    "  \"themes\": [\n" .
    "    {\"title\": \"Theme Name\", \"summary\": \"2-3 sentence intro\", \"weeks\": [\n" .
    "      {\"title\": \"Week X\", \"summary\": \"Overview\", \"sessions\": {\n" .
    "        \"presession\": {...}, \"session\": {...}, \"postsession\": {...}\n" .
    "      }}\n" .
    "    ]}\n" .
    "  ]\n" .
    "}\n" .
    "Compact JSON, no extra whitespace. UK university audience, British English.";
```

**After:**
```php
$formatinstruction = "JSON RESPONSE FORMAT:\n" .
    "{\"themes\": [{\"title\": \"Theme Title\", \"summary\": \"Theme Summary\", \"weeks\": [{\"title\": \"Week Title\", \"summary\": \"Week Summary\", \"sessions\": {\"presession\": {\"activities\": [...]}, \"session\": {\"activities\": [...]}, \"postsession\": {\"activities\": [...]}}}]}]}\n" .
    "Repeat the theme and week structure for each theme/week in the curriculum.\n" .
    "Repeat the session pattern (presession/session/postsession) for each week.\n" .
    "Each activity object: {\"type\": \"quiz|forum|url|book\", \"name\": \"Activity Name\"} plus type-specific fields.\n" .
    "Compact JSON, no extra whitespace.";
```

**Impact:**
- ✅ Single compact line example instead of multi-line with formatting
- ✅ Generic labels "Theme Title", "Week Title" that obviously need replacement
- ✅ Explicit "Repeat the structure for each..." instructions
- ✅ **Massive token savings**: Removed ~20 lines of formatting, ~200+ tokens
- ✅ Eliminates ambiguity about what's example vs. structure

## Technical Details

**Files Modified:**
- `classes/local/ai_service.php` lines 360-410 (roleinstruction + formatinstruction)

**Key Changes:**
1. ✅ Simplified format instruction to single-line compact example
2. ✅ Changed multi-line formatted example (20 lines) to inline JSON (1 line)
3. ✅ Made example labels generic: "Theme Title", "Week Title" instead of "Theme Name", "Week X"
4. ✅ Added explicit "Repeat the structure for each..." instructions
5. ✅ Removed redundant explanation text
6. ✅ Eliminated ambiguous placeholders like "Overview", "..."

**Token Savings:**
- Format instruction: Reduced from ~40 lines to ~5 lines
- Estimated tokens saved: ~200-300 tokens (roughly 7% of total prompt)
- New format example: ~80 characters vs old: ~400 characters

## Expected Behavior After Fix

When user uploads a curriculum file with content, AI will:
- ✅ Parse the ACTUAL file content
- ✅ Generate weeks/themes from ACTUAL topics in the file
- ✅ NOT include "Week X", "Theme Name", or other placeholders
- ✅ NOT include the example presession/session/postsession template
- ✅ Create structure matching format requirements with REAL data

## Testing Recommendations

1. **Re-generate with same course file** → Verify weeks are named from actual content (e.g., "Sustainability Basics", "Climate Change" instead of "Week 1", "Week 2")
2. **Check debugprompt** → Verify it doesn't contain the example JSON structure
3. **Monitor token count** → Should remain similar since we only made prompt more explicit, not longer
4. **Compare outputs** → New generation should have proper curriculum content instead of examples

## Related Issues Fixed

This fix addresses:
- ✅ Example JSON being included as actual data
- ✅ Placeholder text ("Week X", "Theme Name") appearing in responses
- ✅ Sessions structure being treated as required template data instead of format reference
- ✅ General AI confusion about what constitutes examples vs. actual output requirements

## Prevention Going Forward

When adding examples or format instructions to AI prompts:
1. **Use minimal examples**: Single instance showing pattern, not multiple examples
2. **Use generic labels**: "Title", "Name", "Summary" that obviously need replacement
3. **Add repeat instructions**: Explicitly tell AI "Repeat this structure for each X in the data"
4. **Keep examples compact**: Inline single-line JSON is better than multi-line formatted blocks
5. **Avoid placeholder text**: Don't use "Week X", "Theme Name", "Overview" - these look like data
6. **Use clear instructions**: "Repeat for each week in curriculum" is clearer than "create multiple objects"

**Best Practice Example:**
```php
// BAD: Multiple lines, real-looking placeholders
$example = "{\n  \"items\": [\n    {\"title\": \"Item 1\", \"description\": \"Description\"}\n  ]\n}";

// GOOD: Compact, generic labels, explicit repeat instruction
$example = "{\"items\": [{\"title\": \"ItemTitle\", \"description\": \"ItemDesc\"}]}\nRepeat for each item in the data.";
```
