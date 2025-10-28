# AI Response Validation System

## Overview

The validation system prevents malformed AI responses from reaching the user approval stage. It detects common issues like double-encoded JSON and structural errors, providing clear error messages and preventing content creation until a valid response is generated.

## Problem Solved

### The Issue

Sometimes the AI returns responses where the entire JSON structure is double-encoded inside a single field, like this:

```json
{
    "themes": [
        {
            "title": "AI Generated Summary",
            "summary": "{\"themes\":[{\"title\":\"Historical Development\",\"summary\":\"...\",\"weeks\":[...]}]}"
        }
    ]
}
```

Instead of the expected:

```json
{
    "themes": [
        {
            "title": "Historical Development",
            "summary": "<div class='container'>...</div>",
            "weeks": [
                {
                    "title": "Week 1",
                    "summary": "<div>...</div>",
                    "activities": [...]
                }
            ]
        }
    ]
}
```

### The Impact

When this happens:
- Only one theme/section is created (the wrapper)
- The real content is hidden inside the summary field as a string
- Course creation fails or creates incomplete content
- User wastes time approving broken structure

## Solution

### Validation Function

**File**: `classes/local/ai_service.php`

**Function**: `validate_module_structure()` (lines 48-136)

Checks for:
1. **Missing top-level keys**: Ensures `themes` or `sections` array exists
2. **Empty arrays**: Rejects responses with no content
3. **Double-encoded JSON in summaries**: Detects JSON strings in summary fields
4. **Invalid structure**: Checks that themes/sections are properly formatted arrays
5. **Missing weeks**: For theme structure, ensures weeks array exists
6. **Missing titles**: Validates all themes/sections have titles
7. **Double-encoded week summaries**: Checks week-level summaries too

### Integration Points

#### 1. AI Service (ai_service.php:433-448)

After normalizing the response:
```php
if (is_array($jsondecoded) && (isset($jsondecoded['sections']) || isset($jsondecoded['themes']))) {
    // Validate the structure
    $validation = self::validate_module_structure($jsondecoded, $structure);

    if (!$validation['valid']) {
        // Return error response
        return [
            $structure === 'theme' ? 'themes' : 'sections' => [],
            'validation_error' => $validation['error'],
            'template' => 'AI error: ' . $validation['error'],
            ...
        ];
    }

    // Continue with valid response...
}
```

#### 2. Prompt Handler (prompt.php:1072-1093)

Before showing approval form:
```php
// Check if the AI response contains validation errors
if (!empty($json['validation_error'])) {
    // Show error page with regenerate button
    $errorhtml = html_writer::div(
        html_writer::tag('h4', get_string('generationfailed', 'aiplacement_modgen')) .
        html_writer::div($json['validation_error'], 'alert alert-danger') .
        html_writer::tag('p', get_string('validationerrorhelp', 'aiplacement_modgen')),
        'aiplacement-modgen__validation-error'
    );

    // Only show "Try Again" button - no approval option
    $footeractions = [[
        'label' => get_string('tryagain', 'aiplacement_modgen'),
        'classes' => 'btn btn-primary',
        'action' => 'aiplacement-modgen-reenter',
    ]];

    // Exit - don't show approval form
    exit;
}
```

## User Experience

### Before Validation

1. User submits prompt
2. AI returns malformed response
3. User sees approval form
4. User approves
5. Content creation fails or creates incomplete structure
6. User confused and frustrated

### After Validation

1. User submits prompt
2. AI returns malformed response
3. **Validation catches the error**
4. **User sees clear error message**:
   > **Generation Failed**
   >
   > Malformed response detected: The AI returned the entire structure inside a summary field instead of as proper sections. This happens when the AI double-encodes the JSON. Please try regenerating - the AI needs to return properly structured JSON.
   >
   > The AI response was malformed and cannot be used to create content. This sometimes happens when the AI double-encodes the response or returns an incorrect structure. Please try generating again with the same or modified prompt.
5. **User clicks "Try Again"**
6. Form reappears with their original prompt
7. User can regenerate or modify and resubmit

