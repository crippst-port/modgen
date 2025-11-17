# Modal Generator Implementation

## Summary

Implemented a new AJAX-based generator page specifically for modal loading, replacing the deprecated ModalFactory with the modern Modal API.

## Changes Made

### 1. New AJAX Generator Page (`generate.php`)
- **Purpose**: Lightweight AJAX endpoint for loading the generator form in modals
- **Features**:
  - AJAX-only (no full page rendering)
  - Policy acceptance handling
  - JSON response format
  - Security: `require_login()` and capability checks
  - Returns form HTML directly in JSON response

### 2. Updated JavaScript (`amd/src/course_toolbar.js`)
- **Replaced**: Deprecated `core/modal_factory` → Modern `core/modal` API
- **Improvements**:
  - Uses `Modal.create()` instead of `ModalFactory.create()`
  - Loads form via AJAX from `generate.php` using Fetch API
  - Proper promise chain (no nesting warnings)
  - Form submission handler with loading states
  - Vanilla JavaScript (no jQuery dependency)
  - All ESLint warnings resolved

### 3. Removed Unused Code (`lib.php`)
- **Removed**: `aiplacement_modgen_output_fragment_generator_form()` callback
  - No longer needed since form is loaded via AJAX instead of Fragment API
  - Fragment API is still used for toolbar rendering only

### 4. New Template (`templates/ai_policy.mustache`)
- **Purpose**: AI policy acceptance form for AJAX requests
- **Features**:
  - Checkbox validation
  - Form submission via POST
  - Session key security
  - Bootstrap styling

## Architecture

### Modal Loading Flow
```
User clicks "Module Assistant" button
    ↓
course_toolbar.js → Modal.create()
    ↓
Fetch generate.php?courseid=X (AJAX)
    ↓
generate.php returns JSON: {success: true, html: "..."}
    ↓
Modal displays form HTML
    ↓
Form submission → prompt.php (POST)
    ↓
Page reload to show results
```

### Modern Modal API vs Deprecated ModalFactory

**Old (Deprecated)**:
```javascript
ModalFactory.create({
    type: ModalFactory.types.CANCEL,
    title: 'Title',
    large: true,
})
```

**New (Modern)**:
```javascript
Modal.create({
    title: 'Title',
    body: 'Content',
    large: true,
    removeOnClose: true,
})
```

## Benefits

1. **Standards Compliance**: Uses current Moodle Modal API (not deprecated)
2. **Performance**: AJAX loading is faster than Fragment API for forms
3. **Maintainability**: Separate concerns (toolbar vs form loading)
4. **Security**: Proper capability checks at every step
5. **Code Quality**: No ESLint warnings, proper promise chains

## Testing Checklist

- [ ] Navigation bar appears in edit mode
- [ ] "Module Assistant" button opens modal
- [ ] Form loads in modal without errors
- [ ] All form fields render correctly
- [ ] File upload works
- [ ] Form submission processes correctly
- [ ] Page reloads after submission
- [ ] Modal closes properly
- [ ] No console errors
- [ ] AI policy acceptance works (if not yet accepted)

## File Changes Summary

- ✅ **NEW**: `generate.php` - AJAX generator endpoint
- ✅ **NEW**: `templates/ai_policy.mustache` - Policy acceptance template
- ✅ **UPDATED**: `amd/src/course_toolbar.js` - Modern Modal API implementation
- ✅ **UPDATED**: `lib.php` - Removed unused fragment callback
- ✅ **BUILT**: `amd/build/course_toolbar.min.js` - Minified JavaScript

## Notes

- The `prompt.php` file remains unchanged (handles standalone page and form processing)
- The generator form class (`classes/form/generator_form.php`) works for both standalone and modal contexts
- Fragment API is still used for toolbar rendering (that part works correctly)
- Only form loading was changed from Fragment to AJAX for better modal compatibility
