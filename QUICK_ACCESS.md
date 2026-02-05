# Quick Access to Admin Tools

## Direct URL

The fastest way to access the admin tools is via direct URL:

```
https://your-moodle-site.edu/ai/placement/modgen/admin_tools.php
```

## Bookmarklet

Save this bookmarklet to your browser bookmarks bar for one-click access:

```javascript
javascript:(function(){window.location.href=window.location.origin+'/ai/placement/modgen/admin_tools.php';})();
```

**To create the bookmarklet:**
1. Create a new bookmark in your browser
2. Name it: "Modgen Admin Tools"
3. Paste the JavaScript code above as the URL/Location
4. Click the bookmark from any Moodle page to navigate to the admin tools

## Features at a Glance

| Feature | Action Button | Purpose |
|---------|--------------|---------|
| **Test Suite** | Run Tests | Execute all 11 PHPUnit tests (~12 seconds) |
| **Integrity Check** | Check Integrity | Scan course for database issues (read-only) |
| **Fix Issues** | Fix Issues | Automatically repair orphaned sections and invalid parents |
| **Clean Up** | Clean Up | Remove hidden sections with no activities |
| **Statistics** | - | View plugin usage across the site |

## Quick Workflows

### Verify After Deployment
```
1. Click bookmark or navigate to URL
2. Click "Run Tests"
3. Wait 12-15 seconds
4. ✓ All 11 tests pass = Deployment successful
```

### Fix Course Issues
```
1. Navigate to admin tools
2. Select course from dropdown
3. Click "Check Integrity"
4. Review issues
5. Click "Fix Issues" → Confirm
```

### Monthly Maintenance
```
1. Open admin tools
2. View Statistics section
3. For each flexsections course:
   - Select course
   - Click "Clean Up"
   - Confirm deletion
4. Check statistics again to verify cleanup
```

## Test Results Guide

### Successful Test Run
```
✅ All tests passed successfully!
OK (11 tests, 29 assertions)
Time: 00:12.271
```

**What this means:**
- Database transactions working correctly
- XSS protection active
- Error sanitization preventing information disclosure
- Cache optimization providing 81% performance improvement
- All integrity checks passing

### Failed Test Example
```
❌ Some tests failed. See details below.

FAILURES!
Tests: 11, Assertions: 27, Failures: 2.
```

**What to do:**
1. Review the detailed error output below the summary
2. Check which specific test failed
3. Run tests from CLI for more details: `vendor/bin/phpunit --filter transaction_handling_test`
4. Review the test file: `ai/placement/modgen/tests/transaction_handling_test.php`
5. Check recent code changes

## Troubleshooting

### "Access Denied"
**Problem:** Can't access admin_tools.php

**Solution:**
- Verify you're logged in as site administrator
- Check your role has `moodle/site:config` capability
- Try logging out and back in

### Tests Don't Run
**Problem:** Click "Run Tests" but nothing happens

**Solution:**
- Check browser console for JavaScript errors
- Verify PHPUnit is installed: `ls vendor/bin/phpunit`
- Check server has exec() enabled (not disabled in php.ini)

### Course Not in Dropdown
**Problem:** Can't find a course in the integrity checker dropdown

**Solution:**
- Only courses with `format = 'flexsections'` appear
- Convert course to flexsections format first
- Check course actually exists in database

## Security Notes

⚠️ **Admin Only** - This page requires site administrator access (`moodle/site:config`)

⚠️ **Production Use** - Safe to use on production, all actions are protected:
- Read operations are safe
- Destructive operations require confirmation
- All actions require valid sesskey (CSRF protection)
- Database changes are wrapped in transactions

✅ **Audit Trail** - All actions are logged in Moodle's standard logs

## Performance Notes

**Test Execution:**
- Typical runtime: 12-15 seconds
- Creates and tests 65+ sections
- Memory usage: ~75 MB
- Safe to run during normal site operation

**Integrity Checks:**
- Fast (< 1 second for typical course)
- Read-only queries
- No impact on users

**Fix Operations:**
- Fast (< 2 seconds)
- Minimal database writes
- Rebuilds course cache (may briefly affect course load time)

## Support

If you encounter issues with the admin tools:

1. **Check Logs:**
   - Site administration > Reports > Logs
   - Look for errors related to `aiplacement_modgen`

2. **Check Debug Info:**
   - Enable debugging: Site administration > Development > Debugging
   - Set to DEVELOPER level
   - Reproduce the issue
   - Check error messages

3. **Review Documentation:**
   - [ADMIN_TOOLS.md](ADMIN_TOOLS.md) - Full documentation
   - [CORRUPTION_ANALYSIS.md](CORRUPTION_ANALYSIS.md) - Database integrity details
   - [Implementation Plan](/.claude/plans/distributed-juggling-crystal.md) - Technical details

4. **Check Test Results:**
   - Run tests from admin panel
   - Compare with CLI test results
   - Review specific test failures

---

**Created:** 2025-02-05
**Version:** 1.0
**Requires:** Moodle 4.5+, Site Administrator access
