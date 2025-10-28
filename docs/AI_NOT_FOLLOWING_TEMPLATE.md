# AI Not Following Template Instructions

## Problem

Despite detailed instructions, the AI sometimes:
1. **Double-encodes the response** (entire JSON in summary field)
2. **Simplifies the HTML structure** (removes tabs, cards, complex layout)
3. **Ignores template structure** (creates own simplified version)

## Example of Issue

**What AI returns**:
```html
<div class='container my-4'>
  <h5>Introduction</h5>
  <p>This week delves into...</p>
  <p><strong>Learning Outcome(s):</strong> <span class='badge badge-primary'>LO1</span></p>
</div>
```

**What template has**:
```html
<div class="container my-4">
  <h5>Introduction</h5>
  <p>...</p>
  <p><strong>Learning Outcome(s):</strong> ...</p>
  <!-- Activity Tabs -->
  <ul id="weekCirculatoryTabs-week5" class="nav nav-tabs mb-0 border-bottom">
    <li class="nav-item">
      <a id="pre-tab-week5" class="nav-link active" href="#pre-week5" data-toggle="tab">
        <i class="fa fa-book mr-2"></i> Pre-session
      </a>
    </li>
    ...
  </ul>
  <div class="tab-content border-left border-right border-bottom p-3">
    ...
  </div>
</div>
```

AI **removed** the tabs structure entirely!

## Why This Happens

###1. Token Limit Issues

**Your current prompt is 5,582 tokens!**

Breakdown:
- Base instructions: ~3,000 tokens
- Template HTML (full): ~2,000+ tokens
- Template guidance: ~500+ tokens

Many AI models have context windows but may prioritize earlier content over later content, or struggle with very long prompts.

### 2. AI Model Limitations

Some AI models:
- Struggle with "copy exactly" instructions
- Tend to simplify/paraphrase
- Have difficulty maintaining complex nested structures
- Default to generating "similar" content rather than exact copies

### 3. Instruction Overload

The prompt contains:
- General pedagogical instructions
- Activity guidance
- JSON schema requirements
- Template structure instructions
- ID uniqueness requirements
- Forbidden/required lists
- Multiple examples

Too many competing instructions may cause the AI to prioritize some over others.

## Current Mitigations

### ✓ Validation System (Working)

The validation catches:
- Double-encoded JSON
- Missing required fields
- Empty structures

User sees error and can regenerate.

### ✓ Detailed Instructions (In Place)

The prompt includes:
- CRITICAL warnings
- Step-by-step instructions
- Forbidden/required lists
- Multiple examples
- ID uniqueness guidance

## Solutions to Try

### Solution 1: Reduce Prompt Size

**Problem**: 5,582 tokens is very long

**Options**:
1. **Split template HTML**: Instead of full template, show key structural elements only
2. **Remove redundant instructions**: Consolidate similar points
3. **Shorten examples**: Keep them concise
4. **Move some guidance to post-processing**: Validate and fix after generation

**Implementation**:
```php
// Instead of full template HTML (2000+ tokens)
$html_excerpt = self::extract_key_structure($template_data['template_html']);
// Returns just the core structure pattern, not full content
```

### Solution 2: Strengthen Template Enforcement

**Add to beginning of prompt** (highest priority):
```
MANDATORY RULE #1: Every section summary MUST start by copying this exact HTML structure:
[show structure]
Then replace ONLY the text content.
Failure to copy this structure exactly will result in rejection.
```

### Solution 3: Use Better AI Model

**Check**: What AI model/provider are you using?
- GPT-4: Generally good at following complex instructions
- GPT-3.5: May struggle with this level of complexity
- Claude: Excellent at following detailed instructions
- Other models: Variable capability

**Action**: If using a less capable model, consider upgrading.

### Solution 4: Post-Process Validation

**Add structure checking after generation**:

```php
private static function validate_template_structure_followed($generated_html, $template_html) {
    // Check for key markers from template
    $template_markers = [
        'nav-tabs',
        'tab-content',
        'tab-pane',
        'card',
        'alert'
    ];

    $missing = [];
    foreach ($template_markers as $marker) {
        if (strpos($template_html, $marker) !== false) {
            // Template has this component
            if (strpos($generated_html, $marker) === false) {
                // Generated HTML missing it
                $missing[] = $marker;
            }
        }
    }

    if (!empty($missing)) {
        return [
            'valid' => false,
            'error' => 'Generated content missing required template components: ' . implode(', ', $missing)
        ];
    }

    return ['valid' => true];
}
```

