# AI Prompt Enhancements - Double-Encoded JSON Prevention

## Summary

Updated the AI prompt sent to the Moodle AI subsystem to explicitly prohibit double-encoded JSON responses. The prompt now clearly instructs the AI to return JSON directly at the top level, not nested inside field values.

## Changes Made

### File: `classes/local/ai_service.php`

#### 1. Enhanced JSON Requirements (lines ~297-308)

Added comprehensive instructions to prevent double-encoding:

```php
$jsonrequirements = "The JSON structure you return must represent a Moodle module for the user's requirements, not just generic activities.\n" .
    "Return ONLY valid JSON matching the schema below. Do not include any commentary or code fences.\n\n" .
    "CRITICAL - JSON FORMAT REQUIREMENTS:\n" .
    "- Return the JSON object DIRECTLY as the top-level response\n" .
    "- Do NOT wrap the JSON in any outer structure\n" .
    "- Do NOT place the JSON inside any field value or summary\n" .
    "- Do NOT escape or encode the JSON as a string\n" .
    "- The response must be parseable as JSON with a single json_decode() call\n" .
    "- Top-level must contain either 'themes' or 'sections' array, not nested inside any field\n" .
    "Example CORRECT: {\"themes\": [{\"title\": \"...\", ...}]}\n" .
    "Example INCORRECT (do not do this): {\"response\": \"{\\\"themes\\\"...}\"} or {\"data\": \"{\n  \\\"themes\\\":...}\"}";
```

**Key Points:**
- Explicitly states "CRITICAL" to emphasize importance
- Provides both positive (correct) and negative (incorrect) examples
- Covers all known encoding patterns:
  - Nested JSON in fields
  - Escaped quotes and newlines
  - Double-wrapped structures

#### 2. Theme Format Instructions (line ~402)

Added warning to theme-structured module format:
```
"⚠️ IMPORTANT: Return the JSON object DIRECTLY. Do NOT place it inside any field or wrap it as a string value."
```

#### 3. Weekly Format Instructions (line ~445)

Added same warning to weekly-structured module format.

## Why This Matters

### Previous Issue
The AI would sometimes return responses like:
```json
{
    "themes": [{
        "title": "Summary",
        "summary": "{\"themes\":[{...actual structure...}]}"
    }]
}
```

The entire structure was nested inside the first theme's summary field as an escaped JSON string.

### Automatic Handling
The `normalize_ai_response()` function now catches and unwraps these responses. However, preventing them at the source is better because:

1. **Clarity**: Explicit instructions help newer/weaker AI models
2. **Reliability**: Reduces malformed responses overall
3. **Performance**: Fewer responses need unwrapping
4. **Debugging**: Clear examples prevent confusion

## Prompt Sent to AI

The prompt now includes this section BEFORE the schema definition:

```
CRITICAL - JSON FORMAT REQUIREMENTS:
- Return the JSON object DIRECTLY as the top-level response
- Do NOT wrap the JSON in any outer structure
- Do NOT place the JSON inside any field value or summary
- Do NOT escape or encode the JSON as a string
- The response must be parseable as JSON with a single json_decode() call
- Top-level must contain either 'themes' or 'sections' array, not nested inside any field

Example CORRECT: {"themes": [{"title": "...", ...}]}
Example INCORRECT (do not do this): {"response": "{\"themes\"...}"} or {"data": "{\n  \"themes\":...}"}
```

## Impact

- **User Impact**: None - transparent improvement
- **AI Impact**: Clearer instructions should reduce malformed responses
- **Backend Impact**: Works alongside existing `normalize_ai_response()` unwrapping
- **Maintenance**: Easier to debug when AI follows explicit rules

## Testing

To verify the new prompt is being sent:

1. Generate a module using connected_theme format
2. Check `/tmp/modgen_debug.log`:
   - Search for "Final prompt being sent"
   - Verify it contains "CRITICAL - JSON FORMAT REQUIREMENTS"
   - Confirm both example patterns are present

## Future Enhancements

1. **Telemetry**: Track how often double-encoded responses still occur
2. **Feedback Loop**: If still frequent, adjust example formats
3. **Provider-Specific**: Add provider-specific guidance if certain providers consistently malform responses
4. **Monitoring**: Log when unwrapping occurs to identify if pattern changes

## Related Files

- `classes/local/ai_service.php` - Prompt assembly and AI integration
- `docs/DOUBLE_ENCODED_JSON_FIX.md` - Technical details of unwrapping mechanism
- `docs/test_escaped_json.php` - Test script for escape sequence handling
