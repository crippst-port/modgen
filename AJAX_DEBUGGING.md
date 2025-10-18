# Debugging Module Exploration AJAX Issues

## Steps to Diagnose:

1. **Open Browser Console:**
   - Press `F12` or `Cmd+Option+I` (macOS)
   - Go to "Console" tab
   - Look for any JavaScript errors

2. **Expected Console Output:**
   - You should see: `Loading insights for course [ID]`
   - You should see: `Fetching from: http://localhost/.../explore_ajax.php?courseid=...`
   - You should see: `Response status: 200`
   - You should see: `Response data: {success: true, data: {...}}`

3. **Check Network Tab:**
   - Go to "Network" tab in browser console
   - Reload the explore page
   - Look for a request to `explore_ajax.php`
   - Check the response (should be JSON)
   - Check status code (should be 200)

4. **Common Issues:**
   - **Network tab shows 404**: File path is wrong in JavaScript
   - **Network tab shows 403**: Permission/capability issue
   - **Network tab shows 500**: Error in PHP - check `exploreerror` string
   - **No network request at all**: JavaScript module not loading
   - **CORS error**: Cross-origin issue (shouldn't happen with local path)

5. **Clear Cache:**
   - In Moodle: Site administration > Development > Purge caches
   - Or run: `php admin/cli/purge_caches.php` in terminal

6. **Check PHP Error Log:**
   - /var/log/apache2/error_log (or your server's error log)
   - Look for errors from explore_ajax.php

## Quick Test:

You can test the AJAX endpoint directly in browser address bar by visiting:
```
http://localhost:8000/ai/placement/modgen/ajax/explore_ajax.php?courseid=1&sesskey=XXXXX
```

Replace `XXXXX` with your session key (check browser cookies for MOODLESESSID).

The response should be JSON like:
```json
{
  "success": true,
  "data": {
    "pedagogical": "...",
    "learning_types": "...",
    "activities": "..."
  }
}
```
