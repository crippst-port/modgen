# Test Scripts Directory

This directory contains test, debug, and demonstration scripts for the Module Generator plugin.

## Test Scripts

### Layout Detection & Date Application
- **test_layout_detection.php** - Detects and displays course layout type (theme-based, week-based, or flat)
  ```bash
  php test_layout_detection.php <courseid>
  ```

- **test_layout_dates.php** - Validates that dates are applied correctly based on layout type
  ```bash
  php test_layout_dates.php <courseid>
  ```

- **demo_layout_types.php** - Creates test courses for all three layout types
  ```bash
  php demo_layout_types.php
  ```

### Section & Parent Relationships
- **test_session_parent_relationships.php** - Verifies parent-child relationships in flexsections
  ```bash
  php test_session_parent_relationships.php
  ```

- **test_section_dates.php** - Tests section date calculation with various scenarios
  ```bash
  php test_section_dates.php <courseid>
  ```

### Stress Testing
- **test_stress_course_generation.php** - Creates large courses to test performance and scalability
  ```bash
  php test_stress_course_generation.php
  ```

### Other Tests
- **test_suggestions.php** - Tests AI suggestion functionality
  ```bash
  php test_suggestions.php
  ```

## Utility Scripts

- **view_token_usage.sh** - Displays AI token usage statistics
  ```bash
  ./view_token_usage.sh
  ```

## Debug Scripts

### Section Analysis
- **debug_sections.php** - Analyzes section hierarchy and parent relationships
  ```bash
  php debug_sections.php <courseid>
  ```

- **debug_section_dates.php** - Shows which sections have removable date patterns
  ```bash
  php debug_section_dates.php <courseid>
  ```

- **debug_form_sections.php** - Debugs form section filtering logic
  ```bash
  php debug_form_sections.php <courseid>
  ```

## Usage Examples

### Verify Layout Detection
```bash
# Check if course structure is correctly identified
php test_layout_detection.php 4
```

### Test Date Application
```bash
# Ensure dates apply only to weeks, not themes
php test_layout_dates.php 4
```

### Create Test Courses
```bash
# Generate courses with all layout types
php demo_layout_types.php
```

### Debug Section Issues
```bash
# Analyze section hierarchy
php debug_sections.php 2
```

## Test Courses

Common test course IDs:
- **Course 2**: Week-based layout
- **Course 4**: Theme-based layout
- **Course 27**: Week-based layout (created by demo)
- **Course 28**: Flat layout (created by demo)

## Notes

- All scripts require Moodle CLI environment
- Run from the `docs/tests/` directory
- Use absolute paths if running from elsewhere
- Most scripts accept a course ID parameter
