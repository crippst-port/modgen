# Collapsible Module Structure - Implementation

**Date:** 12 November 2025  
**Feature:** Collapsible themes and weeks using HTML `<details>` elements  
**Status:** ✅ Complete

---

## What Changed

### Theme-Based Modules
```
📚 Module Structure

▸ 📂 Theme 1: Introduction
  (Click to expand)
  
  ▾ 📂 Theme 2: Advanced Topics
  (Expanded view)
  └─ ▸ 📅 Week 1: Overview
  └─ ▾ 📅 Week 2: Core Concepts
     ├─ 🔹 Activity 1
     ├─ 🔹 Activity 2
     └─ 🔹 Activity 3
```

### Weekly Modules
```
📚 Module Structure

▾ 📅 Week 1: Introduction
  (Expanded)
  ├─ 🔹 Activity 1
  └─ 🔹 Activity 2
  
▸ 📅 Week 2: Core Concepts
  (Collapsed)
```

---

## Key Features

### ✨ **Collapsible Themes**
- Themes default to **expanded** (`open` attribute)
- Click theme title to collapse/expand
- Shows arrow indicator (▸/▾)
- Smooth transitions and hover effects

### ✨ **Collapsible Weeks**
- Weeks default to **collapsed** (no `open` attribute)
- Click week title to expand and see activities
- Arrow indicator rotates on expand/collapse
- Shows summary on hover

### ✨ **Visual Indicators**
- Animated arrow (▸ → ▾) shows expand/collapse state
- Color-coded: Blue arrows for themes, Purple for weeks
- Theme: #667eea (blue), Week: #764ba2 (purple)
- Smooth 0.2s transitions

### ✨ **Keyboard Accessible**
- Details elements are keyboard navigable
- Space/Enter to toggle expand/collapse
- Tab through themes/weeks
- Focus states visible

---

## Files Modified

### 1. **templates/prompt_preview.mustache**
- Changed theme structure: `<div>` → `<details>` element
- Added `<summary>` for clickable header
- Changed week structure: `<div>` → `<details>` element
- Themes have `open` attribute (expanded by default)
- Weeks no `open` attribute (collapsed by default)

### 2. **styles.css**
- Added: `.aiplacement-modgen-theme-details` - Theme container
- Added: `.aiplacement-modgen-theme-summary` - Clickable header
- Added: `.aiplacement-modgen-theme-content` - Expanded content
- Added: `.aiplacement-modgen-week-details` - Week container
- Added: `.aiplacement-modgen-week-summary` - Clickable header
- Added: `.aiplacement-modgen-week-content` - Expanded content
- Updated: `.aiplacement-modgen-week__activities` - Removed left margin
- Added: Custom arrow indicators with CSS `::before` pseudo-elements
- Added: Hover effects and transitions

---

## User Experience

### Before (Flat Display)
- All themes and weeks always visible
- Long page with lots of content
- Hard to scan when there are many items
- Can't focus on specific theme/week

### After (Collapsible Display)
- Themes expanded, weeks collapsed by default
- Can expand individual weeks to see activities
- Much shorter page initially
- Better for scanning and reviewing
- Smooth expand/collapse animation

---

## Browser Support

✅ **All modern browsers:**
- Chrome/Edge
- Firefox
- Safari
- Mobile browsers

✅ **Features used:**
- HTML `<details>` element (widely supported)
- CSS `::before` pseudo-elements
- CSS transitions
- Flexbox layout

---

## Styling Details

### Theme Header
```css
.aiplacement-modgen-theme-summary {
    padding: 1rem 1.25rem;
    font-weight: 600;
    background-color: #f8f9fa;
    border: none;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    cursor: pointer;
}
```

### Week Header
```css
.aiplacement-modgen-week-summary {
    padding: 0.75rem 1rem;
    font-weight: 500;
    background-color: #ffffff;
    border: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}
```

### Arrow Animation
```css
.aiplacement-modgen-theme-summary::before {
    content: '▸';
    transition: transform 0.2s ease;
}

.aiplacement-modgen-theme-details[open] .aiplacement-modgen-theme-summary::before {
    transform: rotate(90deg);
}
```

---

## Accessibility

✅ **Semantic HTML**
- Uses standard `<details>` and `<summary>` elements
- No ARIA hacks needed
- Built-in keyboard support

✅ **Keyboard Navigation**
- Tab to focus on theme/week header
- Space or Enter to toggle
- All functionality keyboard-accessible

✅ **Visual Indicators**
- Arrow shows state clearly
- Hover effects for feedback
- Focus outline visible

✅ **Screen Readers**
- Details elements announced correctly
- Expanded/collapsed state communicated
- No hidden content from SR

---

## Default States

### Theme-Based Modules
```
✓ Themes: EXPANDED (open attribute)
  └─ Weeks: COLLAPSED (no open attribute)
    └─ Activities: Always visible in expanded week
```

### Weekly Modules
```
✓ Weeks: COLLAPSED (no open attribute)
  └─ Activities: Visible when week expanded
```

**Rationale:**
- Themes expanded because they're the main organizational units
- Weeks collapsed to keep page compact
- User can expand weeks as they review each section

---

## Interaction Flow

### Reviewing Theme-Based Module
1. Page loads, all themes expanded
2. Scroll through themes to see summaries
3. Click week to expand and see activities
4. Review activities
5. Click week again to collapse
6. Move to next theme
7. When satisfied, click "Approve and create"

### Reviewing Weekly Module
1. Page loads, all weeks collapsed
2. Click first week to expand
3. Review activities in that week
4. Click to collapse
5. Click next week to expand
6. Repeat for all weeks
7. When satisfied, click "Approve and create"

---

## CSS Classes Reference

| Class | Purpose |
|-------|---------|
| `.aiplacement-modgen-theme-details` | Theme container |
| `.aiplacement-modgen-theme-summary` | Clickable theme header |
| `.aiplacement-modgen-theme-content` | Theme content area |
| `.aiplacement-modgen-week-details` | Week container |
| `.aiplacement-modgen-week-summary` | Clickable week header |
| `.aiplacement-modgen-week-content` | Week content area |
| `.aiplacement-modgen-week__activities` | Activity list |
| `.aiplacement-modgen-activity` | Individual activity item |

---

## Performance Impact

✅ **No negative impact:**
- HTML `<details>` is native browser feature
- No JavaScript required
- No layout recalculations
- Smooth CSS transitions only
- Fast rendering

---

## Future Enhancements

1. **Remember expanded/collapsed state** - Use localStorage to persist user preference
2. **Expand/collapse all** - Add buttons to expand/collapse all themes or weeks
3. **Search within structure** - Filter activities by keyword
4. **Time estimates** - Show estimated hours per week/theme
5. **Progress tracking** - Mark themes as reviewed
6. **Drag to reorder** - Reorder weeks/themes before approval

---

## Summary

Themes and weeks are now **collapsible using native HTML `<details>` elements**, making the approval page more organized and easier to review. Themes are expanded by default to show the overall structure, while weeks are collapsed to keep the page compact until the user wants to review specific activities.

This provides a better balance between showing structure overview and allowing focused detailed review.
