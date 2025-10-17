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
 * File upload and processing for content-based activity creation.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local\filehandler;

use context_course;
use stored_file;
use core_files\conversion;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles file uploads and extraction for activity creation.
 * 
 * Uses Moodle's native file conversion API for document processing.
 * Supports conversion via configured converters (unoconv, googledrive, etc.).
 */
class file_processor {
    /** @var context_course Course context for file storage. */
    private $context;

    /** @var int Course ID. */
    private $courseid;

    /**
     * Constructor.
     *
     * @param context_course $context
     */
    public function __construct(context_course $context) {
        $this->context = $context;
        $this->courseid = $context->instanceid;
    }

    /**
     * Extract HTML content from an uploaded document file.
     *
     * Supports: .docx, .doc, .odt via Moodle's native conversion API.
     * Falls back to text extraction if conversion not available.
     *
     * @param stored_file $file The uploaded document file.
     * @param string $convertto Target format ('html' or 'txt').
     * @return array {
     *     'success' => bool,
     *     'content' => string HTML or text content,
     *     'mime_type' => string ('text/html' or 'text/plain'),
     *     'warnings' => array Any conversion warnings,
     * }
     */
    public function extract_content_from_file(stored_file $file, string $convertto = 'html'): array {
        $result = [
            'success' => false,
            'content' => '',
            'mime_type' => 'text/plain',
            'warnings' => [],
        ];

        $mimetype = $file->get_mimetype();
        $filename = $file->get_filename();

        // Validate supported formats
        $supported = [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
            'application/msword', // .doc
            'application/vnd.oasis.opendocument.text', // .odt
        ];

        if (!in_array($mimetype, $supported)) {
            $result['warnings'][] = get_string('unsupportedfiletype', 'aiplacement_modgen', $mimetype);
            return $result;
        }

        // Try Moodle's native conversion API first
        if ($convertto === 'html') {
            $htmlcontent = $this->convert_document_via_moodle($file);
            if ($htmlcontent !== false) {
                $result['success'] = true;
                $result['content'] = $htmlcontent;
                $result['mime_type'] = 'text/html';
                return $result;
            } else {
                $result['warnings'][] = get_string('conversionfailed', 'aiplacement_modgen', $filename);
            }
        }

        // Fallback: Extract plain text
        $textcontent = $this->extract_text_from_file($file);
        if ($textcontent !== false) {
            $result['success'] = true;
            $result['content'] = $textcontent;
            $result['mime_type'] = 'text/plain';
            if (empty($result['warnings']) && $convertto === 'html') {
                $result['warnings'][] = get_string('fallbacktoplaintext', 'aiplacement_modgen');
            }
            return $result;
        }

        $result['warnings'][] = get_string('couldnotextractcontent', 'aiplacement_modgen', $filename);
        return $result;
    }

