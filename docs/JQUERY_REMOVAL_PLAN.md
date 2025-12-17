# jQuery Removal Plan for aiplacement_modgen

**Goal:** Remove all jQuery dependencies from the aiplacement_modgen module and replace with vanilla JavaScript and Moodle-native patterns.

**Status:** Ready for implementation
**Created:** 2025-12-17
**Estimated Effort:** 2-3 days

---

## Executive Summary

The module currently uses jQuery in 6 out of 11 JavaScript files (~55% of codebase) with approximately 92 jQuery calls. The heaviest usage is in `suggest.js` (68 occurrences). This plan outlines a phased approach to remove all jQuery dependencies while maintaining functionality and improving code quality.

---

## Phase 1: High Priority - suggest.js (68 jQuery calls)

**File:** `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/suggest.js`

**Impact:** Highest - This file contains 73% of all jQuery usage in the app.

### Current jQuery Usage Breakdown:

| Method | Count | Purpose |
|--------|-------|---------|
| `.find()` | 20 | DOM traversal |
| `.append()` | 17 | DOM insertion |
| `.closest()` | 9 | Parent element lookup |
| `.removeClass()` | 8 | Class removal |
| `.on()` | 6 | Event binding |
| `.html()` | 4 | Content insertion |
| `.addClass()` | 4 | Class addition |
| `.prop()` | 3 | Property manipulation |
| `.empty()` | 3 | Clear content |
| `.data()` | 3 | Data attributes |
| `.each()` | 2 | Iteration |
| Other | 4 | `.toggle()`, `.replaceWith()`, `.prepend()`, `.is()`, `.css()` |

### Replacement Strategy:

#### 1. DOM Selection & Traversal
```javascript
// BEFORE (jQuery)
const $header = root.find('.modgen-progress-header');
const $parent = element.closest('.suggestion-card');

// AFTER (Vanilla JS)
const header = root.querySelector('.modgen-progress-header');
const parent = element.closest('.suggestion-card');
```

#### 2. DOM Manipulation
```javascript
// BEFORE (jQuery)
$element.append(content);
$element.html(newContent);
$element.empty();

// AFTER (Vanilla JS)
element.appendChild(content);
element.innerHTML = newContent;
element.innerHTML = '';
// OR use replaceChildren() for modern browsers
element.replaceChildren();
```

#### 3. Class Manipulation
```javascript
// BEFORE (jQuery)
$element.addClass('active');
$element.removeClass('hidden disabled');
$element.toggleClass('expanded');

// AFTER (Vanilla JS)
element.classList.add('active');
element.classList.remove('hidden', 'disabled');
element.classList.toggle('expanded');
```

#### 4. Event Handling
```javascript
// BEFORE (jQuery)
$element.on('click', handler);
$element.on('change', '.checkbox', handler);

// AFTER (Vanilla JS)
element.addEventListener('click', handler);
// For delegation, use:
container.addEventListener('change', (e) => {
    if (e.target.matches('.checkbox')) {
        handler.call(e.target, e);
    }
});
```

#### 5. Data Attributes
```javascript
// BEFORE (jQuery)
$element.data('suggestion', suggestionObj);
const data = $element.data('suggestion');

// AFTER (Vanilla JS - use WeakMap for objects)
const dataStore = new WeakMap();
dataStore.set(element, suggestionObj);
const data = dataStore.get(element);

// OR for simple data, use dataset:
element.dataset.suggestionId = suggestion.id;
const id = element.dataset.suggestionId;
```

#### 6. Property Manipulation
```javascript
// BEFORE (jQuery)
$checkbox.prop('checked', true);
const isChecked = $checkbox.prop('disabled');

// AFTER (Vanilla JS)
checkbox.checked = true;
const isChecked = checkbox.disabled;
```

#### 7. DOM Creation
```javascript
// BEFORE (jQuery)
const $div = $('<div/>').addClass('list-group');
const $item = $('<div/>').addClass('mb-1');

// AFTER (Vanilla JS)
const div = document.createElement('div');
div.classList.add('list-group');
const item = document.createElement('div');
item.classList.add('mb-1');

// OR for complex HTML, use template strings + innerHTML
const container = document.createElement('div');
container.innerHTML = `<div class="list-group"></div>`;
const div = container.firstElementChild;
```

### Implementation Steps for suggest.js:

