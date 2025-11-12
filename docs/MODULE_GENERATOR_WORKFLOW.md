# Module Generator Workflow Guide (Updated November 2025)

Complete reference for all Module Generator behaviors and configuration options.

## Quick Reference: What Each Checkbox Does

| Scenario | Expand on Themes | Generate Example Content | Result |
|----------|------------------|--------------------------|--------|
| ❌ Both OFF | No | No | CSV only - exact structure |
| ✅ Expand only | Yes | No | Enhanced titles, original structure |
| ✅ Examples only | No | Yes | Original titles, example activities/instructions |
| ✅ Both ON | Yes | Yes | Enhanced titles + activities + instructions |

---

## File Upload

**CSV File (Required)**
- **Type**: `.csv` only
- **Max files**: 1
- **Max size**: 5 MB
- **Purpose**: Defines course structure (themes, weeks, sessions)

---

## CSV Structure Formats

### Theme-Based Format
```
Title:,Course Title
Theme:,Theme 1 Name
Description:,Optional theme introduction
Week:,Week 1 Name
Description:,Optional week description
Week:,Week 2 Name
Theme:,Theme 2 Name
Week:,Week 1 Name
```

**Structure**: Multiple themes → Multiple weeks per theme → Three sessions per week (presession, session, postsession)

### Weekly Format
```
Title:,Course Title
Week:,Week 1 Name
Description:,Optional week description
Week:,Week 2 Name
```

**Structure**: Multiple weeks → Three sessions per week

---

## Behavior Scenarios

### Scenario 1: CSV Only (Both Checkboxes OFF)

**When**: 
- CSV uploaded
- "Expand on themes" = OFF
- "Generate example content" = OFF

**AI Processing**: ❌ DISABLED

**Result**:
- Creates exact structure from CSV
- No title enhancement
- No activities generated
- No summaries/instructions generated
- User-provided descriptions kept exactly as-is

**Use Case**: Bulk import of course templates, exact replication

---

### Scenario 2: CSV + Expand on Themes

**When**:
- CSV uploaded
- "Expand on themes" = ✅ ON
- "Generate example content" = OFF

**AI Processing**: ✅ ENABLED

**What AI Does**:
- Enhances theme and week titles with professional language
- Creates summaries ONLY where CSV fields are empty
- Preserves user-provided descriptions exactly as written
- Respects exact same number of themes/weeks as CSV

**Result**:
- ✅ Enhanced theme and week titles
- ✅ Professional, academic tone
- ✅ Exact same number of themes/weeks as CSV
- ❌ No activities generated
- ❌ No session instructions generated
- ✅ User-provided summaries preserved exactly

**Use Case**: Improving title quality while keeping structure and user content

---

### Scenario 3: CSV + Generate Example Content

**When**:
- CSV uploaded
- "Expand on themes" = OFF
- "Generate example content" = ✅ ON

**AI Processing**: ✅ ENABLED

**What AI Does**:
- Keeps all titles EXACTLY as specified in CSV
- Generates activities (forums, assignments, quizzes, etc.)
- Generates session instructions
- Creates summaries ONLY where CSV fields are empty
- Preserves user-provided summaries exactly

**Result**:
- ✅ Activities generated
- ✅ Session instructions generated
- ✅ Summaries generated ONLY where CSV is empty
- ✅ User-provided summaries preserved exactly
- ✅ All titles kept EXACTLY as in CSV
- ✅ Exact same number of themes/weeks as CSV

**Use Case**: Adding learning activities while keeping user's original titles and descriptions

---

### Scenario 4: CSV + Both Checkboxes ON

**When**:
- CSV uploaded
- "Expand on themes" = ✅ ON
- "Generate example content" = ✅ ON

**AI Processing**: ✅ ENABLED (Full Enhancement)

**What AI Does**:
- Enhances titles with professional language
- Generates activities
- Generates session instructions
- Creates summaries where empty
- Preserves user-provided summaries
- Maintains exact same structure as CSV

**Result**:
- ✅ Enhanced professional titles
- ✅ Activities generated
- ✅ Session instructions generated
- ✅ Summaries generated where empty
- ✅ User-provided summaries preserved
- ✅ Exact same structure as CSV (no weeks added/removed)

**Use Case**: Complete enhancement with professional titles and learning activities

---

## Critical Safeguards (All Scenarios)

### Structural Preservation
Every AI prompt includes explicit instructions:
```
Create EXACTLY [NUMBER] themes with [NUMBER] weeks total
(this is non-negotiable)

Do NOT add extra themes, weeks, or sessions
Do NOT remove any themes, weeks, or sessions
Do NOT merge or split sections
Your output MUST have EXACTLY [NUMBER] themes
```

### How Theme/Week Counting Works
1. **CSV parsing**: System parses CSV into structured format
2. **Counting**: System counts exact themes and weeks
3. **Explicit instruction**: "Create EXACTLY 3 themes with 9 weeks"
4. **AI extraction**: AI service recognizes the number pattern
5. **Enforcement**: AI follows the exact count (no defaults)

