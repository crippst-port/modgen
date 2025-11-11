# Recent Changes Summary

## 1. Template System: Section HTML Extraction Fix

**Problem**: Template reader wasn't getting raw HTML from section descriptions
**Solution**: Changed to query `course_sections` table directly instead of using `get_fast_modinfo()`

### Changes Made:
- **File**: `classes/local/template_reader.php`
- **Method**: `get_course_html_structure()` (lines 414-466)
- **What**: Now uses `$DB->get_records('course_sections', ...)` to get raw HTML
- **Why**: Preserves Bootstrap classes and HTML structure for AI to replicate

### Documentation:
- [SECTION_HTML_EXTRACTION_FIX.md](SECTION_HTML_EXTRACTION_FIX.md)

### Testing:
```bash
php docs/test_section_html_direct.php <courseid>
```

---

## 2. AI Response Validation System

**Problem**: AI sometimes returns double-encoded JSON causing broken course generation
**Solution**: Added validation to detect malformed responses before user approval

### Example Malformed Response:
```json
{
    "themes": [{
        "title": "AI Generated Summary",
        "summary": "{\"themes\":[{\"title\":\"Real Content\",\"weeks\":[...]}]}"
    }]
}
```

The entire structure is encoded as a string inside the summary field!

### Changes Made:

#### 1. Validation Function (`ai_service.php`)
- **Added**: `validate_module_structure()` method (lines 48-136)
- **Checks**:
  - Missing top-level keys (themes/sections)
  - Empty arrays
  - **Double-encoded JSON in summary fields** ⭐
  - Invalid structure (non-array items)
  - Missing weeks (for theme structure)
  - Missing titles
  - Double-encoded JSON in week summaries

#### 2. Integration in AI Service (`ai_service.php`)
- **Location**: Lines 433-448
- **Action**: Validates after normalization, returns error if invalid
- **Result**: Response includes `validation_error` field when malformed

#### 3. Error Display in Prompt Handler (`prompt.php`)
- **Location**: Lines 1072-1093
- **Behavior**:
  - Checks for `validation_error` in response
  - Shows clear error message instead of approval form
  - Provides "Try Again" button (no approve option)
  - User returns to form with original prompt

#### 4. Language Strings (`lang/en/aiplacement_modgen.php`)
- **Added**:
  - `generationfailed` - Error heading
  - `validationerrorhelp` - Explanation text
  - `tryagain` - Button label

### User Experience

**Before**:
1. User approves malformed response
2. Content creation fails
3. Confusion and frustration

**After**:
1. Validation catches error automatically
2. Clear message: "Malformed response detected..."
3. "Try Again" button to regenerate
4. No broken content created

### Testing

**Test Script**: `docs/test_validation.php`

```bash
php docs/test_validation.php
```

**Results**: All 5 tests pass ✓
- Detects double-encoded JSON in theme summary
- Detects double-encoded JSON in week summary
- Rejects empty arrays
- Rejects missing titles
- Accepts valid responses

### Documentation:
- [VALIDATION_SYSTEM.md](VALIDATION_SYSTEM.md) - Complete system documentation

---

## Files Modified

| File | Lines | Purpose |
|------|-------|---------|
| `classes/local/template_reader.php` | 414-466 | Fixed HTML extraction from sections |
| `classes/local/ai_service.php` | 48-136 | Added validation function |
| `classes/local/ai_service.php` | 433-448 | Integrated validation |
| `prompt.php` | 1072-1093 | Added error display |
| `lang/en/aiplacement_modgen.php` | 284-287 | Added error strings |

## Files Created

| File | Purpose |
|------|---------|
| `docs/test_section_html_direct.php` | Test section HTML extraction |
| `docs/test_validation.php` | Test validation system |
| `docs/SECTION_HTML_EXTRACTION_FIX.md` | Template fix documentation |
| `docs/VALIDATION_SYSTEM.md` | Validation system documentation |
| `docs/CHANGES_SUMMARY.md` | This file |

---

## Testing Checklist

### Template System
- [ ] Raw HTML extracted from section descriptions
- [ ] Bootstrap classes preserved
- [ ] HTML structure maintained
- [ ] Works with specific section ID
- [ ] Works with all sections

**Test**: `php docs/test_section_html_direct.php <courseid>`

### Validation System
- [ ] Detects double-encoded theme summary
- [ ] Detects double-encoded week summary
- [ ] Rejects empty responses
- [ ] Rejects missing fields
- [ ] Accepts valid responses
- [ ] Shows error message to user
- [ ] "Try Again" button returns to form
- [ ] No approval possible with malformed response

**Test**: `php docs/test_validation.php`

---

## Impact

### Template System Fix
- ✅ Template HTML now correctly extracted
- ✅ Bootstrap structure preserved for AI
- ✅ Generated courses match template styling
- ✅ Consistent visual appearance

### Validation System
- ✅ Prevents broken content creation
- ✅ Saves user time (no approving bad responses)
- ✅ Clear error messages
- ✅ Easy regeneration workflow
- ✅ Reduces support requests

---

## Next Steps

Optional future enhancements:
1. Validate activity structures in detail
2. Check for minimum activity counts
3. Validate HTML structure in summaries
4. Add activity type validation
5. Add URL format validation
6. More detailed error messages (show what's wrong specifically)

---

## Quick Reference

### Check if template extraction works:
```bash
php docs/test_section_html_direct.php 3
```

### Check if validation works:
```bash
php docs/test_validation.php
```

### Debug logs location:
```
/path/to/moodledata/modgen_logs/debug.log
```

### View recent validation errors:
```bash
tail -20 /path/to/moodledata/modgen_logs/debug.log | grep "validation"
```

---

## Questions?

- **Template extraction**: See [SECTION_HTML_EXTRACTION_FIX.md](SECTION_HTML_EXTRACTION_FIX.md)
- **Validation system**: See [VALIDATION_SYSTEM.md](VALIDATION_SYSTEM.md)
- **Testing**: Run test scripts in `docs/` directory
