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
 * Static wiring checks: every file, class, template and JavaScript module the plugin refers to
 * must exist.
 *
 * Page scripts such as prompt.php cannot be loaded from PHPUnit, so a require_once of a deleted
 * file would otherwise only show up as a fatal error in a browser.
 *
 * @package    aiplacement_modgen
 * @category   test
 * @copyright  2026 Tom Cripps
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Wiring tests.
 *
 * @coversNothing
 */
final class plugin_wiring_test extends advanced_testcase {
    /**
     * Plugin files of one kind, excluding tests and vendored code.
     *
     * @param string $extension File extension without the dot.
     * @return string[] Absolute paths.
     */
    private function plugin_files(string $extension): array {
        $root = dirname(__DIR__);
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            if (
                $file->getExtension() !== $extension
                || preg_match('#^(tests|node_modules|\.git|amd/build)/#', $relative)
            ) {
                continue;
            }
            $found[] = $path;
        }
        return $found;
    }

    /**
     * Every require_once/include of a path built from __DIR__ points at a file that exists.
     */
    public function test_required_files_exist(): void {
        $missing = [];
        foreach ($this->plugin_files('php') as $file) {
            $source = file_get_contents($file);
            preg_match_all('/(?:require_once|require|include_once|include)\s*\(\s*__DIR__\s*\.\s*([\'"])([^\'"]+)\1\s*\)/', $source, $matches);
            foreach ($matches[2] as $relative) {
                if (strpos($relative, '$') !== false) {
                    continue;
                }
                if (!file_exists(dirname($file) . $relative)) {
                    $missing[] = substr($file, strlen(dirname(__DIR__)) + 1) . ' -> ' . $relative;
                }
            }
        }
        $this->assertSame([], $missing, 'Files required but not present.');
    }

    /**
     * Every file the plugin requires through $CFG->dirroot exists as well.
     */
    public function test_dirroot_required_files_exist(): void {
        global $CFG;
        $missing = [];
        foreach ($this->plugin_files('php') as $file) {
            preg_match_all(
                '/\$CFG->dirroot\s*\.\s*[\'"](\/ai\/placement\/modgen\/[^\'"$]+)[\'"]/',
                file_get_contents($file),
                $matches
            );
            foreach ($matches[1] as $relative) {
                if (!file_exists($CFG->dirroot . $relative)) {
                    $missing[] = substr($file, strlen(dirname(__DIR__)) + 1) . ' -> ' . $relative;
                }
            }
        }
        $this->assertSame([], $missing);
    }

    /**
     * Every fully-qualified plugin class named in the code resolves.
     */
    public function test_referenced_classes_exist(): void {
        $missing = [];
        // ai_service sits under classes/local but in the plugin's root namespace, so it is not
        // autoloadable; the code require_once()s it where needed.
        require_once(dirname(__DIR__) . '/classes/local/ai_service.php');
        foreach ($this->plugin_files('php') as $file) {
            $source = file_get_contents($file);
            // Skip comments so prose such as "aiplacement_modgen\foo" cannot raise false alarms,
            // and the namespace/use lines, which name namespaces rather than classes.
            $source = preg_replace('/^\s*(namespace|use)\s[^;]*;$/m', '', $source);
            $source = preg_replace('#/\*.*?\*/#s', '', $source);
            $source = preg_replace('#^\s*//.*$#m', '', $source);
            preg_match_all('/\\\\?(aiplacement_modgen(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)(?=::|\s*\()/', $source, $matches);
            foreach (array_unique($matches[1]) as $class) {
                // Only treat names that are used as classes, not namespaces in "namespace x;" or "use x;".
                if (strpos($class, '\\') === false) {
                    continue;
                }
                if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                    $missing[] = substr($file, strlen(dirname(__DIR__)) + 1) . ' -> ' . $class;
                }
            }
        }
        $this->assertSame([], $missing, 'Classes referenced but not found.');
    }

    /**
     * Every Mustache template named by the PHP, JavaScript or other templates exists.
     */
    public function test_referenced_templates_exist(): void {
        $root = dirname(__DIR__);
        $missing = [];
        $sources = array_merge($this->plugin_files('php'), $this->plugin_files('js'), $this->plugin_files('mustache'));
        foreach ($sources as $file) {
            $source = file_get_contents($file);
            preg_match_all(
                '/(?:render_from_template|renderForPromise|renderPreSnippet)\(\s*[\'"]aiplacement_modgen\/([a-z_]+)|\{\{>\s*aiplacement_modgen\/([a-z_]+)/',
                $source,
                $matches
            );
            $names = array_unique(array_filter(array_merge($matches[1], $matches[2])));
            foreach ($names as $name) {
                if (!file_exists("{$root}/templates/{$name}.mustache")) {
                    $missing[] = substr($file, strlen($root) + 1) . ' -> ' . $name;
                }
            }
        }
        $this->assertSame([], $missing, 'Templates referenced but not present.');
    }

    /**
     * Every AMD module has a source and a built file, and nothing is built without a source.
     */
    public function test_amd_sources_and_builds_match(): void {
        $root = dirname(__DIR__);
        $sources = array_map(fn($f) => basename($f, '.js'), glob("{$root}/amd/src/*.js"));
        $builds = array_map(fn($f) => basename($f, '.min.js'), glob("{$root}/amd/build/*.min.js"));
        sort($sources);
        sort($builds);

        $this->assertSame([], array_values(array_diff($sources, $builds)), 'AMD sources with no built file.');
        $this->assertSame([], array_values(array_diff($builds, $sources)), 'Built AMD files with no source.');
    }

    /**
     * Built JavaScript does not load modules that no longer exist.
     */
    public function test_built_javascript_loads_only_existing_modules(): void {
        $root = dirname(__DIR__);
        $missing = [];
        foreach (glob("{$root}/amd/build/*.min.js") as $file) {
            preg_match_all('/aiplacement_modgen\/([a-z_]+)/', file_get_contents($file), $matches);
            foreach (array_unique($matches[1]) as $name) {
                if (
                    $name !== 'statechanged' // A custom event name, not a module.
                    && !file_exists("{$root}/amd/build/{$name}.min.js")
                    && !file_exists("{$root}/templates/{$name}.mustache")
                ) {
                    $missing[] = basename($file) . ' -> ' . $name;
                }
            }
        }
        $this->assertSame([], $missing);
    }

    /**
     * The language strings the code asks for exist.
     */
    public function test_language_strings_used_in_code_exist(): void {
        $root = dirname(__DIR__);
        $string = [];
        require($root . '/lang/en/aiplacement_modgen.php');

        $missing = [];
        foreach (array_merge($this->plugin_files('php'), $this->plugin_files('mustache')) as $file) {
            $source = file_get_contents($file);
            preg_match_all('/get_string\(\s*\'([a-zA-Z0-9_:]+)\'\s*,\s*\'aiplacement_modgen\'/', $source, $php);
            preg_match_all('/\{\{#str\}\}\s*([a-zA-Z0-9_:]+)\s*,\s*aiplacement_modgen/', $source, $mustache);
            foreach (array_unique(array_merge($php[1], $mustache[1])) as $key) {
                if (!isset($string[$key])) {
                    $missing[] = substr($file, strlen($root) + 1) . ' -> ' . $key;
                }
            }
        }
        $this->assertSame([], $missing, 'Strings requested but not defined in the language file.');
    }
}