1. **Remove jQuery import**
   ```javascript
   // REMOVE this line:
   import $ from 'jquery';
   ```

2. **Create helper utilities** (add to top of file)
   ```javascript
   // WeakMap for storing data on DOM elements
   const elementData = new WeakMap();

   // Helper for event delegation
   const delegate = (element, eventType, selector, handler) => {
       element.addEventListener(eventType, (e) => {
           if (e.target.matches(selector)) {
               handler.call(e.target, e);
           }
       });
   };
   ```

3. **Replace jQuery calls systematically** (work through file line by line)
   - Start with simple replacements (`.find()` → `.querySelector()`)
   - Move to DOM manipulation (`.append()` → `.appendChild()`)
   - Handle event bindings last
   - Test after each major section

4. **Update Promise chains** that use jQuery for DOM manipulation
   ```javascript
   // BEFORE
   return Templates.renderForPromise('template', context)
       .then(result => {
           const $card = $(result.html);
           $card.data('suggestion', s);
           $list.append($card);
           return result;
       });

   // AFTER
   return Templates.renderForPromise('template', context)
       .then(result => {
           const temp = document.createElement('div');
           temp.innerHTML = result.html;
           const card = temp.firstElementChild;
           elementData.set(card, s);
           list.appendChild(card);
           return result;
       });
   ```

5. **Test thoroughly**
   - Test all suggestion card rendering
   - Test checkbox interactions
   - Test chart legend updates
   - Test modal size toggling
   - Test progress header updates

**Files to modify:**
- `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/suggest.js`

**Testing checklist:**
- [ ] Suggestion cards render correctly
- [ ] Checkboxes work and update state
- [ ] Chart legends display properly
- [ ] Modal resizing works
- [ ] Progress headers update
- [ ] All event handlers fire correctly
- [ ] Data attributes are preserved
- [ ] No console errors

---

## Phase 2: Medium Priority - course_nav.js (5 jQuery calls)

**File:** `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/course_nav.js`

**Current Usage:**
```javascript
define(['jquery', 'core/templates', 'core/modal_factory'], function($, Templates, ModalFactory) {
    // Uses:
    // - $.ajax() for AJAX requests
    // - $.prepend() for DOM insertion
    // - $.on() for event binding
    // - $.css() for styling
```

### Replacement Strategy:

#### 1. Replace $.ajax() with fetch API
```javascript
// BEFORE (jQuery)
$.ajax({
    url: url,
    method: 'GET',
    dataType: 'html'
}).done(function(response) {
    modal.setBody(response);
}).fail(function() {
    modal.setBody('<div class="alert alert-danger">Failed to load generator</div>');
});

// AFTER (Fetch API)
fetch(url, {
    method: 'GET',
    headers: {
        'Accept': 'text/html'
    }
})
.then(response => {
    if (!response.ok) {
        throw new Error('Network response was not ok');
    }
    return response.text();
})
.then(html => {
    modal.setBody(html);
})
.catch(error => {
    modal.setBody('<div class="alert alert-danger">Failed to load generator</div>');
});
```

#### 2. Replace DOM manipulation
```javascript
// BEFORE (jQuery)
var region = $('#region-main');
if (region.length && html) {
    region.prepend(html);
}

// AFTER (Vanilla JS)
const region = document.getElementById('region-main');
if (region && html) {
    region.insertAdjacentHTML('afterbegin', html);
}
```

#### 3. Update AMD definition
```javascript
// BEFORE
define(['jquery', 'core/templates', 'core/modal_factory'], function($, Templates, ModalFactory) {

// AFTER
define(['core/templates', 'core/modal_factory'], function(Templates, ModalFactory) {
```

### Implementation Steps:

1. Remove jQuery from AMD dependencies
2. Replace all AJAX calls with fetch
3. Replace DOM selection with `document.querySelector()` or `document.getElementById()`
4. Replace event binding with `addEventListener()`
5. Test all navigation bar functionality

**Files to modify:**
- `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/course_nav.js`

**Testing checklist:**
- [ ] Navigation bar loads correctly
- [ ] Generator button opens modal
- [ ] Modal content loads via AJAX
- [ ] Error handling works
- [ ] No console errors

---

## Phase 3: Medium Priority - prompt.php (4 lines inline jQuery)

**File:** `/Users/tom/Sites/moodle45/ai/placement/modgen/prompt.php` (lines 346-363)

