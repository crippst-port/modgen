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
 * Polls the whole time the page is visible, not only after an edit made on this page: a renumber
 * can be queued from anywhere (Quick Add, an activity edit, another tab or another editor), and
 * the icon would otherwise sit on an old outcome.
 *
 * @module     aiplacement_modgen/reorder_status
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import Templates from 'core/templates';

/** Milliseconds between polls while a renumber is queued or running. */
const ACTIVE_INTERVAL = 10000;

/** Milliseconds between polls otherwise, to catch a renumber queued from elsewhere. */
const IDLE_INTERVAL = 30000;

/** Milliseconds to wait after an edit control is clicked before the next poll. */
const EDIT_DELAY = 2000;

/** Edit controls on this page whose use can queue a renumber. */
const EDIT_SELECTOR = '[data-action-flexsections], [data-action="addSection"], [data-action="deleteSection"]';

let timer = null;
let stopped = false;

/**
 * Whether a status is changing soon.
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
    const current = region.querySelector('.aiplacement-modgen-reorder-icon');
    // The label carries the state and its time, so an unchanged label means an unchanged icon. Leaving it
    // in place stops the slide-in replaying on every poll.
    if (current && status && current.getAttribute('aria-label') === status.label) {
        return;
    }
    if (current) {
        // An open tooltip lives outside the region and would keep the old text on screen.
        $(current).tooltip('dispose');
    }
    if (!status) {
        region.innerHTML = '';
        return;
    }
    const {html, js} = await Templates.renderForPromise('aiplacement_modgen/reorder_status_icon', status);
    Templates.replaceNodeContents(region, html, js);
};

/**
 * Poll after a delay. Only one timer runs at a time.
 *
 * @param {HTMLElement} region The status region
 * @param {number} courseid Course ID
 * @param {number} delay Milliseconds to wait
 */
const schedule = (region, courseid, delay) => {
    if (stopped) {
        return;
    }
    if (timer !== null) {
        clearTimeout(timer);
    }
    // eslint-disable-next-line no-use-before-define
    timer = setTimeout(() => poll(region, courseid), delay);
};

/**
 * Ask the server for the status, show it and schedule the next look.
 *
 * @param {HTMLElement} region The status region
 * @param {number} courseid Course ID
 */
const poll = (region, courseid) => {
    timer = null;
    // A hidden tab waits; it looks again as soon as it is shown.
    if (document.hidden) {
        return;
    }
    fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/reorder_status.php?' + new URLSearchParams({
        courseid: courseid,
        sesskey: M.cfg.sesskey,
    }))
    .then((response) => response.json())
    .then(async(data) => {
        if (!data.success) {
            // Refused: the session ended, or the switch was turned off. Nothing more to show.
            stopped = true;
            return;
        }
        await render(region, data.status);
        schedule(region, courseid, isActive(data.status) ? ACTIVE_INTERVAL : IDLE_INTERVAL);
    })
    // A dropped request is not worth a notice every half minute: try again later.
    .catch(() => schedule(region, courseid, IDLE_INTERVAL));
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

    const active = region.querySelector('.aiplacement-modgen-reorder-icon--queued, .aiplacement-modgen-reorder-icon--running');
    schedule(region, courseid, active ? ACTIVE_INTERVAL : IDLE_INTERVAL);

    // An edit on this page queues a renumber: look soon after, rather than at the next idle poll.
    document.addEventListener('click', (e) => {
        if (e.target.closest(EDIT_SELECTOR)) {
            schedule(region, courseid, EDIT_DELAY);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            schedule(region, courseid, 0);
        }
    });
};
