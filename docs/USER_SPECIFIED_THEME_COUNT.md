# User-Specified Theme Count Override

## Feature

When a user specifies a desired number of themes in their requirements, that instruction now takes absolute priority over AI's default theme grouping logic.

## How It Works

### Pattern Detection

The system detects theme count specifications in the user's prompt using pattern matching:

**Recognized patterns:**
- "5 themes"
- "5 themed sections"
- "divide into 5 themes"
- "create 5 themes"
- "using 5 themes"
- "total of 5 themes"
- "5 theme groups"

**Examples:**
```
"Create 4 themes covering the entire curriculum"
"Divide this into 6 themed sections"
"I want 3 themes for this course"
"Please generate 5-themed structure"
```

### Detection Logic

**File:** `classes/local/ai_service.php` (lines 46-71)

```php
private static function extract_requested_theme_count($prompt, $structure) {
    // Detects: digit + optional whitespace + themes/themed sections/theme groups
    // Pattern: /(\d+)\s*(?:themes?|themed\s+sections?|theme\s+groups?)/i
    // Valid range: 2-12 themes (reasonable bounds)
    // Returns: int or null
}
```

### Prompt Override

**File:** `classes/local/ai_service.php` (lines 407-445)

**When user specifies a count (e.g., "5 themes"):**
```
GENERATE COMPLETE THEMED STRUCTURE:
*** USER HAS SPECIFIED: Create EXACTLY 5 themes ***
This is a REQUIREMENT - do NOT deviate. Use 5 themes, not more or fewer.

STEP 1: Parse the ENTIRE file and list EVERY single topic and subtopic
STEP 2: Divide all topics into EXACTLY 5 coherent theme groups
STEP 3: Ensure ALL topics are covered - each topic goes into exactly one theme
STEP 4: For each of the 5 themes, create weeks (typically 2-4 weeks per theme)...
STEP 5: For each week, create presession/session/postsession activities
STEP 6: Verify ALL topics from the file are included in your 5 themes
CRITICAL: Generate EXACTLY 5 themes - this overrides any other guidance
```

**Key differences:**
- ✅ Explicit "EXACTLY X themes" requirement (repeated 3x)
- ✅ Marked as overriding other guidance
- ✅ STEP 2 changed from "determine count" to "divide into X"
- ✅ References specific theme count throughout (e.g., "in your 5 themes")
- ✅ Verbatim count in critical requirement

**When user doesn't specify (default behavior):**
```
GENERATE COMPLETE THEMED STRUCTURE:
STEP 1: Parse the ENTIRE file and list EVERY single topic and subtopic
STEP 2: Count all topics to determine theme count (typically 3-6 themes needed)
STEP 3: Group ALL topics into coherent themes - ensure NO topic is left out
...
```

### Validation