**Current Code:**
```php
<script>
    require(["jquery"], function($) {
        $("#acceptpolicy").on("change", function() {
            $("[data-action=\"aiplacement-modgen-submit\"]").prop("disabled", !this.checked);
        });

        $("#ai-policy-form").on("submit", function(e) {
            if (!$("#acceptpolicy").is(":checked")) {
                e.preventDefault();
                return false;
            }
        });
    });
</script>
```

### Replacement Strategy:

#### Option A: Inline vanilla JavaScript (simplest)
```php
<script>
    (function() {
        const acceptCheckbox = document.getElementById('acceptpolicy');
        const submitButton = document.querySelector('[data-action="aiplacement-modgen-submit"]');
        const form = document.getElementById('ai-policy-form');

        if (acceptCheckbox && submitButton) {
            acceptCheckbox.addEventListener('change', function() {
                submitButton.disabled = !this.checked;
            });
        }

        if (form && acceptCheckbox) {
            form.addEventListener('submit', function(e) {
                if (!acceptCheckbox.checked) {
                    e.preventDefault();
                    return false;
                }
            });
        }
    })();
</script>
```

#### Option B: Create AMD module (recommended for consistency)

1. **Create new file:** `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/prompt_policy.js`
   ```javascript
   /**
    * Policy acceptance handling for prompt page.
    */
   define([], function() {
       return {
           init: function() {
               const acceptCheckbox = document.getElementById('acceptpolicy');
               const submitButton = document.querySelector('[data-action="aiplacement-modgen-submit"]');
               const form = document.getElementById('ai-policy-form');

               if (acceptCheckbox && submitButton) {
                   acceptCheckbox.addEventListener('change', function() {
                       submitButton.disabled = !this.checked;
                   });
               }

               if (form && acceptCheckbox) {
                   form.addEventListener('submit', function(e) {
                       if (!acceptCheckbox.checked) {
                           e.preventDefault();
                           return false;
                       }
                   });
               }
           }
       };
   });
   ```

2. **Update prompt.php:**
   ```php
   <script>
       require(['aiplacement_modgen/prompt_policy'], function(PromptPolicy) {
           PromptPolicy.init();
       });
   </script>
   ```

### Implementation Steps:

1. Choose Option A (inline) or Option B (AMD module)
2. Replace jQuery code in prompt.php
3. Build AMD if using Option B: `npm run build`
4. Test policy acceptance functionality

**Files to modify:**
- `/Users/tom/Sites/moodle45/ai/placement/modgen/prompt.php`
- (Optional) Create `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/prompt_policy.js`

**Testing checklist:**
- [ ] Submit button is disabled by default
- [ ] Submit button enables when checkbox is checked
- [ ] Submit button disables when checkbox is unchecked
- [ ] Form submission is blocked when checkbox is unchecked
- [ ] No console errors

---

## Phase 4: Low Priority - course_toolbar.js (4 jQuery calls)

**File:** `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/course_toolbar.js`

**Status:** ✅ Already optimized - uses conditional jQuery for Bootstrap compatibility

**Current Code:**
```javascript
if (collapseToggle && collapseTarget && window.$ && window.$.fn && window.$.fn.collapse) {
    // Initialize Bootstrap 4 collapse - requires jQuery
    window.$(collapseTarget).collapse({toggle: false});

    collapseToggle.addEventListener('click', () => {
        window.$(collapseTarget).collapse('toggle');
    });
}
```

**Analysis:** This code checks if jQuery is available before using it. Bootstrap 4's collapse component requires jQuery, so this is appropriate. The rest of the file already uses vanilla JS.

### Replacement Strategy (if Bootstrap 5 upgrade happens):

Bootstrap 5 removed jQuery dependency. If/when Moodle upgrades to Bootstrap 5:

```javascript
// Bootstrap 5 (no jQuery)
if (collapseToggle && collapseTarget) {
    // Import Bootstrap's Collapse
    import Collapse from 'bootstrap/js/dist/collapse';

    const bsCollapse = new Collapse(collapseTarget, {
        toggle: false
    });

    collapseToggle.addEventListener('click', () => {
        bsCollapse.toggle();
    });
}
```

**Action:** No changes needed now. Monitor for Moodle Bootstrap version updates.

**Files to modify:**
- None (unless Bootstrap 5 upgrade occurs)

