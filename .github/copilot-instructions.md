# Copilot Instructions for AI Agents

## Project Overview
**Module Generator** (`aiplacement_modgen`) is a Moodle plugin for AI-assisted course module creation. It integrates with Moodle's core AI subsystem to generate course structures, activities, and pedagogical insights. Install under `ai/placement/modgen`. Currently in alpha (v0.2.0).

## Architecture: Three Core Systems

### 1. Module Generation (`prompt.php` + `classes/local/ai_service.php`)
**Data flow:** User prompt → AI service → JSON schema → Activity registry → Moodle activities

```php
// Core generation path
ai_service::generate_module($prompt, $docs, $structure, $template_data)
  → Moodle core_ai\manager::process_action()
  → Returns JSON with sections/themes + activities array
  → registry::create_activities($activities, $course, $section)
  → Dispatches to activity_type handlers (quiz, label, book, etc.)
```

**Critical patterns:**
- AI prompts include strict JSON schema enforcement (see `ai_service.php:80-150`)
- Activity types discovered via registry scanning `classes/activitytype/*.php`
- Template mode: Includes HTML structure + Bootstrap classes in prompt for visual consistency
- JSON normalization handles double-encoded responses (`normalize_ai_response()`)

### 2. Module Exploration (`explore.php` + `ajax/explore_ajax.php`)
**Data flow:** Course data → AI analysis → Cached insights → Chart.js visualization → PDF export

```javascript
// Frontend orchestration (explore.js)
loadInsights(courseId, refresh) 
  → fetch('ajax/explore_ajax.php?courseid=X&refresh=0/1')
  → processInsights() // Extract for PDF
  → renderAllSections() // Mustache templates
  → renderCharts() // Chart.js with setTimeout delays
```

**Caching system** (`classes/local/explore_cache.php` + `aiplacement_modgen_cache` table):
- Cache hit: <500ms database query
- Cache miss: 5-10s AI generation, then cached
- User-triggered refresh: Updates cache with fresh AI analysis
- See `docs/CACHING_SYSTEM.md` for complete architecture

### 3. Activity Type Registry (`classes/activitytype/`)
**Extensible plugin architecture** - discovers handlers via filesystem scan:

```php
// Activity handler contract (activity_type.php)
interface activity_type {
    public static function get_type(): string;           // 'quiz', 'label', 'book'
    public static function get_display_string_id(): string;
    public static function get_prompt_description(): string; // Sent to AI
    public function create(stdClass $data, ...): ?array;
}
```

**Adding new activity types:**
1. Create `classes/activitytype/{type}.php` implementing interface
2. Implement `create()` using Moodle's `create_module($moduleinfo)`
3. Registry auto-discovers on next request (no registration needed)
4. AI automatically includes in schema via `registry::get_supported_activity_metadata()`

## Critical Developer Workflows

### JavaScript Build Pipeline (Grunt)
```bash
npm install              # One-time setup
npm run build            # Minify: amd/src/*.js → amd/build/*.min.js
npm run watch            # Auto-minify on save
```
**Always commit both** source (`amd/src/`) **and** minified (`amd/build/`) files.

### Moodle Cache Management
```bash
php admin/cli/upgrade.php        # Apply DB schema changes
php admin/cli/purge_caches.php   # Clear after JS/template changes
```

### Debugging Patterns
- **AI responses:** Check `/tmp/modgen_debug.log` (temporary debug logs from `ai_service.php`)
- **AJAX calls:** Browser Network tab → `explore_ajax.php` → Verify `success: true`
- **Chart rendering:** Add `setTimeout(..., 100-500)` if canvas elements not ready
- **Activity creation:** Check `registry::create_activities()` debug logs in `/tmp/modgen_debug.log`

## Project-Specific Conventions

### PHP: AI Service Integration
```php
// ALWAYS use Moodle's AI subsystem, never direct API calls
$action = new \core_ai\aiactions\generate_text($contextid, $userid, $prompt);
$response = $aimanager->process_action($action);
$text = $response->get_response_data()['generatedtext'];

// AI prompts MUST include JSON schema for structured responses
$schema = ['type' => 'object', 'required' => ['sections'], ...];
$prompt = "Role: {$pedagogical_guidance}\n\nUser: {$user_input}\n\nSchema: " . json_encode($schema);
```

