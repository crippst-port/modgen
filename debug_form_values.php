<?php
// Inline debugging helper - shows what form values are being submitted
// Call this from prompt.php after form submission to see what data was received

$debug_output = [];

// Check if we're in a template test
if (isset($pdata->curriculum_template)) {
    $debug_output['curriculum_template'] = [
        'isset' => true,
        'value' => $pdata->curriculum_template,
        'empty' => empty($pdata->curriculum_template),
        'length' => strlen((string)$pdata->curriculum_template),
    ];
} else {
    $debug_output['curriculum_template'] = [
        'isset' => false,
        'value' => null,
        'empty' => true,
    ];
}

// Check form itself
$debug_output['form_fields'] = [];
if (isset($pdata)) {
    foreach ((array)$pdata as $key => $value) {
        $debug_output['form_fields'][$key] = [
            'type' => gettype($value),
            'value' => is_string($value) ? substr($value, 0, 100) : var_export($value, true),
        ];
    }
}

// Output as formatted HTML debug box
echo '<div style="background: #f0f0f0; border: 2px solid #cc0000; padding: 15px; margin: 20px; font-family: monospace; font-size: 12px; border-radius: 4px;">';
echo '<strong style="color: #cc0000;">DEBUG: Form Submission Data</strong><br>';
echo 'Time: ' . date('Y-m-d H:i:s') . '<br><br>';
echo '<pre style="background: white; padding: 10px; overflow-x: auto;">';
echo json_encode($debug_output, JSON_PRETTY_PRINT);
echo '</pre>';
echo '</div>';
?>