## Validation Checks

### Check 1: Top-Level Structure
```php
if ($structure === 'theme' && !isset($data['themes'])) {
    return ['valid' => false, 'error' => 'Response missing "themes" array'];
}
```

### Check 2: Non-Empty Content
```php
if (empty($items)) {
    return ['valid' => false, 'error' => 'Response contains no themes/sections'];
}
```

### Check 3: Double-Encoded JSON Detection
```php
if (isset($item['summary']) && is_string($item['summary'])) {
    $summary = trim($item['summary']);
    if (strlen($summary) > 0 && ($summary[0] === '{' || $summary[0] === '[')) {
        $decoded = json_decode($summary, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return [
                'valid' => false,
                'error' => 'Malformed response detected: The AI returned the entire structure inside a summary field...'
            ];
        }
    }
}
```

### Check 4: Theme Structure Validation
```php
if ($structure === 'theme') {
    if (!isset($item['weeks'])) {
        return ['valid' => false, 'error' => 'Theme missing "weeks" array'];
    }
    // Check each week for double-encoded JSON...
}
```

### Check 5: Required Fields
```php
if (!isset($item['title']) || trim($item['title']) === '') {
    return ['valid' => false, 'error' => 'Theme/section missing title'];
}
```

## Testing

### Test Script

**File**: `docs/test_validation.php`

**Usage**:
```bash
php docs/test_validation.php
```

**Tests**:
1. ✓ Malformed response (double-encoded JSON in summary)
2. ✓ Valid response (properly structured)
3. ✓ Empty themes array
4. ✓ Missing title
5. ✓ Double-encoded JSON in week summary

**Expected Output**:
```
SUMMARY:
Tests passed: 5/5

✓ All tests passed! Validation system is working correctly.
```

## Language Strings

**File**: `lang/en/aiplacement_modgen.php`

Added strings:
```php
$string['generationfailed'] = 'Generation Failed';
$string['validationerrorhelp'] = 'The AI response was malformed and cannot be used to create content. This sometimes happens when the AI double-encodes the response or returns an incorrect structure. Please try generating again with the same or modified prompt.';
$string['tryagain'] = 'Try Again';
```

## Edge Cases Handled

1. **JSON string in theme summary**: Entire structure double-encoded at theme level
2. **JSON string in week summary**: Structure double-encoded at week level
3. **Empty response**: AI returns empty arrays
4. **Missing fields**: Required fields like `title` or `weeks` missing
5. **Invalid types**: Fields that should be arrays are strings or other types
6. **Partial structures**: Valid top-level but invalid nested structure

## Future Enhancements

Potential additions:
- Validate activity structure (check required fields)
- Check for minimum content (at least one activity per section)
- Validate HTML in summaries (ensure it's not just text)
- Detect other encoding issues (triple-encoded, escaped quotes)
- Activity type validation (only allowed types)
- URL validation (check externalurl format)

## Debug Logging

The validation system logs to `modgen_logs/debug.log`:
```
[2025-01-15 10:30:45] AI_SERVICE: Structure validation FAILED: Malformed response detected...
```

Check logs when:
- User reports generation issues
- Debugging why content wasn't created
- Analyzing AI response quality

## Related Files

| File | Purpose |
|------|---------|
| [ai_service.php](../classes/local/ai_service.php#L48-L136) | Validation function |
| [ai_service.php](../classes/local/ai_service.php#L433-L448) | Validation call |
| [prompt.php](../prompt.php#L1072-L1093) | Error display |
| [aiplacement_modgen.php](../lang/en/aiplacement_modgen.php#L284-L287) | Language strings |
| [test_validation.php](test_validation.php) | Test suite |

## Summary

The validation system:
- ✓ Prevents malformed responses from being approved
- ✓ Provides clear, actionable error messages
- ✓ Allows users to regenerate easily
- ✓ Catches double-encoding issues
- ✓ Validates structure at all levels
- ✓ Fully tested with edge cases
- ✓ Logged for debugging

This ensures users only see and approve valid AI-generated content, preventing wasted time and failed content creation.
