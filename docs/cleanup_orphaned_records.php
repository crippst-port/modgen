<?php
/**
 * Cleanup script for orphaned aiplacement_modgen_aigen records.
 * Run from command line: php cleanup_orphaned_records.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Get course ID from command line or default to all courses.
list($options, $unrecognized) = cli_get_params(
    ['courseid' => null, 'help' => false],
    ['h' => 'help']
);

if ($options['help']) {
    echo "Cleanup orphaned AI generation tracking records.\n\n";
    echo "Options:\n";
    echo "  --courseid=N    Clean specific course (default: all courses)\n";
    echo "  -h, --help      Print this help\n\n";
    echo "Example:\n";
    echo "  php cleanup_orphaned_records.php --courseid=2\n";
    exit(0);
}

$courseid = $options['courseid'];

// Build SQL to find orphaned records.
$sql = "SELECT aigen.id, aigen.cmid, aigen.courseid
          FROM {aiplacement_modgen_aigen} aigen
          LEFT JOIN {course_modules} cm ON cm.id = aigen.cmid
         WHERE (cm.id IS NULL OR cm.deletioninprogress = 1)";

$params = [];
if ($courseid) {
    $sql .= " AND aigen.courseid = :courseid";
    $params['courseid'] = $courseid;
}

echo "Searching for orphaned records...\n";
$orphaned = $DB->get_records_sql($sql, $params);

if (empty($orphaned)) {
    echo "No orphaned records found.\n";
    exit(0);
}

echo "Found " . count($orphaned) . " orphaned record(s).\n";
echo "Deleting...\n";

$deleted = 0;
foreach ($orphaned as $record) {
    if ($DB->delete_records('aiplacement_modgen_aigen', ['id' => $record->id])) {
        $deleted++;
        echo "  Deleted record ID {$record->id} (cmid: {$record->cmid}, courseid: {$record->courseid})\n";
    }
}

echo "\nCleanup complete. Deleted {$deleted} record(s).\n";

// Show remaining counts by course if no specific course specified.
if (!$courseid) {
    $sql = "SELECT aigen.courseid, COUNT(aigen.id) as count
              FROM {aiplacement_modgen_aigen} aigen
              JOIN {course_modules} cm ON cm.id = aigen.cmid
             WHERE cm.deletioninprogress = 0
             GROUP BY aigen.courseid";
    
    $remaining = $DB->get_records_sql($sql);
    
    if (!empty($remaining)) {
        echo "\nRemaining valid records by course:\n";
        foreach ($remaining as $course) {
            echo "  Course {$course->courseid}: {$course->count} record(s)\n";
        }
    } else {
        echo "\nNo valid records remaining.\n";
    }
} else {
    $sql = "SELECT COUNT(aigen.id)
              FROM {aiplacement_modgen_aigen} aigen
              JOIN {course_modules} cm ON cm.id = aigen.cmid
             WHERE aigen.courseid = :courseid
               AND cm.deletioninprogress = 0";
    
    $remaining = $DB->count_records_sql($sql, ['courseid' => $courseid]);
    echo "\nRemaining valid records for course {$courseid}: {$remaining}\n";
}

exit(0);
