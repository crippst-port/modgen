# Module Preview - Download & View Update

**Date:** 12 November 2025  
**Update:** Fixed JSON download functionality and improved module display

---

## What Changed

### Previous Issues
- JSON display was in a `<details>` element (just for expanding/collapsing)
- Only showed as "collapse/expand" - no actual download capability
- Module structure not displaying properly (just showing blue line)
- No way to save the JSON file

### What's Fixed

#### 1. **Proper Download Functionality**
- Added two buttons: "💾 Download JSON" and "👁️ View JSON"
- Download button actually downloads a `.json` file to user's computer
- File named with date: `module-structure-2025-11-12.json`
- File can be saved locally for archival or sharing

#### 2. **View JSON Functionality**
- "View JSON" button toggles display of the raw JSON
- JSON only shown when user clicks "View JSON"
- Keeps approval page clean and focused on structure
- User can collapse JSON after viewing

#### 3. **Module Structure Display**
- Improved template logic to handle empty sections
- Shows "No activities in this week" message when empty
- Shows "No module structure data" warning if parsing failed
- Better error handling and fallback messages

---

## Files Modified

### 1. **amd/src/json_handler.js** (NEW)
- Created new JavaScript module for download/view functionality
- `handleDownload()` - Creates JSON file and triggers download
- `init()` - Sets up button click handlers
- Handles HTML entity decoding from template

### 2. **templates/prompt_preview.mustache**
- Replaced `<details>` element with button-based controls
- Added two buttons: Download and View JSON
- Hidden JSON viewer div (only shows on demand)
- Added empty state messages for sections with no activities
- Better error handling for missing data

### 3. **styles.css**
- Added `.aiplacement-modgen-json-controls` - Button container
- Added `.aiplacement-modgen-json-viewer` - Hidden JSON display
- Improved button styling with hover effects
- Added `.aiplacement-modgen-activity-none` - Empty state message
- Better spacing and visual hierarchy

### 4. **prompt.php**
- Added JS initialization: `$PAGE->requires->js_call_amd('aiplacement_modgen/json_handler', 'init')`
- Ensures download functionality available when JSON present

### 5. **lang/en/aiplacement_modgen.php**
- Updated: `$string['downloadjson']` = '💾 Download JSON'
- Added: `$string['viewjson']` = '👁️ View JSON'

---

## User Experience Flow

### Before Approval
1. User sees module structure display
2. If they need JSON, they click "View JSON" to expand it
3. They can copy the JSON or click "Download JSON" to save it
4. Once they've reviewed, they click "Approve and create"

### Download Process
```
User clicks "Download JSON"
    ↓
JavaScript extracts JSON from data attribute
    ↓
Creates Blob object with JSON content
    ↓
Generates filename with today's date
    ↓
Triggers browser download dialog
    ↓
File saved as: module-structure-YYYY-MM-DD.json
```

### View Process
```
User clicks "View JSON"
    ↓
Hidden JSON viewer div becomes visible
    ↓
Shows raw JSON in scrollable code block
    ↓
User can scroll and read JSON
    ↓
Click "View JSON" again to hide it
```

---

## Technical Details

### Download Handler
```javascript
// Gets JSON from button's data-json attribute
const jsonData = e.target.getAttribute('data-json');

// Decodes HTML entities from template
const textarea = document.createElement('textarea');
textarea.innerHTML = jsonData;
const jsonContent = textarea.value;

// Creates downloadable file
const blob = new Blob([jsonContent], {type: 'application/json'});
const link = document.createElement('a');
link.download = 'module-structure-' + new Date().toISOString().split('T')[0] + '.json';
```

### Template Structure
```mustache
[Module Structure Display]
  - Themes/Weeks
  - Activities

[JSON Controls]
  - Download JSON button
  - View JSON button

[JSON Viewer] (Hidden by default)
  <pre><code>Raw JSON</code></pre>
```

---

## Browser Compatibility

✅ **Works in all modern browsers:**
- Chrome/Edge (Chromium-based)
- Firefox
- Safari
- Mobile browsers

✅ **Uses standard Web APIs:**
- Blob API
- URL.createObjectURL()
- Dynamic link creation

---

## Security Notes

✅ All JSON data HTML-escaped before output
✅ No external dependencies required
✅ No API calls needed for download
✅ File generated client-side only
✅ Sensitive data not logged or transmitted

---

## Testing Checklist

- [x] Download button creates downloadable file
- [x] Downloaded file has correct name (with date)
- [x] Downloaded file contains valid JSON
- [x] View JSON button toggles display
- [x] Module structure shows correctly
- [x] Empty sections show fallback message
- [x] No JavaScript errors in console
- [x] Works on mobile/tablet
- [x] Button styling matches Moodle theme
- [x] All language strings display correctly

---

## Example Usage

### Step 1: Review Module Structure
User sees:
```
📚 Module Structure

📂 Unit 1: Introduction
   └─ 📅 Week 1: Welcome
      ├─ 🔹 Lecture slides
      ├─ 🔹 Reading assignment
      └─ 🔹 Welcome quiz (quiz)
```

### Step 2: Download if Needed
User clicks "💾 Download JSON" → File saved as `module-structure-2025-11-12.json`

### Step 3: View Raw JSON if Needed
User clicks "👁️ View JSON" → Expands to show:
```json
{
  "themes": [
    {
      "title": "Unit 1: Introduction",
      "weeks": [
        {
          "title": "Week 1: Welcome",
          "presession": [...],
          "session": [...],
          "postsession": [...]
        }
      ]
    }
  ]
}
```

### Step 4: Approve
User clicks "Approve and create" → Activities created in course

---

## Future Enhancements

1. **Copy to Clipboard** - Add "Copy JSON" button
2. **Format Selection** - Option to download as YAML/CSV
3. **Validation** - Show validation warnings before download
4. **Statistics** - Show theme/week/activity counts
5. **Preview** - Export preview as PDF alongside JSON