    /**
     * Convert a document to HTML using Moodle's native conversion API.
     *
     * Moodle supports multiple converters (unoconv, googledrive, etc.).
     * This method uses whichever converters are configured on the site.
     *
     * @param stored_file $file
     * @return string|false HTML content or false on failure.
     */
    private function convert_document_via_moodle(stored_file $file): ?string {
        try {
            // Check if a conversion is available via Moodle's API
            $conversions = conversion::get_conversions_for_file($file, 'html');

            if (!empty($conversions)) {
                // Use the first available conversion
                $conversion = reset($conversions);
                $destfile = $conversion->get_destfile();

                if ($destfile) {
                    $content = $destfile->get_content();
                    return !empty($content) ? $content : false;
                }
            }

            // If no conversion available, try direct conversion
            // Create a new conversion request
            $conversion = new conversion($file, 'html');
            
            // Start the conversion (async-friendly)
            if ($conversion->start_conversion()) {
                // Try to get the result (may be async)
                $destfile = $conversion->get_destfile();
                if ($destfile) {
                    $content = $destfile->get_content();
                    return !empty($content) ? $content : false;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract plain text from a Word document using embedded extraction.
     *
     * @param stored_file $file
     * @return string|false Extracted text or false on failure.
     */
    private function extract_text_from_file(stored_file $file): ?string {
        $mimetype = $file->get_mimetype();

        if ($mimetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return $this->extract_from_docx($file);
        } else if ($mimetype === 'application/msword') {
            // For older .doc format, LibreOffice is the best bet
            return false;
        } else if ($mimetype === 'application/vnd.oasis.opendocument.text') {
            return $this->extract_from_odt($file);
        }

        return false;
    }

    /**
     * Extract text from a .docx file (Office Open XML).
     *
     * @param stored_file $file
     * @return string|false Extracted text or false on failure.
     */
    private function extract_from_docx(stored_file $file): ?string {
        try {
            $tempdir = make_temp_directory('modgen_extract');
            $filepath = $tempdir . '/document.docx';
            $file->copy_content_to($filepath);

            $zip = new \ZipArchive();
            if ($zip->open($filepath) !== true) {
                return false;
            }

            // Extract document.xml from the archive
            $xmlcontent = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xmlcontent === false) {
                return false;
            }

            // Parse XML and extract text nodes
            $dom = new \DOMDocument();
            $dom->loadXML($xmlcontent, LIBXML_NOERROR);
            $xpath = new \DOMXPath($dom);

            // Extract all text nodes from paragraphs and tables
            $textnodes = $xpath->query('//w:t');
            $text = '';
            foreach ($textnodes as $node) {
                $text .= $node->nodeValue . "\n";
            }

            @unlink($filepath);
            return trim($text) ?: false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract text from a .odt file (OpenDocument).
     *
     * @param stored_file $file
     * @return string|false Extracted text or false on failure.
     */
    private function extract_from_odt(stored_file $file): ?string {
        try {
            $tempdir = make_temp_directory('modgen_extract');
            $filepath = $tempdir . '/document.odt';
            $file->copy_content_to($filepath);

            $zip = new \ZipArchive();
            if ($zip->open($filepath) !== true) {
                return false;
            }

            // Extract content.xml from the archive
            $xmlcontent = $zip->getFromName('content.xml');
            $zip->close();

            if ($xmlcontent === false) {
                return false;
            }

            // Parse XML and extract text nodes
            $dom = new \DOMDocument();
            $dom->loadXML($xmlcontent, LIBXML_NOERROR);
            $xpath = new \DOMXPath($dom);

            // Extract all text nodes
            $textnodes = $xpath->query('//text:p//text()');
            $text = '';
            foreach ($textnodes as $node) {
                $text .= $node->nodeValue . "\n";
            }

            @unlink($filepath);
            return trim($text) ?: false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if LibreOffice is available on the system.
     *
     * @return bool
     */
    private function is_libreoffice_available(): bool {
        static $available = null;

        if ($available === null) {
            $result = shell_exec('which libreoffice 2>&1');
            $available = !empty($result);
        }

        return $available;
    }

    /**
     * Parse HTML content into book chapters based on heading hierarchy.
     *
     * Maps heading levels to chapter structure:
     * - H1 = Chapter
     * - H2+ = Sections within chapter
     *
     * @param string $htmlcontent
     * @return array<int, array> Array of chapters with title and content.
     */
    public function parse_html_to_chapters(string $htmlcontent): array {
        try {
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $htmlcontent, LIBXML_NOERROR);

            $chapters = [];
            $currentchapter = null;
            $currentcontent = '';

            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query('//body/*');

            foreach ($nodes as $node) {
                $tagname = strtolower($node->nodeName);

                if ($tagname === 'h1') {
                    // Save previous chapter
                    if ($currentchapter !== null) {
                        $chapters[] = [
                            'title' => $currentchapter,
                            'content' => trim($currentcontent),
                        ];
                    }

                    // Start new chapter
                    $currentchapter = $this->extract_text_from_node($node);
                    $currentcontent = '';
                } else if ($currentchapter !== null) {
                    // Add content to current chapter
                    $currentcontent .= $this->get_node_html($node);
                }
            }

            // Save final chapter
            if ($currentchapter !== null) {
                $chapters[] = [
                    'title' => $currentchapter,
                    'content' => trim($currentcontent),
                ];
            }

            return $chapters;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Extract plain text from a DOM node.
     *
     * @param \DOMNode $node
     * @return string
     */
    private function extract_text_from_node(\DOMNode $node): string {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->nodeValue;
            } else if ($child->nodeType === XML_ELEMENT_NODE) {
                $text .= $this->extract_text_from_node($child);
            }
        }
        return trim($text);
    }

    /**
     * Get the HTML representation of a DOM node.
     *
     * @param \DOMNode $node
     * @return string
     */
    private function get_node_html(\DOMNode $node): string {
        $dom = new \DOMDocument();
        $domnode = $dom->importNode($node, true);
        $dom->appendChild($domnode);
        $html = $dom->saveHTML($domnode);
        return $html ?: '';
    }
}
