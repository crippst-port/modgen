# Moodle File Upload & Processing Assessment

## Summary
**Yes, this is possible using Moodle's standard APIs—no third-party extensions required.**

Moodle provides:
- Built-in file upload handling via `filepicker` form element
- Standard file storage API with file areas
- **Native document conversion API** (`\core_files\conversion`)
- Extensible converter plugins (unoconv, googledrive, etc.)
- HTML extraction utilities for processing converted documents

---

## Feasibility Analysis

### 1. **File Upload (100% Native)**
- Use `MoodleQuickForm::addElement('filepicker', ...)` element
- Files stored in Moodle's standard file areas (`context_course`, etc.)
- Automatic virus scanning, size validation, MIME type checks
- No external dependencies required

### 2. **Word Doc → HTML Conversion (100% Native via Moodle API)**
- Moodle includes a **native conversion API** in `/files/classes/conversion.php`
- Supports multiple converter backends:
  - **unoconv** (LibreOffice/OpenOffice via network protocol)
  - **googledrive** (if configured and API enabled)
  - Custom converters can be added via plugins
- **No external code required** — Moodle manages the conversion
- Converters are configured by site admin in Moodle settings
- Graceful fallback if no converters available

### 3. **HTML → Moodle Book Chapters (100% Native)**
- Create book activity: `add_moduleinfo()` with `modulename = 'book'`
- Parse HTML into chapters using simple DOM parsing (PHP's `DOMDocument`)
- Insert chapters via `mod_book_chapters` database table
- Each chapter is stored as HTML with `content` and `contentformat` fields

### 4. **Content Mapping Strategy**

```
Word Document Structure → Moodle Book Structure:
┌─────────────────────┐     ┌─────────────────────────┐
│ Heading 1 = Chapter │ →  │ Book Chapter (title)    │
│ Body Text/Sections  │  →  │ Chapter Content (HTML)  │
│ Heading 2 = Section │  →  │ Subsection within       │
│ Tables/Images       │  →  │ Preserved in HTML       │
└─────────────────────┘     └─────────────────────────┘
```

---

## Implementation Using Moodle's Conversion API

### Core Usage Pattern
```php
use core_files\conversion;

// Get a conversion for a file
$conversions = conversion::get_conversions_for_file($file, 'html');

if (!empty($conversions)) {
    $conversion = reset($conversions);
    $destfile = $conversion->get_destfile();
    $htmlcontent = $destfile->get_content();
}

// Or initiate a new conversion
$conversion = new conversion($file, 'html');
$conversion->start_conversion();
```

### How Moodle's Conversion Works
1. **Site Admin configures converters** (unoconv, googledrive, etc.) in Site Administration
2. **Plugin requests conversion** via `\core_files\conversion` API
3. **Moodle queues conversion job** (can be async via scheduled tasks)
4. **Converter plugin processes file** using its backend (LibreOffice, Google Drive API, etc.)
5. **Result stored as a new file** in Moodle's file storage
6. **Plugin retrieves converted content** when ready

---

## Converter Plugins Available

### **unoconv** (Most Common)
- Built-in to Moodle (locate at `/files/converter/unoconv/`)
- Converts via LibreOffice/OpenOffice network interface
- Supports: .docx, .doc, .odt → PDF, HTML, and other formats
- Requires: LibreOffice or OpenOffice installed on server (but via network, not CLI)

### **googledrive** (Optional)
- Built-in to Moodle (locate at `/files/converter/googledrive/`)
- Uses Google Drive API for conversion
- No server setup needed if Google API credentials provided
- Supports same formats as Google Drive

### Custom Converters
- Can be added via plugins implementing `fileconverter_*` interface
- Moodle will use any available converter automatically

---

## Constraints & Limitations

### ✅ **Supported**
- `.docx` (Office Open XML) - best support
- `.doc` (Office 97-2003) - supported via converters
- `.odt` (OpenDocument) - best support
- Text extraction
- HTML preservation
- Image embedding (within docx binary)
- Table conversion to HTML
- **Multi-page documents** → Multiple chapters

### ⚠️ **Partial Support**
- Complex formatting (styles may be lost in conversion)
- Embedded charts/diagrams (may not convert cleanly)
- Complex headers/footers (typically ignored)
- Track changes/comments (lost in conversion)
- Form fields and macros (not supported)

### ❌ **Not Supported**
- Real-time conversion feedback (process is typically async)
- Conversion if no converters configured (falls back to text extraction)

---

## Server Requirements
```bash
# For unoconv converter (most common):
# LibreOffice or OpenOffice should be installed, but Moodle manages it
# Check in Site Administration > File converters > Unoconv settings

# OR for googledrive converter:
# Configure Google Drive API credentials in Site Administration
```

**If no converters configured:**
- Plugin still works via fallback plain text extraction
- User informed via warning message

---

## Moodle APIs to Use

| API | Purpose | File |
|-----|---------|------|
| `\core_files\conversion` | Request and track document conversion | `lib/files/conversion.php` |
| `filepicker` form element | Frontend upload widget | `lib/form/filepicker.php` |
| `file_storage` | Access uploaded files | `lib/filestorage/file_storage.php` |
| `\mod_book\local\structure\chapter` | Book chapter management | `mod/book/classes/local/structure/chapter.php` |
| `add_moduleinfo()` | Create book activity | `course/modlib.php` |
| `html_to_text()` | Fallback text extraction | `lib/moodlelib.php` |
| `DOMDocument` | Parse and manipulate HTML | PHP Standard Library |

---

## No External Dependencies (Beyond Moodle's Ecosystem)
- ✅ No external PHP libraries required
- ✅ No npm packages needed
- ✅ No CDN-based tools
- ✅ All via Moodle core + configured converters
- ✅ LibreOffice is system software (not a PHP dependency)

---

## Recommendation
**Use Moodle's native `\core_files\conversion` API:**

1. **Advantages:**
   - Works with any converter admin configures
   - Site-wide consistency
   - Handles async conversion automatically
   - Graceful fallback to text extraction
   - No custom system calls needed

2. **Fallback behavior:**
   - If no converters available → extract plain text automatically
   - User informed via warning
   - Feature always works

3. **Future-proof:**
   - If admin enables new converters → your code works without changes
   - Supports Google Drive, future converters, custom plugins

---

## Quick Start Integration
```php
// In your file processor:
$file = /* uploaded file from filepicker */;
$conversions = \core_files\conversion::get_conversions_for_file($file, 'html');

if (!empty($conversions)) {
    $conversion = reset($conversions);
    $destfile = $conversion->get_destfile();
    $htmlcontent = $destfile->get_content();
    $chapters = $processor->parse_html_to_chapters($htmlcontent);
} else {
    // Fallback to text extraction
    $textcontent = $processor->extract_text_from_file($file);
}
```

