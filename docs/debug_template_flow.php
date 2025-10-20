<?php
// Debug endpoint for template flow diagnosis
// Access at: /ai/placement/modgen/debug_template_flow.php

define('CLI_SCRIPT', false);
define('REQUIRE_LOGIN', false);
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../../config.php');

// Set headers for plain text output
header('Content-Type: text/plain; charset=utf-8');

// Reset OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
}

echo "=== MODGEN TEMPLATE DEBUG FLOW ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "===============================\n\n";

// 1. Check if code is loaded
echo "1. CODE VERIFICATION:\n";
echo "-------------------\n";
$prompt_file = __DIR__ . '/prompt.php';
$ai_service_file = __DIR__ . '/classes/local/ai_service.php';
$template_reader_file = __DIR__ . '/classes/local/template_reader.php';

$prompt_content = file_get_contents($prompt_file);
$ai_content = file_get_contents($ai_service_file);
$template_content = file_get_contents($template_reader_file);

echo "✓ prompt.php has DEBUG logs: " . (strpos($prompt_content, 'DEBUG: $pdata->curriculum_template') !== false ? 'YES' : 'NO') . "\n";
echo "✓ ai_service.php has wrapper logging: " . (strpos($ai_content, 'generate_module_with_template called') !== false ? 'YES' : 'NO') . "\n";
echo "✓ template_reader.php has type casting: " . (strpos($template_content, '(int)trim($parts[1])') !== false ? 'YES' : 'NO') . "\n\n";

// 2. Check configuration
echo "2. PLUGIN CONFIGURATION:\n";
echo "------------------------\n";
$enable_templates = get_config('aiplacement_modgen', 'enable_templates');
echo "Templates enabled: " . ($enable_templates ? 'YES' : 'NO') . "\n";

if ($enable_templates) {
    $templates_config = get_config('aiplacement_modgen', 'curriculum_templates');
    echo "Templates config set: " . (!empty($templates_config) ? 'YES' : 'NO') . "\n";
    if ($templates_config) {
        echo "\nRaw config value:\n";
        echo str_repeat("-", 40) . "\n";
        echo $templates_config;
        echo "\n" . str_repeat("-", 40) . "\n\n";
    }
    
    // 3. Parse templates
    echo "3. TEMPLATE PARSING:\n";
    echo "-------------------\n";
    try {
        $template_reader = new \aiplacement_modgen\local\template_reader();
        $templates = $template_reader->get_curriculum_templates();
        echo "Templates found: " . count($templates) . "\n";
        
        if (!empty($templates)) {
            echo "\nParsed templates:\n";
            foreach ($templates as $key => $name) {
                echo "  Key: '$key' => Name: '$name'\n";
            }
            echo "\n";
            
            // 4. Try to extract first template
            if (count($templates) > 0) {
                echo "4. TEMPLATE EXTRACTION TEST:\n";
                echo "----------------------------\n";
                $first_key = array_key_first($templates);
                echo "Testing extraction of: '$first_key' => '" . $templates[$first_key] . "'\n\n";
                
                try {
                    $template_data = $template_reader->extract_curriculum_template($first_key);
                    echo "✓ Extraction successful!\n";
                    echo "Template data keys: " . implode(', ', array_keys($template_data)) . "\n\n";
                    
                    foreach ($template_data as $key => $value) {
                        if (is_array($value)) {
                            echo "  $key: ARRAY with " . count($value) . " items\n";
                        } elseif (is_string($value)) {
                            echo "  $key: STRING with " . strlen($value) . " chars\n";
                        } else {
                            echo "  $key: " . gettype($value) . "\n";
                        }
                    }
                    echo "\n";
                    
                    // 5. Check what will be passed to AI
                    echo "5. AI SERVICE READINESS:\n";
                    echo "----------------------\n";
                    if (!empty($template_data['course_info'])) {
                        echo "✓ Course info available\n";
                        echo "  Name: " . $template_data['course_info']['name'] . "\n";
                    }
                    if (!empty($template_data['structure'])) {
                        echo "✓ Structure available: " . count($template_data['structure']) . " sections\n";
                    }
                    if (!empty($template_data['activities'])) {
                        echo "✓ Activities available: " . count($template_data['activities']) . " activities\n";
                    }
                    if (!empty($template_data['template_html'])) {
                        echo "✓ HTML available: " . strlen($template_data['template_html']) . " chars\n";
                    }
                    
                } catch (Exception $e) {
                    echo "✗ Extraction FAILED: " . $e->getMessage() . "\n";
                }
            }
        } else {
            echo "✗ No templates found after parsing!\n";
        }
    } catch (Exception $e) {
        echo "✗ Error loading template_reader: " . $e->getMessage() . "\n";
    }
} else {
    echo "✗ Templates are DISABLED in plugin settings!\n";
}

// 6. Recent error logs
echo "\n6. RECENT ERROR LOGS (last 30 lines):\n";
echo "------------------------------------\n";
$log_file = '/Users/tom/moodledata45/modgen_logs/debug.log';
if (file_exists($log_file)) {
    $lines = file($log_file);
    $recent = array_slice($lines, -30);
    foreach ($recent as $line) {
        echo $line;
    }
} else {
    echo "Log file not found at: $log_file\n";
}

echo "\n\n=== DIAGNOSIS COMPLETE ===\n";
echo "If all items show ✓, the template system is ready.\n";
echo "Check error logs immediately after selecting a template in prompt.php\n";
echo "to see if DEBUG logs appear.\n";
?>
