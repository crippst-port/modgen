# Forum Activity Handler

## Overview

Added support for creating Forum activities via AI-generated module structures. The Forum activity handler allows the AI to suggest discussion forums as part of the module generation process.

## Implementation Details

### New Files Created

**`classes/activitytype/forum.php`**
- Implements the `activity_type` interface
- Handles creation of Forum module instances
- Supports multiple forum types (general discussion, Q&A, news, single forum, etc.)
- Automatically normalizes AI-provided forum type strings to valid Moodle types

### Forum Types Supported

The handler automatically normalizes forum types specified by the AI:

- **`general`** - Standard discussion forum (default)
  - Aliases: `discussion`, `thread`, `general discussion`
- **`qanda`** - Question & Answer forum for Q&A interactions
  - Aliases: `q&a`, `qa`, `question`, `qanda`, `qandquestion`
- **`news`** - News/announcements forum
  - Aliases: `announcement`, `news`
- **`single`** - Single forum for restricted topics
- **`eachuser`** - Each user posts one discussion
  - Aliases: `each`, `eachuser`
- **`teacher`** - Teacher posts, students can reply

### Language Strings Added

```php
$string['activitytype_forum'] = 'Forum';
$string['forumdescription'] = 'Collaborative discussion space for peer interaction and group communication';
```

### AI Guidance Updates

Updated activity guidance in `lang/en/aiplacement_modgen.php` to include forum examples:
- "Discuss in the X forum how..." 
- Forums listed as valid learning activities alongside quiz, book, assignment, etc.

## How It Works

### JSON Schema

When generating activities, the AI can now create forum definitions like:

```json
{
  "type": "forum",
  "name": "Module Discussion",
  "intro": "This forum is for discussing topics covered in this week's content",
  "type": "general"
}
```

Or for Q&A forums:

```json
{
  "type": "forum",
  "name": "Weekly Q&A",
  "intro": "Ask questions about this week's material here",
  "type": "qanda"
}
```

### Creation Process

1. AI includes forum object in `activities` array with:
   - `type`: `"forum"` (activity type identifier)
   - `name`: Forum display name
   - `intro`: Forum description/introduction
   - `type`: (optional) Forum interaction type - automatically normalized

2. Registry discovers Forum handler via auto-discovery
   - Scans `classes/activitytype/` directory
   - Instantiates `forum.php` class
   - Normalizes forum type string to valid Moodle type

3. Forum is created in course section with:
   - Optional tracking enabled by default
   - 9 attachments maximum
   - Optional subscription model
   - Appropriate settings for discussion type

## Integration with Module Generator

### Supported in AI Prompts

The Forum activity is now:
- Listed in activity guidance instructions
- Included in examples of natural language references
- Automatically discovered by the registry
- Ready for AI suggestions in generated modules

### Auto-Discovery

The registry in `classes/activitytype/registry.php` automatically:
1. Scans all `.php` files in the `activitytype` directory
2. Checks if they implement the `activity_type` interface
3. Calls `get_type()`, `get_display_string_id()`, and `get_prompt_description()`
4. Registers them in the internal map

Adding `forum.php` means no manual registration needed—it's automatically available.

## Usage Example

### AI-Generated Module with Forum

Module JSON might include:

```json
{
  "sections": [
    {
      "title": "Week 1: Introduction",
      "summary": "This week introduces key concepts.",
      "activities": [
        {
          "type": "forum",
          "name": "Introduce Yourself",
          "intro": "Please introduce yourself to the class and share your background."
        },
        {
          "type": "book",
          "name": "Course Textbook",
          "intro": "Read the introductory chapter"
        }
      ]
    }
  ]
}
```

Result: Forum and Book activities created in Week 1 section.

## Technical Notes

### Forum Configuration

- **Tracking**: Optional (allows students to opt-in)
- **Forced tracking**: Disabled
- **Subscription**: Optional (students choose)
- **Mail digest**: Disabled (no digest emails)
- **Attachments**: Up to 9 per post
- **Blocking**: No post blocking enabled
- **Rating**: No scale/rating applied
- **Word count**: Display disabled

### Error Handling

- Invalid forum types automatically normalize to `general`
- Missing name field returns null (activity creation fails gracefully)
- Exception handling logs errors for debugging
- Activity creation is atomic—whole forum or nothing

## Testing

To verify the Forum handler works:

1. Ensure `forum.php` exists in `classes/activitytype/`
2. Run `php admin/cli/purge_caches.php` to refresh registry
3. Check that registry includes `'forum' => 'aiplacement_modgen\activitytype\forum'`
4. Generate a module requesting forums
5. Verify forums appear in course with correct settings

## Future Enhancements

Possible future improvements:
- Support for forum-specific discussion prompts in AI guidance
- Pre-population of forums with starter discussion posts
- Configuration of forum grading/rating
- Template-based forum structures
- Forum-specific moderation settings

## References

- Moodle Forum Activity: https://docs.moodle.org/en/Forum_activity
- Activity Handler Base: `classes/activitytype/activity_type.php`
- Registry: `classes/activitytype/registry.php`
- Similar Handlers: `book.php`, `quiz.php`
