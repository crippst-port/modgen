# Unique IDs for Bootstrap Components

## Problem Solved

When multiple sections use identical HTML IDs for Bootstrap components (tabs, accordions, modals), JavaScript breaks because:
- HTML requires unique IDs across the entire page
- Bootstrap uses IDs to link tabs/panels (`href="#pre"` → `id="pre"`)
- Multiple identical IDs cause only the first one to work
- Other tabs/accordions become non-functional

## The Issue

### Template HTML (Week 1)
```html
<ul id="week1Tabs" class="nav nav-tabs" role="tablist">
  <li class="nav-item">
    <a id="pre-tab" href="#pre" data-toggle="tab">Pre-session</a>
  </li>
</ul>
<div class="tab-content">
  <div id="pre" class="tab-pane active">Content</div>
</div>
```

### If AI Copies IDs Exactly (WRONG)
**Week 1**: `id="week1Tabs"`, `id="pre-tab"`, `id="pre"`, `href="#pre"` ✓ Works
**Week 2**: `id="week1Tabs"`, `id="pre-tab"`, `id="pre"`, `href="#pre"` ❌ Broken!
**Week 3**: `id="week1Tabs"`, `id="pre-tab"`, `id="pre"`, `href="#pre"` ❌ Broken!

Result: Only Week 1 tabs work. Weeks 2 and 3 tabs don't function.

### Solution: Unique IDs Per Section (CORRECT)
**Week 1**: `id="week1Tabs"`, `id="pre-tab-w1"`, `id="pre-w1"`, `href="#pre-w1"` ✓ Works
**Week 2**: `id="week2Tabs"`, `id="pre-tab-w2"`, `id="pre-w2"`, `href="#pre-w2"` ✓ Works
**Week 3**: `id="week3Tabs"`, `id="pre-tab-w3"`, `id="pre-w3"`, `href="#pre-w3"` ✓ Works

Result: All tabs work independently!

## Solution Implemented

### Enhanced AI Instructions

**File**: `classes/local/ai_service.php`
**Function**: `build_template_prompt_guidance()`
**Lines**: 755-763, 784-817, 824-826, 835-836, 864-869

### Key Changes

#### 1. Added Critical Step 5

```
5. CRITICAL: Make HTML 'id' and 'href' attributes UNIQUE for each section/week you create
   - REASON: Multiple sections with identical IDs will cause Bootstrap components to break
   - METHOD: Add a unique suffix to every id and corresponding href value
   - If template has id="week1Tabs", change to: id="week2Tabs", id="week3Tabs", id="theme1Tabs"
   - If template has id="pre-tab", change to: id="pre-tab-w2", id="pre-tab-w3", id="pre-tab-t1"
   - If template has href="#pre", change to: href="#pre-w2", href="#pre-w3", href="#pre-t1"
   - Use week number (w1, w2, w3) or theme number (t1, t2, t3) or section number as suffix
   - EVERY id in a section must have the same suffix pattern for that section
   - Matching href values must use the same suffix (if id="pre-w2" then href="#pre-w2")
```

#### 2. Added Detailed Example (EXAMPLE 2)

Shows complete before/after for tabs with IDs:

**Template (Week 1)**:
```html
<ul id="week1Tabs" class="nav nav-tabs" role="tablist">
  <li class="nav-item">
    <a id="pre-tab" class="nav-link active" href="#pre" data-toggle="tab">Pre-session</a>
  </li>
</ul>
<div class="tab-content">
  <div id="pre" class="tab-pane active">Content here</div>
</div>
```

**Your output for Week 2 (note unique IDs)**:
```html
<ul id="week2Tabs" class="nav nav-tabs" role="tablist">
  <li class="nav-item">
    <a id="pre-tab-w2" class="nav-link active" href="#pre-w2" data-toggle="tab">Pre-session</a>
  </li>
</ul>
<div class="tab-content">
  <div id="pre-w2" class="tab-pane active">New content here</div>
</div>
```

**Your output for Theme 1 (different suffix)**:
```html
<ul id="theme1Tabs" class="nav nav-tabs" role="tablist">
  <li class="nav-item">
    <a id="pre-tab-t1" class="nav-link active" href="#pre-t1" data-toggle="tab">Pre-session</a>
  </li>
</ul>
<div class="tab-content">
  <div id="pre-t1" class="tab-pane active">Theme content here</div>
</div>
```

#### 3. Updated Forbidden/Required Actions

