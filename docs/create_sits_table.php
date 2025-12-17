<?php
// Execute SQL directly through Moodle
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Create table
$DB->execute("
CREATE TABLE IF NOT EXISTS {sits_module_learning_outcomes} (
    id BIGINT(10) AUTO_INCREMENT PRIMARY KEY,
    module_code TEXT,
    module_academic_year TEXT,
    module_period TEXT,
    module_occurrence TEXT,
    learning_outcome TEXT,
    fullcode TEXT
)
");

echo "Table created successfully\n";

// Insert data
$records = [
    ['id' => 49, 'module_code' => 'M20003', 'module_academic_year' => '2024/25', 'module_period' => 'Y', 'module_occurrence' => 'SMYEAR', 'learning_outcome' => 'Describe and understand the main concepts of microbiology, including diversity of microbial life, pathogenic and beneficial microorganisms, microbial growth requirements, control of microbial growth, diversity and replication of viruses', 'fullcode' => 'M20003-2024/25-SMYEAR'],
    ['id' => 50, 'module_code' => 'M20003', 'module_academic_year' => '2024/25', 'module_period' => 'Y', 'module_occurrence' => 'SMYEAR', 'learning_outcome' => 'Recall and understand the structure and organisation of genetic material and explain the mechanisms of inheritance.', 'fullcode' => 'M20003-2024/25-SMYEAR'],
    ['id' => 51, 'module_code' => 'M20003', 'module_academic_year' => '2024/25', 'module_period' => 'Y', 'module_occurrence' => 'SMYEAR', 'learning_outcome' => 'Describe and understand the processes of DNA replication, transcription and translation.', 'fullcode' => 'M20003-2024/25-SMYEAR'],
    ['id' => 52, 'module_code' => 'M20003', 'module_academic_year' => '2024/25', 'module_period' => 'Y', 'module_occurrence' => 'SMYEAR', 'learning_outcome' => 'Become competent in basic microbiology laboratory skills, such as aseptic technique, preparation and maintenance of pure cultures, Gram-staining and microscopy.', 'fullcode' => 'M20003-2024/25-SMYEAR'],
    ['id' => 53, 'module_code' => 'M20003', 'module_academic_year' => '2024/25', 'module_period' => 'Y', 'module_occurrence' => 'SMYEAR', 'learning_outcome' => 'Define the main types of microbiology culture media and identify the main methods of sterilisation and decontamination.', 'fullcode' => 'M20003-2024/25-SMYEAR'],
    ['id' => 54, 'module_code' => 'M20003', 'module_academic_year' => '2024/25', 'module_period' => 'Y', 'module_occurrence' => 'SMYEAR', 'learning_outcome' => 'Recall and understand the control of cellular processes at the molecular level and the nature of genetic damage and its repair.', 'fullcode' => 'M20003-2024/25-SMYEAR'],
];

foreach ($records as $record) {
    $DB->insert_record('sits_module_learning_outcomes', (object)$record);
}

echo "Inserted " . count($records) . " records successfully\n";
