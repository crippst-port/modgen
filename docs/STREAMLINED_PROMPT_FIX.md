# Streamlined and Clarified AI Prompt for Complete Module Generation

## Problem Addressed

The AI was truncating module responses, returning only partial content (e.g., 2-3 weeks instead of all 15 weeks). Root causes:
1. **Unclear completion requirements**: AI wasn't sure if it should generate everything or just stop
2. **Scattered instructions**: Completeness directives were mixed in with other guidance
3. **Lack of emphasis**: No clear priority on "generate EVERYTHING"
4. **Ambiguous language**: Mix of suggestions and requirements

## Solution: Streamlined and Clarified Prompt

### What Changed

**File:** `classes/local/ai_service.php` (lines 350-465)

#### 1. Numbered Critical Requirements (Crystal Clear)

**Before:** Mixed guidance scattered throughout
```
CRITICAL - Return ONLY valid JSON. No commentary...
IMPORTANT: Do NOT include any example data...
Every field MUST contain actual content...
```

**After:** Clear numbered list at the very start
```
CRITICAL REQUIREMENTS:
1. Return ONLY valid JSON. No commentary, code blocks, or wrapping.
2. Generate the COMPLETE module structure from the curriculum file.
3. Do NOT omit, truncate, or stop early - include ALL content from the file.
4. Do NOT include example data or placeholder text...
5. Every field MUST contain actual content from the curriculum.
6. Return as pure JSON object at the top level...
```

**Impact:** 
- ✅ Requirements listed first (highest priority)
- ✅ Numbered for clarity and emphasis
- ✅ Item #2 and #3 explicitly mandate completeness
- ✅ No ambiguity about expectations

#### 2. Task-Specific Generation Instructions (Clear Section)

**Before:** Generic "TASK: Generate complete..." mixed with bullets
```
TASK: Generate complete themed module structure...
- Read and parse the ENTIRE file content...
- Group related sections...
```

**After:** Specific, actionable steps
```
GENERATE COMPLETE THEMED STRUCTURE:
- Parse the ENTIRE file and extract ALL topics and sections
- Group related content into 3-5 coherent themes
- For each theme, create weeks covering all subtopics
- For each week, create presession/session/postsession activities
- Do NOT skip any content - include everything from the curriculum file
- Each theme summary: 2-3 sentence introduction...
```

**Impact:**
- ✅ Actionable steps (parse → group → create → ensure completeness)
- ✅ "Do NOT skip any content" is now explicit
- ✅ Tells AI what to do AND what NOT to skip

#### 3. Improved JSON Format Examples (Readable Structure)

**Before:** Inline one-liner
```
{"themes": [{\"title\": \"Theme Title\", \"summary\": \"Theme Summary\", \"weeks\": [{...}]}]}
Repeat the theme and week structure...
```

**After:** Formatted, readable structure
```
{"themes": [
  {"title": "Theme Name", "summary": "2-3 sentences", "weeks": [
    {"title": "Week N", "summary": "Overview", "sessions": {
      "presession": {"activities": [...]},
      "session": {"activities": [...]},
      "postsession": {"activities": [...]}
    }}
  ]}
]}
IMPORTANT: Repeat this structure for EVERY theme and week in the curriculum.
IMPORTANT: Include ALL weeks from all themes - do not truncate.
```

**Impact:**
- ✅ Readable format shows nesting clearly
- ✅ "IMPORTANT" keywords highlight critical points
- ✅ Two explicit "Include ALL weeks" reminders

#### 4. Final Completion Reminder (End of Prompt)

**New addition at the end:**
```
FINAL REMINDER: Generate the COMPLETE module. Include EVERY topic from the file above.
Do NOT stop early, do NOT truncate, do NOT omit content.
Return ONLY JSON - no other text.
```

**Impact:**
- ✅ Last thing AI sees before generating
- ✅ Triple emphasis: "COMPLETE", "EVERY topic", "do NOT stop"
- ✅ Recency effect - most recent instruction is highest priority

#### 5. Activity Types Listed Clearly

**Before:** Buried in prose
```
When listing activities, use the optional 'activities' array and only choose from the supported types below:
- quiz: A Moodle Quiz activity...
```

