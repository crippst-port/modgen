# Quick Reference: Native Moodle Conversion API

## Usage in Your Code

```php
use core_files\conversion;

// Get a conversion for a file
$conversions = conversion::get_conversions_for_file($file, 'html');

if (!empty($conversions)) {
    // Use the first available conversion
    $conversion = reset($conversions);
    $destfile = $conversion->get_destfile();
    
    if ($destfile) {
        $htmlcontent = $destfile->get_content();
        // Process the HTML...
    }
} else {
    // No converters available - use fallback (text extraction)
    $textcontent = extract_text_from_file($file);
}
```

## Available Converters (Built-in)

### unoconv Converter
- **Location:** `/files/converter/unoconv/`
- **Backend:** LibreOffice/OpenOffice network protocol
- **Supports:** `.docx`, `.doc`, `.odt` → PDF, HTML, SVG, ODT, DOCX
- **Status:** Included with Moodle
- **Admin Setup:** Site Admin > File converters > Unoconv settings

### googledrive Converter
- **Location:** `/files/converter/googledrive/`
- **Backend:** Google Drive API
- **Supports:** Same formats as Google Drive
- **Status:** Included with Moodle (requires API setup)
- **Admin Setup:** Site Admin > File converters > Google Drive settings

### Custom Converters
- Any plugin implementing `fileconverter_*` interface
- Admin can add via plugins
- Your code automatically uses them

## Moodle Conversion API Classes

**Full namespace:** `\core_files\conversion`

### Key Methods
```php
// Get existing conversions for a file
conversion::get_conversions_for_file($file, 'html')

// Create and start a new conversion
$conversion = new conversion($file, 'html');
$conversion->start_conversion();

// Get the destination file
$destfile = $conversion->get_destfile();

// Get content
$content = $destfile->get_content();
```

### Key Properties
```php
$conversion->get_sourcefile()     // Original uploaded file
$conversion->get_destfile()        // Converted file
$conversion->get_from_format()     // Source format
$conversion->get_to_format()       // Target format
```

## Integration Flow

```
Document Upload
    ↓
Choose Activity Type
    ↓
Submit Form
    ↓
File stored in Moodle's file area
    ↓
\core_files\conversion::get_conversions_for_file()
    ↓
    ├─ Converter available?
    │  ├─ YES → Use converted HTML
    │  └─ NO → Use fallback (text extraction)
    ↓
Parse chapters from content
    ↓
Create Book Activity with chapters
    ↓
Success message + warnings (if any)
```

## Error Handling

```php
try {
    $conversions = conversion::get_conversions_for_file($file, 'html');
    
    if (empty($conversions)) {
        // No converter available
        $warnings[] = get_string('noconvertersavailable', 'aiplacement_modgen');
        // Fall back to text extraction
        $content = extract_text_from_file($file);
    } else {
        $conversion = reset($conversions);
        $destfile = $conversion->get_destfile();
        $content = $destfile->get_content();
    }
} catch (Exception $e) {
    // Conversion failed
    $warnings[] = get_string('conversionfailed', 'aiplacement_modgen');
    // Fall back to text extraction
    $content = extract_text_from_file($file);
}
```

## Async Conversion Consideration

**Important:** Moodle conversions can be **asynchronous**!

- `start_conversion()` queues the job
- Result may not be immediate
- Use scheduled tasks for background processing
- For user-facing features, check if conversion is ready:

```php
$conversion = new conversion($file, 'html');
$conversion->start_conversion();

// Try to get result (may not be ready immediately)
$destfile = $conversion->get_destfile();

if ($destfile && $destfile->get_content()) {
    // Conversion complete, process it
    $content = $destfile->get_content();
} else {
    // Conversion in progress or failed
    // Show message to user asking to wait or try again
}
```

## Supported Formats (via unoconv)

Input formats:
- Microsoft Office: `.doc`, `.docx`, `.xls`, `.xlsx`, `.ppt`, `.pptx`
- OpenDocument: `.odt`, `.ods`, `.odp`
- PDF: `.pdf`
- Others: `.txt`, `.rtf`, `.html`

Output formats:
- `.html` (what we use)
- `.pdf`
- `.svg`
- `.odt` (and other OpenDocument formats)
- And many more (depends on LibreOffice capabilities)

## Testing Conversion Availability

```php
// Check if converters are configured
$formats = \core_files\converter::get_supported_conversions();
// Returns array of available format pairs, e.g., ['docx' => ['html', 'pdf'], ...]

// Check specific conversion
$supported = \core_files\converter::supports_conversion($from_format, $to_format);
// Returns true/false
```

## Documentation
- Official Moodle File Conversion API: https://docs.moodle.org/dev/File_Conversion
- Converter plugin development: https://moodledev.io/docs/plugins/fileconverter

## File Processor Reference

See `classes/local/filehandler/file_processor.php` for complete implementation using:
- `\core_files\conversion` for HTML conversion
- ZIP extraction fallback for `.docx` and `.odt`
- HTML → chapters parsing (H1 = chapter)
