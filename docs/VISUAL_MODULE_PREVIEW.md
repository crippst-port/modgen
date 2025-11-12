# Visual Module Preview Implementation

**Date:** 12 November 2025  
**Version:** 2.3  
**Status:** Complete

## Overview

Replaced the raw JSON code display in the approval page with a human-readable, hierarchical visual representation of the module structure. Users now see exactly what will be created in a beautiful, scannable format instead of code.

## Changes Made

### 1. **PHP Functions (prompt.php)**

Added new function: `aiplacement_modgen_build_module_preview()`
- **Purpose:** Converts decoded JSON module data into structured preview data
- **Location:** Lines 248-360 in prompt.php
- **Parameters:**
  - `$moduledata` - Decoded module structure from AI
  - `$structure` - Module type ('theme' or 'weekly')
- **Returns:** Associative array with:
  - `hasthemes` - Boolean flag for theme-based structure
  - `themes` - Array of theme objects
  - `hasweeks` - Boolean flag for weekly structure
  - `weeks` - Array of week objects

**Data Structure:**
```
Theme Object:
  - title: Theme name
  - summary: Theme description
  - hasweeks: Boolean flag
  - weeks: Array of week objects

Week Object:
  - title: Week title/name
  - summary: Week overview
  - hasactivities: Boolean flag
  - activities: Array of activity objects

Activity Object:
  - name: Activity name
  - type: Activity type (e.g., 'quiz', 'assignment')
  - session: Session type ('presession', 'session', 'postsession', 'outline')
```

### 2. **Template Changes (prompt_preview.mustache)**

**New sections added:**
1. **Module Structure Section** - Displays hierarchical preview
   - Shows themes (with 📂 icon) for theme-based modules
   - Shows weeks (with 📅 icon) for weekly modules
   - Displays activities (with 🔹 icon) with name, type, and session info

2. **JSON Download Section** - Collapsed by default
   - `<details>` element with summary "💾 Download module JSON"
   - Shows raw JSON only when user clicks to expand
   - Users can copy/download if needed

**Layout Flow:**
```
[Notifications]
[Prompt Section]
[Summary Section]
[NEW] Module Structure Section
      ├─ Themes (if theme-based)
      │  └─ Weeks
      │     └─ Activities
      └─ Weeks (if weekly)
         └─ Activities
[JSON Download Link] ← Collapsed by default
[Approval Form]
```

### 3. **Template Data Updates (prompt.php)**

Modified the `$previewdata` array construction (lines 2206-2226):
- Added: `'modulepreview' => aiplacement_modgen_build_module_preview($json, $moduletype)`
- Added: `'downloadjsontext' => get_string('downloadjson', 'aiplacement_modgen')`
- Kept: JSON string for backward compatibility in collapsed section

### 4. **CSS Styling (styles.css)**

Added comprehensive styling for visual hierarchy:

**Module Section:**
- `.aiplacement-modgen-preview__module` - Main container with border

**Theme Display:**
- `.aiplacement-modgen-theme` - Theme container with left border (667eea - blue)
- `.aiplacement-modgen-theme__title` - Theme title with icon
- `.aiplacement-modgen-theme__summary` - Theme description
- `.aiplacement-modgen-theme__weeks` - Week list container

**Week Display:**
- `.aiplacement-modgen-week` - Week container with left border (764ba2 - purple)
- `.aiplacement-modgen-week__title` - Week title with icon
- `.aiplacement-modgen-week__summary` - Week description
- `.aiplacement-modgen-week__activities` - Activity list container

**Activity Display:**
- `.aiplacement-modgen-activity` - Activity item with flex layout
- `.aiplacement-modgen-activity__icon` - Bullet point icon (🔹)
- `.aiplacement-modgen-activity__name` - Activity name (bold)
- `.aiplacement-modgen-activity__type` - Activity type in italic (e.g., "quiz")
- `.aiplacement-modgen-activity__session` - Session type badge (pre/session/post)

