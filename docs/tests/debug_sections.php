<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once(__DIR__ . '/../../classes/local/date_calculator.php');

$courseid = 2;
$modinfo = get_fast_modinfo($courseid);
$sections = $modinfo->get_section_info_all();

echo "=== Section Structure ===\n\n";

foreach($sections as $s) {
    if($s->section >= 2 && $s->section <= 15) {
        echo sprintf(
            "Section %2d (ID %3d): %-50s | Parent: %s\n",
            $s->section,
            $s->id,
            substr($s->name, 0, 50),
            isset($s->parent) ? $s->parent : 'NONE'
        );
    }
}

// Test the hierarchy building directly
echo "\n=== Testing Hierarchy Building ===\n\n";
$reflection = new ReflectionClass('\aiplacement_modgen\local\date_calculator');
$method = $reflection->getMethod('build_section_hierarchy');
$method->setAccessible(true);
$hierarchy = $method->invoke(null, $sections);

echo "Parents array:\n";
print_r($hierarchy['parents']);

echo "\n=== Date Calculation (includeparents=false) ===\n\n";
$results_without = \aiplacement_modgen\local\date_calculator::calculate_section_dates($courseid, [], false);
foreach($results_without as $sectionid => $data) {
    if ($data['section'] >= 2 && $data['section'] <= 15) {
        echo sprintf(
            "ID %3d (Sec %2d): %-35s | Date: %-20s | Parent: %s\n",
            $sectionid,
            $data['section'],
            substr($data['name'], 0, 35),
            $data['formatted_date'] ?? 'NONE',
            $data['is_parent'] ? 'YES' : 'NO'
        );
    }
}

echo "\n=== Date Calculation (includeparents=true) ===\n\n";
$results_with = \aiplacement_modgen\local\date_calculator::calculate_section_dates($courseid, [], true);
foreach($results_with as $sectionid => $data) {
    if ($data['section'] >= 2 && $data['section'] <= 15) {
        echo sprintf(
            "ID %3d (Sec %2d): %-35s | Date: %-20s | Parent: %s\n",
            $sectionid,
            $data['section'],
            substr($data['name'], 0, 35),
            $data['formatted_date'] ?? 'NONE',
            $data['is_parent'] ? 'YES' : 'NO'
        );
    }
}