**Forbidden**:
```
❌ DO NOT copy id and href attributes without making them unique
❌ DO NOT use the same IDs across multiple sections (causes JavaScript conflicts)
```

**Required**:
```
✓ Make id and href attributes UNIQUE per section (add suffix like -w2, -w3, -t1, -t2)
✓ Keep matching pairs consistent (if id="pre-w2" then href="#pre-w2")
```

#### 4. Added ID Uniqueness Check Section

```
ID UNIQUENESS CHECK:
Before finalizing each section, verify:
  • Every id attribute has a unique suffix for this section
  • Every href attribute targeting an ID has the matching suffix
  • No two sections have the same ID values
  • Tab/accordion functionality will work (unique IDs prevent conflicts)
```

## Suffix Patterns

### Recommended Suffixes

| Structure | Pattern | Example |
|-----------|---------|---------|
| Weekly | `-w1`, `-w2`, `-w3` | `id="pre-tab-w2"` |
| Theme-based | `-t1`, `-t2`, `-t3` | `id="pre-tab-t1"` |
| Section number | `-s1`, `-s2`, `-s3` | `id="pre-tab-s2"` |
| Custom | `-wk2`, `-week2` | `id="pre-tab-wk2"` |

### What to Make Unique

| Element | Original | Make Unique |
|---------|----------|-------------|
| Tab container | `id="week1Tabs"` | `id="week2Tabs"`, `id="week3Tabs"` |
| Tab link ID | `id="pre-tab"` | `id="pre-tab-w2"`, `id="pre-tab-w3"` |
| Tab link href | `href="#pre"` | `href="#pre-w2"`, `href="#pre-w3"` |
| Tab panel | `id="pre"` | `id="pre-w2"`, `id="pre-w3"` |
| Accordion | `id="accordion1"` | `id="accordion2"`, `id="accordion-w2"` |
| Modal | `id="myModal"` | `id="myModal-w2"`, `id="myModal-t1"` |
| Collapse target | `data-target="#collapse1"` | `data-target="#collapse1-w2"` |

### What NOT to Change

Keep these attributes **exactly the same**:
- `class` - All CSS classes (Bootstrap and custom)
- `role` - ARIA roles
- `data-toggle` - Bootstrap toggle type
- `aria-*` - Accessibility attributes (except aria-controls if it references an ID)
- `style` - Inline styles
- `data-*` - Data attributes (except those referencing IDs)

## Complete Example

### Template Section
```html
<div class="container my-4">
  <h5>Week 1: Introduction</h5>
  <p><strong>Learning Outcomes:</strong> <span class="badge badge-primary">LO1</span></p>

  <ul id="week1Tabs" class="nav nav-tabs mb-0 border-bottom" role="tablist">
    <li class="nav-item">
      <a id="pre-tab" class="nav-link active" href="#pre" data-toggle="tab" role="tab" aria-controls="pre">
        <i class="fa fa-book mr-2"></i> Pre-session
      </a>
    </li>
    <li class="nav-item">
      <a id="seminar-tab" class="nav-link" href="#seminar" data-toggle="tab" role="tab" aria-controls="seminar">
        <i class="fas fa-chalkboard-teacher mr-2"></i> Seminar
      </a>
    </li>
  </ul>

  <div class="tab-content border-left border-right p-3">
    <div id="pre" class="tab-pane fade show active" role="tabpanel" aria-labelledby="pre-tab">
      <p>Read the introduction materials.</p>
    </div>
    <div id="seminar" class="tab-pane fade" role="tabpanel" aria-labelledby="seminar-tab">
      <p>Attend the seminar session.</p>
    </div>
  </div>
</div>
```

### Generated Section (Week 2)
```html
<div class="container my-4">
  <h5>Week 2: Core Concepts</h5>
  <p><strong>Learning Outcomes:</strong> <span class="badge badge-primary">LO2</span></p>

  <ul id="week2Tabs" class="nav nav-tabs mb-0 border-bottom" role="tablist">
    <li class="nav-item">
      <a id="pre-tab-w2" class="nav-link active" href="#pre-w2" data-toggle="tab" role="tab" aria-controls="pre-w2">
        <i class="fa fa-book mr-2"></i> Pre-session
      </a>
    </li>
    <li class="nav-item">
      <a id="seminar-tab-w2" class="nav-link" href="#seminar-w2" data-toggle="tab" role="tab" aria-controls="seminar-w2">
        <i class="fas fa-chalkboard-teacher mr-2"></i> Seminar
      </a>
    </li>
  </ul>

  <div class="tab-content border-left border-right p-3">
    <div id="pre-w2" class="tab-pane fade show active" role="tabpanel" aria-labelledby="pre-tab-w2">
      <p>Review core concepts from the readings.</p>
    </div>
    <div id="seminar-w2" class="tab-pane fade" role="tabpanel" aria-labelledby="seminar-tab-w2">
      <p>Discuss core concepts in the seminar.</p>
    </div>
  </div>
</div>
```

