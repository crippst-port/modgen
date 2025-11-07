<?php
/**
 * Test script for escaped JSON normalization
 * 
 * This simulates what happens when the AI returns JSON with escaped newlines
 * in the summary field.
 */

// Simulate the normalize_ai_response functions
function unescape_json_string($str) {
    $replacements = [
        '\\\\n' => "\n",
        '\\\\t' => "\t",
        '\\\\r' => "\r",
        '\\\"' => '"',
        "\\\\" => "\\",
    ];
    
    $result = $str;
    foreach ($replacements as $pattern => $replacement) {
        $result = str_replace($pattern, $replacement, $result);
    }
    
    return $result;
}

// Test Case 1: JSON with escaped newlines (like the WWE/WCW response)
echo "=== TEST 1: Escaped Newlines ===\n";
$json_with_newlines = '{
  "themes": [
    {
      "title": "Theme 1: History and Formation",
      "summary": "{\\n  \\"themes\\": [\\n    {\\n      \\"title\\": \\"Theme 1\\",\\n      \\"summary\\": \\"\\",\\n      \\"weeks\\": []\\n    }\\n  ]\\n}",
      "weeks": []
    }
  ]
}';

echo "Input summary field (first 200 chars):\n";
$outer = json_decode($json_with_newlines, true);
$summary = $outer['themes'][0]['summary'];
echo substr($summary, 0, 200) . "...\n\n";

echo "Attempting direct decode: ";
$direct = json_decode($summary, true);
echo ($direct ? "✓ SUCCESS\n" : "✗ FAILED\n");

echo "Attempting after unescape: ";
$unescaped = unescape_json_string($summary);
$decoded = json_decode($unescaped, true);
echo ($decoded ? "✓ SUCCESS\n" : "✗ FAILED\n");

if ($decoded) {
    echo "Decoded structure has themes: " . (isset($decoded['themes']) ? "✓ YES\n" : "✗ NO\n");
}

// Test Case 2: Simple escaped JSON
echo "\n=== TEST 2: Simple Escaped JSON ===\n";
$simple = '{"name": "Test\\"Value"}';
echo "Input: " . $simple . "\n";
echo "Direct decode: ";
$test = json_decode($simple, true);
echo ($test ? "✓ SUCCESS - " . $test['name'] . "\n" : "✗ FAILED\n");

// Test Case 3: The actual user's response pattern
echo "\n=== TEST 3: User's Response Pattern ===\n";
$user_pattern = '{
    "themes": [
        {
            "title": "AI Generated Summary",
            "summary": "{\n  \"themes\": [\n    {\n      \"title\": \"Theme 1: History\",\n      \"summary\": \"\",\n      \"weeks\": []\n    }\n  ]\n}"
        }
    ]
}';

echo "Outer JSON decodes: ";
$outer2 = json_decode($user_pattern, true);
echo ($outer2 ? "✓ YES\n" : "✗ NO\n");

if ($outer2) {
    $summary2 = $outer2['themes'][0]['summary'];
    echo "Summary field starts with: " . substr($summary2, 0, 30) . "...\n";
    echo "Inner JSON decodes directly: ";
    $inner = json_decode($summary2, true);
    echo ($inner && isset($inner['themes']) ? "✓ YES\n" : "✗ NO\n");
}

echo "\n=== All Tests Complete ===\n";
?>
