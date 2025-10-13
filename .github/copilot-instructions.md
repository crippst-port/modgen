# Copilot Instructions for AI Agents

## Project Overview
This codebase is a Moodle plugin named **Module Generator** (`aiplacement_modgen`). It is designed to be installed in a Moodle instance under `ai/placement/modgen`. The plugin is currently in alpha maturity and under active development.

## Key Files & Structure
- `settings.php`: Defines plugin admin settings. Extend this to add configuration options using Moodle's admin settings API.
- `version.php`: Contains plugin metadata (component name, version, required Moodle version, maturity).
- `lang/en/aiplacement_modgen.php`: Defines English language strings for the plugin.
- `README.md`: Outlines installation steps and licensing. Update with functional and architectural details as the project evolves.

## Development Patterns
- **Moodle Plugin Conventions**: Follow [Moodle plugin development docs](https://moodledev.io/docs/apis/core/plugins) for file structure, naming, and API usage.
- **Settings**: Use `admin_settingpage` and `$ADMIN->fulltree` in `settings.php` for configuration. See [Admin settings API](https://docs.moodle.org/dev/Admin_settings).
- **Language Strings**: Define all user-facing text in `lang/en/aiplacement_modgen.php` using `$string` array.
- **Versioning**: Update `version.php` for every release. Bump `$plugin->version` and `$plugin->release` as needed.

## Workflows
- **Installation**: Install via Moodle's plugin interface or manually by copying files and running `php admin/cli/upgrade.php`.
- **Upgrades**: After code changes, always run the Moodle upgrade script to apply database or config changes.
- **Debugging**: Enable Moodle debugging in site admin for error visibility. Check logs for plugin-related issues.

## Project-Specific Notes
- The plugin is in early development; many features are marked as TODO. Extend settings and functionality following Moodle's best practices.
- All code must be GPL v3 or later.
- Use the provided language file for all new strings.
- No custom build or test scripts are present; rely on Moodle's upgrade and validation mechanisms.

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

## References
- [Moodle Plugin Development Docs](https://moodledev.io/docs/apis/core/plugins)
- [Admin Settings API](https://docs.moodle.org/dev/Admin_settings)

---
**Update this file as the plugin evolves.**
