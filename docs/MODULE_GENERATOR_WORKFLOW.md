# Module Generator Workflow Guide

This guide explains how the Module Generator works in different configurations, focusing on the workflow with AI enabled vs. disabled.

## Overview

The Module Generator creates Moodle course modules using either:
- **CSV files** for exact, structured imports
- **AI enhancement** to expand on CSV structure or generate from scratch
- **Combination** of CSV base structure with AI enhancement

---

## File Upload Requirements

| Setting | File Type | Max Files | Max Size | Purpose |
|---------|-----------|-----------|----------|---------|
| Always | `.csv` only | 1 file | 5MB | Provides module structure |

---

## AI Disabled Workflow

When **AI is disabled** in plugin settings (`enable_ai = false`):

### Behavior
- **CSV file is REQUIRED**
- **Exact creation**: Module created exactly as specified in CSV
- No AI enhancement or expansion
- All AI-specific form fields are hidden (template selector, prompt field, suggested content section)

### Form Display
```
✓ CSV structure file upload
✗ Template selector (hidden)
✗ Prompt field (hidden)
✗ Suggested content section (hidden)
```

### Workflow Steps
1. User uploads CSV file with module structure
2. System detects CSV format (theme or weekly)
3. CSV parsed directly to JSON structure
4. Sections and activities created exactly as specified
5. No names changed, no descriptions added, no AI involvement

### Use Cases
- Importing pre-defined course structures
- Bulk module creation from templates
- Exact replication of existing course designs
- When AI service is unavailable or not needed

---

## AI Enabled Workflow

When **AI is enabled** in plugin settings (`enable_ai = true`):

### Available Options

The form displays additional controls in the **Suggested Content** section:

1. **Expand on themes found in file** (checkbox)
   - Default: **OFF** (unchecked)
   - When ON: AI enhances CSV structure (names, descriptions)
   - When OFF: Creates exactly what's in CSV (unless prompt provided)

2. **Create suggested activities** (checkbox)
   - Default: OFF
   - Controls whether AI creates activity shells
   - Works independently of "Expand on themes"

3. **Generate session instructions** (checkbox)
   - Default: OFF
   - Controls whether AI generates session descriptions
   - Works independently of "Expand on themes"

4. **Generate theme introductions** (checkbox)
   - Default: ON
   - Only visible for `connected_theme` module type
   - Adds introductory paragraphs to theme sections

### Decision Matrix

| CSV Upload | User Prompt | Expand on Themes | Result |
|------------|-------------|------------------|--------|
| ✓ Yes | Empty | OFF | **Pure CSV** - Exact creation |
| ✓ Yes | Empty | ON | **Enhanced structure** - AI improves names/descriptions |
| ✓ Yes | "Make it fun" | OFF | **AI follows prompt** - Respects user instructions |
| ✓ Yes | "Make it fun" | ON | **AI + Enhancement** - Follows prompt + expands |
| ✗ No | Any text | N/A | **AI generation** - Creates from scratch |

### Key Rule
**User prompts are ALWAYS followed**, regardless of the "Expand on themes" checkbox state.

---

## Detailed AI Workflows

### Workflow A: Pure CSV Import (AI On, No Enhancement)

**Settings:**
- AI enabled: ✓
- CSV uploaded: ✓
- User prompt: Empty
- Expand on themes: ✗ OFF
- Create activities: ✗ OFF
- Session instructions: ✗ OFF

**Process:**
1. CSV file uploaded
2. System detects: AI enabled but no enhancement requested
3. CSV parsed directly (same as AI disabled mode)
4. Exact structure created from CSV
5. No AI involvement

**Result:** Identical to AI disabled workflow - exact CSV replication

---

### Workflow B: CSV with Structure Enhancement Only

**Settings:**
- AI enabled: ✓
- CSV uploaded: ✓ (e.g., "Week 1", "Week 2", "Week 3")
- User prompt: Empty
- Expand on themes: ✓ ON
- Create activities: ✗ OFF
- Session instructions: ✗ OFF

**Process:**
1. CSV file uploaded and parsed to extract base structure
2. CSV structure converted to JSON
3. JSON sent to AI with prompt:
   ```
   *** BASE STRUCTURE FROM CSV ***
   Use this as the foundation and enhance the theme names, 
   descriptions, and structure:
   {CSV structure as JSON}
   
   Enhance the names, descriptions, and overall structure 
   while maintaining the core organization.
   ```
4. AI enhances:
   - Week/theme names (e.g., "Week 1" → "Introduction to Key Concepts")
   - Section descriptions
   - Overall narrative flow
5. **Activities NOT created** (toggle is OFF)
6. **Session instructions NOT added** (toggle is OFF)

**Result:** Enhanced structure with better names/descriptions, but no activity shells

---

### Workflow C: CSV with Full Enhancement

**Settings:**
- AI enabled: ✓
- CSV uploaded: ✓
- User prompt: Empty
- Expand on themes: ✓ ON
- Create activities: ✓ ON
- Session instructions: ✓ ON

**Process:**
1. CSV parsed and sent to AI as base structure
2. AI enhances theme/week names and descriptions
3. AI creates suggested activity shells based on structure
4. AI generates session instruction text for each week/session
5. Complete module created with enhanced content

**Result:** Fully enhanced module with improved structure, activity suggestions, and instructional text