---

## Phase 5: Minimal - aigen_list.js & aigen_marker.js

**Files:**
- `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/aigen_list.js` (3 jQuery refs)
- `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/aigen_marker.js` (1 jQuery ref)

**Status:** These files are already 99% vanilla JS. Any jQuery references are minimal or comments.

### Implementation Steps:

1. Search each file for `$` or `jQuery`
2. Replace any remaining jQuery calls with vanilla equivalents
3. These should be very quick wins

**Testing checklist:**
- [ ] AI generation list displays correctly
- [ ] Markers function properly
- [ ] No console errors

---

## Phase 6: Review modal_generator_reactive.js

**File:** `/Users/tom/Sites/moodle45/ai/placement/modgen/amd/src/modal_generator_reactive.js`

**Note:** The 13 "$" occurrences are mostly template literals (e.g., `$section`, `${variable}`), not jQuery calls.

### Implementation Steps:

1. Review file for actual jQuery usage vs. template literals
2. If any jQuery found, replace with vanilla JS
3. Most likely no changes needed

---

## Phase 7: Dates for Sections Form Template

**File:** `/Users/tom/Sites/moodle45/ai/placement/modgen/classes/form/dates_for_sections_form.php`

**Current Status:** The form now uses a Mustache template (as of latest changes). The template may have inline jQuery for "select all" functionality.

### Files to Check:
- `/Users/tom/Sites/moodle45/ai/placement/modgen/templates/dates_for_sections_form.mustache`
- Any associated JavaScript

### Replacement Strategy:

If template has jQuery for select-all checkboxes:
```javascript
// BEFORE (jQuery)
$('#select-all-themes').on('change', function() {
    $('.section-checkbox.theme-section').prop('checked', this.checked);
});

// AFTER (Vanilla JS)
const selectAllThemes = document.getElementById('select-all-themes');
if (selectAllThemes) {
    selectAllThemes.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.section-checkbox.theme-section');
        checkboxes.forEach(cb => cb.checked = this.checked);
    });
}
```

### Implementation Steps:

1. Check if Mustache template exists and what it contains
2. Look for any inline jQuery in rendered output
3. Replace with vanilla JS if found
4. Test all checkbox selection functionality

**Testing checklist:**
- [ ] Select all themes checkbox works
- [ ] Select all weeks checkbox works
- [ ] Individual checkboxes update select-all state
- [ ] Form submission collects correct section IDs
- [ ] No console errors

---

## Testing Strategy

### Unit Testing Approach

For each phase, follow this testing pattern:

1. **Before changes:**
   - Document current behavior
   - Take screenshots if UI-heavy
   - Note any existing bugs

2. **During changes:**
   - Make incremental changes
   - Test after each major section
   - Use browser dev tools to verify

3. **After changes:**
   - Manual testing of all functionality
   - Check browser console for errors
   - Test in multiple browsers (Chrome, Firefox, Safari)
   - Verify mobile responsive behavior

### Browser Compatibility

Target browsers (Moodle 4.5 support):
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

All vanilla JS replacements are compatible with these browsers.

### Performance Testing

Measure before/after:
- Page load time
- Time to interactive
- JavaScript bundle size
- Memory usage

Expected improvements:
- Smaller bundle size (jQuery is ~85KB minified)
- Faster initial load
- Better performance on mobile devices

---

## Risk Mitigation

### Potential Issues:

1. **Breaking existing functionality**
   - Mitigation: Thorough testing after each change
   - Rollback plan: Git branches for each phase

2. **Browser compatibility issues**
   - Mitigation: Test in all supported browsers
   - Use polyfills if needed (though unlikely)

3. **Event delegation differences**
   - Mitigation: Test all dynamically added elements
   - Document event delegation patterns

4. **Third-party integrations**
   - Mitigation: Check if any external code depends on jQuery
   - Verify Moodle core integrations still work

### Rollback Strategy:

Each phase should be in its own Git branch:
```bash
git checkout -b jquery-removal-phase1-suggest
# Make changes
git commit -m "Phase 1: Remove jQuery from suggest.js"

# If issues arise:
git checkout main
git branch -D jquery-removal-phase1-suggest
```

---

## Implementation Timeline

