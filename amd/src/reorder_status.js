// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Keeps the section reorder status icon on the assistant bar up to date.
 *
 * @module     aiplacement_modgen/reorder_status
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import Notification from 'core/notification';

/** Milliseconds between polls while a renumber is queued or running. */
const POLL_INTERVAL = 10000;

/** Milliseconds to wait after an edit control is clicked before the first poll. */
const EDIT_DELAY = 2000;

/** Edit controls whose use can queue a renumber. */
const EDIT_SELECTOR = '[data-action-flexsections], [data-action="addSection"], [data-action="deleteSection"]';

let timer = null;

/**
 * Whether a status still needs watching.
 *
 * @param {Object|null} status Status from the server
 * @returns {boolean}
 */
const isActive = (status) => !!status && (status.state === 'queued' || status.state === 'running');

/**
 * Show the status in the toolbar.
 *
 * @param {HTMLElement} region The status region
 * @param {Object|null} status Status from the server
 * @returns {Promise}
 */
const render = async(region, status) => {
    if (!status) {
        region.innerHTML = '';
        return;
    }
    const {html, js} = await Templates.renderForPromise('aiplacement_modgen/reorder_status_icon', status);
    Templates.replaceNodeContents(region, html, js);
};

/**
 * Ask the server for the status, show it and decide whether to keep polling.
 *
 * @param {HTMLElement} region The status region
 * @param {number} courseid Course ID
 */
const poll = (region, courseid) => {
    timer = null;
    fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/reorder_status.php?' + new URLSearchParams({
        courseid: courseid,
        sesskey: M.cfg.sesskey,
    }))
    .then((response) => response.json())
    .then(async(data) => {
        if (!data.success) {
            return;
        }
        await render(region, data.status);
        if (isActive(data.status)) {
            schedule(region, courseid, POLL_INTERVAL);
        }
    })
    .catch(Notification.exception);
};

/**
 * Poll after a delay. Only one timer runs at a time.
 *
 * @param {HTMLElement} region The status region
 * @param {number} courseid Course ID
 * @param {number} delay Milliseconds to wait
 */
const schedule = (region, courseid, delay) => {
    if (timer !== null) {
        clearTimeout(timer);
    }
    timer = setTimeout(() => poll(region, courseid), delay);
};

/**
 * Start watching the status icon.
 *
 * @param {number} courseid Course ID
 */
export const init = (courseid) => {
    const region = document.querySelector('[data-region="aiplacement-modgen-reorder-status"]');
    if (!region) {
        return;
    }

    if (region.querySelector('.aiplacement-modgen-reorder-icon--queued, .aiplacement-modgen-reorder-icon--running')) {
        schedule(region, courseid, POLL_INTERVAL);
    }

    // An edit by this admin queues a renumber: look soon after, rather than waiting for a page load.
    document.addEventListener('click', (e) => {
        if (e.target.closest(EDIT_SELECTOR)) {
            schedule(region, courseid, EDIT_DELAY);
        }
    });
};
