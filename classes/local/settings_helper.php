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
 * Settings helper for the plugin's switches.
 *
 * @package    aiplacement_modgen
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * Helper class for the plugin's switches.
 *
 * Read through get_config() every time, which Moodle already caches: a copy of its own here would
 * outlive a change of setting in the same process (a long cron run, or one test after another).
 */
class settings_helper {
    /**
     * Check whether this placement is switched on in Site admin > AI > AI placements.
     *
     * This is core's master switch for the plugin, stored by
     * \core\plugininfo\aiplacement::enable_plugin() as the 'enabled' config key. It is
     * distinct from is_ai_enabled() below, which is one of this plugin's own feature
     * settings: the placement switch decides whether modgen appears at all, enable_ai
     * decides whether the AI-backed features within it are offered.
     *
     * Core does not enforce this for us - get_plugins_with_function() filters only for
     * deprecated hook migration, not for plugin enablement - so every entry point has to
     * consult it. Note that an unset key reads as disabled, which is core's own convention
     * (enable_plugin() unsets the key rather than writing 0).
     *
     * @return bool True if the placement is enabled
     */
    public static function is_placement_enabled(): bool {
        return \core\plugininfo\aiplacement::is_plugin_enabled('modgen');
    }

    /**
     * Stop with a "disabled" error unless this placement is switched on.
     *
     * For entry points reachable by URL, so that switching the placement off actually closes
     * them rather than just hiding the links that point at them.
     *
     * @throws \moodle_exception if the placement is disabled
     */
    public static function require_placement_enabled(): void {
        if (!self::is_placement_enabled()) {
            throw new \moodle_exception('placementdisabled', 'aiplacement_modgen');
        }
    }

    /**
     * Check if AI generation is enabled.
     *
     * @return bool True if AI generation is enabled
     */
    public static function is_ai_enabled(): bool {
        return self::get_bool('enable_ai');
    }

    /**
     * Whether the current user can generate a structure with AI in a context right now.
     *
     * The AI setting and the capability, and also what Site administration > AI allows: a provider
     * offering Generate text, with that action not switched off for this placement. For offering
     * actions such as Regenerate, which would otherwise lead straight to a refusal.
     *
     * @param \context $context Course context
     * @return bool
     */
    public static function can_generate_with_ai(\context $context): bool {
        return self::is_ai_enabled()
            && has_capability('aiplacement/modgen:generatewithprompt', $context)
            && \core_ai\manager::is_action_available(\core_ai\aiactions\generate_text::class)
            && \core_ai\manager::is_action_enabled('aiplacement_modgen', \core_ai\aiactions\generate_text::class);
    }

    /**
     * Stop with a "disabled" error unless AI generation is enabled.
     *
     * @throws \moodle_exception if AI generation is disabled
     */
    public static function require_ai_enabled(): void {
        if (!self::is_ai_enabled()) {
            throw new \moodle_exception('aidisabled', 'aiplacement_modgen');
        }
    }

    /**
     * Check whether Quick Add's New Theme(s) and New Week(s) entries are enabled.
     *
     * @return bool True if quick add is enabled
     */
    public static function is_quickadd_enabled(): bool {
        return self::get_bool('enable_quickadd');
    }

    /**
     * Stop with a "disabled" error unless Quick add is enabled.
     *
     * @throws \moodle_exception if quick add is disabled
     */
    public static function require_quickadd_enabled(): void {
        if (!self::is_quickadd_enabled()) {
            throw new \moodle_exception('quickadddisabled', 'aiplacement_modgen');
        }
    }

    /**
     * Check whether building a structure from a CSV file is enabled.
     *
     * Independent of is_ai_enabled(): this path is the non-AI one, so a site can offer
     * CSV-driven structures with AI off, or the reverse.
     *
     * @return bool True if structure-from-file is enabled
     */
    public static function is_structurefromfile_enabled(): bool {
        return self::get_bool('enable_structurefromfile');
    }

    /**
     * Stop with a "disabled" error unless structure-from-file is enabled.
     *
     * @throws \moodle_exception if structure-from-file is disabled
     */
    public static function require_structurefromfile_enabled(): void {
        if (!self::is_structurefromfile_enabled()) {
            throw new \moodle_exception('structurefromfiledisabled', 'aiplacement_modgen');
        }
    }

    /**
     * Check whether Quick Add's Assessments section entry is enabled.
     *
     * Separate from is_quickadd_enabled(), which covers New Theme(s) and New Week(s).
     *
     * @return bool True if the Assessments section entry is enabled
     */
    public static function is_assessments_enabled(): bool {
        return self::get_bool('enable_assessments');
    }

    /**
     * Stop with a "disabled" error unless the Assessments section entry is enabled.
     *
     * @throws \moodle_exception if it is disabled
     */
    public static function require_assessments_enabled(): void {
        if (!self::is_assessments_enabled()) {
            throw new \moodle_exception('assessmentsdisabled', 'aiplacement_modgen');
        }
    }

    /**
     * Check whether building in Custom sections and Weekly sections courses is enabled.
     *
     * Off, the plugin works in Flexible sections courses only, as it did before it could build with
     * core subsections. See course_formats.
     *
     * @return bool True if the core formats are enabled
     */
    public static function is_coreformats_enabled(): bool {
        return self::get_bool('enable_coreformats');
    }

    /**
     * Get a boolean setting value.
     *
     * @param string $name Setting name
     * @return bool Setting value
     */
    private static function get_bool(string $name): bool {
        return !empty(get_config('aiplacement_modgen', $name));
    }
}
