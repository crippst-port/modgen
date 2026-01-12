<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * File processing service for uploaded documents.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Service class for processing uploaded files and extracting text content.
 */
class file_processor_service {
    
    /**
     * Process files from Moodle file manager draft area.
     *
     * @param int $draftitemid Draft item ID from file manager
     * @param int $contextid User context ID
     * @return array Array of processed files with filename, mimetype, extracted content, and preview
     */
    public function process_draft_files(int $draftitemid, int $contextid): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'user', 'draft', $draftitemid, 'filename', false);
        
        $supportingfiles = [];
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            
            // Validate file size
            if ($file->get_filesize() > constants::MAX_UPLOAD_SIZE) {
                debugging('File ' . $file->get_filename() . ' exceeds max size, skipping', DEBUG_DEVELOPER);
                continue;
            }
            
            $processed = $this->process_single_file(
                $file->get_filename(),
                $file->get_mimetype(),
                $file->get_content()
            );
            
            if ($processed) {
                $supportingfiles[] = $processed;
            }
        }
        
        return $supportingfiles;
    }
    
    /**
     * Process a single file and extract text content.
     *
     * @param string $filename File name
     * @param string $mimetype MIME type
     * @param string $content File content
     * @return array|null Processed file data or null if extraction failed
     */
    public function process_single_file(string $filename, string $mimetype, string $content): ?array {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Validate file extension
        if (!in_array($ext, constants::SUPPORTED_EXTENSIONS)) {
            return null;
        }
        
        $extracted = $this->extract_text_content($content, $ext, $mimetype);
        
        if ($extracted === null) {
            return null;
        }
        
        // Truncate large content
        if (strlen($extracted) > constants::MAX_FILE_CONTENT_LENGTH) {
            $extracted = substr($extracted, 0, constants::MAX_FILE_CONTENT_LENGTH) . "\n...[truncated]";
        }
        
        return [
            'filename' => $filename,
            'mimetype' => $mimetype,
            'extracted' => $extracted,
            'base64_preview' => substr(base64_encode($content), 0, constants::FILE_PREVIEW_LENGTH),
        ];
    }
    
    /**
     * Extract text content from file based on format.
     *
     * @param string $content File content
     * @param string $ext File extension
     * @param string $mimetype MIME type
     * @return string|null Extracted text or null if extraction failed
     */
    private function extract_text_content(string $content, string $ext, string $mimetype): ?string {
        // Simple text files
        if (in_array($ext, constants::TEXT_EXTENSIONS)) {
            return $content;
        }
        
        // RTF files
        if ($ext === 'rtf' || $mimetype === 'application/rtf' || $mimetype === 'text/rtf') {
            return $this->extract_rtf_text($content);
        }
        
        // DOCX files
        if ($ext === 'docx') {
            return $this->extract_docx_text($content);
        }
        
        // ODT files
        if ($ext === 'odt') {
            return $this->extract_odt_text($content);
        }
        
        // Generic text-based MIME types
        if (strpos($mimetype, 'text/') === 0 || 
            strpos($mimetype, 'application/xml') === 0 || 
            strpos($mimetype, 'application/json') === 0) {
            return $content;
        }
        
        return null;
    }
    
    /**
     * Extract text from RTF content.
     *
     * @param string $content RTF file content
     * @return string|null Extracted text or null on failure
     */
    private function extract_rtf_text(string $content): ?string {
        try {
            // Set timeout for regex operations to prevent hanging
            $oldlimit = ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', '1000000');
            
            $extracted = preg_replace('/\\\[a-z0-9]+\s?/i', '', $content);
            $extracted = preg_replace('/[{}]/', '', $extracted);
            $extracted = trim($extracted);
            
            // Clean up common RTF artifacts
            $extracted = str_replace(['\\\'97', '\\\'92'], ['-', '\''], $extracted);
            
            ini_set('pcre.backtrack_limit', $oldlimit);
            
            return strlen($extracted) > 10 ? $extracted : null;
        } catch (\Exception $e) {
            debugging('RTF extraction failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }
    
    /**
     * Extract text from DOCX content.
     *
     * @param string $content DOCX file content
     * @return string|null Extracted text or null on failure
     */
    private function extract_docx_text(string $content): ?string {
        $tmp = tempnam(sys_get_temp_dir(), 'modgen_docx_');
        
        try {
            file_put_contents($tmp, $content);
            $zip = new \ZipArchive();
            
            if ($zip->open($tmp) !== true) {
                return null;
            }
            
            // Validate ZIP entries to prevent path traversal
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, '..') !== false || strpos($name, '/') === 0) {
                    $zip->close();
                    return null;
                }
            }
            
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            
            if ($xml === false) {
                return null;
            }
            
            $extracted = strip_tags($xml);
            return strlen($extracted) > 10 ? $extracted : null;
            
        } catch (\Exception $e) {
            debugging('DOCX extraction failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        } finally {
            @unlink($tmp);
        }
    }
    
    /**
     * Extract text from ODT content.
     *
     * @param string $content ODT file content
     * @return string|null Extracted text or null on failure
     */
    private function extract_odt_text(string $content): ?string {
        $tmp = tempnam(sys_get_temp_dir(), 'modgen_odt_');
        
        try {
            file_put_contents($tmp, $content);
            $zip = new \ZipArchive();
            
            if ($zip->open($tmp) !== true) {
                return null;
            }
            
            // Validate ZIP entries to prevent path traversal
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, '..') !== false || strpos($name, '/') === 0) {
                    $zip->close();
                    return null;
                }
            }
            
            $xml = $zip->getFromName('content.xml');
            $zip->close();
            
            if ($xml === false) {
                return null;
            }
            
            $extracted = strip_tags($xml);
            return strlen($extracted) > 10 ? $extracted : null;
            
        } catch (\Exception $e) {
            debugging('ODT extraction failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        } finally {
            @unlink($tmp);
        }
    }
}
