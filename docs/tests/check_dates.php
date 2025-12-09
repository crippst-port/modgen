<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../../../config.php');
require_once(__DIR__ . '/../../classes/local/date_calculator.php');

$courseid = 4;
$results = \aiplacement_modgen\local\date_calculator::calculate_section_dates($courseid, [], true);

echo "=== Calculated Section Dates ===\n\n";
foreach ($results as $sectionid => $data) {
    if (!empty($data['formatted_date'])) {
        $type = $data['is_parent'] ? 'THEME' : 'WEEK';
        echo sprintf(
            "%s [%2d]: %-40s -> %s\n",
            $type,
            $data['section'],
            substr($data['name'], 0, 40),
            $data['formatted_date']
        );
    }
}