---

### Workflow D: CSV with Custom Instructions

**Settings:**
- AI enabled: ✓
- CSV uploaded: ✓
- User prompt: "Make this course fun and engaging for high school students"
- Expand on themes: ✗ OFF (doesn't matter - prompt triggers AI)
- Create activities: ✓ ON
- Session instructions: ✗ OFF

**Process:**
1. CSV parsed to get base structure
2. User prompt combined with CSV structure context
3. AI processes with custom instructions
4. Theme/week names enhanced per user's request
5. Activities created (toggle ON)
6. Session instructions skipped (toggle OFF)

**Result:** CSV structure enhanced according to user's specific instructions, with activities but no session text

---

### Workflow E: AI Generation from Scratch (No CSV)

**Settings:**
- AI enabled: ✓
- CSV uploaded: ✗ None
- User prompt: "Create a 12-week introduction to psychology course"
- All enhancement toggles: User's choice

**Process:**
1. No CSV provided
2. AI generates complete structure from user prompt
3. Module type determines structure format (weekly/theme)
4. Activity and session toggles control what gets created

**Result:** Completely AI-generated module based on user description

---

## Toggle Independence

The three content toggles work **independently**:

| Expand Themes | Create Activities | Session Instructions | What Gets Created |
|---------------|-------------------|---------------------|-------------------|
| OFF | OFF | OFF | **CSV only** - exact replication |
| ON | OFF | OFF | **Enhanced names** - no activities |
| ON | ON | OFF | **Enhanced + activities** - no session text |
| ON | OFF | ON | **Enhanced + session text** - no activities |
| ON | ON | ON | **Full enhancement** - everything |

### Example: Structure Enhancement Without Activities

This is particularly useful when you want:
- Better theme/week names and descriptions
- **But NOT** pre-created activity placeholders
- Instructor will add their own activities manually

Simply check **"Expand on themes"** and leave **"Create activities"** unchecked.

---

## CSV Format Detection

The system auto-detects CSV format based on columns:

### Theme Format
```csv
Theme,Session,Activity Name,Activity Type,Description
Digital Literacy,Pre-session,Introduction Forum,forum,Welcome to the theme
Digital Literacy,Session 1,Research Task,assignment,Find digital resources
```

### Weekly Format  
```csv
Week,Activity Name,Activity Type,Description
1,Course Introduction,forum,Introduce yourself
1,Reading Assignment,assignment,Read chapter 1
```

Auto-detection happens when:
- Module type is not explicitly set
- Module type is set to default `connected_weekly`

---

## Template-Based Generation

When using an existing module as a template:

### Without CSV
- AI analyzes template structure
- Generates new content maintaining template's organization pattern

### With CSV + Expand On
- Template provides visual/organizational context
- CSV provides specific structure
- AI enhances CSV while respecting template patterns

---

## Processing Paths

The system has **3 processing code paths** (all updated with CSV enhancement logic):

1. **Template extraction path**: When existing module selected as template
2. **Fallback path**: If template extraction fails
3. **Regular path**: Standard generation without template

All three paths support the same CSV enhancement logic for consistency.

---

## Best Practices

### When to Use AI Off
- Importing exact course structures
- Bulk creation from standardized templates
- AI service unavailable
- Want complete control over all content

### When to Use AI On (No Enhancement)
- CSV has perfect names/descriptions already
- Want exact CSV but need AI available for other features
- Testing CSV format before enhancement

### When to Use "Expand on Themes"
- CSV has basic structure but generic names (Week 1, Week 2)
- Want AI to improve descriptions and naming
- Need better narrative flow
- Still want control over base structure

### When to Add Custom Prompt
- Have specific tone/audience requirements
- Need content adapted for specific context
- Want to combine CSV structure with custom instructions

### Independent Toggles Strategy
- Use **Expand themes** alone for structure improvement
- Add **Create activities** when you want suggestions
- Add **Session instructions** for student-facing guidance
- Combine as needed for your use case

---

## Error Handling

### CSV Required Scenarios
If CSV file is required but missing:
```
Exception: 'No CSV file uploaded. A CSV file with the 
module structure is required.'
```

This happens when:
- AI is disabled (CSV always required)
- AI enabled but expand off and no prompt (CSV needed for structure)

### CSV Optional Scenarios
CSV is optional when:
- AI enabled AND user provides a prompt
- System will generate from scratch using AI

---

## Summary Comparison

| Feature | AI OFF | AI ON (No Enhancement) | AI ON (With Enhancement) |
|---------|--------|------------------------|--------------------------|
| CSV Required | Yes | Yes (if no prompt) | Yes (if no prompt) |
| Exact CSV Replication | Yes | Yes (if expand off + no prompt) | No |
| Enhanced Names | No | No | Yes (if expand on OR prompt) |
| Activity Creation | From CSV only | From CSV OR toggle | AI-suggested OR from CSV |
| Session Instructions | From CSV only | From CSV OR toggle | AI-generated OR from CSV |
| User Prompt Support | No | Yes | Yes |
| Template Support | No | Yes | Yes |

---

## Version Info

- **Plugin**: aiplacement_modgen
- **Type**: AI Placement plugin for Moodle
- **Install Path**: `ai/placement/modgen`
- **Moodle Version**: 4.5+
- **Documentation Updated**: 2025-11-12
