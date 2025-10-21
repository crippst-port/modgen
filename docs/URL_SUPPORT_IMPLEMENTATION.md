# URL Activity Support - Implementation Summary

## Overview

Successfully added support for URL activities (external links) to the module generator. The URL handler is now fully integrated with the JSON schema and AI prompt guidance.

## Changes Made

### 1. URL Activity Handler (`classes/activitytype/url.php`)
- Created new `url` class implementing `activity_type` interface
- Handles creation of Moodle URL/Link activities
- Validates URLs before creation
- Automatically adds https:// protocol if missing
- Gracefully handles invalid URLs by returning null

**Key Methods:**
- `get_type()`: Returns 'url'
- `create()`: Creates URL activity in course section
- `ensure_url_protocol()`: Adds protocol to URL if missing
- `is_valid_url()`: Validates that a string looks like a URL

### 2. Language Strings (`lang/en/aiplacement_modgen.php`)
```php
$string['activitytype_url'] = 'External Link';
$string['urldescription'] = 'Links to external websites, articles, videos, or resources';
```

### 3. Activity Guidance (`lang/en/aiplacement_modgen.php`)
Updated `activityguidanceinstructions` to include:

**Activity Limits:**
- Maximum 5 Moodle activities per week
- External links do NOT count toward the limit
- Can be used liberally for supplementary materials

**External Links Usage:**
- Direct students to reading materials
- Reference websites for context
- Videos or multimedia content
- Database links
- Examples: "Review the X article to understand background"

**Face-to-Face Activities:**
- Include in weekly summaries as descriptive text
- Do NOT require Moodle activities
- No need to create activity objects for lectures, seminars, etc.
- Example: "Attend the Wednesday 2pm lecture on X topic"

### 4. JSON Schema (`classes/local/ai_service.php`)
Updated activity schema in 3 locations to include additional fields:

```json
{
  "type": "string",
  "enum": ["quiz", "book", "forum", "url", "..."],
  "name": "string",
  "intro": "string", 
  "description": "string",
  "externalurl": "string",  // NEW for URL activities
  "chapters": [{            // NEW for Book activities
    "title": "string",
    "content": "string"
  }]
}
```

## How It Works

### AI Generation

When generating modules, the AI can now create URL activities:

```json
{
  "type": "url",
  "name": "Background Reading on Theory X",
  "externalurl": "https://example.com/article/theory-x",
  "intro": "This article provides foundational understanding"
}
```

### URL Creation Process

1. **Validation**: Check that URL field exists and is not empty
2. **URL Verification**: Validate it looks like an actual URL (contains domain, path, or protocol)
3. **Protocol Addition**: Add https:// if URL doesn't have protocol
4. **Module Creation**: Create URL module in Moodle with display settings
5. **Error Handling**: Return null if any step fails

### Key Features

- **Automatic Protocol Addition**: `example.com` → `https://example.com`
- **URL Validation**: Rejects text like "Read this article" that aren't URLs
- **Flexible Field Names**: Accepts `externalurl` or `url` field name
- **Graceful Failure**: Returns null so AI can try other activities instead
- **Debug Logging**: Detailed error logs for troubleshooting

## Updated Activity Guidance Summary