### Why Explicit Counting Matters
**OLD SYSTEM** (before v2.2):
- Had default: "Each theme must have 2-4 weeks"
- Caused AI to ignore CSV week counts
- Created wrong number of weeks

**NEW SYSTEM** (v2.2+):
- "Create EXACTLY 3 themes with 9 weeks"
- Explicit counts from CSV
- No arbitrary limits
- Respects any week/theme structure

---

## User-Provided Descriptions

### When User Provides Description in CSV
```
Theme:,Data Analysis
Description:,Students explore statistical methods and data visualization
```

**With "Expand on themes" ON**: 
- Title: Enhanced professionally
- Description: Kept EXACTLY as written

**With "Generate example content" ON**: 
- Title: Kept EXACTLY as written
- Description: Kept EXACTLY as written
- Activities: Generated

**With both OFF**:
- Everything kept exactly as written

### When User Leaves Description Empty
```
Week:,Week 1 Introduction
Description:[empty]
```

**With "Expand on themes" ON**: 
- AI generates professional week summary

**With "Generate example content" ON**: 
- AI generates week summary

**With both OFF**:
- Stays empty

---

## Form Controls

| Option | Visibility |
|--------|------------|
| CSV upload | Always visible |
| Expand on themes | Visible when AI enabled |
| Generate example content | Visible when AI enabled |
| Template selector | Hidden |
| Prompt field | Hidden |

---

## AI Service Processing

### Phase 1: CSV Parsing
- Detect format (theme or weekly)
- Parse to structured array
- Count themes and weeks
- Extract user-provided summaries

### Phase 2: Prompt Construction
- Add complete CSV structure
- Add critical structural requirements
- Add explicit theme/week counts
- Add scenario-specific instructions

### Phase 3: AI Generation
- Send prompt to Moodle AI subsystem
- AI extracts explicit count
- AI generates exact structure
- AI respects all requirements

### Phase 4: Validation
- Validate response structure
- Check theme/week count matches
- Process activities and sessions

---

## Session Structure

Every week automatically gets 3 sessions:
1. **presession**: Pre-class preparation
2. **session**: In-class/synchronous activities
3. **postsession**: Post-class reflection/review

Each session can contain:
- Custom instructions/guidance
- Multiple activities (if "Generate example content" ON)

---

## Professional Tone

When "Expand on themes" is ON, AI uses:

```
Professional, academic language suitable for UK higher education:
- Clear, descriptive, informative titles
- Avoid marketing language or casual tone
- Focus on clarity and academic rigor
- Scholarly but accessible content
```

Examples:
- ✅ "Data Analysis Fundamentals" (good)
- ✅ "Introduction to Statistical Methods" (good)
- ❌ "Exciting Data Adventure!" (bad)
- ❌ "Fun Week!" (bad)

---

## Decision Guide

### "I have a CSV template I want to import exactly"
→ **Both OFF** → Exact CSV import

### "I have a CSV but want better-sounding titles"
→ **Expand ON, Examples OFF** → Professional titles

### "I have a CSV but want to add activities"
→ **Examples ON, Expand OFF** → Same titles + activities

### "I have a CSV and want the full treatment"
→ **Both ON** → Professional titles + activities + instructions

---

## Troubleshooting

### "Wrong number of weeks generated"
✓ Check: "Create EXACTLY X themes with Y weeks" in prompt
✓ Check: CSV was parsed correctly
✓ Check: No "2-4 weeks per theme" constraint in AI output

### "User-provided summaries were overwritten"
✓ Check: Prompt includes "DO NOT replace user-provided summaries"
✓ Check: CSV structure shows summary field status
✓ Check: Settings when generating

### "Activities not being generated"
✓ Check: "Generate example content" is ON
✓ Check: Supported activity types used
✓ Check: No errors in AI response

---

## Version History

- **v2.2** (November 2025): **Current**
  - ✅ Explicit theme/week counts in AI prompts
  - ✅ Removed "2-4 weeks per theme" default constraint
  - ✅ Preserve user-provided summaries
  - ✅ Independent example content generation
  - ✅ Only generate summaries where CSV is empty
  - ✅ Professional UK higher education tone
  - ✅ Critical structural requirements in all scenarios

---

## Technical Details

### Explicit Count Extraction
AI service looks for patterns like:
- "3 themes"
- "EXACTLY 3 themes with 9 weeks"
- "create 3 themes"

When found, explicit count is used (no defaults applied)

### Activity Types Supported
- forum
- assignment
- quiz
- label
- book
- url
- choice
- glossary
- wiki

---

## Summary

The Module Generator v2.2 provides precise control over course structure with AI enhancement:

- **Exact CSV import** when AI not needed
- **Title enhancement** for professional academic tone
- **Content generation** for activities and instructions
- **Flexible combination** of both features
- **Structure preservation** with explicit week counts
- **Summary respecting** to keep user content

Use the checkbox combinations to get exactly what you need!
