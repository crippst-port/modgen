# AI Placement - Module Generator

An AI-powered Moodle plugin that helps educators rapidly create structured course content using generative AI. Part of the AI Placement Tools suite.

## Overview

The Module Generator (aiplacement_modgen) plugin streamlines course design by:

- **AI-Powered Section Creation**: Generate themes, weeks, or sessions with AI assistance
- **Flexible Course Structures**: Support for hierarchical sections using format_flexsections
- **Curriculum Templates**: Import from JSON or create from scratch
- **Activity Suggestions**: AI recommends appropriate learning activities
- **Date Management**: Automatically schedule sections with holiday support
- **Background Processing**: Handle large operations via Moodle's task queue

## Features

### Core Functionality

- **Theme-based Structure**: Create top-level themes with AI-generated titles and descriptions
- **Week-based Structure**: Generate weekly sections with configurable start dates
- **Session Builder**: Create structured sessions within themes
- **JSON Import**: Bulk import pre-defined curriculum structures
- **Activity Explorer**: Visualize learning types (Acquisition, Discussion, Production, etc.)

### AI Integration

Uses Moodle's core AI subsystem (Moodle 4.5+) to:
- Generate contextually appropriate section titles
- Create engaging descriptions
- Suggest relevant learning activities
- Maintain pedagogical balance across learning types

### GDPR Compliance

Full Privacy API implementation:
- User data export
- Right to erasure
- Metadata transparency
- Automatic cleanup of old operational data (30-day retention)

## Requirements

- **Moodle**: 4.5 or higher
- **Course Format**: format_flexsections (recommended) or topics
- **PHP**: 8.1 or higher
- **AI Provider**: Configured via Moodle AI subsystem

## Installation

### Via Moodle Plugin Directory

1. Visit Site administration > Plugins > Install plugins
2. Search for "AI Placement - Module Generator"
3. Click Install and follow the prompts

### Via Uploaded ZIP

1. Download the latest release from GitHub
2. Log in as admin and go to Site administration > Plugins > Install plugins
3. Upload the ZIP file
4. Complete the installation wizard

### Manual Installation

1. Extract the plugin to: `{moodle}/ai/placement/modgen`
2. Run: `php admin/cli/upgrade.php`
3. Or visit: Site administration > Notifications

## Configuration

### Setup AI Provider

1. Navigate to: Site administration > AI > AI Providers
2. Configure your preferred provider (OpenAI, Azure, etc.)
3. Enable the provider and set API credentials

### Plugin Settings

Site administration > Plugins > AI Placement Tools > Module Generator:

- **Default Section Format**: Choose default structure (themes/weeks)
- **AI Generation Timeout**: Max time for AI operations (default: 60s)
- **Background Job Retention**: Days to keep completed jobs (default: 30)

## Usage

### Creating Course Structure

1. Navigate to your course
2. Access: Course administration > AI Tools > Module Generator
3. Select structure type (Themes, Weeks, Sessions, or JSON)
4. Configure parameters and click "Generate"
5. Review and apply suggestions

### Date Management

1. Go to: Course administration > AI Tools > Module Dates
2. Select sections to schedule
3. Set term start date
4. Optionally add holiday dates
5. Click "Apply Dates"

### Activity Suggestions

1. Open Module Generator interface
2. Click "Suggest Activities" for any section
3. Review AI-recommended activities
4. Add selected activities to your course

## Permissions

| Capability | Description | Default Roles |
|------------|-------------|---------------|
| `aiplacement/modgen:use` | Use the module generator | Teacher, Manager |
| `aiplacement/modgen:usesuggest` | Get AI activity suggestions | Teacher, Manager |
| `aiplacement/modgen:managedates` | Manage section dates | Teacher, Manager |
| `aiplacement/modgen:manage` | Full administrative access | Manager |

## Privacy & Data

### Data Collected

- User ID (who created sections)
- Course ID (where sections were created)
- Job parameters (may include prompts/templates)
- Job results (AI-generated content)
- Timestamps

### Data Retention

- Job records: Auto-deleted after 30 days
- Course content: Retained as institutional asset
- User deletion: Removes job records, preserves course content

### Compliance

- ✅ GDPR Article 13 (Right to be informed)
- ✅ GDPR Article 15 (Right of access)
- ✅ GDPR Article 17 (Right to erasure)
- ✅ GDPR Article 20 (Right to portability)

## Development

### Running Tests

```bash
# Initialize PHPUnit
php admin/tool/phpunit/cli/init.php

# Run plugin tests
vendor/bin/phpunit --testsuite aiplacement_modgen_testsuite

# Run specific test
vendor/bin/phpunit ai/placement/modgen/tests/privacy_test.php
```

### Code Quality

```bash
# Check coding standards
phpcs --standard=moodle ai/placement/modgen/

# Auto-fix minor issues
phpcbf --standard=moodle ai/placement/modgen/
```

## Troubleshooting

### Common Issues

**"Could not acquire lock"**
- Another operation is running on this course
- Wait 60 seconds and try again
- Check scheduled tasks aren't stuck

**"AI generation failed"**
- Verify AI provider is configured
- Check API credentials and quota
- Review Moodle error logs

**Sections not appearing**
- Ensure flexsections format is installed
- Clear Moodle caches
- Rebuild course cache

### Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/moodle-aiplacement_modgen/issues)
- **Documentation**: [Wiki](https://github.com/yourusername/moodle-aiplacement_modgen/wiki)
- **Email**: tom.cripps@port.ac.uk

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

Copyright 2025 Tom Cripps <tom.cripps@port.ac.uk>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <https://www.gnu.org/licenses/>.

## Credits

Developed by Tom Cripps for the University of Portsmouth.

Built with:
- Moodle AI Subsystem
- format_flexsections by Marina Glancy
- Pedagogical framework based on Laurillard's Conversational Framework
