# Learning Activity Integration Guide

## Overview

The `learningactivity` activity type is now integrated into the Module Assistant's activity registry. It allows programmatic creation of learning design metadata modules when generating course structures.

## Key Features

✅ **Integrated with Registry** - Uses same architecture as other activity types
✅ **Hidden from AI** - Won't be suggested by AI (set `AI_CREATABLE = false`)
✅ **Easy to Enable** - Change constant to `true` to allow AI suggestions in future
✅ **Flexible Creation** - Can be called directly or via registry

## How It Works

### 1. Activity Handler Structure

Location: `classes/activitytype/learningactivity.php`

```php
class learningactivity implements activity_type {
    // Flag to control AI visibility - change to true to enable AI suggestions
    public const AI_CREATABLE = false;
    
    public static function get_type(): string {
        return 'learningactivity';
    }
    
    public function create($activitydata, $course, $sectionnumber, $options): ?array {
        // Creates mod_learningactivity instance via create_module()
    }
}
```

### 2. Registry Filter

The registry's `get_supported_activity_metadata()` method now checks the `AI_CREATABLE` constant:

```php
foreach (self::get_map() as $type => $class) {
    if (defined("$class::AI_CREATABLE") && !$class::AI_CREATABLE) {
        continue; // Skip non-AI-creatable types
    }
    // ... add to metadata
}
```

### 3. Direct Access Method

New helper method `get_handler()` allows direct access to any handler:

```php
$handler = registry::get_handler('learningactivity');
$instance = new $handler();
$result = $instance->create($data, $course, $section);
```

## Usage Examples

### Example 1: Create When Generating Weeks

```php
// In your week/theme creation code
use aiplacement_modgen\activitytype\registry;

function create_week_with_metadata($course, $weekname, $sectionnumber) {
    // 1. Create the section first
    $sectionid = create_course_section($course, $sectionnumber);
    
    // 2. Get learningactivity handler
    $handler = registry::get_handler('learningactivity');
    
    if ($handler) {
        // 3. Prepare metadata
        $data = new stdClass();
        $data->sectiontype = 'section';
        $data->name = $weekname;
        $data->duration = '2-3 hours';
        $data->learningmode = 'Online';
        $data->learningtypes = ['Acquisition', 'Practice'];
        
        // 4. Create learning activity instance
        $instance = new $handler();
        $result = $instance->create($data, $course, $sectionnumber);
        
        if ($result) {
            // Success! CM ID available in $result['cmid']
        }
    }
}
```

### Example 2: Batch Creation with Registry

```php
// Create multiple learning activities at once
$activities = [];

foreach ($weeks as $week) {
    $activities[] = (object)[
        'type' => 'learningactivity',
        'sectiontype' => 'section',
        'name' => $week->name,
        'duration' => $week->duration,
        'learningmode' => 'Blended',
    ];
}

$outcome = registry::create_activities($activities, $course, $sectionnumber);
```

### Example 3: With Full Metadata

```php
$data = new stdClass();

// Required
$data->sectiontype = 'section'; // or 'activity'

// Optional metadata fields
$data->name = 'Week 1: Introduction';
$data->duration = '2 hours';
$data->learningmode = 'Online'; // or 'Blended', 'Face-to-face'
$data->groupactivity = 0; // 1 for group work
$data->instructions = 'This week covers...';
$data->learningoutcomes_weekly = 'Students will be able to...';
$data->designnotes = 'Consider adding forum for discussion';

// Arrays (will be converted to proper format)
$data->learningtypes = ['Acquisition', 'Practice', 'Discussion'];
$data->learningoutcomes = ['Outcome 1', 'Outcome 2'];
$data->assessments = ['Assessment 1'];

// Create
$handler = registry::get_handler('learningactivity');
$instance = new $handler();
$result = $instance->create($data, $course, $sectionnumber);
```

## Available Fields

Based on `mod_learningactivity` schema:

| Field | Type | Description |
|-------|------|-------------|
| `sectiontype` | string | 'section' (theme/week) or 'activity' |
| `name` | string | Title (optional for sections) |
| `duration` | string | Time estimate (e.g., "2 hours") |
| `groupactivity` | int | 1 for group work, 0 for individual |
| `learningmode` | string | 'Online', 'Blended', 'Face-to-face' |
| `instructions` | string/array | Description/instructions (HTML allowed) |
| `learningtypes` | array | Tags like ['Acquisition', 'Practice'] |
| `learningoutcomes` | array | Selected course outcomes |
| `learningoutcomes_weekly` | string | Week-specific outcomes |
| `assessments` | array | Linked assessments |
| `designnotes` | string | Instructor notes |

## Integration Points

### Where to Add Learning Activity Creation

1. **Quick Add Templates** (`ajax/suggest.php` or template handler)
   - When creating themes/weeks via templates
   - Add metadata to each section

2. **CSV Import** (`ajax/create_sections.php`)
   - Parse additional columns for metadata
   - Create learning activities alongside sections

3. **AI Module Generation** (`classes/local/ai_service.php`)
   - After creating sections, add metadata
   - Extract learning design info from AI response

4. **Date Application** (`classes/local/date_calculator.php`)
   - When applying dates, optionally create metadata

## Enabling AI Suggestions (Future)

To allow AI to suggest learning activities in the future:

1. Open `classes/activitytype/learningactivity.php`
2. Change line 37:
   ```php
   public const AI_CREATABLE = true; // Was: false
   ```
3. Update `get_prompt_description()` with proper description:
   ```php
   public static function get_prompt_description(): string {
       return 'A Learning Activity metadata module that captures pedagogical design information about a section or activity, including duration, learning modes, learning types (Acquisition, Practice, Discussion, etc.), and learning outcomes alignment.';
   }
   ```
4. Clear Moodle caches
5. AI will now see and suggest learning activities

## Testing

Run the test script:
```bash
docker-compose exec moodle php /var/www/html/ai/placement/modgen/docs/test_learningactivity_creation.php
```

Expected output:
- ✓ Handler found
- ✓ Created successfully
- ✓ learningactivity correctly hidden from AI
- List of AI-visible types (should NOT include learningactivity)

## Benefits

1. **Consistent Architecture** - Same pattern as all other activities
2. **Future-Ready** - Easy to enable AI suggestions later
3. **Discoverable** - Other code can find handler via registry
4. **Maintainable** - Single location for creation logic
5. **Flexible** - Can be used directly or via registry batch methods

## Next Steps

Integrate learning activity creation into your section generation workflows:
- Add to Quick Add template creation
- Add to CSV import processing
- Add to theme/week creation functions
- Optionally: Extract learning design info from AI responses

---

**Note**: Learning activities are hidden from AI by default to prevent unintended suggestions. Change `AI_CREATABLE` to `true` when ready to allow AI to suggest learning design metadata.
