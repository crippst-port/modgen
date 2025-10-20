# Explore Insights Caching System

## Overview

The Explore page now includes intelligent caching of AI-generated insights. This system:

- **Saves API calls**: After insights are generated once, they're cached in the database
- **Loads instantly**: Subsequent page visits retrieve cached data instead of calling the AI
- **User control**: A "Refresh" button lets users force fresh AI analysis when needed
- **Automatic updates**: Refreshed insights replace the cached version

## Architecture

### Database Table: `aiplacement_modgen_cache`

```sql
CREATE TABLE aiplacement_modgen_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    courseid INT NOT NULL UNIQUE,
    data MEDIUMTEXT NOT NULL,
    timecreated INT NOT NULL,
    FOREIGN KEY (courseid) REFERENCES course(id),
    INDEX courseid_idx (courseid)
);
```

**Fields:**
- `id` - Primary key
- `courseid` - Course ID (unique - one cache per course)
- `data` - Complete insights JSON (pedagogical, learning types, improvements, charts, etc.)
- `timecreated` - Unix timestamp when cache was created/updated

### PHP Cache Manager: `classes/local/explore_cache.php`

Provides a simple API for cache operations:

```php
// Get cached insights
$insights = \aiplacement_modgen\local\explore_cache::get($courseid);

// Save/update cache
\aiplacement_modgen\local\explore_cache::set($courseid, $data);

// Check if cache exists
$exists = \aiplacement_modgen\local\explore_cache::exists($courseid);

// Get cache timestamp
$timestamp = \aiplacement_modgen\local\explore_cache::get_timestamp($courseid);

// Clear cache for one course
\aiplacement_modgen\local\explore_cache::clear($courseid);

// Clear all cache
\aiplacement_modgen\local\explore_cache::clear_all();
```

### AJAX Endpoint: `ajax/explore_ajax.php`

**Updated Parameters:**
- `courseid` (required) - Course ID
- `refresh` (optional) - Set to 1 to force fresh AI analysis

**Cache Logic Flow:**

```
User loads Explore page
    ↓
explore_ajax.php called with ?courseid=X
    ↓
if refresh=0 (default):
    Check if cache exists for course X
    ↓
    Cache found?
        YES → Return cached data immediately ⚡ (fast)
        NO ↓
    Generate new insights via AI
    Save to cache
    Return insights
    ↓
if refresh=1:
    Skip cache check
    Generate fresh insights via AI
    Update cache with new data
    Return insights
```

### JavaScript Module: `amd/src/explore.js`

**New Methods:**

```javascript
/**
 * Refresh insights by forcing new AI analysis.
 * Shows loading spinner and re-fetches data with refresh=1
 */
loadInsights(courseId, refresh = false)

/**
 * Trigger a refresh from the UI.
 * User clicks the "Refresh" button
 */
refreshInsights(courseId)

/**
 * Attach handler to refresh button
 */
enableRefreshButton(courseId)
```

## User Interface

### Explore Page Header

```
EXPLORE | 🔄 Refresh | 📥 Download PDF
```

**Buttons:**
- **Refresh (🔄)** - Forces new AI analysis, saves to cache, refreshes display
- **Download PDF (📥)** - Creates PDF from current insights

## User Workflows

### First Visit (Cold Load)
1. User navigates to Explore page
2. Loading spinner appears
3. System checks cache → not found
4. AI generates insights (~5-10 seconds)
5. Results saved to database cache
6. Page displays insights
7. Future visits will be instant

### Subsequent Visits (Cached)
1. User navigates to Explore page
2. Loading spinner briefly appears
3. System checks cache → found ✓
4. Page displays cached insights immediately ⚡
5. Data remains current until explicitly refreshed

### Manual Refresh
1. User clicks **Refresh (🔄)** button
2. Page shows loading spinner
3. System skips cache, calls AI
4. New insights generated
5. Cache is updated with new data
6. Page displays fresh insights

## Database Integration

### Installation

The cache table is created automatically when the plugin is installed or upgraded:

```bash
php admin/cli/upgrade.php
```

### Migration Path

If updating from a version without caching:

1. Plugin is uploaded
2. Moodle detects new db/install.xml
3. Upgrade process creates `aiplacement_modgen_cache` table
4. Existing functionality continues uninterrupted
5. First access to each course creates new cache entry