**After:** Clear section with formatting
```
Supported activity types:
  - quiz: A Moodle Quiz activity...
  - forum: A Moodle Forum activity...
Use ONLY these activity types. Do not invent new ones.
```

**Impact:**
- ✅ Dedicated section - easy to find
- ✅ Clear constraint: "Use ONLY these"
- ✅ Easy list format

## Prompt Structure Flow (Improved)

### Old Structure
```
CRITICAL REQUIREMENTS (mixed)
  - Return JSON
  - Don't include examples
  - Every field has content
  - Top-level structure

TASK + Instructions (mixed together)
  - Parse file
  - Create structure
  - Don't stop early

FORMAT INSTRUCTIONS
  - One-liner example
  - Repeat this pattern
  - Activities list

USER PROMPT
USER REQUIREMENTS
TEMPLATE GUIDANCE
```

### New Structure
```
ROLE + CRITICAL REQUIREMENTS (1-6, numbered)
  1. JSON only
  2. COMPLETE module
  3. DON'T STOP EARLY
  4. No examples
  5. Actual content only
  6. JSON structure type

SPECIFIC GENERATION INSTRUCTIONS
  - Parse → Group → Create → Ensure completeness
  - Explicit "Do NOT skip"
  - Each element requirement

FORMAT EXAMPLE
  - Readable multi-line structure
  - IMPORTANT: Repeat for EVERY item
  - IMPORTANT: Include ALL items

ACTIVITY TYPES
  - Clear list
  - Use ONLY these

FILE CONTENT

USER REQUIREMENTS

TEMPLATE GUIDANCE

FINAL REMINDER
  - Generate COMPLETE
  - Include EVERY topic
  - Do NOT stop/truncate/omit
  - JSON only
```

## Expected Improvements

With this streamlined prompt:

✅ **Clarity**: Requirements are numbered and crystal clear
✅ **Emphasis**: Completeness is mentioned 6+ times (items 1, 2, 3, instructions, format examples, final reminder)
✅ **Action**: AI knows exactly what to do and what to prioritize
✅ **No ambiguity**: No "maybe" - direct commands
✅ **Complete output**: Should generate all weeks/themes, not truncate

## Testing the Fix

**Expected behavior:**
- User uploads 24-week Environmental Sustainability module
- Selects "Theme-Based" structure
- AI should return ALL 5 themes with ALL 15 weeks
- NOT 2 themes with 4 weeks (old truncation behavior)

**Key indicators of success:**
- Response includes all curriculum topics
- No "truncated" or "incomplete" in output
- All themes have multiple weeks
- finish_reason: "stop" (normal completion)

## Commit Message

```
Feat: Streamline and clarify AI prompt for complete module generation

- Reorganized prompt with numbered CRITICAL REQUIREMENTS (1-6)
- Moved completeness to items 2-3 for maximum emphasis
- Restructured task instructions with explicit "Do NOT skip"
- Improved JSON format example with readable multi-line structure
- Added "IMPORTANT: Include ALL" reminders in format section
- Added FINAL REMINDER at end emphasizing completeness
- Better visual hierarchy: Requirements → Instructions → Format → Content
- Emphasis on "COMPLETE module", "EVERY topic", "Do NOT truncate"
- Expected result: Complete module generation instead of truncation
- Resolves issue where AI was stopping early and not returning all weeks
```

## Technical Details

**File Modified:** `classes/local/ai_service.php`

**Lines Changed:** 360-469 (roleinstruction, formatinstruction, finalprompt assembly)

**Key Changes:**
1. Replaced flat instruction list with numbered requirements
2. Elevated completeness to requirement #2 and #3
3. Restructured task instructions with action verbs
4. Improved format example readability
5. Added "IMPORTANT" markers in format section
6. Added FINAL REMINDER before sending to AI
7. Reordered prompt assembly for better flow

**No code logic changes** - just clearer, more forceful instructions to the AI

**Expected token impact:** Minimal +50-100 tokens (added clarity and reminders)

**Performance impact:** Should reduce truncation issues (improves quality)
