# CSV-Based Module Generation (No AI)

## Overview

When AI is disabled in the admin settings (`Site administration > Plugins > AI > Module Assistant > Enable AI generation`), the Module Assistant will parse uploaded CSV files directly to create module structures without using AI.

## CSV Format

The CSV file uses a simple two-column format:

```csv
Title:,Your Module Title

Theme:,Theme Name
Description:,Optional description for this theme
Week:,Week Description
Description:,Optional description for this week
Week:,Week Description

Theme:,Next Theme Name
Week:,Week Description
Week:,Week Description
```

### Format Rules:
- **Column 1**: Label (Title:, Theme:, Week:, or Description:)
- **Column 2**: Value (the actual title/name/description)
- **Empty lines**: Used to visually separate themes (optional, will be ignored)
- **Title**: Optional - provides the module title (not used in structure creation)
- **Theme**: Starts a new theme section
- **Week**: Adds a week to the current theme (or standalone section if using weekly format)
- **Description**: Adds a description to the most recently defined Theme or Week (optional)

### How Description Works:
- A `Description:` line applies to whatever was defined immediately before it
- If placed after a `Theme:` line, it becomes the theme's section description
- If placed after a `Week:` line, it becomes that week's section description
- Descriptions are completely optional - sections will have empty descriptions if omitted
- Only the first Description after each Theme/Week is used (additional Descriptions are ignored)

## Structure Types

### Connected Themed

For themed structures with nested weeks:

```csv
Title:,Introduction to Cloud Computing

Theme:,Cloud Fundamentals
Description:,This theme introduces core cloud computing concepts and terminology.
Week:,Week 1 (Oct 18-24): What is Cloud Computing?
Description:,Explore the definition and history of cloud computing.
Week:,Week 2 (Oct 25-31): Cloud Service Models
Description:,Learn about IaaS, PaaS, and SaaS service models.
Week:,Week 3 (Nov 1-7): Cloud Deployment Models
Description:,Understand public, private, hybrid, and community clouds.

Theme:,Cloud Storage
Description:,Deep dive into various cloud storage solutions and use cases.
Week:,Week 4 (Nov 8-14): Object Storage
Week:,Week 5 (Nov 15-21): Block Storage
Week:,Week 6 (Nov 22-28): Database Storage

Theme:,Security and Best Practices
Week:,Week 7 (Nov 29-Dec 5): Cloud Security
Week:,Week 8 (Dec 6-12): Compliance
```

### Connected Weekly

For weekly structures without themes (just use Week: entries):

```csv
Title:,Introduction to Programming

Week:,Week 1 (Oct 18-24): Introduction to Programming
Description:,Learn programming fundamentals and set up your development environment.
Week:,Week 2 (Oct 25-31): Variables and Data Types
Description:,Understand different data types and how to store information.
Week:,Week 3 (Nov 1-7): Control Structures
Description:,Master if statements, loops, and flow control.
Week:,Week 4 (Nov 8-14): Functions and Methods
Week:,Week 5 (Nov 15-21): Object-Oriented Programming
```

## Important Notes

1. **No Header Row**: Do not include column headers like "Label,Value" - start directly with your data
2. **Empty Lines**: Blank lines are ignored and can be used for readability
3. **Case Insensitive**: "Theme:", "theme:", "THEME:" all work the same (also applies to Description:)
4. **Sessions**: Each week automatically gets pre-session, session, and post-session subsections created (empty)
5. **Activities**: Currently, activities must be added manually after structure creation
6. **Week Titles**: Include date ranges and descriptive topics for clarity (e.g., "Week 1 (Oct 18-24): Introduction")
7. **Descriptions**: Optional - add section descriptions by placing a "Description:" line after any Theme: or Week: line

## Workflow

1. **Admin**: Go to Site administration > Plugins > AI > Module Assistant
2. **Disable AI**: Uncheck "Enable AI generation" and save
3. **Prepare CSV**: Create a CSV file following the simple format above
4. **Upload**: Go to your course > Module Assistant
5. **Select Module Type**: Choose "Connected Weekly" or "Connected Themed"
6. **Upload File**: Upload your CSV file in the "Supporting documents" field
7. **Submit**: Click submit to create the module structure

## Example File

Your example format:
```csv
Title:,Tom's File Test Module

Theme:,Introduction to Moodle Stuff
Week:,What is Moodle?
Week:,What is an introduction?
Week:,The end of the theme!

Theme:,The start of the next theme
Week:,Week four dudes
Week:,Week's coming out your ears
Week:,This is it guys
Week:,Beef week!
Week:,Eating your dinner
Week:,Smile in photos

Theme:,The final theme
Week:,Week four dudes
Week:,Week's coming out your ears
```

This will create:
- 3 themes
- Theme 1 with 3 weeks
- Theme 2 with 6 weeks  
- Theme 3 with 2 weeks

Each week will have pre-session, session, and post-session subsections ready for you to add content.