**Color Scheme:**
- Theme border: `#667eea` (Moodle brand blue)
- Week border: `#764ba2` (Moodle brand purple)
- Activity icon: `#667eea` (Moodle brand blue)
- Session badge background: `#f1f3f5` (Light gray)

### 5. **Language Strings (lang/en/aiplacement_modgen.php)**

Added new language strings (lines 280-287):
- `$string['moduleoverview']` - Section heading
- `$string['themes']` - Label for themes
- `$string['weeks']` - Label for weeks
- `$string['activities']` - Label for activities
- `$string['downloadjson']` - Download link text
- `$string['nothemes']` - Empty state message
- `$string['noweeks']` - Empty state message
- `$string['noactivities']` - Empty state message

## User Experience

### Before:
- User sees raw JSON code block
- Hard to understand structure
- Not scannable or clear

### After:
- User sees beautiful hierarchical visual structure
- Clear nesting: Themes → Weeks → Activities
- Icons (📂 📅 🔹) for visual distinction
- Color-coded borders for hierarchy levels
- Session types (pre/session/post) shown as badges
- Activity types (quiz, forum, etc.) shown in parentheses
- JSON hidden in collapsible "Download module JSON" section at bottom
- User can expand JSON if needed for copying/archiving

## Approval Flow

1. **User fills form** → Submits prompt
2. **AI generates module** → Sent to approval page
3. **User sees preview:**
   - Summary of what will be created
   - Beautiful visual structure (themes/weeks/activities)
   - All details clearly displayed
4. **User reviews** → Can expand JSON if needed
5. **User approves** → Clicks "Approve and create"
6. **Activities created** → Course populated

## Technical Details

### Theme-Based Modules
```
Theme 1 (e.g., "Introduction to X")
├─ Week 1
│  ├─ Activity: Lecture (pre-session)
│  ├─ Activity: Workshop (session)
│  └─ Activity: Reflection (post-session)
├─ Week 2
│  └─ Activity: Assessment (session)
└─ Week 3
   └─ Activity: Project (post-session)

Theme 2 (e.g., "Advanced Topics")
└─ Week 4
   └─ Activity: Seminar (session)
```

### Weekly Modules
```
Week 1: Introduction
├─ Activity: Lecture slides
├─ Activity: Reading assignment
└─ Activity: Quiz

Week 2: Core concepts
├─ Activity: Discussion forum
├─ Activity: Practical task
└─ Activity: Self-assessment
```

## Files Modified

1. **prompt.php** (2247 lines)
   - Added: `aiplacement_modgen_build_module_preview()` function
   - Modified: `$previewdata` array construction

2. **templates/prompt_preview.mustache** (119 lines)
   - Complete redesign of JSON section
   - Added: Module structure section with hierarchical display
   - Changed: JSON to collapsible details element

3. **styles.css** (~150 new lines)
   - Added: Complete styling for module preview
   - Added: Theme, week, and activity styling
   - Added: Color scheme and visual hierarchy

4. **lang/en/aiplacement_modgen.php** (+8 lines)
   - Added: New language strings for UI labels

## Testing Checklist

- [ ] Theme-based modules display correctly
- [ ] Weekly modules display correctly
- [ ] Activities show with correct types and session info
- [ ] Module summaries display properly
- [ ] JSON section collapses/expands correctly
- [ ] Colors and spacing look good
- [ ] Icons display correctly
- [ ] Session badges appear where appropriate
- [ ] Empty activities don't show if none present
- [ ] Responsive on mobile devices
- [ ] Form submission still works after review

## Future Enhancements

1. **Expandable Activities** - Click activity to see full description
2. **Activity Icons** - Different icons for different activity types
3. **Drag & Reorder** - Let users reorder activities before approval
4. **Export Preview** - Export visual preview as PDF
5. **Activity Validation** - Show warnings for potential issues
6. **Time Estimates** - Show estimated completion time per activity
7. **Dependencies** - Show activity dependencies visually

## Notes

- JSON is still available in the collapsible section for users who need it
- All data is HTML-escaped to prevent XSS
- Structure respects both theme and weekly module formats
- Fallback strings used if titles/summaries missing
- Session type badges only show for theme-based modules
