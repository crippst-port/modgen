# Tabbed Modal Implementation Summary

## ✅ Completed Components

### 1. **Moodle File Handling Assessment** (`MOODLE_FILE_HANDLING_ASSESSMENT.md`)
- Confirmed all features are **100% native Moodle**—no third-party dependencies
- Document: LibreOffice conversion, fallback text extraction, HTML parsing
- Supports: `.docx`, `.doc`, `.odt`
- Book creation: Direct database insertion of chapters using Moodle APIs

### 2. **Tabbed Modal Template** (`templates/modal_tabbed.mustache`)
- Bootstrap 5 tab interface with smooth animations
- Two tabs: "Generate from Template" and "Upload Content"
- Responsive design, accessibility attributes (aria-*)
- CSS styling for active/hover states

### 3. **File Processor** (`classes/local/filehandler/file_processor.php`)
- Detects available converters via Moodle's conversion API
- Converts documents to HTML via `\core_files\conversion`
- Fallbacks: 
  - If no converters available: ZIP extraction + XML parsing for `.docx`/`.odt`
  - `.doc` → Plain text if converters unavailable
- Parses HTML heading hierarchy into book chapters (H1 = chapter, H2+ = sections)
- **100% uses Moodle's native APIs**

### 4. **Book Activity Handler** (`classes/local/activity/book_activity.php`)
- Implements `activity_type` interface
- Creates book module with chapters
- Stores chapters in `book_chapters` table with content and formatting
- Returns success message with chapter count

### 5. **Language Strings** (Updated `lang/en/aiplacement_modgen.php`)
- Tab labels, file upload labels
- Error messages for unsupported types, conversion failures
- Success messages with chapter counts

---

## 🔧 Remaining Work (Step-by-Step)

### Step 1: Create File Upload Form Class
Add to `prompt.php` after the `aiplacement_modgen_prompt_form` class:

```php
class aiplacement_modgen_upload_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);
        if (!empty($this->_customdata['embedded'])) {
            $mform->addElement('hidden', 'embedded', 1);
            $mform->setType('embedded', PARAM_BOOL);
        }
        
        $mform->addElement('filepicker', 'contentfile', 
            get_string('contentfile', 'aiplacement_modgen'),
            null, 
            ['accepted_types' => ['.docx', '.doc', '.odt']]
        );
        $mform->addRule('contentfile', null, 'required', null, 'client');
        $mform->addHelpButton('contentfile', 'contentfile', 'aiplacement_modgen');
        
        $activities = [
            'book' => get_string('activitytype_book', 'aiplacement_modgen') . ' - Chapter-based content',
        ];
        $mform->addElement('select', 'activitytype', 
            get_string('selectactivitytype', 'aiplacement_modgen'), $activities);
        $mform->setType('activitytype', PARAM_ALPHA);
        $mform->setDefault('activitytype', 'book');
        
        $mform->addElement('text', 'activityname', 
            get_string('name', 'moodle'));
        $mform->setType('activityname', PARAM_TEXT);
        $mform->addRule('activityname', null, 'required', null, 'client');
        
        $mform->addElement('textarea', 'activitydescription', 
            get_string('intro', 'moodle'), 'rows="3" cols="60"');
        $mform->setType('activitydescription', PARAM_RAW);
        
        $this->add_action_buttons(false, get_string('uploadandcreate', 'aiplacement_modgen'));
    }
}
```

### Step 2: Render Tabbed Modal in prompt.php
Replace the initial form rendering with:

```php
// Generate tab content
$generateform = new aiplacement_modgen_prompt_form(null, [
    'courseid' => $courseid,
    'embedded' => $embedded ? 1 : 0,
]);
$generatecontent = $generateform->render();

// Upload tab content
$uploadform = new aiplacement_modgen_upload_form(null, [
    'courseid' => $courseid,
    'embedded' => $embedded ? 1 : 0,
]);
$uploadcontent = $uploadform->render();

// Render tabbed modal
$tabdata = [
    'generatecontent' => $generatecontent,
    'uploadcontent' => $uploadcontent,
    'generatetablabel' => get_string('generatetablabel', 'aiplacement_modgen'),
    'uploadtablabel' => get_string('uploadtablabel', 'aiplacement_modgen'),
];
echo $OUTPUT->render_from_template('aiplacement_modgen/modal_tabbed', $tabdata);
```

