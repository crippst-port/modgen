# Module Generator Prompts Directory

This directory contains all AI prompts used by the Module Generator plugin, organized by purpose and structure type. This makes it easy to review, modify, and understand what instructions are sent to the AI.

## Directory Structure

```
docs/prompts/
├── core/                          # Core prompts used in all generation
│   ├── pedagogical_guidance_default.txt       # Default system prompt
│   └── critical_requirements.txt              # Critical rules for all outputs
├── theme/                         # Theme-based structure prompts
│   ├── role_instruction_with_specific_count.txt      # When user requests specific theme count
│   ├── role_instruction_flexible_count.txt           # When user doesn't specify count
│   ├── format_instruction_with_sessions.txt          # JSON format with session phases
│   └── format_instruction_without_sessions.txt       # JSON format without sessions
├── weekly/                        # Weekly structure prompts
│   ├── role_instruction.txt                          # Weekly generation guidance
│   └── format_instruction.txt                        # JSON format for weekly output
├── activities/                    # Activity-related prompts
│   ├── field_specifications.txt                      # Required fields for each activity type
│   ├── validation_rule.txt                           # Supported activity types validation
│   └── instruction_no_activities.txt                 # Instruction when not creating activities
└── special/                       # Special-case prompts
    ├── session_instructions_guidance.txt             # Detailed student guidance for sessions
    ├── final_reminder_theme.txt                      # Final reminder for theme output
    ├── final_reminder_theme_count_requirement.txt    # Specific theme count requirement reminder
    ├── final_reminder_weekly.txt                     # Final reminder for weekly output
    └── week_dates_guidance.txt                       # Week date formatting guidance
```

## How These Prompts Are Used

### Module Generation Flow

1. **Core Setup**
   - `pedagogical_guidance_default.txt` - Establishes AI role and expertise
   - `critical_requirements.txt` - Sets non-negotiable rules (JSON format, content completeness, titles)

2. **Structure Selection (Theme or Weekly)**
   - **For Theme Structure:**
     - If user specified theme count → `role_instruction_with_specific_count.txt`
     - If flexible count → `role_instruction_flexible_count.txt`
     - If activities + sessions → `format_instruction_with_sessions.txt`
     - If no sessions → `format_instruction_without_sessions.txt`
   
   - **For Weekly Structure:**
     - `role_instruction.txt`
     - `format_instruction.txt`

3. **Optional Additions**
   - `field_specifications.txt` - When creating activities
   - `validation_rule.txt` - After activity types list
   - `instruction_no_activities.txt` - When activities disabled
   - `session_instructions_guidance.txt` - When session instructions enabled
   - `week_dates_guidance.txt` - When course has start date

4. **Final Reinforcement**
   - `final_reminder_theme.txt` - For theme output
   - `final_reminder_theme_count_requirement.txt` - If specific count requested
   - `final_reminder_weekly.txt` - For weekly output

## Key Prompts to Modify

### If You Want to Change...

**Theme naming rules**
- Files: `core/critical_requirements.txt`, `theme/role_instruction_*.txt`
- Look for: "NEVER use generic names like 'Theme 1'"

**Activity types and their fields**
- Files: `activities/field_specifications.txt`
- Format: One example per activity type

**Session guidance structure**
- File: `special/session_instructions_guidance.txt`
- Structure: A (Learning Context) → B (Activity Guidance) → C (Learning Outcomes) → D (Support)

**Date format for weeks**
- File: `special/week_dates_guidance.txt`
- Format: "Jan 6 - Jan 12, 2025"

**Theme count constraints**
- Files: `theme/role_instruction_with_specific_count.txt`, `special/final_reminder_theme_count_requirement.txt`
- Range: 2-12 (defined in `extract_requested_theme_count()` in ai_service.php)

## Template Variables

Some files use template variables that get replaced at runtime:

- `{THEME_COUNT}` - Replaced with requested theme count (in theme instruction files)
- `{START_DATE}` - Replaced with first week date (in week_dates_guidance.txt)
- `{END_DATE}` - Replaced with last week date (in week_dates_guidance.txt)

These are injected by `ai_service.php` using string replacement, so preserve the `{VARIABLE_NAME}` format.

## Loading Prompts in Code

All prompts are loaded via `file_get_contents()` in `classes/local/ai_service.php`:

```php
// Example: Load a prompt
$roleinstruction = file_get_contents(__DIR__ . '/../../docs/prompts/theme/role_instruction_with_specific_count.txt');

// Replace template variables if needed
$roleinstruction = str_replace('{THEME_COUNT}', $requestedthemecount, $roleinstruction);
```

## Important Rules

1. **Preserve line breaks** - Prompts use `\n` for readability. Preserve formatting when editing.

2. **Be explicit in negatives** - Use "NEVER", "Do NOT", "CRITICAL:" to emphasize critical rules.

3. **Include examples** - Show AI what good output looks like (e.g., "Theme: Data Analysis Fundamentals")

4. **Balance verbosity** - Prompts are sent to AI, so keep them clear but concise to avoid token waste.

5. **Test after changes** - Always test prompt changes with actual generation to verify AI behavior.

## Prompt Sections Checklist

When modifying prompts, ensure they cover:

- ✓ **What** to create (themes, weeks, activities)
- ✓ **How** to structure it (JSON format example)
- ✓ **Why** it matters (learning outcomes, pedagogical rationale)
- ✓ **What NOT to do** (generic titles, truncation, placeholder text)
- ✓ **Examples** of good output
- ✓ **Validation rules** (only use these activity types, URLs must start with https://, etc.)
- ✓ **Final reminder** (don't stop early, include everything, return only JSON)

## Files Used by Code

See `classes/local/ai_service.php` function `generate_module()` (lines 440-700) for actual usage.

Key locations:
- Lines 442-445: Loads pedagogical guidance
- Lines 453-502: Builds role instruction from core + structure-specific prompts
- Lines 508-570: Builds format instruction from structure + activity + special prompts
- Lines 630-660: Combines all prompts with documents and user input
- Lines 636-660: Adds final reminders

## Adding New Prompts

To add a new prompt:

1. Create a `.txt` file in the appropriate subdirectory
2. Use descriptive naming: `{purpose}_{context}.txt`
3. Update `ai_service.php` to load and use it
4. Add an entry to this README
5. Test with actual module generation

Example:
```php
$newprompt = file_get_contents(__DIR__ . '/../../docs/prompts/new_category/new_prompt.txt');
$finalprompt .= $newprompt;
```

## Version History

**v1.0.0** (2025-11-08)
- Initial extraction of prompts from inline code
- Organized into 5 categories: core, theme, weekly, activities, special
- Created comprehensive README with examples and guidelines
