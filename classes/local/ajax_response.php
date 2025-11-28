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
 * AJAX response helper for consistent JSON responses.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper class for sending consistent AJAX JSON responses.
 */
class ajax_response {

    /**
     * Send a success response and terminate script.
     *
     * @param array $data Data to include in response
     * @return void (terminates script)
     */
    public static function success($data = []) {
        self::send(['success' => true] + $data);
    }

    /**
     * Send an error response and terminate script.
     *
     * @param string $message Error message
     * @param string|null $code Optional error code
     * @param mixed $extra Optional extra debug info
     * @return void (terminates script)
     */
    public static function error(string $message, string $code = null, $extra = null) {
        $response = ['success' => false, 'error' => $message];
        if ($code !== null) {
            $response['code'] = $code;
        }
        if ($extra !== null && debugging('', DEBUG_DEVELOPER)) {
            $response['debug'] = $extra;
        }
        self::send($response);
    }

    /**
     * Send JSON response with proper headers and terminate.
     *
     * @param array $data Response data
     * @return void (terminates script)
     */
    private static function send(array $data) {
        // Clean any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Set JSON header
        header('Content-Type: application/json; charset=utf-8');

        // Output JSON
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Terminate script
        exit(0);
    }
}
