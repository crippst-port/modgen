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
 * Embedded results helpers for closing the parent modal.
 *
 * @module     aiplacement_modgen/embedded_results
 * @copyright  2025
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    const READY_MESSAGE = 'aiplacement_modgen_ready';
    const CLOSE_MESSAGE = 'aiplacement_modgen_close';
    const REFRESH_MESSAGE = 'aiplacement_modgen_refresh';

    const notifyParent = (event) => {
        event.preventDefault();

        try {
            window.parent.postMessage({type: REFRESH_MESSAGE}, window.location.origin);
            window.parent.postMessage({type: CLOSE_MESSAGE}, window.location.origin);
        } catch (error) {
            // eslint-disable-next-line no-console
            console.error('Failed to notify parent window', error);
        }
    };

    const init = () => {
        try {
            window.parent.postMessage({type: READY_MESSAGE}, window.location.origin);
        } catch (error) {
            // eslint-disable-next-line no-console
            console.error('Failed to notify parent window about readiness', error);
        }

        const button = document.querySelector('[data-action="aiplacement-modgen-close"]');
        if (!button) {
            return;
        }

        button.addEventListener('click', notifyParent);
    };

    return {init};
});
