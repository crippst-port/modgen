<?php
// Quick test to verify code changes are being loaded

require_once(__DIR__ . '/../../../config.php');

error_log('=== CACHE TEST ===');
error_log('Time: ' . date('Y-m-d H:i:s'));

// Check if our new logs would trigger
$test_code_present = false;
$prompt_file = __DIR__ . '/prompt.php';
$content = file_get_contents($prompt_file);
if (strpos($content, 'DEBUG: $pdata->curriculum_template') !== false) {
    error_log('✓ New code IS in prompt.php file');
    $test_code_present = true;
} else {
    error_log('✗ New code NOT found in prompt.php file');
}

// Try to trigger OPcache reset
if (function_exists('opcache_reset')) {
    opcache_reset();
    error_log('✓ OPcache reset called');
}

error_log('Testing complete. Next request should show new logs.');
echo "Cache test complete. Check error logs.";
?>
