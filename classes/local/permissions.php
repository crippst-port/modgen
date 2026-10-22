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
}
