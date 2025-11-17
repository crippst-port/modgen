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
 * Course navigation toolbar - uses Fragment API and Reactive Modal.
 *
 * @module     aiplacement_modgen/course_toolbar
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Fragment from 'core/fragment';
import * as ModalGenerator from 'aiplacement_modgen/modal_generator_reactive';
import Notification from 'core/notification';

let modalComponent = null;

/**
 * Initialize the course navigation toolbar.
 *
 * @param {Object} config Configuration object
 * @param {number} config.courseid Course ID
 * @param {number} config.contextid Context ID
 * @param {boolean} config.showgenerator Show generator button
 * @param {boolean} config.showexplore Show explore button
 */
export const init = (config) => {
    // Load toolbar HTML via Fragment API
    Fragment.loadFragment('aiplacement_modgen', 'course_toolbar', config.contextid, {
        courseid: config.courseid,
        showgenerator: config.showgenerator ? 1 : 0,
        showexplore: config.showexplore ? 1 : 0,
    })
    .then((html) => {
        // Insert toolbar at top of region-main using vanilla JS
        const regionMain = document.querySelector('#region-main');
        if (regionMain) {
            regionMain.insertAdjacentHTML('afterbegin', html);

            // Attach event listener to generator button if present
            if (config.showgenerator) {
                setupGeneratorButton(config.courseid, config.contextid);
            }
        }
        return html;
    })
    .catch(Notification.exception);
};

/**
 * Setup generator button to open modal.
 *
 * @param {number} courseid Course ID
 * @param {number} contextid Context ID
 */
const setupGeneratorButton = (courseid, contextid) => {
    const generatorBtn = document.querySelector('[data-action="open-generator"]');
    if (!generatorBtn) {
        return;
    }

    generatorBtn.addEventListener('click', (e) => {
        e.preventDefault();
        
        // Initialize component on first click
        if (!modalComponent) {
            modalComponent = ModalGenerator.init(courseid, contextid);
        }
        
        // Open the modal
        modalComponent.open();
    });
};
