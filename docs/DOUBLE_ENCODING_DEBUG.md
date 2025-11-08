# Double-Encoding Debug Guide

## Problem
AI sometimes returns JSON with stringified JSON nested within string fields:

```json
{
    "themes": [
        {
            "title": "Theme Title",
            "summary": "{\"themes\": [{...}]}"   // <- This is a STRING, not parsed JSON
        }
    ]
}
```

This happens because AI stringifies its output when returning complex nested structures.

## Solution: Four-Layer Parse + Deep Recursive Unescape

The AI response parsing pipeline now has **4 attempts** to handle increasingly complex encoding:

### Layer 1: Direct Decode
```php
$jsondecoded = json_decode($text, true);
```
Works for: Clean JSON responses
Fails for: Escaped or stringified responses

### Layer 2: Extract from Commentary
```php
preg_match('/(\{.*\}|\[.*\])/s', $text, $m)
json_decode($m[1], true)
```
Works for: JSON wrapped in code blocks or embedded in commentary
Fails for: Escaped JSON strings

### Layer 3: Pre-Decode Unescape
```php
$unescaped = self::unescape_json_string($text);
$jsondecoded = json_decode($unescaped, true);
```
Works for: Fully escaped responses like `{\"themes\": [...]}`
Fails for: Nested stringified JSON within string fields

### Layer 4: Deep Unescape (NEW - LATEST)
```php
$jsondecoded = self::deep_unescape_stringified_json($jsondecoded);
```
**Recursively scans the parsed JSON structure** for string fields that contain JSON.
- When found, unescapes and decodes them
- Recursively processes decoded content
- Repeats up to depth 10 (prevents infinite loops)

Works for: **Deeply nested stringified JSON**
Pattern: `{"summary": "{\"nested\": {...}}"}`
→ Decoded to: `{"summary": {"nested": {...}}}`

## Execution Flow

```
1. Try direct decode
   ↓ (if fails)
2. Try extract from commentary
   ↓ (if fails)
3. Try unescape entire response + decode
   ↓ (if succeeds OR fails)
4. Deep unescape stringified JSON within fields
   └─ Scans all string values
   └─ If value looks like JSON, unescapes + decodes
   └─ Recursively processes decoded content
   └─ Repeats up to depth 10
```

## Logging

Check `/tmp/modgen_token_usage.log`:
- **DEEP UNESCAPE**: Applied recursive JSON string decoding (Layer 4 activated)
- **DOUBLE ENCODING DETECTED**: Top-level key contains JSON string (pre-Layer 4 detection)
- **NORMALIZATION CHANGED STRUCTURE**: normalize_ai_response() unwrapped nested JSON
- **Response appears truncated**: Token limit may have been hit

## Examples

### Before (Double-Encoded - Fails)
```json
{
    "themes": [{
        "title": "Theme",
        "summary": "{\"themes\": [{\"title\": \"Nested\"}]}"
    }]
}
```
Problem: `summary` is STRING containing escaped JSON
Result: Data unusable, breaks downstream processing

### After (Layer 4 Deep Unescape - Fixed)
```json
{
    "themes": [{
        "title": "Theme",
        "summary": {
            "themes": [{"title": "Nested"}]
        }
    }]
}
```
Result: `summary` is now proper nested object, fully usable

## Testing

Generate a module with content that returns deeply nested stringified JSON:

```bash
# Check if deep unescape ran
tail -f /tmp/modgen_token_usage.log | grep "DEEP UNESCAPE"

# View the result
tail -f /tmp/modgen_ai_response.json | jq '.themes[0].summary | type'
# Should output: "object" (not "string")

# Clear logs and retry
rm /tmp/modgen_ai_response.json /tmp/modgen_token_usage.log
```

## Recursion Safety

Deep unescape has built-in limits:
- **Max depth**: 10 levels of nesting
- **Pattern check**: Only attempts decode if value starts with `{` or `[` and ends with `}` or `]`
- **Type check**: Only processes string values
- **Prevention**: Stops if reaches max depth or value doesn't look like JSON

## Related Functions

- `unescape_json_string()`: Handles common escape sequences (`\"`, `\\`, `\/`, etc.)
- `deep_unescape_stringified_json()`: NEW - Recursive field-level unescaping
- `normalize_ai_response()`: Handles additional nested stringification patterns after Layer 4
