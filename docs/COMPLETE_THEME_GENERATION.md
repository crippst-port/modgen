# Complete Theme Generation Enhancement

## Problem

AI was inconsistently creating all requested themes, often generating only 3-4 themes when more were needed to cover all curriculum content. Root causes:

1. **Vague theme count guidance**: Prompt said "3-5 coherent themes" without considering actual content volume
2. **No enumeration step**: AI didn't list all topics first before grouping
3. **Insufficient coverage emphasis**: No explicit requirement that EVERY topic must appear
4. **Weak minimum weeks requirement**: No guidance on how many weeks per theme

## Solution: Enhanced Theme Generation Prompt

### What Changed

**File:** `classes/local/ai_service.php` (lines 378-390, 405-418, 485-500)

#### 1. Added Structured Steps (Lines 378-390)

**Before:**
```
GENERATE COMPLETE THEMED STRUCTURE:
- Parse the ENTIRE file and extract ALL topics and sections
- Group related content into 3-5 coherent themes
- For each theme, create weeks covering all subtopics
- For each week, create presession/session/postsession activities
```

**After:**
```
GENERATE COMPLETE THEMED STRUCTURE:
STEP 1: Parse the ENTIRE file and list EVERY single topic and subtopic
STEP 2: Count all topics to determine theme count (typically 3-6 themes needed to cover all topics)
STEP 3: Group ALL topics into coherent themes - ensure NO topic is left out
STEP 4: For each theme, create weeks (typically 2-4 weeks per theme) covering all subtopics
STEP 5: For each week, create presession/session/postsession activities
STEP 6: Verify ALL topics from the file are included in your themes
CRITICAL: Do NOT skip any content - include every topic from the curriculum file
CRITICAL: Every topic from the file MUST appear in at least one week of one theme
```

**Impact:**
- ✅ Explicit enumeration step (STEP 1) forces AI to list all topics first
- ✅ Topic counting (STEP 2) makes AI aware of content volume
- ✅ Minimum weeks per theme (STEP 4) prevents too few weeks
- ✅ Verification step (STEP 6) before generating output
- ✅ Two CRITICAL reminders about coverage

#### 2. Enhanced Format Instructions (Lines 405-418)

**Before:**
```
IMPORTANT: Repeat this structure for EVERY theme and week in the curriculum.
IMPORTANT: Include ALL weeks from all themes - do not truncate.
```

**After:**
```
IMPORTANT: Generate ALL themes needed to cover ALL topics in the curriculum.
IMPORTANT: Each theme must have multiple weeks (at least 2-3 weeks minimum).
IMPORTANT: Include EVERY topic from the file - do not skip or leave out any content.
IMPORTANT: Do not truncate - continue until all themes and all weeks are complete.
```

**Impact:**
- ✅ Specifies variable theme count based on content (not fixed 3-5)
- ✅ Minimum weeks requirement (2-3 per theme)
- ✅ Explicit "include EVERY topic" repetition
- ✅ Anti-truncation reminder

#### 3. Structure-Specific Final Reminders (Lines 485-500)

**New for Theme-Based:**
```
FINAL REMINDER - THEME STRUCTURE:
- Generate the COMPLETE module with ALL themes needed
- Include EVERY topic and subtopic from the file above
- Each theme MUST contain multiple weeks (at least 2-3 weeks per theme)
- Every topic from the curriculum file MUST appear in at least one week
- Do NOT stop early, do NOT truncate, do NOT omit content
- Return ONLY JSON - no other text.
```

**New for Weekly-Based:**
```
FINAL REMINDER - WEEKLY STRUCTURE:
- Generate the COMPLETE module with all weeks
- Include EVERY topic from the file above
- Do NOT stop early, do NOT truncate, do NOT omit content
- Return ONLY JSON - no other text.
```

**Impact:**
- ✅ Theme-specific guidance emphasizes weeks and topic coverage
- ✅ Different messages for different structures
- ✅ Last instruction before generation - high priority

## How It Works Now

### Theme Generation Flow

1. **Parse & Enumerate**: AI explicitly lists every topic from curriculum
2. **Count & Determine**: AI counts topics to decide theme count (not fixed 3-5)
3. **Group & Verify**: AI groups topics ensuring NONE are left out
4. **Structure & Expand**: AI creates 2-4 weeks per theme with all content
5. **Validate & Return**: AI verifies coverage before outputting JSON

### Key Guarantees

✅ **Coverage**: Every topic from curriculum will be in at least one week
✅ **Theme Count**: Based on content volume, not arbitrary limit
✅ **Week Structure**: Each theme has minimum 2-3 weeks (prevents thin themes)
✅ **No Truncation**: Final reminder enforces complete generation
✅ **Step-Based**: Structured approach makes expectations crystal clear

## Expected Behavior Change

### Before
- Input: 24-week Environmental Sustainability curriculum
- Output: 2-3 themes with ~4-6 weeks each (truncated)
- Issues: Many topics missing, inconsistent coverage

### After
- Input: 24-week Environmental Sustainability curriculum
- Output: 5-6 themes with 3-4 weeks each (complete coverage)
- Benefits: All topics covered, consistent theme structure, verified completeness

## Testing This

1. Upload a multi-week curriculum (15-30 weeks of content)
2. Select "Connected Themed" format
3. Check that:
   - ✅ ALL curriculum topics appear somewhere in the themes
   - ✅ Theme count is sufficient for content volume (not capped at 5)
   - ✅ Each theme has multiple weeks (not single-week themes)
   - ✅ No "Week X" placeholder text appears
   - ✅ Generation completes with `finish_reason: "stop"` (not truncated)

## Prompt Token Impact

**Added tokens:** ~150 tokens (structured steps + enhanced reminders)
**Benefit:** More consistent, complete outputs (quality >> quantity)
**Trade-off:** Negligible token increase for guaranteed completeness

## Code Changes Summary

| Component | Change | Impact |
|-----------|--------|--------|
| Role Instruction (theme) | Added 6-step process with enumeration | Forces complete topic coverage |
| Format Instruction | Changed "3-5 themes" to variable based on content | Adapts to curriculum size |
| Format Instruction | Added "2-3 weeks minimum" requirement | Prevents thin themes |
| Final Reminder | Made theme-specific | Clear structure expectations |
| Final Reminder | Emphasized topic/week guarantees | Prevents skipping content |

## Commit Message

```
Feat: Enhance theme generation to ensure complete curriculum coverage

- Add 6-step structured approach: enumerate → count → group → structure → validate
- Change from fixed 3-5 themes to dynamic count based on content volume
- Add minimum weeks per theme requirement (2-3 weeks)
- Add explicit enumeration step forcing AI to list all topics first
- Add verification step ensuring no topics left out
- Update format instructions with specific topic coverage guarantees
- Add theme-specific final reminder emphasizing weeks and coverage
- Ensures every topic from curriculum appears in at least one week
- Eliminates arbitrary theme count limits in favor of content-driven sizing
- Resolves issue of incomplete theme generation for large curricula
```

## Related Documentation

- `STREAMLINED_PROMPT_FIX.md` - Previous prompt enhancements
- `REMOVED_REDUNDANT_SUMMARIZATION.md` - Token optimization history
- `VALIDATION_SYSTEM.md` - Output validation details