### JavaScript: AMD + Moodle Templates
```javascript
// ALWAYS use templates.renderForPromise() for complex HTML
templates.renderForPromise('aiplacement_modgen/insights_summary', data)
    .then(({html, js}) => {
        element.innerHTML = html;
        templates.runTemplateJS(js);
    });

// Chart rendering needs DOM stability delay
setTimeout(() => {
    new Chart(canvas, config);
}, 200);
```

### Naming Conventions
- **JavaScript:** camelCase (`loadInsights`, `renderCharts`) - snake_case is linting error
- **PHP:** snake_case (`generate_module`, `explore_cache`)
- **Language strings:** `$string['key']` in `lang/en/aiplacement_modgen.php`
- **CSS classes:** Kebab-case (`aiplacement-modgen-embedded`)

### Template-Based Generation (Advanced)
When `$template_data` is passed to `generate_module()`:
- AI prompt includes actual HTML structure + Bootstrap classes from template
- Section summaries MUST be HTML, not plain text
- Extracted Bootstrap classes guide visual consistency
- See `build_template_prompt_guidance()` in `ai_service.php`

## Integration Points

### Moodle Core AI Subsystem
- `placement.php` registers with core AI manager
- Only supports `\core_ai\aiactions\generate_text` action
- Requires user to accept AI policy (`$aimanager->get_user_policy_status()`)

### Course Navigation Hook
```php
// lib.php - Injects FAB button in edit mode
function aiplacement_modgen_extend_navigation_course($navigation, $course, $context) {
    $PAGE->requires->js_call_amd('aiplacement_modgen/fab', 'init', [$params]);
}
```

### External Libraries
- **Chart.js** (via Moodle AMD): Pie charts, bar charts in explore.js
- **Mustache** (Moodle core): All template rendering
- **Grunt + Uglify** (dev): JavaScript minification pipeline

## File Organization Logic

### Code Files
- `*.php` (root): Page controllers and entry points
- `classes/`: Autoloaded classes (PSR-4: `aiplacement_modgen\*`)
- `classes/form/`: Moodle form definitions
- `classes/local/`: Internal services (not external API)
- `classes/activitytype/`: Plugin system for activity handlers

### Frontend Assets
- `amd/src/`: AMD source JavaScript (ESLint checked, never minified in git)
- `amd/build/`: Minified JS (Grunt output, commit both)
- `templates/`: Mustache templates
- `ajax/`: AJAX endpoints (return JSON, no page wrapper)

### Documentation
- `docs/`: ALL documentation, debug scripts, guides
- `docs/*_REFERENCE.md`: Quick reference guides
- `docs/test_*.php`: Debug/test scripts (not in production)

## Known Issues & Workarounds

1. **Chart Race Conditions:** Canvas elements may not exist when JS runs → Use `setTimeout(fn, 200)`
2. **AI Double-Encoded JSON:** AI sometimes returns stringified JSON in JSON → `normalize_ai_response()` handles this
3. **Activity Registry Cache:** After adding new activity handler, may need to purge caches
4. **FAB Button Initialization:** Requires page edit mode + `has_capability('local/aiplacement_modgen:use')`

## Testing Checklist (Post-Edit)
1. Run `npm run build` after JS changes
2. Clear Moodle caches (`purge_caches.php`)
3. Browser DevTools: Network tab (AJAX), Console (errors), Elements (DOM)
4. Check `/tmp/modgen_debug.log` for AI service issues
5. Test with course editing ON and OFF
6. Verify language strings in `lang/en/` file

## Key Documentation
- `docs/EXPLORE_QUICK_REFERENCE.md`: explore.js API
- `docs/CACHING_SYSTEM.md`: Cache architecture
- `docs/GRUNT_SETUP.md`: Build system
- `docs/*_ACTIVITY_HANDLER.md`: Activity type patterns

## Critical Rules
- **Never commit to git** - User handles all commits
- **GPL v3+ license** - All code must be compatible
- **All strings in lang file** - Never hardcode user-facing text
- **Documentation in docs/** - Keep root directory clean
- **Commit minified JS** - Both src/ and build/ files
