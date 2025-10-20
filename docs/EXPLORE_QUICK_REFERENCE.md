# Explore.js Quick Reference Guide

## 📚 Module Overview

**Location:** `amd/src/explore.js` (and `explore_refactored.js`)

**Purpose:** Handle the "Explore" page which displays AI-generated insights about a course module

**Key Responsibilities:**
- Fetch insights from server via AJAX
- Render insights to the page
- Display interactive charts
- Generate and download PDF reports

---

## 🎯 How It Works (High Level)

```
User loads explore.php
    ↓
JavaScript init() is called
    ↓
loadInsights() fetches data from AJAX endpoint
    ↓
Data is processed into structured format
    ↓
Sections are rendered to the DOM
    ↓
Charts are rendered (with delays for stability)
    ↓
Content is displayed to user
    ↓
[User can download PDF or view insights]
```

---

## 🔧 Main Functions

### Public API (What You Call)

#### `init(courseId, chartData, activitySummary)`
**When:** Called automatically when explore.php loads  
**What:** Initializes the module and starts loading insights  
**Params:**
- `courseId` (Number) - The course ID to load insights for
- `chartData` (Object) - Pre-calculated chart data (currently unused)
- `activitySummary` (Array) - Pre-calculated activity summary (currently unused)

**Example:**
```javascript
// Called from PHP via Moodle's js_call_amd
require(['aiplacement_modgen/explore'], function(module) {
    module.init(123, chartData, activitySummary);
});
```

---

#### `loadInsights(courseId)`
**When:** Called by init(), or manually if you need to refresh  
**What:** Fetches insights from server and orchestrates rendering  
**Params:**
- `courseId` (Number) - The course ID

**Flow:**
1. Constructs AJAX URL
2. Fetches JSON data
3. Validates response
4. Calls processInsights()
5. Calls renderAllSections()
6. Calls renderChartsIfAvailable()
7. Calls enableDownloadButton()
8. Shows content, hides spinner

**Example:**
```javascript
// Refresh insights for course 42
module.loadInsights(42);
```

---

#### `downloadReport(courseId)`
**When:** User clicks the PDF download button  
**What:** Generates a PDF and triggers download  
**Params:**
- `courseId` (Number) - The course ID

**Requires:** `reportData` must be populated (done by processInsights)

**Example:**
```javascript
// User clicks download button
module.downloadReport(42);
```

---

### Private Helper Functions (Internal Use)

These are called internally and shouldn't be called directly, but you might modify them:

#### `extractTextFromSection(section)`
Converts a section object with `heading` and `paragraphs` into formatted text.

**Used by:** `processInsights()`

#### `getElement(id)`
Safely gets a DOM element, returns null if not found.

**Used by:** Other functions for DOM access

#### `setElementDisplay(id, displayValue)`
Sets the CSS display property of an element.

**Used by:** `hideLoadingAndShowContent()`

---

## 📋 DOM Elements Referenced

The module expects these HTML elements to exist:

| Element ID | Purpose | Set by |
|-----------|---------|--------|
| `insights-pedagogical` | Pedagogical insights section | Template |
| `ped-heading` | Pedagogical section heading | JS |
| `ped-content` | Pedagogical paragraphs container | JS |
| `insights-summary` | Summary section (template-based) | Template |
| `insights-workload-analysis` | Workload analysis section | Template |
| `learning-types-chart` | Canvas for learning types pie chart | Template |
| `section-activity-chart` | Canvas for section activity bar chart | Template |
| `insights-loading` | Loading spinner container | Template |
| `content-wrapper` | Main content wrapper | Template |
| `download-report-btn` | PDF download button | Template |
| `refresh-insights-btn` | Refresh button (optional) | Template |

**Note:** These are defined in `templates/explore.mustache`

---

## 🔄 Data Flow

### Input (From AJAX)
```javascript
{
    success: true,
    data: {
        pedagogical: {
            heading: "...",
            paragraphs: ["...", "..."]
        },
        learning_types: {
            heading: "...",
            paragraphs: ["...", "..."]
        },
        improvements: {
            summary: "...",
            suggestions: ["...", "..."]
        },
        summary: { /* template data */ },
        workload_analysis: { /* template data */ },
        chart_data: {
            hasActivities: true,
            labels: ["...", "..."],
            data: [1, 2, 3],
            colors: ["#fff", "#fff"]
        },
        section_chart_data: { /* similar structure */ }
    }
}
```

### Processing (reportData)
```javascript
{
    pedagogical: "Formatted text...",
    learningTypes: "Formatted text...",
    improvements: "Formatted text...",
    chartData: { /* original chart data */ }
}
```

### Output (To PDF Endpoint)
Sends `reportData` as JSON POST body to `download_report_pdf.php`

---

## ⚙️ Configuration & Tweaking

### Timing Delays

Charts are rendered with `setTimeout` delays for DOM stability:

```javascript
// Learning types chart - 100ms delay
setTimeout(function() {
    self.renderLearningTypesChart(data.chart_data);
}, 100);

// Section activity chart - 500ms delay
setTimeout(function() {
    self.renderSectionActivityChart(data.section_chart_data);
}, 500);
```

**Why?** Ensures canvas elements are fully in the DOM before attempting to render

**To change:** Modify the delay values (in milliseconds)

---

### Chart Configuration

