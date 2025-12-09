<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once(__DIR__ . '/../../classes/local/date_calculator.php');

$testnames = [
    "June 1–7: June 1–7: The Intersection of L...",
    "June 8–14: June 8–14: Commercialization o...",
    "June 1–7: The Intersection of L...",
    "May 11–June 7: Christmas Literature and Arts",
    "June 8–July 5: Christmas and Society",
];

echo "=== Testing remove_existing_date() ===\n\n";

foreach ($testnames as $name) {
    $cleaned = \aiplacement_modgen\local\date_calculator::remove_existing_date($name);
    $changed = ($cleaned !== $name) ? '✓ CHANGED' : '✗ NO CHANGE';
    echo "{$changed}\n";
    echo "  Original: {$name}\n";
    echo "  Cleaned:  {$cleaned}\n\n";
}
