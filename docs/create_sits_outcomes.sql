-- Create SITS module learning outcomes table
CREATE TABLE IF NOT EXISTS mdl_sits_module_learning_outcomes
(
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    module_code          TEXT,
    module_academic_year TEXT,
    module_period        TEXT,
    module_occurrence    TEXT,
    learning_outcome     TEXT,
    fullcode             TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert test data
INSERT INTO mdl_sits_module_learning_outcomes (id, module_code, module_academic_year, module_period, module_occurrence, learning_outcome, fullcode) VALUES 
(49, 'M20003', '2024/25', 'Y', 'SMYEAR', 'Describe and understand the main concepts of microbiology, including diversity of microbial life, pathogenic and beneficial microorganisms, microbial growth requirements, control of microbial growth, diversity and replication of viruses', 'M20003-2024/25-SMYEAR'),
(50, 'M20003', '2024/25', 'Y', 'SMYEAR', 'Recall and understand the structure and organisation of genetic material and explain the mechanisms of inheritance.', 'M20003-2024/25-SMYEAR'),
(51, 'M20003', '2024/25', 'Y', 'SMYEAR', 'Describe and understand the processes of DNA replication, transcription and translation.', 'M20003-2024/25-SMYEAR'),
(52, 'M20003', '2024/25', 'Y', 'SMYEAR', 'Become competent in basic microbiology laboratory skills, such as aseptic technique, preparation and maintenance of pure cultures, Gram-staining and microscopy.', 'M20003-2024/25-SMYEAR'),
(53, 'M20003', '2024/25', 'Y', 'SMYEAR', 'Define the main types of microbiology culture media and identify the main methods of sterilisation and decontamination.', 'M20003-2024/25-SMYEAR'),
(54, 'M20003', '2024/25', 'Y', 'SMYEAR', 'Recall and understand the control of cellular processes at the molecular level and the nature of genetic damage and its repair.', 'M20003-2024/25-SMYEAR');