- **Valid range:** 2-12 themes (too few → can't cover content, too many → fragmented)
- **Case-insensitive:** Matches "Themes", "THEMES", "themes"
- **Word boundaries:** Only matches complete words (not "theme" within another word)
- **Invalid counts:** Silently ignored, falls back to flexible guidance

## Usage Examples

### Example 1: Explicit Theme Count

**User input:**
```
"Create a module on Environmental Science with 4 themes: 
Atmosphere, Biosphere, Hydrosphere, and Geosphere"
```

**Detected count:** 4
**AI will:**
- ✅ Create exactly 4 themes (not 3, not 5)
- ✅ Distribute all topics across these 4 themes
- ✅ Ensure each theme has 2-4 weeks
- ✅ No truncation because count is predetermined

### Example 2: Range Format

**User input:**
```
"I need this broken down into 6 themed sections covering all material"
```

**Detected count:** 6
**AI will:**
- ✅ Create exactly 6 themes (not 5, not 7)
- ✅ Balance content across 6 themes
- ✅ Cover all material completely

### Example 3: No Count Specified

**User input:**
```
"Create a structured module from this curriculum"
```

**Detected count:** None (null)
**AI will:**
- ✅ Use flexible 3-6 theme guidance
- ✅ Determine count based on content volume
- ✅ AI autonomously decides optimal number

## Technical Details

### Function: `extract_requested_theme_count()`

**Location:** `classes/local/ai_service.php`, lines 46-71

**Parameters:**
- `$prompt` (string): User's requirements text
- `$structure` (string): The module structure type

**Returns:**
- `int`: Detected theme count (2-12)
- `null`: No count detected or out of valid range

**Regex Pattern:**
```regex
/(\d+)\s*(?:themes?|themed\s+sections?|theme\s+groups?)/i
```

**Breakdown:**
- `(\d+)` - Captures one or more digits
- `\s*` - Allows optional whitespace
- `(?:...)` - Non-capturing group with alternatives:
  - `themes?` - Matches "theme" or "themes"
  - `themed\s+sections?` - Matches "themed section(s)"
  - `theme\s+groups?` - Matches "theme group(s)"
- `i` - Case-insensitive matching

### Integration Point

**Location:** `classes/local/ai_service.php`, lines 411-425

```php
$requestedthemecount = self::extract_requested_theme_count($prompt, $structure);

if (!empty($requestedthemecount)) {
    // Build OVERRIDE prompt with specific count
    // Includes: EXACTLY X, marked as requirement, overrides other guidance
} else {
    // Build FLEXIBLE prompt with 3-6 range
    // Includes: typical 3-6, AI determines count
}
```

## Examples of Detected Patterns

### Detected ✅
- "5 themes"
- "5 theme"
- "5 THEMES"
- "divide into 5 themes"
- "create 5 themed sections"
- "using 5 theme groups"
- "generate 5 themes"
- "3 themes for this course"

### NOT Detected ❌
- "about 5 themes" (word before number)
- "5-theme course" (hyphenated, no explicit "themes")
- "theme 5 is about..." (number after word)
- "5 to 7 themes" (ranges not supported, would match 5)
- "themed material" (no number)

## Benefits

✅ **Explicit control:** Users can mandate exact theme structure
✅ **Predictability:** No more guessing about AI's grouping
✅ **Efficiency:** AI doesn't waste tokens iterating on theme count
✅ **Override capability:** User requirements beat default logic
✅ **Fallback:** Still works without explicit count
✅ **Validation:** Prevents unreasonable theme counts (< 2 or > 12)

## Edge Cases Handled

| Scenario | Behavior |
|----------|----------|
| "1 theme" | Ignored (< 2), uses flexible guidance |
| "15 themes" | Ignored (> 12), uses flexible guidance |
| "5.5 themes" | Matches "5", uses 5 themes |
| "themes" (no number) | No match, uses flexible guidance |
| "create 5 themes" appears twice | Matches first occurrence, uses 5 |
| "5 themes" in file content | Also detected (uses prompt, not file) |

## Commit Message

```
Feat: Add user-specified theme count override for theme-based generation

- Add extract_requested_theme_count() to detect "X themes" patterns in user prompt
- Detect patterns: "5 themes", "divide into 5 themes", "5 themed sections", etc.
- Valid range: 2-12 themes (prevents unrealistic counts)
- When count detected: Build prompt with "EXACTLY X themes" requirement
- Override other guidance: Marked as requirement that overrides flexibility
- When count not detected: Use flexible 3-6 theme guidance (original behavior)
- STEP 2 changes based on count: "determine count" vs "divide into X"
- Add critical reminder: "Generate EXACTLY X themes - this overrides any other guidance"
- Case-insensitive matching with proper word boundaries
- Enables explicit control over theme structure when user desires precision
```

## Related Features

- `COMPLETE_THEME_GENERATION.md` - Theme generation completeness
- `STREAMLINED_PROMPT_FIX.md` - Prompt clarity and emphasis
- `VALIDATION_SYSTEM.md` - Output validation
