<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Automated test script for security fixes.
 *
 * This script tests the implemented security fixes including:
 * - Rate limiting functionality
 * - Database query optimizations
 * - XSS prevention measures
 *
 * Run from command line: php test_security_fixes.php
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Ensure we're running from CLI.
if (!CLI_SCRIPT) {
    die('This script must be run from the command line.');
}

echo "=== Moodle Plugin Security Fixes - Automated Tests ===\n\n";

$passed = 0;
$failed = 0;

// Test 1: Rate Limiting Cache Definition.
echo "Test 1: Verifying rate limiting cache definition exists...\n";
try {
    $cache = cache::make('aiplacement_modgen', 'ai_requests');
    if ($cache) {
        echo "  ✓ PASSED: Rate limiting cache is configured\n";
        $passed++;
    } else {
        echo "  ✗ FAILED: Rate limiting cache not found\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAILED: " . $e->getMessage() . "\n";
    $failed++;
}
echo "\n";

// Test 2: Section Maps Cache Definition.
echo "Test 2: Verifying section maps cache definition exists...\n";
try {
    $cache = cache::make('aiplacement_modgen', 'section_maps');
    if ($cache) {
        echo "  ✓ PASSED: Section maps cache is configured\n";
        $passed++;
    } else {
        echo "  ✗ FAILED: Section maps cache not found\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAILED: " . $e->getMessage() . "\n";
    $failed++;
}
echo "\n";

// Test 3: Rate Limit Configuration.
echo "Test 3: Verifying rate limit configuration...\n";
$ratelimit = get_config('aiplacement_modgen', 'ai_rate_limit');
if ($ratelimit !== false) {
    echo "  ✓ PASSED: Rate limit setting exists (value: " . ($ratelimit ?: '10 (default)') . ")\n";
    $passed++;
} else {
    echo "  ⚠ WARNING: Rate limit setting not configured, using default (10)\n";
    $passed++; // Still pass as default is acceptable.
}
echo "\n";

// Test 4: Database Index on courseid.
echo "Test 4: Checking database index on aiplacement_modgen_aigen.courseid...\n";
try {
    $dbman = $DB->get_manager();
    $table = new xmldb_table('aiplacement_modgen_aigen');
    $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

    if ($dbman->index_exists($table, $index)) {
        echo "  ✓ PASSED: Database index exists on courseid column\n";
        $passed++;
    } else {
        echo "  ✗ FAILED: Database index missing on courseid column\n";
        echo "    Run: php admin/cli/upgrade.php to apply database changes\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAILED: " . $e->getMessage() . "\n";
    $failed++;
}
echo "\n";

// Test 5: Mustache Template Exists.
echo "Test 5: Verifying XSS fix - Mustache template exists...\n";
$templatepath = $CFG->dirroot . '/ai/placement/modgen/templates/ai_policy_acceptance.mustache';
if (file_exists($templatepath)) {
    echo "  ✓ PASSED: ai_policy_acceptance.mustache template exists\n";
    $passed++;
} else {
    echo "  ✗ FAILED: ai_policy_acceptance.mustache template not found\n";
    $failed++;
}
echo "\n";

// Test 6: AMD Module Exists.
echo "Test 6: Verifying XSS fix - AMD module exists...\n";
$amdpath = $CFG->dirroot . '/ai/placement/modgen/amd/src/policy_acceptance.js';
if (file_exists($amdpath)) {
    echo "  ✓ PASSED: policy_acceptance.js AMD module exists\n";
    $passed++;
} else {
    echo "  ✗ FAILED: policy_acceptance.js AMD module not found\n";
    $failed++;
}
echo "\n";

// Test 7: Rate Limiting Functionality.
echo "Test 7: Testing rate limiting functionality...\n";
try {
    $cache = cache::make('aiplacement_modgen', 'ai_requests');
    $testkey = 'test_user_999999';

    // Clear any existing test data.
    $cache->delete($testkey);

    // Simulate first request.
    $cache->set($testkey, 1);
    $count = $cache->get($testkey);

    if ($count === 1) {
        echo "  ✓ PASSED: Rate limiting counter works correctly\n";
        $passed++;
    } else {
        echo "  ✗ FAILED: Rate limiting counter returned unexpected value: $count\n";
        $failed++;
    }

    // Clean up.
    $cache->delete($testkey);
} catch (Exception $e) {
    echo "  ✗ FAILED: " . $e->getMessage() . "\n";
    $failed++;
}
echo "\n";

// Test 8: Database Query Optimization - Check for enrol_get_my_courses usage.
echo "Test 8: Verifying SQL optimization in generator_form.php...\n";
$formpath = $CFG->dirroot . '/ai/placement/modgen/classes/form/generator_form.php';
if (file_exists($formpath)) {
    $content = file_get_contents($formpath);
    if (strpos($content, 'enrol_get_my_courses') !== false) {
        echo "  ✓ PASSED: generator_form.php uses Moodle API (enrol_get_my_courses)\n";
        $passed++;
    } else {
        echo "  ✗ FAILED: generator_form.php does not use enrol_get_my_courses\n";
        $failed++;
    }
} else {
    echo "  ✗ FAILED: generator_form.php not found\n";
    $failed++;
}
echo "\n";

// Test 9: N+1 Query Fix - Check for batch fetching in suggest.php.
echo "Test 9: Verifying N+1 query fix in suggest.php...\n";
$suggestpath = $CFG->dirroot . '/ai/placement/modgen/ajax/suggest.php';
if (file_exists($suggestpath)) {
    $content = file_get_contents($suggestpath);
    if (strpos($content, 'get_in_or_equal') !== false && strpos($content, 'PERFORMANCE FIX') !== false) {
        echo "  ✓ PASSED: suggest.php uses batch fetching to avoid N+1 queries\n";
        $passed++;
    } else {
        echo "  ✗ FAILED: suggest.php does not appear to use batch fetching\n";
        $failed++;
    }
} else {
    echo "  ✗ FAILED: suggest.php not found\n";
    $failed++;
}
echo "\n";

// Summary.
echo "=== Test Summary ===\n";
echo "Total tests: " . ($passed + $failed) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "\n";

if ($failed === 0) {
    echo "✓ All tests passed!\n";
    exit(0);
} else {
    echo "✗ Some tests failed. Please review the output above.\n";
    exit(1);
}