Charts are configured inside their respective render functions:

**Learning Types Chart:**
- Type: Pie chart
- Legend: Bottom
- Responsive: Yes, maintains aspect ratio

**Section Activity Chart:**
- Type: Horizontal bar chart
- Legend: Hidden
- Responsive: Yes, variable height

**To customize:** Edit the `config` object inside the render function

---

## 🐛 Debugging Tips

### Check if Data Loaded

In browser console:
```javascript
// Check if reportData exists
console.log(window.reportData);

// Or through the module
require(['aiplacement_modgen/explore'], function(m) {
    m.downloadReport(courseId); // Tries to download, shows if data exists
});
```

### Verify AJAX Request

Browser DevTools → Network tab → Filter by "explore_ajax.php"
- Check URL includes correct `courseid`
- Check response status is 200
- Check response JSON has `success: true`

### Check Template Rendering

Look at page source - search for rendered template content
- If found: Template rendering worked
- If not: Check browser console for errors

### Chart Issues

Check browser console:
- Look for "Chart is not defined" - need core/chartjs dependency
- Look for "Canvas element not found" - element not in DOM yet
- Look for missing canvas elements in page HTML

---

## 🔗 Related Files

### PHP Backend

| File | Purpose |
|------|---------|
| `explore.php` | Main page controller |
| `ajax/explore_ajax.php` | AJAX endpoint for fetching insights |
| `ajax/download_report_pdf.php` | PDF generation endpoint |

### Templates

| File | Purpose |
|------|---------|
| `templates/explore.mustache` | Main page layout |
| `templates/insights_summary.mustache` | Summary section template |
| `templates/workload_analysis.mustache` | Workload analysis template |

### Classes

| File | Purpose |
|------|---------|
| `classes/local/ai_service.php` | AI integration |

---

## 🚀 Common Modifications

### Add a New Insights Section

1. **Add to data fetching** (ajax/explore_ajax.php):
   ```php
   $newSection = generate_new_insights($course);
   $response['data']['new_section'] = $newSection;
   ```

2. **Add to rendering** (explore.js):
   ```javascript
   renderAllSections: function(data) {
       // ... existing code ...
       if (data.new_section) {
           this.renderTemplateSection(
               'aiplacement_modgen/new_section',
               data.new_section,
               'insights-new-section'
           );
       }
   }
   ```

3. **Add DOM element** (templates/explore.mustache):
   ```html
   <div id="insights-new-section"></div>
   ```

4. **Create template** (templates/new_section.mustache):
   ```html
   {{#heading}}<h2>{{heading}}</h2>{{/heading}}
   {{#content}}<p>{{content}}</p>{{/content}}
   ```

---

### Change Chart Colors

Edit the `renderLearningTypesChart()` or `renderSectionActivityChart()` function:

```javascript
data: {
    labels: chartData.labels,
    datasets: [{
        data: chartData.data,
        backgroundColor: [
            '#FF6B6B', // Change these colors
            '#4ECDC4',
            '#45B7D1'
        ],
        // ...
    }]
}
```

---

### Add Error Handling

Modify the `catch` block in `loadInsights()`:

```javascript
.catch(function(error) {
    console.error('Failed to load insights:', error);
    // Show error message to user
    var msgEl = document.getElementById('error-message');
    if (msgEl) {
        msgEl.innerHTML = 'Error loading insights. Please try again.';
        msgEl.style.display = 'block';
    }
    self.hideLoadingAndShowContent();
});
```

---

## 📊 Performance Notes

### Rendering Sequence

1. **Parallel (immediate):**
   - Pedagogical section
   - Summary template
   - Workload analysis template

2. **Sequential (with delays):**
   - Learning types chart (100ms delay)
   - Section activity chart (500ms delay)

**Why delays?** Charts need the canvas elements to be fully rendered in the DOM. The delays prevent race conditions.

### Optimization Opportunities

- [ ] Use Promise.all() for parallel template rendering
- [ ] Cache DOM element references
- [ ] Lazy-load Chart.js only when charts needed
- [ ] Debounce window resize for responsive charts

---

## ❓ FAQs

**Q: What happens if AJAX fails?**  
A: Content is shown anyway (empty state). User sees loading spinner disappear but no data.

**Q: Can I refresh insights without reloading the page?**  
A: Yes, call `loadInsights(courseId)` again. (Future: add refresh button)

**Q: How do I test without hitting the real AI API?**  
A: Mock the AJAX endpoint or modify the URL in `loadInsights()` to point to test data.

**Q: What if a template is missing?**  
A: Template rendering fails silently. Section just won't appear.

**Q: Can I modify chart data after rendering?**  
A: Currently no. Would require storing Chart.js instances and updating them.

**Q: How big can the PDF be?**  
A: Limited by server memory and browser handling. No hard limit but keep report data reasonable.

---

## 📝 Checklist for New Developers

- [ ] Read this quick reference
- [ ] Read the EXPLORE_BEFORE_AFTER.md for architecture
- [ ] Read inline code comments
- [ ] Check DOM elements exist in template
- [ ] Test loading insights (check Network tab)
- [ ] Test PDF download
- [ ] Verify charts appear
- [ ] Check browser console for errors

---

**Version:** Refactored (2025)  
**Last Updated:** 20 October 2025  
**Author:** Refactoring documentation by AI Assistant