### Step 3: Handle Upload Form Submission
Add handling logic in `prompt.php` after checking `$approveform`:

```php
$uploadform = null;
$uploadjsonparam = optional_param('uploadjson', null, PARAM_RAW);

if ($uploadjsonparam !== null) {
    // Already processed, show results
    $uploaddata = json_decode($uploadjsonparam, true);
    // ... show results
} else {
    $uploadform = new aiplacement_modgen_upload_form(null, [
        'courseid' => $courseid,
        'embedded' => $embedded ? 1 : 0,
    ]);
    
    if ($udata = $uploadform->get_data()) {
        // Process file upload
        $processor = new \local_aiplacement_modgen\filehandler\file_processor($context);
        
        // Get uploaded file
        file_save_draft_area_files($udata->contentfile, $context->id, 'user', 'draft', 0);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'user', 'draft', $udata->contentfile);
        
        foreach ($files as $file) {
            if ($file->is_directory()) continue;
            
            // Extract content
            $extraction = $processor->extract_content_from_file($file, 'html');
            
            if ($extraction['success']) {
                // Parse to chapters
                $chapters = $processor->parse_html_to_chapters($extraction['content']);
                
                // Create book activity
                $activitydata = new stdClass();
                $activitydata->name = $udata->activityname;
                $activitydata->intro = $udata->activitydescription;
                $activitydata->chapters = $chapters;
                
                $bookhandler = new \local_aiplacement_modgen\activity\book_activity();
                $result = $bookhandler->create($activitydata, $course, 1);
                
                if ($result) {
                    // Show success
                    $resultsdata = [
                        'results' => [$result['message']],
                        'notifications' => [],
                    ];
                    // Add warnings if any
                    foreach ($extraction['warnings'] as $warning) {
                        $resultsdata['notifications'][] = [
                            'message' => $warning,
                            'classes' => 'alert alert-warning',
                        ];
                    }
                }
            }
        }
    }
}
```

### Step 4: Language Strings for Book Activity
Add to `lang/en/aiplacement_modgen.php`:
```php
$string['activitytype_book'] = 'Book';
```

---

## 📋 Key Files Created/Modified

| File | Status | Purpose |
|------|--------|---------|
| `MOODLE_FILE_HANDLING_ASSESSMENT.md` | ✅ Created | Feasibility guide |
| `templates/modal_tabbed.mustache` | ✅ Created | Tabbed UI |
| `classes/local/filehandler/file_processor.php` | ✅ Created | Document extraction |
| `classes/local/activity/book_activity.php` | ✅ Created | Book creation handler |
| `lang/en/aiplacement_modgen.php` | ✅ Updated | New strings |
| `prompt.php` | ⏳ To update | Form integration |

---

## 🎯 Architecture

```
Modal Tabs
├─ Tab 1: Generate from Template (existing workflow)
│  └─ aiplacement_modgen_prompt_form
└─ Tab 2: Upload Content (new)
   └─ aiplacement_modgen_upload_form
      ├─ File picker (.docx, .doc, .odt)
      ├─ Activity type selector (Book, future: Label, etc.)
      └─ Activity metadata (name, intro)
      
Upload Flow:
1. User uploads file
2. FileProcessor extracts content (LibreOffice → HTML or fallback)
3. Chapters parsed from HTML (H1 = chapter)
4. BookActivity creates module + inserts chapters
5. Results displayed with any warnings
```

---

## 🔒 No External Dependencies
✅ All using Moodle core APIs:
- `file_storage`, `filepicker` (file upload)
- `\core_files\conversion` (document conversion - uses site-configured converters)
- `add_moduleinfo()` (activity creation)
- `mod_book_chapters` (chapter storage)
- PHP's native `DOMDocument`, `ZipArchive` (fallback extraction)

---

## 🚀 Next Steps
1. Add `aiplacement_modgen_upload_form` class definition to `prompt.php`
2. Implement tab rendering logic
3. Add file processing and book creation logic
4. Test file uploads and chapter extraction
5. Consider future activity types (Label, Resource, etc.)
