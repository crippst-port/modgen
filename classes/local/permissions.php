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
 * Who may build course structure.
 *
 * @package     aiplacement_modgen
 * @copyright   2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace aiplacement_modgen\local;

/**
 * The capabilities that let someone start a build, and checks that accept any of them.
 *
 * Quick Add needs the structure capability, and building from a file or a prompt needs one of the
 * generate capabilities. Everything that happens as a result of a build (creating its activities,
 * following its job) must accept all of them, or a build started with one capability would fail
 * halfway on a check for another.
 */
class permissions {
    /** @var string[] Capabilities that each allow a user to start a build. */
    private const BUILD_CAPABILITIES = [
        'aiplacement/modgen:managestructure',
        'aiplacement/modgen:generatewithprompt',
        'aiplacement/modgen:generatefromtemplate',
    ];

    /**
     * Whether a user holds any capability that lets them start a build in a course.
     *
     * @param \context $context Course context.
     * @param int|null $userid User id (the current user when null).
     * @return bool
     */
    public static function can_build(\context $context, ?int $userid = null): bool {
        foreach (self::BUILD_CAPABILITIES as $capability) {
            if (has_capability($capability, $context, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Require that a user may start a build in a course.
     *
     * @param \context $context Course context.
     * @param int|null $userid User id (the current user when null).
     * @throws \required_capability_exception If they may not.
     */
    public static function require_build(\context $context, ?int $userid = null): void {
        if (!self::can_build($context, $userid)) {
            throw new \required_capability_exception($context, self::BUILD_CAPABILITIES[0], 'nopermissions', '');
        }
    }

    /**
     * Whether the current user may build from a prompt: the capability, and AI switched on.
     *
     * @param \context $context Course context.
     * @return bool
     */
    public static function can_build_from_prompt(\context $context): bool {
        return has_capability('aiplacement/modgen:generatewithprompt', $context) && settings_helper::is_ai_enabled();
    }

    /**
     * Whether the current user may build from a file: the capability, and structure-from-file switched on.
     *
     * @param \context $context Course context.
     * @return bool
     */
    public static function can_build_from_file(\context $context): bool {
        return has_capability('aiplacement/modgen:generatefromtemplate', $context)
            && settings_helper::is_structurefromfile_enabled();
    }

    /**
     * Require that the current user may start a build of one kind in a course right now.
     *
     * The one statement of which capability and which switches open each build route, used where a
     * build is queued and again when its task runs, so the two cannot drift apart. Quick Add
     * (create_themes, create_weeks) needs the structure capability with Quick Add on. An approved
     * structure (create_from_json) can come from a prompt or a file, so either route being open
     * to the user is enough.
     *
     * @param string $action Build action: create_themes, create_weeks or create_from_json.
     * @param \context $context Course context.
     * @throws \moodle_exception If the placement or route is switched off, or the action is unknown.
     * @throws \required_capability_exception If the user has no capability for the route.
     */
    public static function require_build_action(string $action, \context $context): void {
        settings_helper::require_placement_enabled();

        if ($action === 'create_themes' || $action === 'create_weeks' || $action === 'create_assessments') {
            settings_helper::require_quickadd_enabled();
            require_capability('aiplacement/modgen:managestructure', $context);
            return;
        }

        if ($action === 'create_from_json') {
            if (self::can_build_from_prompt($context) || self::can_build_from_file($context)) {
                return;
            }
            if (
                !has_capability('aiplacement/modgen:generatewithprompt', $context)
                && !has_capability('aiplacement/modgen:generatefromtemplate', $context)
            ) {
                throw new \required_capability_exception(
                    $context,
                    'aiplacement/modgen:generatefromtemplate',
                    'nopermissions',
                    ''
                );
            }
            throw new \moodle_exception('generationdisabled', 'aiplacement_modgen');
        }

        throw new \moodle_exception('invalidaction', 'aiplacement_modgen');
    }
}
