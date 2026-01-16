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
 * Reusable accessible loading indicator component.
 *
 * Provides consistent loading states with proper ARIA attributes for accessibility.
 * Supports both indeterminate spinners and progress bars.
 *
 * @module     aiplacement_modgen/loading_indicator
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import {get_string as getString} from 'core/str';

/** @type {WeakMap<HTMLElement, Object>} Store loading state per container */
const loadingStates = new WeakMap();

/**
 * Show loading indicator in a container.
 *
 * @param {HTMLElement} container - Container element to show loading indicator in
 * @param {string} message - Loading message to display
 * @param {Object} options - Additional options
 * @param {boolean} options.showProgress - Whether to show a progress bar (default: false)
 * @param {number} options.progress - Progress percentage (0-100), only used if showProgress is true
 * @param {string} options.size - Size of spinner: 'small', 'medium', 'large' (default: 'medium')
 * @returns {Promise<void>}
 */
export const show = async(container, message, options = {}) => {
    if (!container) {
        return;
    }

    const config = {
        showProgress: options.showProgress || false,
        progress: options.progress || 0,
        size: options.size || 'medium',
        message: message || await getString('processingrequest', 'aiplacement_modgen'),
    };

    // Store original content if not already stored
    if (!loadingStates.has(container)) {
        loadingStates.set(container, {
            originalContent: container.innerHTML,
            originalAriaLive: container.getAttribute('aria-live'),
            originalAriaBusy: container.getAttribute('aria-busy'),
        });
    }

    // Set aria-busy on container
    container.setAttribute('aria-busy', 'true');

    // Render loading indicator template
    const {html} = await Templates.renderForPromise('aiplacement_modgen/loading_indicator', config);
    container.innerHTML = html;

    // Focus on the loading container for screen readers
    const loadingElement = container.querySelector('.modgen-loading');
    if (loadingElement) {
        loadingElement.setAttribute('tabindex', '-1');
        loadingElement.focus();
    }
};

/**
 * Update loading message and/or progress.
 *
 * @param {HTMLElement} container - Container element containing the loading indicator
 * @param {string|null} message - New loading message (null to keep current)
 * @param {number|null} progress - New progress percentage (null to keep current)
 * @returns {void}
 */
export const update = (container, message = null, progress = null) => {
    if (!container) {
        return;
    }

    const statusElement = container.querySelector('.modgen-loading__status');
    const messageElement = container.querySelector('.modgen-loading__message');
    const progressBar = container.querySelector('.modgen-loading__progress-bar');

    if (message !== null && statusElement && messageElement) {
        statusElement.textContent = message;
        messageElement.textContent = message;
    }

    if (progress !== null && progressBar) {
        progressBar.style.width = `${Math.min(100, Math.max(0, progress))}%`;
        progressBar.setAttribute('aria-valuenow', progress);
    }
};

/**
 * Hide loading indicator and restore original content.
 *
 * @param {HTMLElement} container - Container element to hide loading indicator from
 * @returns {void}
 */
export const hide = (container) => {
    if (!container) {
        return;
    }

    const state = loadingStates.get(container);
    if (state) {
        // Restore original content
        container.innerHTML = state.originalContent;

        // Restore original ARIA attributes
        if (state.originalAriaLive) {
            container.setAttribute('aria-live', state.originalAriaLive);
        } else {
            container.removeAttribute('aria-live');
        }

        if (state.originalAriaBusy) {
            container.setAttribute('aria-busy', state.originalAriaBusy);
        } else {
            container.removeAttribute('aria-busy');
        }

        // Clean up stored state
        loadingStates.delete(container);
    } else {
        // No stored state, just remove aria-busy
        container.removeAttribute('aria-busy');
    }
};

/**
 * Show loading indicator in a Moodle modal.
 *
 * @param {Object} modal - Moodle modal instance
 * @param {string} message - Loading message to display
 * @param {Object} options - Additional options (same as show())
 * @returns {Promise<void>}
 */
export const showInModal = async(modal, message, options = {}) => {
    if (!modal || !modal.getBody) {
        return;
    }

    const bodyElement = modal.getBody()[0];
    if (!bodyElement) {
        return;
    }

    // Clear footer during loading
    if (modal.setFooter) {
        modal.setFooter('');
    }

    await show(bodyElement, message, options);
};

/**
 * Hide loading indicator from a Moodle modal.
 *
 * @param {Object} modal - Moodle modal instance
 * @returns {void}
 */
export const hideFromModal = (modal) => {
    if (!modal || !modal.getBody) {
        return;
    }

    const bodyElement = modal.getBody()[0];
    if (bodyElement) {
        hide(bodyElement);
    }
};

/**
 * Update loading indicator in a Moodle modal.
 *
 * @param {Object} modal - Moodle modal instance
 * @param {string|null} message - New loading message (null to keep current)
 * @param {number|null} progress - New progress percentage (null to keep current)
 * @returns {void}
 */
export const updateInModal = (modal, message = null, progress = null) => {
    if (!modal || !modal.getBody) {
        return;
    }

    const bodyElement = modal.getBody()[0];
    if (bodyElement) {
        update(bodyElement, message, progress);
    }
};
