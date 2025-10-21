# URL Activity Handler

## Overview

Added support for creating URL activities (external links) via AI-generated module structures. The URL activity handler allows the AI to suggest external reading materials, reference websites, videos, and other resources that provide context and support for other learning activities.

## Implementation Details

### New Files Created

**`classes/activitytype/url.php`**
- Implements the `activity_type` interface
- Handles creation of URL module instances pointing to external resources
- Automatically adds protocol (https://) to URLs if not present
- Validates that URLs are properly formatted

### Key Features

- **External URL Support**: Links to any external website, article, video, or resource
- **Flexible URL Input**: Accepts URLs with or without protocol (automatically adds https://)
- **Context and Reading Support**: Designed specifically for providing external reading materials and context
- **No Activity Count Limit**: External links do NOT count toward the 5-activity per week limit
- **Simple Configuration**: Minimal required fields (name and URL)

### Language Strings Added

```php
$string['activitytype_url'] = 'External Link';
$string['urldescription'] = 'Links to external websites, articles, videos, or resources';
```

### AI Guidance Updates

Updated activity guidance in `lang/en/aiplacement_modgen.php` to:

1. **Clarify Activity Limits**: Maximum 5 Moodle activities per week (up to content needs)
2. **Exclude External Links from Limit**: URLs do not count toward the activity limit
3. **Provide URL Examples**: Show natural language for referencing links
4. **Support Face-to-Face**: Allow AI to include face-to-face activities as descriptions without Moodle activities

## How It Works

### JSON Schema

When generating activities, the AI can now create URL definitions like:

```json
{
  "type": "url",
  "name": "Background Reading on Theory X",
  "externalurl": "https://example.com/article/theory-x",
  "intro": "This article provides foundational understanding of the theory before the quiz"
}
```

### URL Protocol Handling

The handler automatically:
1. Checks if URL has http:// or https:// protocol
2. If missing, adds https:// to the URL
3. If URL looks like a domain, adds https://
4. Ensures URLs are always valid before creating activity

### Creation Process

1. AI includes URL object in `activities` array with:
   - `type`: `"url"` (activity type identifier)
   - `name`: Display name for the link
   - `externalurl` or `url`: The external URL to link to
   - `intro`: (optional) Description of what the link contains

2. Registry discovers URL handler via auto-discovery

3. URL activity is created with:
   - External URL properly formatted
   - Display in same window (not popup)
   - Display options for intro and heading
   - No time restrictions

## Integration with Module Generator

### Supported in AI Prompts

The URL activity is now:
- Listed in activity guidance as a valid activity type
- Documented as not counting toward weekly activity limits
- Recommended for external reading materials and context
- Automatically discovered by the registry

### Activity Limit Clarification

**Moodle Activities per Week**: Maximum 5
- Quiz, Book, Forum, Assignment, URL (when used as primary activity)
- These count toward the limit

**Non-Counting Activities**:
- External links used for supplementary reading (type: url)
- Face-to-face activities (described in summary, no Moodle activity)
- These can be included in any quantity

### Recommended Usage

**External Links Should Be Used For:**
- Background reading before main activities
- Reference materials during assessments
- Supplementary videos or multimedia
- Links to institutional resources
- Context from related courses or materials

**Natural Language Examples:**
- "Review the X article for background context"
- "Watch the X video to see applications in practice"
- "Use the X database for references and citations"
- "Check the X resource to understand the framework"
- "Read the X publication for the original research"

## Activity Limit Guidance

### Per Week/Section:
- **Minimum**: 1 Moodle activity (quiz, book, forum, assignment, etc.)
- **Maximum**: 5 Moodle activities
- **External Links**: No limit (supplementary, don't count)
- **Face-to-Face**: Can be included (described, don't create Moodle activity)

### Example Week with Multiple Components:

```json
{
  "title": "Week 3: Advanced Topics",
  "summary": "This week you'll master advanced applications. Start by reviewing the X article for context about the framework. Then work through the Y book chapters 4-5 for detailed explanations. Attend the Wednesday lecture (3pm, Building A, Room 102) where we'll work through case studies together. Take the Z quiz to assess your understanding. Discuss implications in the forum. By the end, you'll be able to apply the framework to new contexts.",
  "activities": [
    {
      "type": "url",
      "name": "Background Article: Framework Overview",
      "externalurl": "https://example.com/articles/framework"
    },
    {
      "type": "book",
      "name": "Advanced Topics Textbook"
    },
    {
      "type": "quiz",
      "name": "Framework Application Quiz"
    },
    {
      "type": "forum",
      "name": "Case Study Discussion"
    }
  ]
}
```

In this example:
- 1 External link (doesn't count toward limit)
- 4 Moodle activities (within 5-activity limit)
- 1 Face-to-face activity (described, no Moodle activity)
- **Total**: 5 activities for comprehensive learning

## Face-to-Face Activity Guidelines

When the AI includes face-to-face components:

1. **Include in Summary**: Describe as natural part of weekly narrative
2. **Don't Create Moodle Activity**: No need for URL, book, or other activity shell
3. **Be Specific**: Include timing, location, format when possible
4. **Link to Learning**: Explain how it connects to other learning activities

**Example Summary Narrative:**
> "This week you'll explore research methods. Begin by reading the X article to understand different methodologies. Attend the Monday seminar (10am, Room 123) where we'll discuss real-world applications. Follow up by completing the Y assignment to apply what you've learned. Participate in the discussion forum to share your insights."

## Technical Notes

### URL Configuration

- **Display**: Same window (not popup)
- **Display Options**: Show intro and heading
- **Parameters**: None by default
- **Time Restrictions**: None (always available)
- **Protocol**: Automatic addition if missing

### Error Handling

- Invalid/empty URLs return null (activity creation fails gracefully)
- URLs without protocol automatically get https://
- Missing name field returns null
- Exception handling logs errors for debugging

### Supported URL Types

- Regular web pages (https://example.com)
- Media (YouTube, Vimeo, etc. if direct links)
- PDFs and documents
- Institutional repositories
- Database access links
- Streaming services (where available)

## Testing

To verify the URL handler works:

1. Ensure `url.php` exists in `classes/activitytype/`
2. Run `php admin/cli/purge_caches.php` to refresh registry
3. Generate a module requesting external links
4. Verify URLs appear with correct format in course
5. Test that URLs open to external sites correctly

## Future Enhancements

Possible improvements:
- Support for popup window display option
- Custom display height/width for popups
- Automatic link validation to check if URLs are accessible
- Categorization of URLs by type (reading, video, resource, etc.)
- Tracking of external link usage in learning analytics

## References

- Moodle URL Activity: https://docs.moodle.org/en/URL_activity
- Activity Handler Base: `classes/activitytype/activity_type.php`
- Registry: `classes/activitytype/registry.php`
- Similar Handlers: `forum.php`, `book.php`
- Activity Guidance: `lang/en/aiplacement_modgen.php` (activityguidanceinstructions string)
