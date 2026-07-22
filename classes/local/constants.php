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
 * Constants for the Module Generator plugin.
 *
 * @package     aiplacement_modgen
 * @copyright   2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Constants class for module generator configuration values.
 */
class constants {
    /** @var int Maximum file content length in characters (100KB) */
    public const MAX_FILE_CONTENT_LENGTH = 100000;

    /** @var int File content preview length for base64 encoding */
    public const FILE_PREVIEW_LENGTH = 1024;

    /** @var int Generation lock timeout in seconds (10 minutes) */
    public const GENERATION_LOCK_TIMEOUT = 600;

    /**
     * @var int Default maximum total sections per course (existing + projected).
     *
     * flexsections renumbers and rebuilds the course on every section insert, so
     * section creation is ~O(n^2) in the course's total section count. Measured on
     * this codebase, per-section time climbs from ~24ms at 47 sections to ~430ms at
     * ~490 (memory stays ~105MB in isolation, but a real production course hit the
     * 512MB limit around 400 sections). This cap keeps a course below the size where
     * generation becomes pathologically slow or risks exhausting memory. Override via
     * the 'maxtotalsections' admin setting.
     */
    public const MAX_TOTAL_SECTIONS = 300;

    /** @var int Maximum file upload size in bytes (10MB) */
    public const MAX_UPLOAD_SIZE = 10485760;

    /** @var int Default timeout for AI processing in seconds */
    public const DEFAULT_AI_TIMEOUT = 300;

    /** @var int Cache TTL for template extraction in seconds (1 hour) */
    public const TEMPLATE_CACHE_TTL = 3600;

    /** @var array Supported text-based file extensions */
    public const TEXT_EXTENSIONS = ['txt', 'md', 'html', 'htm'];

    /** @var array Supported document file extensions (no PDF) */
    public const DOCUMENT_EXTENSIONS = ['docx', 'odt', 'rtf'];

    /** @var array All supported file extensions */
    public const SUPPORTED_EXTENSIONS = ['txt', 'md', 'html', 'htm', 'docx', 'odt', 'rtf'];
}