### What Changed
✓ `id="week1Tabs"` → `id="week2Tabs"`
✓ `id="pre-tab"` → `id="pre-tab-w2"`
✓ `href="#pre"` → `href="#pre-w2"`
✓ `aria-controls="pre"` → `aria-controls="pre-w2"`
✓ `id="pre"` → `id="pre-w2"`
✓ `aria-labelledby="pre-tab"` → `aria-labelledby="pre-tab-w2"`
✓ All same changes for seminar tab

### What Stayed the Same
✓ All `class` attributes
✓ All `role` attributes
✓ `data-toggle="tab"`
✓ Structure and nesting
✓ Bootstrap classes

## Testing

### Manual Test

1. **Generate course with template** containing tabs or accordions
2. **View the course** and check each section
3. **Click tabs in each section**:
   - ✓ Week 1 tabs should work
   - ✓ Week 2 tabs should work
   - ✓ Week 3 tabs should work
4. **Check HTML source**:
   - Verify IDs are unique across sections
   - Verify href matches corresponding id

### Browser Console Check

If tabs don't work, check browser console (F12) for errors:
```
❌ Bad: "Uncaught Error: Syntax error, unrecognized expression: #pre"
   Cause: Duplicate IDs or mismatched id/href pairs

✓ Good: No JavaScript errors, tabs switch correctly
```

### HTML Validation

Use browser dev tools to check for duplicate IDs:
```javascript
// Run in browser console
const ids = Array.from(document.querySelectorAll('[id]')).map(el => el.id);
const duplicates = ids.filter((id, index) => ids.indexOf(id) !== index);
console.log('Duplicate IDs:', duplicates);
```

Should return empty array: `Duplicate IDs: []`

## Benefits

1. **All Bootstrap components work** - Tabs, accordions, modals function in every section
2. **No JavaScript conflicts** - Each section's components independent
3. **Standards compliant** - HTML spec requires unique IDs
4. **Maintainable** - Clear naming pattern (w1, w2, w3)
5. **Debuggable** - Easy to identify which section has issues

## Related Components

### Bootstrap Components Using IDs

These all require unique IDs:
- **Tabs** (`nav-tabs` + `tab-content`)
- **Accordion** (`accordion` + `collapse`)
- **Modals** (`modal` + trigger button)
- **Collapse** (`collapse` + trigger)
- **Carousels** (`carousel` + indicators)
- **Popovers/Tooltips** (if using IDs for targeting)

### ARIA Attributes That May Reference IDs

Update these if they reference IDs:
- `aria-controls` - Points to controlled element ID
- `aria-labelledby` - Points to labeling element ID
- `aria-describedby` - Points to description element ID

## Troubleshooting

### Issue: Tabs don't switch
**Check**: Do multiple sections have `id="pre"`?
**Fix**: Make IDs unique with suffixes

### Issue: Only first tab works
**Check**: Are all `href` values `#pre`?
**Fix**: Make href values unique matching the IDs

### Issue: Tabs work but show wrong content
**Check**: Does `href="#pre-w2"` match `id="pre-w2"`?
**Fix**: Ensure matching pairs have same suffix

### Issue: AI still using duplicate IDs
**Check**: Is template extraction working?
**Debug**: Check logs for template HTML
**Try**: Regenerate with emphasis on unique IDs in prompt

## Related Documentation

- [EXACT_STRUCTURE_REPLICATION.md](EXACT_STRUCTURE_REPLICATION.md) - Structure copying
- [SECTION_HTML_EXTRACTION_FIX.md](SECTION_HTML_EXTRACTION_FIX.md) - Template extraction
- [ai_service.php](../classes/local/ai_service.php#L755-L869) - Implementation

## Summary

**The Fix**: Added explicit instructions for AI to make HTML IDs unique per section by adding suffixes (w1, w2, w3, t1, t2, etc.) while keeping all other structure identical.

**Why**: Multiple sections with identical IDs break Bootstrap JavaScript components (tabs, accordions, modals).

**Result**: All Bootstrap components work correctly in every generated section.
