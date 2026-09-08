# Copilot Instructions for AI Agents

## Project Overview

**Module Generator** (`aiplacement_modgen`) is a Moodle plugin integrating with Moodle's core AI subsystem to generate course structures and analyze existing modules. Install path: `ai/placement/modgen`. Alpha version 0.2.0.

**⚠️ CRITICAL: Always consult [Moodle Developer Documentation](https://moodledev.io) as the authoritative source for plugin development, APIs, and best practices. This project follows standard Moodle conventions unless explicitly noted below.**

## Architecture: Three Core Flows

**1. Module Generation** (`prompt.php` → `ai_service.php` → `registry.php`)
- User prompt → `ai_service::generate_module()` → Moodle `core_ai\manager::process_action()`
- AI returns JSON with strict schema → `registry::create_activities()` dispatches to handler classes
- Activity handlers auto-discovered via filesystem scan of `classes/activitytype/*.php`
- Template mode includes HTML structure in AI prompt for visual consistency

**2. Module Exploration** (`explore.php` + caching system)
- Cached insights: `explore_cache::get($courseid)` → <500ms database lookup
- Cache miss: 5-10s AI analysis → saved to `aiplacement_modgen_cache` table
- Frontend: `explore.js` orchestrates fetch → process → Chart.js render (with setTimeout delays)
- User refresh button forces new AI analysis and updates cache

**3. Activity Registry** (plugin architecture)
- Discovers handlers by scanning `classes/activitytype/*.php` for `activity_type` interface implementations
- Each handler: `get_type()`, `get_prompt_description()` (sent to AI), `create()` (uses Moodle's `create_module()`)
- No registration needed - add file, implement interface, auto-discovered on next request

### Core Plugin Files
- `settings.php`: Defines plugin admin settings. Extend this to add configuration options using Moodle's admin settings API.
- `version.php`: Contains plugin metadata (component name, version, required Moodle version, maturity).
- `lang/en/aiplacement_modgen.php`: Defines English language strings for the plugin.
- `README.md`: Outlines installation steps and licensing. Update with functional and architectural details as the project evolves.

### Main Feature Files (Explore Page)
- `explore.php`: Main page controller for the Explore feature
- `amd/src/explore.js`: Well-refactored AMD module handling insights display (see `docs/EXPLORE_QUICK_REFERENCE.md` for full details)
- `templates/explore.mustache`: Main page template with loader, content wrapper, and chart containers
- `templates/insights_summary.mustache`: Summary section template
- `templates/workload_analysis.mustache`: Workload analysis section template
- `ajax/explore_ajax.php`: AJAX endpoint for fetching insights data
- `ajax/download_report_pdf.php`: PDF generation endpoint

### Organization
- `docs/`: All documentation, guides, debug files, and test scripts (keep main directory clean)
  - `docs/EXPLORE_QUICK_REFERENCE.md`: Quick reference for explore.js API
  - `docs/EXPLORE_BEFORE_AFTER.md`: Visual comparison of refactoring improvements
  - `docs/EXPLORE_REFACTORING.md`: Migration guide and statistics
  - Legacy debug/test files also stored here

## Critical Workflows

**Build & Deploy:**
```bash
npm install              # One-time: installs Grunt + plugins
npm run build            # Minify amd/src/*.js → amd/build/*.min.js
php admin/cli/upgrade.php        # Apply DB schema changes
php admin/cli/purge_caches.php   # Clear after JS/template changes
```
**Always commit both** `amd/src/` and `amd/build/` files. Grunt watch mode: `npm run watch`.

**Debugging:**
- AI responses: `/tmp/modgen_debug.log` (temporary, see `ai_service.php`)
- AJAX: Browser Network tab → `explore_ajax.php` → verify `success: true` in JSON
- Charts: Add `setTimeout(..., 200)` if canvas elements not ready (DOM race condition)
- Activity creation: Check debug logs in `/tmp/modgen_debug.log` from `registry::create_activities()`

## Development Patterns

### PHP Conventions (Standard Moodle)
Follow [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle) and [Plugin Development](https://moodledev.io/docs/apis/plugintypes) guidelines.

- **Language Strings**: All user text in `lang/en/aiplacement_modgen.php`, never hardcoded ([String API](https://moodledev.io/docs/apis/subsystems/string))
- **Database Access**: Use Moodle DML ([Data Manipulation API](https://moodledev.io/docs/apis/core/dml))
- **Capabilities**: Check permissions via `require_capability()` ([Access API](https://moodledev.io/docs/apis/subsystems/access))
- **Form Handling**: Extend `moodleform` for all forms ([Forms API](https://moodledev.io/docs/apis/subsystems/form))

### PHP Conventions (Project-Specific)
**🔸 Deviations from standard Moodle patterns:**

- **AI Integration**: Always use `core_ai\manager` + `aiactions\generate_text`, never direct API calls ([AI Subsystem](https://moodledev.io/docs/apis/subsystems/ai))
- **AI Prompts**: Include JSON schema in prompt text (see `ai_service.php:80-150` for pattern)
- **JSON Normalization**: AI sometimes double-encodes responses → `normalize_ai_response()` handles recursively (project-specific workaround)
- **Debug Logging**: Uses `/tmp/modgen_debug.log` for AI debugging (⚠️ non-standard, temporary - should use [Moodle debugging](https://moodledev.io/general/development/tools/debugging))

### JavaScript (AMD Modules - Standard Moodle)
Follow [JavaScript Modules](https://moodledev.io/docs/guides/javascript/modules) and [AMD](https://moodledev.io/docs/guides/javascript/modules/amd) guidelines.

- **Module Format**: AMD modules via `define()` ([JavaScript Guide](https://moodledev.io/docs/guides/javascript))
- **Templates**: Use `templates.renderForPromise('aiplacement_modgen/template', data)` ([Templates](https://moodledev.io/docs/guides/templates))
- **Naming**: camelCase for variables/functions ([JS Coding Style](https://moodledev.io/general/development/policies/codingstyle/javascript))
- **Error Handling**: `.catch()` chains on all fetch calls, log via `core/log`
- **Minification**: Use Grunt to build `amd/build/*.min.js` from `amd/src/*.js` ([Grunt Guide](https://moodledev.io/general/development/tools/nodejs#grunt))

### JavaScript (Project-Specific)
**🔸 Deviations/additions:**

- **Chart Timing**: Charts need DOM stability - wrap in `setTimeout(() => new Chart(...), 200)` (⚠️ workaround for race conditions)
- **Structure**: JSDoc header → module state → private helpers → public API object (enhanced documentation pattern)

## Project-Specific Patterns

**Adding New Activity Type:**
1. Create `classes/activitytype/{type}.php` implementing `activity_type` interface
2. Implement `create($activitydata, $course, $sectionnumber, $options)` using `create_module($moduleinfo)` ([Course API](https://moodledev.io/docs/apis/core/course))
3. Registry auto-discovers on next request (🔸 filesystem scan, no manual registration - deviates from typical plugin registration)
4. AI automatically includes new type in schema via `registry::get_supported_activity_metadata()`

**Template-Based Generation** (advanced):
- Pass `$template_data` to `generate_module()` → AI prompt includes actual HTML + Bootstrap classes
- Section summaries become HTML, not plain text → `build_template_prompt_guidance()` in `ai_service.php`
- Extracts Bootstrap classes from template for visual consistency

**Moodle Integration Points (Standard):**
- `lib.php::aiplacement_modgen_extend_navigation_course()` → Injects FAB button via AMD ([Navigation API](https://moodledev.io/docs/apis/subsystems/navigation))
- `placement.php` registers with core AI subsystem ([AI Subsystem](https://moodledev.io/docs/apis/subsystems/ai))
- Requires user acceptance of AI policy via `$aimanager->get_user_policy_status()`
- Uses `require_login()` and `require_capability()` for access control ([Access API](https://moodledev.io/docs/apis/subsystems/access))

## File Organization Logic (Standard Moodle)
Follow [Plugin Files](https://moodledev.io/docs/apis/commonfiles) structure:

- `version.php`: Plugin metadata ([Version.php](https://moodledev.io/docs/apis/commonfiles#versionphp))
- `lib.php`: Standard plugin callbacks ([Lib.php](https://moodledev.io/docs/apis/commonfiles#libphp))
- `settings.php`: Admin settings ([Settings.php](https://moodledev.io/docs/apis/commonfiles/settings.php))
- `lang/en/*.php`: Language strings ([Language Files](https://moodledev.io/docs/apis/commonfiles#language-files))
- `db/`: Database definitions ([Database Files](https://moodledev.io/docs/apis/commonfiles#database-related-files))
- `classes/`: Autoloaded PSR-4 (`aiplacement_modgen\*`) ([Namespaces](https://moodledev.io/general/development/policies/codingstyle/php#namespaces))
- `amd/src/` + `amd/build/`: AMD JavaScript source + minified ([JavaScript Modules](https://moodledev.io/docs/guides/javascript/modules))
- `templates/`: Mustache templates ([Templates](https://moodledev.io/docs/guides/templates))

**🔸 Project-specific additions:**
- `classes/local/`: Internal services (not external API)
- `classes/activitytype/`: Plugin system for activity handlers (auto-discovered)
- `docs/`: ALL documentation, debug scripts, guides (⚠️ non-standard, keeps root clean)
- `ajax/`: AJAX endpoints (🔸 should use web services API - see [Web Services](https://moodledev.io/docs/apis/subsystems/external))

## Critical Rules (Moodle Requirements)
- **GPL v3+ license** - All code must be compatible ([License](https://moodledev.io/general/development/policies/license))
- **All strings in lang file** - `lang/en/aiplacement_modgen.php`, never hardcode ([String API](https://moodledev.io/docs/apis/subsystems/string))
- **Commit minified JS** - Both src/ and build/ files required ([JavaScript Guide](https://moodledev.io/docs/guides/javascript/modules#building-javascript-modules))
- **Follow coding style** - PHPDoc, indentation, naming ([Coding Style](https://moodledev.io/general/development/policies/codingstyle))
- **Security first** - Always validate input, escape output ([Security](https://moodledev.io/docs/security))

**🔸 Project-specific rules:**
- **Never commit to git** - User handles all commits
- **Documentation in docs/** - Keep root directory clean (non-standard location)

## Code Examples

**AI Service Integration Pattern:**
```php
// classes/local/ai_service.php - Always use Moodle AI subsystem
$action = new \core_ai\aiactions\generate_text($contextid, $userid, $prompt);
$response = $aimanager->process_action($action);
$text = $response->get_response_data()['generatedtext'];
// Include JSON schema in $prompt text for structured responses
```

**Activity Handler Implementation:**
```php
// classes/activitytype/example.php
class example implements activity_type {
    public static function get_type(): string { return 'example'; }
    public static function get_prompt_description(): string { 
        return 'Description sent to AI about this activity type'; 
    }
    public function create(stdClass $data, stdClass $course, int $section, array $opts): ?array {
        $moduleinfo = (object)['course' => $course->id, 'modulename' => 'example', ...];
        $cm = create_module($moduleinfo); // Moodle core function
        return ['cmid' => $cm->coursemodule, 'message' => "Created: {$data->name}"];
    }
}
```

## Key Documentation

### Moodle Official Documentation (ALWAYS CHECK FIRST)
- **[Moodle Developer Docs](https://moodledev.io)** - Primary reference for ALL development
- **[Plugin Development](https://moodledev.io/docs/apis/plugintypes)** - Plugin types and structure
- **[Coding Style](https://moodledev.io/general/development/policies/codingstyle)** - PHP, JavaScript, CSS standards
- **[API Reference](https://moodledev.io/docs/apis)** - Core APIs and subsystems
- **[JavaScript Guide](https://moodledev.io/docs/guides/javascript)** - AMD modules, templates, AJAX
- **[Database Schema](https://moodledev.io/docs/apis/core/dml)** - DML and database conventions
- **[Security Best Practices](https://moodledev.io/docs/security)** - Input validation, XSS prevention
- **[Testing](https://moodledev.io/general/development/tools/phpunit)** - PHPUnit and Behat testing

### Project-Specific Documentation
- `docs/EXPLORE_QUICK_REFERENCE.md`: explore.js API reference
- `docs/CACHING_SYSTEM.md`: Cache architecture + performance
- `docs/GRUNT_SETUP.md`: Build system details
- `docs/*_ACTIVITY_HANDLER.md`: Activity type implementation patterns

### When Moodle Docs Conflict with Project Code
**🔸 Flag deviations immediately** and document why:
- Temporary workaround pending proper implementation?
- Performance optimization for specific use case?
- Alpha-stage code that needs refactoring?

**Prefer Moodle standard approaches** - deviate only when absolutely necessary and document the reason.