## Performance Impact

### Load Times

| Scenario | Time | Source |
|----------|------|--------|
| First load, no cache | 5-10s | AI API call |
| Subsequent loads | <500ms | Database query |
| Manual refresh | 5-10s | AI API call (cache updated) |

### Database Considerations

- **Size**: Average insight data ~5-10 KB per course (scales well)
- **Queries**: Single indexed SELECT by courseid (~1-5ms)
- **Updates**: Batched updates when refresh is clicked
- **Cleanup**: No automatic cache expiration (optional: implement TTL later)

## API Reference

### PHP: Get Cached Insights

```php
<?php
namespace aiplacement_modgen\local;

// Load insights from cache if available
$courseid = 123;
$insights = explore_cache::get($courseid);

if ($insights) {
    // Cache hit - use cached data
    echo json_encode(['success' => true, 'data' => $insights, 'source' => 'cache']);
} else {
    // Cache miss - generate new insights
    $insights = generate_insights($courseid);
    explore_cache::set($courseid, $insights);
    echo json_encode(['success' => true, 'data' => $insights, 'source' => 'ai']);
}
?>
```

### JavaScript: Force Refresh

```javascript
require(['aiplacement_modgen/explore'], function(module) {
    // Get the courseId from the page (or pass it explicitly)
    var courseId = 42;
    
    // Option 1: Refresh through the button
    // (User clicks the Refresh button - automatic)
    
    // Option 2: Programmatic refresh
    module.refreshInsights(courseId);
});
```

### AJAX Endpoint: Usage Examples

**Load cached insights (default):**
```
GET /ai/placement/modgen/ajax/explore_ajax.php?courseid=42
```

**Force fresh AI analysis:**
```
GET /ai/placement/modgen/ajax/explore_ajax.php?courseid=42&refresh=1
```

## Configuration

### Optional: Cache Expiration (Future Enhancement)

Add to settings.php if you want automatic cache invalidation:

```php
// Clear cache older than X days
$timecreated = $this->get_timestamp($courseid);
$age_seconds = time() - $timecreated;
$cache_ttl_seconds = 86400 * 7; // 7 days

if ($age_seconds > $cache_ttl_seconds) {
    $this->clear($courseid);
    // Generate fresh insights
}
```

### Optional: Cache Size Limit (Future Enhancement)

Prevent cache table from growing indefinitely:

```php
// Keep only the 100 most recent caches
$max_caches = 100;
$db->delete_records_select('aiplacement_modgen_cache',
    'id NOT IN (SELECT id FROM (SELECT id FROM aiplacement_modgen_cache ORDER BY timecreated DESC LIMIT ?) t)',
    [$max_caches]
);
```

## Troubleshooting

### Cache Not Being Used

**Symptoms:** Always calls AI, never returns cached data

**Checks:**
1. Verify database table exists: `SELECT * FROM aiplacement_modgen_cache;`
2. Check error logs for PHP exceptions
3. Verify class autoloading: `grep explore_cache /config.php`
4. Check courseid parameter is being passed correctly

### Stale Cache

**Symptoms:** Old data persists even after course changes

**Solutions:**
- Click **Refresh (🔄)** button to force new analysis
- Manually clear: `php admin/cli/purge_caches.php`
- DB clear: `DELETE FROM aiplacement_modgen_cache WHERE courseid = 42;`

### Performance Issues

**If caching seems slow:**
- Check database index: `SHOW INDEX FROM aiplacement_modgen_cache;`
- Verify courseid index exists on table
- Check course load: `SELECT COUNT(*) FROM aiplacement_modgen_cache;`

## Future Enhancements

1. **Cache TTL** - Add configurable expiration (e.g., refresh every 7 days)
2. **Compression** - Store compressed JSON to reduce DB size
3. **Incremental Updates** - Update only changed sections
4. **Course Change Detection** - Auto-clear cache when course structure changes
5. **Admin Dashboard** - Show cache status, hit rates, manage cache

## Version History

- **v1.0.0** (2025-10-20) - Initial caching system implementation
  - Database cache table
  - Cache manager class
  - Refresh button in UI
  - AJAX endpoint cache support
  - JavaScript refresh handler