### Activity Per Week Breakdown
- **Minimum**: 1 Moodle activity (quiz, book, forum, assignment, URL)
- **Maximum**: 5 Moodle activities
- **External Links**: Unlimited (don't count toward limit)
- **Face-to-Face**: Unlimited (don't create Moodle activities)

### Example Week Structure
```
"This week you'll explore Advanced Topics through structured learning. 
 Begin by reviewing the Background Article (external link) for context. 
 Then use the Textbook to read chapters 4-5 for detailed explanations. 
 Attend the Wednesday 3pm lecture where we'll work through case studies. 
 Take the Assessment Quiz to check your understanding. 
 Discuss implications in the Discussion Forum. 
 By the end, you'll be able to apply concepts to new contexts."
```

**Activity Breakdown:**
- 1 External link (doesn't count)
- 1 Book (counts as 1/5)
- 1 Lecture described (doesn't create activity)
- 1 Quiz (counts as 2/5)
- 1 Forum (counts as 3/5)
- **Total:** 3 out of 5 Moodle activities

## AI Prompt Updates

The AI guidance now explicitly states:

**EXTERNAL LINKS (URLs):**
- Use external links to direct students to reading materials, reference websites, videos, multimedia content, or context related to other activities
- External links do NOT count toward the activity limit and can be used liberally to supplement learning
- Include externalurl field with full URL (e.g., "https://example.com")

**FACE-TO-FACE ACTIVITIES:**
- If the module includes face-to-face components, include these as descriptive text in the weekly summary
- Face-to-face activities do NOT require associated Moodle activities
- Examples: "Attend the Wednesday 2pm lecture on X topic", "Complete face-to-face group work in lab session"

## JSON Schema Fields

All three activity array definitions in ai_service.php now include:

```
'externalurl' => ['type' => 'string']     // For URL activities
'chapters' => [                            // For Book activities
    'type' => 'array',
    'items' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'content' => ['type' => 'string']
        ]
    ]
]
```

## File Changes Summary

| File | Changes |
|------|---------|
| `classes/activitytype/url.php` | **NEW** - URL activity handler |
| `lang/en/aiplacement_modgen.php` | Added language strings + updated activity guidance |
| `classes/local/ai_service.php` | Updated JSON schema in 3 locations (lines 108-133, 164-191, 206-233) |
| `.github/copilot-instructions.md` | No changes needed (already mentions URL in examples) |

## Testing the URL Handler

1. **Syntax Check**: ✅ No PHP syntax errors
2. **Registry Auto-Discovery**: ✅ url.php automatically discovered
3. **Schema Update**: ✅ JSON now includes externalurl field
4. **Caches**: ✅ Purged successfully

## Example Usage

### AI-Generated Module with URLs

```json
{
  "sections": [
    {
      "title": "Week 1: Foundations",
      "summary": "This week introduces key concepts. Start by reviewing the foundational article for context...",
      "outline": ["Understand basics", "Apply to examples"],
      "activities": [
        {
          "type": "url",
          "name": "Foundational Reading",
          "externalurl": "https://academic.example.com/foundations",
          "intro": "Essential background before tackling complex concepts"
        },
        {
          "type": "book",
          "name": "Core Textbook",
          "intro": "Chapters 1-3 cover essential theory"
        },
        {
          "type": "quiz",
          "name": "Foundations Quiz",
          "intro": "Check your understanding of core concepts"
        }
      ]
    }
  ]
}
```

**Result:** 1 external link (doesn't count) + 1 book + 1 quiz = 2 out of 5 activities

## Validation & Error Handling

### Valid URLs Accepted:
- `https://example.com`
- `http://example.com`
- `example.com` (converted to `https://example.com`)
- `www.example.com` (converted to `https://www.example.com`)
- `/path/to/local/resource`
- `example.com/path/to/resource`

### Invalid/Rejected:
- Empty string → returns null
- "Read this article" → returns null (not a URL format)
- "Please watch the video" → returns null (natural language, not URL)

## Known Limitations

1. **No Link Validation**: Doesn't check if URL is actually accessible
2. **No Embedded Preview**: URLs open in new window, no inline preview
3. **No Tracking**: No built-in analytics for link clicks
4. **No Update Mechanism**: Existing URLs can't be modified via generator

## Future Enhancements

- Add link validation (check if URL returns 200 status)
- Support for popup display option
- Custom window width/height for popups
- Link categorization metadata
- Automatic link title extraction from headers
- Broken link detection

## Related Documentation

- See `docs/URL_ACTIVITY_HANDLER.md` for detailed technical reference
- See `docs/FORUM_ACTIVITY_HANDLER.md` for similar activity handler
- Activity guidance details in `lang/en/aiplacement_modgen.php` (activityguidanceinstructions)

## Troubleshooting

**URLs not being created?**
1. Check that JSON includes `externalurl` field
2. Verify URL format (should start with http://, https://, www., or domain)
3. Check /tmp/modgen_debug.log for detailed error messages

**AI not generating URLs?**
1. Verify ai_service.php JSON schema has `externalurl` field
2. Check that 'url' is in supported activity types list
3. Review AI guidance for examples of URL usage

**Wrong number of activities per week?**
1. Remember: External links do NOT count toward 5-activity limit
2. Face-to-face activities don't create Moodle objects
3. Only quiz, book, forum, assignment, URL activities count

