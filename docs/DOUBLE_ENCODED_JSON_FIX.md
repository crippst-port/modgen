# Double-Encoded JSON Fix

## Problem

Sometimes the AI response gets double-encoded, where the entire module structure ends up nested inside a `summary` field:

```json
{
    "themes": [
        {
            "title": "AI Generated Summary",
            "summary": "{\"themes\":[{\"title\":\"Theme 1\",...}]}"
        }
    ]
}
```

This happens when the AI's valid JSON response gets wrapped by another layer of JSON encoding, typically from the API response wrapper or middleware.

## Root Causes Handled

### Cause 1: Response Wrapping
The AI response gets wrapped in additional JSON layers:
```
Response wrapper → Middleware → API → Application
```
Result: The valid JSON structure ends up nested inside a field.

### Cause 2: Escaped Characters
The JSON string in the summary field may contain escaped newlines and quotes:
- `\n` (escaped newline)
- `\t` (escaped tab)
- `\"` (escaped quote)
- `\\` (escaped backslash)

These need to be handled both with PHP's `json_decode()` and manual unescaping.

## Solution

The `normalize_ai_response()` function now:

1. **Recursively decodes** all string fields that contain JSON
2. **Unwraps nested structures** at the top level - if the decoded structure contains the full `themes` or `sections` array, it extracts it
3. **Preserves valid data** - only unwraps when it detects the actual module structure is nested

### Key Implementation Details

**File**: `classes/local/ai_service.php`

**Functions**: 
- `normalize_ai_response($value, $isTopLevel = false)` - Main normalization
- `unescape_json_string($str)` - Helper for escape sequences

The main function now handles **three specific wrapping patterns and escape variants**:

#### Pattern 1: Double-Wrapped Object
```php
{outer: {themes: [...]}}  →  {themes: [...]}
```

#### Pattern 2: Structure in First Item's Summary (Most Common)
With direct decode:
```php
// Input:
{themes: [{title: "...", summary: "{\"themes\":[...]}"}]}

// Output:
{themes: [{title: "Theme 1", ...}]}
```

With escaped characters:
```php
// Input (with \n, \t, \" in the string):
{themes: [{summary: "{\n  \"themes\": [...]"}]}

// Step 1: Decode outer JSON → summary becomes: "{\n  \"themes\": [...]"
// Step 2: Detect JSON in summary field
// Step 3: Unescape characters using helper function
// Step 4: Decode the unescaped JSON
// Output: Proper structure
```

#### Pattern 3: Escape Sequences
The helper function `unescape_json_string()` handles:
- `\\n` → newline
- `\\t` → tab
- `\\r` → carriage return
- `\"` → quote
- `\\` → backslash

**Implementation**:
```php
// When called at the top level with isTopLevel=true:
// 1. Detects if we have themes/sections at top level
// 2. Checks if the first item's summary contains a JSON structure
// 3. If summary starts with { and decodes to a valid structure with themes/sections
// 4. Tries direct decode first
// 5. If that fails, unescapes common escape sequences and tries again
// 6. Automatically unwraps and returns the decoded structure
// 7. Logs the unwrapping action for debugging
```

**Validation**: `validate_module_structure()`

Simplified to focus on structural validation instead of double-encoding detection, since normalization now handles it.

## How It Works

### Example 1: Wrapped Structure in First Item (Most Common)

**Input (malformed)**:
```json
{
    "themes": [
        {
            "title": "AI Generated Summary",
            "summary": "{\"themes\":[{\"title\":\"Theme 1: Egging as a Metaphor in Literature\",...}]}"
        }
    ]
}
```

**Processing**:
1. `normalize_ai_response()` called with `isTopLevel=true`
2. Detects pattern: `themes` array with first item containing full structure in `summary`
3. Decodes the summary field: `{"themes":[...]}`
4. Verifies it contains `themes` or `sections` (actual structure indicators)
5. Unwraps and returns the correct structure
6. Logs: `"Detected structure wrapped in first item's summary field, unwrapping..."`

**Output (fixed)**:
```json
{
    "themes": [
        {
            "title": "Theme 1: Egging as a Metaphor in Literature",
            "summary": "This theme explores...",
            "weeks": [...]
        },
        {
            "title": "Theme 2: The Psychological Impact of Egging",
            ...
        }
    ]
}
```

### Example 2: Nested Activity Activities (Sessions)

The normalization also handles deeply nested JSON-encoded structures in the new session-based format:

**Input**:
```json
{
    "themes": [{
        "weeks": [{
            "sessions": {
                "session": {
                    "activities": "[{\"type\":\"forum\",\"name\":\"Discussion\"}]"
                }
            }
        }]
    }]
}
```

**Processing**: Recursively decodes the activities array
**Output**: Activities properly decoded and ready for creation

## When It's Applied

1. **Always on**: Every AI response goes through normalization
2. **Main location**: Lines 532-540 in `ai_service.php`
3. **Called after**: Initial JSON decode from AI text
4. **Before**: Validation and structure processing

## Logging

Debug logs show when normalization occurs:

```
AI_SERVICE: Normalised AI JSON structure; differences detected.
AI_SERVICE: Normalised JSON: [structure output]
```

## Testing Scenarios

### Test 1: Basic Double-Encoding
- AI returns valid nested structure
- Verify it unwraps correctly
- Check sections/themes are at top level

### Test 2: Session-Based Activities
- Ensure activities in session subsections remain properly nested
- Verify presession/session/postsession structures preserved
- Check activities array is properly decoded

### Test 3: Mixed Encoding
- Some fields JSON-encoded, others plain text
- Verify only JSON strings are decoded
- Plain text summaries unchanged

## Migration & Compatibility

This fix is **backward compatible**:
- Correctly formatted responses pass through unchanged
- Malformed double-encoded responses are now fixed automatically
- No changes needed to calling code
- No database migrations required

## Future Improvements

1. Add telemetry to track how often double-encoding occurs
2. Provide feedback to AI subsystem about response format issues
3. Consider rate-limiting regeneration if double-encoding is frequent
