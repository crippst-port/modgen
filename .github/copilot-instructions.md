# Copilot Instructions for AI Agents

## Project Overview
This codebase is a Moodle plugin named **Module Generator** (`aiplacement_modgen`). It is designed to be installed in a Moodle instance under `ai/placement/modgen`. The plugin is currently in alpha maturity and under active development.

**Current Focus:** The Explore page displays AI-generated insights about course modules, with features for viewing pedagogical insights, learning type analysis, workload analysis, interactive charts, and PDF report generation.

## Key Files & Structure

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

## Development Patterns

### PHP & Moodle Conventions
- **Moodle Plugin Conventions**: Follow [Moodle plugin development docs](https://moodledev.io/docs/apis/core/plugins) for file structure, naming, and API usage.
- **Settings**: Use `admin_settingpage` and `$ADMIN->fulltree` in `settings.php` for configuration. See [Admin settings API](https://docs.moodle.org/dev/Admin_settings).
- **Language Strings**: Define all user-facing text in `lang/en/aiplacement_modgen.php` using `$string` array.
- **Versioning**: Update `version.php` for every release. Bump `$plugin->version` and `$plugin->release` as needed.

### JavaScript (AMD Modules)
- **Module Architecture**: Use functional patterns with clear separation of concerns (fetch → process → render)
- **Code Organization**: Structure as:
  1. Module overview comment (JSDoc with purpose, key functions, architecture)
  2. Configuration and state (module-level variables)
  3. Private helper functions (prefixed with `// PRIVATE` comment)
  4. Public API (returned object with public methods)
- **Documentation**: Every function should have JSDoc comments including purpose, parameters with types, return values, and usage notes
- **DOM Access**: Use helper functions like `getElement(id)` for safe DOM queries; always check null
- **Naming**: Use camelCase for functions/variables, never snake_case
- **Error Handling**: Use `.catch()` chains for fetch requests; fail gracefully without breaking page
- **Timing**: Use `setTimeout()` for DOM stability when rendering charts (100-500ms delays prevent race conditions)
- **Templates**: Use Moodle's `templates.renderForPromise()` for complex HTML rendering; prefer over manual DOM creation

## Code Quality
- **Linting**: JavaScript files are linted with ESLint. Use camelCase naming and eliminate console.log statements.
- **Reduce Errors**: Aim for minimal linting errors; address naming conventions and unused parameters
- **Comments**: Add inline comments explaining complex logic, especially in loops, conditionals, and async operations
- **Refactoring**: When functions exceed 50 lines or handle multiple concerns, break them into smaller focused functions
- **Testing**: After code changes, manually test in browser: check Network tab for AJAX calls, browser console for errors
- **Documentation**: When creating new features, document them in `docs/` folder with reference guides

## Workflows
- **Installation**: Install via Moodle's plugin interface or manually by copying files and running `php admin/cli/upgrade.php`.
- **Upgrades**: After code changes, always run the Moodle upgrade script to apply database or config changes.
- **Cache Purging**: After JavaScript/template changes, run `php admin/cli/purge_caches.php` to clear caches
- **Debugging**: Enable Moodle debugging in site admin for error visibility. Check logs for plugin-related issues.
- **Browser Testing**: Check Network tab (DevTools) for AJAX calls, console for errors, and DOM for rendered content
- **GIT**: NEVER commit to git. All changes are made directly to working files. The user will handle commits.

## Project-Specific Notes
- The plugin is in early development; many features are marked as TODO. Extend settings and functionality following Moodle's best practices.
- All code must be GPL v3 or later.
- Use the provided language file for all new strings.
- **Documentation Folder**: All new documentation, guides, debug files, and test scripts should go in `docs/` folder to keep main directory clean
- **Known Issues**:
  - Refresh button AMD module initialization may require debugging (check browser Network tab and PHP logs)
  - Chart rendering requires DOM stability delays (setTimeout 100-500ms) to prevent race conditions
- **Best Practices for New Features**:
  1. Create new code with comprehensive comments and JSDoc
  2. Place all documentation/guides in `docs/` folder
  3. Test thoroughly in browser DevTools (Network tab for AJAX, Console for errors)
  4. Keep functions focused and under 50 lines where possible
  5. Use helper functions to eliminate code duplication
  6. Always handle errors gracefully (try/catch, .catch() chains)

## Explore Page (Recent Refactoring)

### Overview
The Explore page displays AI-generated insights with interactive charts and PDF download functionality. The main JavaScript module (`explore.js`) was refactored to improve maintainability and code quality.

### Architecture
- **init()**: Initializes the module and triggers data loading
- **loadInsights()**: Orchestrates the entire flow (fetch → process → render → cleanup)
- **processInsights()**: Extracts text data for PDF generation
- **renderAllSections()**: Coordinates rendering of all page sections
- **renderCharts()**: Renders interactive Chart.js visualizations
- **downloadReport()**: Generates and downloads PDF reports

### Key Improvements (Recent Refactoring)
- Reduced linting errors: 74 → 29 (60.8% reduction)
- Function complexity: 36 → 4 (main orchestrator function)
- Added 100+ lines of JSDoc and inline comments
- Broke 150-line monolithic function into 12 focused functions
- Separated concerns: fetching vs processing vs rendering

### DOM Elements Expected
The explore.js module expects these HTML IDs in the template:
- `insights-pedagogical` / `ped-heading` / `ped-content`: Pedagogical insights section
- `insights-summary`: Summary section (template-based)
- `insights-workload-analysis`: Workload analysis section
- `learning-types-chart` / `section-activity-chart`: Chart canvases
- `insights-loading`: Loading spinner
- `content-wrapper`: Main content wrapper
- `download-report-btn`: PDF download button

### Data Flow
1. AJAX endpoint (`explore_ajax.php`) returns insights JSON
2. JavaScript processes and stores data
3. Templates render static content
4. Charts render with delays for DOM stability
5. User can download PDF with processed data

### Debugging Tips
- Check Network tab for AJAX calls to `explore_ajax.php`
- Verify response has `success: true` and `data` object
- Check canvas elements exist in DOM before chart rendering
- Use browser console to verify `reportData` exists before PDF download
- Look for DOM element IDs if sections not displaying

## Example: Adding a Setting
To add a new admin setting:
```php
if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'aiplacement_modgen/example',
        new lang_string('example', 'aiplacement_modgen'),
        'Description of the setting.',
        'defaultvalue'
    ));
}
```

## Documentation References
When working on the Explore feature, refer to:
- `docs/EXPLORE_QUICK_REFERENCE.md`: Quick API reference and function guide
- `docs/EXPLORE_BEFORE_AFTER.md`: Visual comparison of refactoring improvements
- `docs/EXPLORE_REFACTORING.md`: Detailed migration guide and code statistics

## References
- [Moodle Plugin Development Docs](https://moodledev.io/docs/apis/core/plugins)
- [Admin Settings API](https://docs.moodle.org/dev/Admin_settings)

---
**Update this file as the plugin evolves.**
