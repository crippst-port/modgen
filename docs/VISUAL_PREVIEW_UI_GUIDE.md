# Visual Module Preview - User Interface Guide

## Approval Page Flow

```
┌─────────────────────────────────────────────────┐
│                 APPROVAL PAGE                   │
├─────────────────────────────────────────────────┤
│                                                 │
│  📝 Your Prompt                                 │
│  ─────────────────────────────────────────     │
│  [Your original prompt text visible]            │
│                                                 │
│  ─────────────────────────────────────────     │
│                                                 │
│  ✓ What will be created                        │
│  ─────────────────────────────────────────     │
│  Summary of structure (# themes, # weeks)       │
│                                                 │
│  ─────────────────────────────────────────     │
│                                                 │
│  📚 Module Structure  ← NEW SECTION            │
│  ─────────────────────────────────────────     │
│                                                 │
│  📂 Theme 1: Introduction                       │
│     Course overview and getting started         │
│     │                                           │
│     └─ 📅 Week 1: Welcome & Setup              │
│        │  ├─ 🔹 Lecture slides (lecture)      │
│        │  ├─ 🔹 Introductory reading          │
│        │  └─ 🔹 Welcome quiz (quiz)           │
│        │  [pre-session] [session] [post-session]
│        │                                       │
│        └─ 📅 Week 2: Core Concepts             │
│           ├─ 🔹 Discussion forum (forum)      │
│           └─ 🔹 Reflection task (assign)      │
│                                                 │
│  📂 Theme 2: Advanced Topics                    │
│     Deep dive into core subject matter          │
│     │                                           │
│     └─ 📅 Week 3: Advanced Methods             │
│        ├─ 🔹 Case study (forum)               │
│        └─ 🔹 Final assessment (quiz)          │
│                                                 │
│  ─────────────────────────────────────────     │
│                                                 │
│  💾 Download module JSON                        │ ← Collapsed
│     ↓ Click to expand                          │
│     [Raw JSON hidden until clicked]            │
│                                                 │
│  ─────────────────────────────────────────     │
│                                                 │
│  [Re-enter prompt] [Approve and create]        │
│                                                 │
└─────────────────────────────────────────────────┘
```

## Component Details

### Module Structure Section

```
📚 Module Structure
├─ Title at top with blue underline
├─ Clear visual hierarchy
└─ Two possible layouts:
   
   1. THEME-BASED (Indented structure):
      📂 Theme Title (bold, with description)
      └─ 📅 Week Title (bold, with week summary)
         ├─ 🔹 Activity Name (type: quiz) [pre-session]
         ├─ 🔹 Activity Name (type: forum) [session]
         └─ 🔹 Activity Name [post-session]
   
   2. WEEKLY (Flat structure):
      📅 Week Title (bold)
      ├─ 🔹 Activity Name (type)
      ├─ 🔹 Activity Name (type)
      └─ 🔹 Activity Name (type)
```

### Color Coding

| Element | Color | Purpose |
|---------|-------|---------|
| Theme border | Blue (#667eea) | Top-level container |
| Week border | Purple (#764ba2) | Sub-section |
| Activity icon | Blue (#667eea) | Individual item |
| Session badge | Light gray | Shows session type |
| Text | Dark gray | Content |

### Icons

| Icon | Meaning | Usage |
|------|---------|-------|
| 📂 | Theme/Folder | Top-level organizational unit |
| 📅 | Calendar/Week | Time-based unit |
| 🔹 | Bullet/Activity | Individual learning activity |
| 💾 | Save/Download | JSON download link |

## Example Displays

### Example 1: Theme-Based Module

```
📚 Module Structure

📂 Unit 1: Introduction to Data Science
   A comprehensive introduction to data science fundamentals
   
   └─ 📅 Week 1: Getting Started with Python
      Learn Python basics and set up your environment
      
      ├─ 🔹 Python Installation Guide [pre-session]
      ├─ 🔹 Python Fundamentals Tutorial [session]
      │      (type: book)
      ├─ 🔹 Python Basics Quiz (quiz) [post-session]
      │      (type: quiz)
      
      └─ 📅 Week 2: Data Types and Structures
         Explore Python's built-in data structures
         
         ├─ 🔹 Data Types Reference (book) [pre-session]
         ├─ 🔹 Coding Exercise (assign) [session]
         └─ 🔹 Self-Assessment Quiz (quiz) [post-session]

📂 Unit 2: Data Analysis with Pandas
   Working with datasets using the Pandas library
   
   └─ 📅 Week 3: Introduction to Pandas
      Get started with data manipulation
      
      ├─ 🔹 Pandas Documentation (url)
      ├─ 🔹 Data Loading Exercise (assign)
      └─ 🔹 Discussion: Your Data (forum)
```

### Example 2: Weekly Module

```
📚 Module Structure

📅 Week 1: Course Overview
   Introduction to the course and key concepts

   ├─ 🔹 Syllabus and Course Goals (url)
   ├─ 🔹 Welcome Video (book)
   └─ 🔹 Pre-course Survey (quiz)

📅 Week 2: Core Concepts
   Foundation knowledge for this course

   ├─ 🔹 Lecture Slides: Chapter 1 (book)
   ├─ 🔹 Required Reading: Article A (url)
   ├─ 🔹 Class Discussion (forum)
   └─ 🔹 Knowledge Check (quiz)

📅 Week 3: Application
   Apply concepts to real-world scenarios

   ├─ 🔹 Case Study Analysis (assign)
   ├─ 🔹 Group Project Brief (forum)
   └─ 🔹 Peer Review Activity (assign)
```

## User Actions

### Reviewing the Module

1. **Scroll through structure** - See all themes/weeks and activities
2. **Check for completeness** - Verify all expected topics included
3. **Verify activity types** - Ensure activities are appropriate
4. **Review session distribution** - See pre/session/post balance
5. **Check descriptions** - Read theme/week summaries

### If Changes Needed

1. **Click "Re-enter prompt"** - Modify request and regenerate
2. **Or "Approve and create"** - Accept and create activities

### If Needs Code Review

1. **Click "Download module JSON"** - Expand collapsed section
2. **Copy or save JSON** - For archival/sharing
3. **Continue approval** - Or re-enter prompt

## Responsive Behavior

- **Desktop**: Full width with proper spacing
- **Tablet**: Adjusts padding and font sizes
- **Mobile**: Stacked layout, icons remain visible

## Accessibility

- Color not only distinguishing feature (icons and text also differentiate)
- Session badges have semantic meaning (not just color)
- All text has sufficient contrast (WCAG AA compliant)
- Semantic HTML structure (section, details, h3-h5 hierarchy)
- Icon-emoji for decoration only, text conveys meaning