| Phase | File(s) | Estimated Time | Priority |
|-------|---------|----------------|----------|
| 1 | suggest.js | 4-6 hours | HIGH |
| 2 | course_nav.js | 1-2 hours | MEDIUM |
| 3 | prompt.php | 1 hour | MEDIUM |
| 4 | course_toolbar.js | Review only (~15 min) | LOW |
| 5 | aigen_list.js, aigen_marker.js | 30 min | LOW |
| 6 | modal_generator_reactive.js | Review only (~15 min) | LOW |
| 7 | dates_for_sections_form template | 1-2 hours | MEDIUM |
| **Testing** | All files | 2-3 hours | HIGH |
| **Total** | - | **1-2 days** | - |

---

## Build & Deployment Steps

After each phase:

1. **Build JavaScript:**
   ```bash
   cd /Users/tom/Sites/moodle45/ai/placement/modgen
   npm run build
   ```

2. **Clear Moodle caches:**
   ```bash
   php /Users/tom/Sites/moodle45/admin/cli/purge_caches.php
   ```

3. **Test in browser:**
   - Hard refresh (Cmd+Shift+R / Ctrl+Shift+F5)
   - Test all affected functionality
   - Check console for errors

4. **Commit changes:**
   ```bash
   git add .
   git commit -m "Phase X: Remove jQuery from [filename]"
   ```

---

## Success Metrics

### Before jQuery Removal:
- jQuery files: 6/11 (55%)
- jQuery calls: ~92
- Bundle size: ~[measure current]
- Load time: ~[measure current]

### After jQuery Removal:
- jQuery files: 0/11 (0%)
- jQuery calls: 0
- Bundle size: ~[should be smaller by ~85KB]
- Load time: ~[should be faster]
- Code maintainability: Improved (modern JS patterns)
- Moodle alignment: Better (following Moodle coding standards)

---

## Additional Benefits

1. **Modern JavaScript Patterns**
   - ES6+ syntax throughout
   - Better consistency with Moodle core
   - Easier for new developers to understand

2. **Performance**
   - Smaller bundle size
   - Faster page loads
   - Less memory usage

3. **Maintainability**
   - No dependency version conflicts
   - Easier debugging (native browser APIs)
   - Better IDE support for vanilla JS

4. **Future-Proofing**
   - Aligned with Moodle's direction
   - Easier to adopt future Moodle features
   - No jQuery deprecation concerns

---

## Resources & References

### Vanilla JavaScript Equivalents:
- [You Might Not Need jQuery](http://youmightnotneedjquery.com/)
- [MDN Web Docs - JavaScript](https://developer.mozilla.org/en-US/docs/Web/JavaScript)

### Moodle Development:
- [Moodle JavaScript Guide](https://moodledev.io/docs/guides/javascript)
- [Moodle AMD Modules](https://moodledev.io/docs/guides/javascript/amd)
- [Moodle Templates](https://moodledev.io/docs/guides/templates)

### Event Delegation:
- [Event Delegation Pattern](https://javascript.info/event-delegation)
- [Element.closest()](https://developer.mozilla.org/en-US/docs/Web/API/Element/closest)

### Fetch API:
- [Fetch API Guide](https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API/Using_Fetch)
- [Fetch vs jQuery.ajax()](https://developers.google.com/web/updates/2015/03/introduction-to-fetch)

---

## Questions & Decisions Needed

Before starting implementation, clarify:

1. **Bootstrap Version:** Is there a plan to upgrade to Bootstrap 5? (affects Phase 4)
2. **Browser Support:** Confirm minimum browser versions (affects polyfill needs)
3. **Testing Resources:** Manual testing only or automated tests available?
4. **Deployment:** Can this be deployed incrementally or need all-at-once?

---

## Conclusion

This plan provides a systematic approach to removing all jQuery from the aiplacement_modgen module. By tackling the largest dependencies first (suggest.js) and working through smaller files, we minimize risk while maximizing impact. Each phase is independently testable and can be deployed separately if needed.

**Recommendation:** Start with Phase 1 (suggest.js) as it provides the biggest win. Once that's stable, the remaining phases will be straightforward.

**Next Steps:**
1. Review and approve this plan
2. Create git branch for Phase 1
3. Begin implementation of suggest.js jQuery removal
4. Test thoroughly before moving to Phase 2

---

**Document Version:** 1.0
**Last Updated:** 2025-12-17
**Author:** Planning Session
**Status:** Ready for Implementation