### Solution 5: Template Injection

**Force the structure** by providing it as a wrapper:

```php
// Instead of asking AI to copy structure
// Provide the structure and ask AI to fill in content slots

$template_with_slots = "
<div class='container my-4'>
  <h5>{{TITLE}}</h5>
  <p>{{DESCRIPTION}}</p>
  <p><strong>Learning Outcome(s):</strong> {{LEARNING_OUTCOMES}}</p>
  <!-- Activity Tabs -->
  <ul id='{{TAB_ID}}' class='nav nav-tabs mb-0 border-bottom'>
    <li class='nav-item'>
      <a id='{{PRE_TAB_ID}}' class='nav-link active' href='#{{PRE_ID}}' data-toggle='tab'>
        <i class='fa fa-book mr-2'></i> Pre-session
      </a>
    </li>
    ...
  </ul>
  <div class='tab-content'>
    <div id='{{PRE_ID}}' class='tab-pane active'>
      {{PRE_CONTENT}}
    </div>
    ...
  </div>
</div>
";

// Ask AI to fill in {{SLOTS}} with content
// Then replace slots with AI content
```

### Solution 6: Two-Stage Generation

**Stage 1**: Generate content only (text, no HTML)
```
Generate:
- Week title
- Week description
- Pre-session content
- Seminar content
- Post-session content
- Learning outcomes
```

**Stage 2**: Inject into template structure
```php
$template = load_template_structure();
$ai_content = generate_content_only();
$final_html = inject_content_into_template($template, $ai_content);
```

This **guarantees** structure matching.

## Recommended Immediate Actions

### Action 1: Add Structure Validation

Add this to `validate_module_structure()`:

```php
// After checking for double-encoding
// Check if template structure is being followed
if (!empty($template_data)) {
    // Template was provided - check if structure followed
    $structure_check = self::validate_template_structure_followed(
        $item['summary'],
        $template_data['template_html']
    );

    if (!$structure_check['valid']) {
        return [
            'valid' => false,
            'error' => $structure_check['error'] . ' The AI simplified the template structure instead of copying it exactly. Please regenerate with emphasis on exact structural replication.'
        ];
    }
}
```

### Action 2: Move Template to Top of Prompt

Currently template guidance comes after general instructions. Move it to the very beginning:

```php
// In generate_module(), move template guidance first
if (!empty($template_data)) {
    $template_guidance = self::build_template_prompt_guidance($template_data);
    // Put this BEFORE roleinstruction, before everything else
    $finalprompt = $template_guidance . "\n\n" . $roleinstruction . "\n\n" . $userprompt;
}
```

### Action 3: Add Rejection Warning in Prompt

At the very top:

```
⚠️ CRITICAL: IF YOU SIMPLIFY OR MODIFY THE TEMPLATE STRUCTURE, YOUR RESPONSE WILL BE REJECTED AND YOU WILL NEED TO REGENERATE.
This is an automatic check. Copy the template structure EXACTLY.
```

## Testing After Changes

1. **Generate with template**
2. **Check if validation catches simplification**:
   - Should detect missing `nav-tabs`
   - Should detect missing `tab-content`
   - Should detect missing `card` or `alert` classes
3. **User sees error**: "Generated content missing required template components: nav-tabs, tab-content"
4. **User regenerates**

## Long-Term Solution

**Consider two-stage approach**:
1. AI generates content only (no HTML)
2. System injects content into template structure automatically

This is more reliable than asking AI to copy complex HTML.

## Current Status

✓ **Validation catches double-encoding** ✓ **Validation prevents broken responses**
✓ **User can regenerate easily**

❌ **AI still sometimes ignores structure**
❌ **No detection of simplified structure yet**

## Files to Modify

To add structure validation:
1. `classes/local/ai_service.php` - Add `validate_template_structure_followed()`
2. `classes/local/ai_service.php` - Call it from `validate_module_structure()`
3. `lang/en/aiplacement_modgen.php` - Add error string for missing components

## Summary

The double-encoding validation works perfectly. The issue is the AI is not following the template structure instructions at all - it's simplifying/ignoring them.

**Best immediate fix**: Add validation to detect when template components are missing and reject with clear error message.

**Long-term fix**: Consider two-stage generation (content only, then inject into structure) to guarantee structure matching.
