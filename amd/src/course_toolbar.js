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
import Notification from 'core/notification';
import {init as initModalComponent} from 'aiplacement_modgen/modal_generator_reactive';

// Store modal component instance for button handlers.
let modalComponent = null;

/**
 * Initialize the course navigation toolbar.
 *
 * @param {Object} config Configuration object
 * @param {number} config.courseid Course ID
 * @param {number} config.contextid Context ID
 * @param {boolean} config.showgenerator Show generator button
 * @param {boolean} config.showexplore Show explore button
 * @param {number} config.currentsection Current section number (for context-aware creation)
 */
export const init = (config) => {
    // Initialize modal component once for reuse, passing current section.
    modalComponent = initModalComponent(config.courseid, config.contextid, config.currentsection || 0);

    // Load toolbar HTML via Fragment API
    Fragment.loadFragment('aiplacement_modgen', 'course_toolbar', config.contextid, {
        courseid: config.courseid,
        showgenerator: config.showgenerator ? 1 : 0,
        showexplore: config.showexplore ? 1 : 0,
        showsuggest: config.showsuggest ? 1 : 0,
        currentsection: config.currentsection,
    })
    .then((html) => {
        // Insert toolbar at top of region-main using vanilla JS
        const regionMain = document.querySelector('#region-main');
        if (regionMain) {
            regionMain.insertAdjacentHTML('afterbegin', html);

            // Bootstrap 4 collapse handling via jQuery (Moodle loads jQuery globally)
            const collapseToggle = document.querySelector('.navbar-toggler');
            const collapseTarget = document.querySelector('#aimodgenNavbar');
            if (collapseToggle && collapseTarget && window.$ && window.$.fn && window.$.fn.collapse) {
                // Initialize Bootstrap 4 collapse
                window.$(collapseTarget).collapse({toggle: false});

                // Handle toggle button clicks
                collapseToggle.addEventListener('click', () => {
                    window.$(collapseTarget).collapse('toggle');
                });
            }

            // Setup quick add buttons for theme/week forms.
            setupQuickAddButtons();

            // Attach event listener to generator button if present
            if (config.showgenerator) {
                setupGeneratorButton(config.courseid);
            }
        }
        return html;
    })
    .catch(Notification.exception);
};

/**
 * Setup quick add buttons to open modal with forms.
 */
const setupQuickAddButtons = () => {
    const quickAddButtons = document.querySelectorAll('[data-action="quick-add"]');
    quickAddButtons.forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            const formName = button.getAttribute('data-form');
            const title = button.getAttribute('data-title');
            if (formName && title && modalComponent) {
                modalComponent.openWithForm(formName, title);
            }
        });
    });
};

/**
 * Setup generator button to link directly to prompt.php.
 *
 * @param {number} courseid Course ID
 */
const setupGeneratorButton = (courseid) => {
    const generatorBtn = document.querySelector('[data-action="open-generator"]');
    if (!generatorBtn) {
        return;
    }

    generatorBtn.addEventListener('click', (e) => {
        e.preventDefault();

        // Navigate directly to prompt.php
        window.location.href = M.cfg.wwwroot + '/ai/placement/modgen/prompt.php?id=' + courseid;
    });
};
