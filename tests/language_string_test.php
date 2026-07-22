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
 * Language string integrity tests.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use basic_testcase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests that plugin string references resolve to local language definitions.
 */
final class language_string_test extends basic_testcase {
    /** Plugin component name. */
    private const COMPONENT = 'aiplacement_modgen';

    /**
     * The plugin root directory.
     *
     * @return string
     */
    private function plugin_root(): string {
        return dirname(__DIR__);
    }

    /**
     * Read defined language string keys with their line numbers.
     *
     * @return array<string, int[]>
     */
    private function language_definitions(): array {
        $langfile = $this->plugin_root() . '/lang/en/aiplacement_modgen.php';
        $contents = file_get_contents($langfile);
        $definitions = [];

        preg_match_all('/\\$string\\[[\\\'"]([^\\\'"]+)[\\\'"]\\]\\s*=/', $contents, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $match) {
            [$key, $offset] = $match;
            $definitions[$key][] = substr_count($contents, "\n", 0, $offset) + 1;
        }

        return $definitions;
    }

    /**
     * Return plugin source files that may contain string references.
     *
     * @return string[]
     */
    private function source_files(): array {
        $root = $this->plugin_root();
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            $normalised = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if (preg_match('#(^|/)(\\.git|node_modules|tests)(/|$)#', $normalised)) {
                continue;
            }
            if (preg_match('#^amd/build/#', $normalised)) {
                continue;
            }
            if ($normalised === 'lang/en/aiplacement_modgen.php') {
                continue;
            }
            if (!preg_match('/\\.(php|js|mustache)$/', $normalised)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);
        return $files;
    }

    /**
     * Find local component string references in source files.
     *
     * @return array<int, array{key: string, file: string, line: int, type: string}>
     */
    private function string_references(): array {
        $references = [];
        $component = preg_quote(self::COMPONENT, '/');
        $patterns = [
            '/get_string\\s*\\(\\s*[\\\'"]([^\\\'"]+)[\\\'"]\\s*,\\s*[\\\'"]' . $component . '[\\\'"]/' => 'get_string',
            '/getString\\s*\\(\\s*[\\\'"]([^\\\'"]+)[\\\'"]\\s*,\\s*[\\\'"]' . $component . '[\\\'"]/' => 'getString',
            '/Str\\.get_string\\s*\\(\\s*[\\\'"]([^\\\'"]+)[\\\'"]\\s*,\\s*[\\\'"]' . $component . '[\\\'"]/' => 'Str.get_string',
            '/M\\.util\\.get_string\\s*\\(\\s*[\\\'"]([^\\\'"]+)[\\\'"]\\s*,\\s*[\\\'"]' . $component . '[\\\'"]/' => 'M.util.get_string',
            '/\\{\\{#str\\}\\}\\s*([^,}\\s]+)\\s*,\\s*' . $component . '(?:\\s*,[^}]*)?\\s*\\{\\{\\/str\\}\\}/' => 'mustache_str',
        ];

        foreach ($this->source_files() as $file) {
            $contents = file_get_contents($file);
            foreach ($patterns as $pattern => $type) {
                preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[1] as $match) {
                    [$key, $offset] = $match;
                    $references[] = [
                        'key' => $key,
                        'file' => $file,
                        'line' => substr_count($contents, "\n", 0, $offset) + 1,
                        'type' => $type,
                    ];
                }
            }

            preg_match_all(
                '/strings_for_js\\s*\\(\\s*\\[([\\s\\S]*?)\\]\\s*,\\s*[\\\'"]' . $component . '[\\\'"]/',
                $contents,
                $matches,
                PREG_OFFSET_CAPTURE
            );
            foreach ($matches[1] as $match) {
                [$body, $offset] = $match;
                preg_match_all('/[\\\'"]([^\\\'"]+)[\\\'"]/', $body, $strings, PREG_OFFSET_CAPTURE);
                foreach ($strings[1] as $stringmatch) {
                    [$key, $relativeoffset] = $stringmatch;
                    $references[] = [
                        'key' => $key,
                        'file' => $file,
                        'line' => substr_count($contents, "\n", 0, $offset + $relativeoffset) + 1,
                        'type' => 'strings_for_js',
                    ];
                }
            }
        }

        return $references;
    }

    /**
     * All static plugin string references should have language definitions.
     */
    public function test_static_plugin_string_references_are_defined(): void {
        $definitions = $this->language_definitions();
        $missing = [];

        foreach ($this->string_references() as $reference) {
            if (!array_key_exists($reference['key'], $definitions)) {
                $missing[] = sprintf(
                    '%s:%d references missing string "%s" via %s',
                    $reference['file'],
                    $reference['line'],
                    $reference['key'],
                    $reference['type']
                );
            }
        }

        $this->assertEmpty($missing, "Missing language strings:\n" . implode("\n", $missing));
    }

    /**
     * Duplicate keys silently override earlier definitions and should not creep in.
     */
    public function test_language_string_keys_are_unique(): void {
        $duplicates = [];

        foreach ($this->language_definitions() as $key => $lines) {
            if (count($lines) > 1) {
                $duplicates[] = sprintf('"%s" is defined on lines %s', $key, implode(', ', $lines));
            }
        }

        $this->assertEmpty($duplicates, "Duplicate language strings:\n" . implode("\n", $duplicates));
    }
}
