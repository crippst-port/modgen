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
 * Reactive modal generator component.
 *
 * @module     aiplacement_modgen/modal_generator_reactive
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {Reactive, BaseComponent} from 'core/reactive';
import {dispatchEvent} from 'core/event_dispatcher';
import Templates from 'core/templates';
import ModgenModal from 'aiplacement_modgen/modal';
import Notification from 'core/notification';

/**
 * Event types for modal generator.
 */
const eventTypes = {
    stateChanged: 'aiplacement_modgen/statechanged',
};

/**
 * Dispatch state change event.
 * This wrapper function matches the signature expected by Moodle's reactive StateManager.
 *
 * @param {Object} detail Event detail containing action and state
 * @param {HTMLElement} container The element to dispatch the event on
 * @returns {CustomEvent}
 */
const notifyStateChanged = (detail, container) => {
    return dispatchEvent(eventTypes.stateChanged, detail, container);
};

/**
 * Mutation handlers for modal state.
 */
class ModalMutations {
    /**
     * Open the modal.
     *
     * @param {StateManager} stateManager
     */
    openModal(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.modal.isOpen = true;
        stateManager.state.modal.isLoading = true;
        stateManager.setReadOnly(true);
    }    /**
     * Close the modal.
     *
     * @param {StateManager} stateManager The state manager
     */
    closeModal(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.modal.isOpen = false;
        stateManager.state.modal.isLoading = false;
        stateManager.setReadOnly(true);
    }

    /**
     * Mark form as loaded.
     *
     * @param {StateManager} stateManager The state manager
     */
    formLoaded(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.modal.isLoading = false;
        stateManager.setReadOnly(true);
    }
}

// Create the reactive instance immediately when module loads
const reactiveInstance = new Reactive({
        name: 'ModalGenerator',
        eventName: eventTypes.stateChanged,
        eventDispatch: notifyStateChanged,
        // Pass initial state in constructor like nosferatu beginner example
        state: {
            modal: {
                isOpen: false,
                isLoading: false,
            },
            form: {
                isValid: false,
                isDirty: false,
                isSubmitting: false,
            },
        },
        // Pass mutations in constructor
        mutations: new ModalMutations(),
    });

/**
 * Modal Generator Component extending BaseComponent.
 */
class ModalGeneratorComponent extends BaseComponent {
    /**
     * Create method - called when component is instantiated.
     *
     * @param {Object} descriptor Component descriptor
     */
    create(descriptor) {
        this.courseid = descriptor.courseid;
        this.contextid = descriptor.contextid;
        this.modal = null;
    }

    /**
     * Called when state is ready - this is where we can start using reactive state.
     */
    stateReady() {
        // State is now ready and watchers are active
    }

    /**
     * Get watchers for state changes.
     *
     * @returns {Array} Array of watchers
     */
    getWatchers() {
        return [
            {watch: 'modal.isOpen:updated', handler: this.handleModalStateChange},
            {watch: 'modal.isLoading:updated', handler: this.handleLoadingChange},
        ];
    }

    /**
     * Handle modal open/close state changes.
     *
     * @param {Object} args Watcher arguments
     * @param {Object} args.state Current reactive state
     */
    handleModalStateChange({state}) {
        if (state.modal.isOpen && !this.modal) {
            this.createModal();
        } else if (!state.modal.isOpen && this.modal) {
            this.modal.destroy();
            this.modal = null;
        } else if (state.modal.isOpen && this.modal) {
            this.modal.show();
        }
    }

    /**
     * Handle loading state changes.
     *
     * @param {Object} args Watcher arguments
     * @param {Object} args.state Current reactive state
     */
    handleLoadingChange({state}) {
        if (this.modal && state.modal.isLoading) {
            this.modal.setBody('<div class="spinner-border" role="status"><span class="sr-only">Loading...</span></div>');
        }
    }

    /**
     * Create and show the modal.
     */
    createModal() {
        ModgenModal.create({
            title: 'Module Generator',
            body: '<div class="d-flex justify-content-center p-5"><div class="spinner-border" role="status">' +
                  '<span class="sr-only">Loading...</span></div></div>',
            large: true,
        }).then((modal) => {
            this.modal = modal;
            this.modal.show();
            
            // Load the generator form via AJAX
            this.loadForm();
            
            return modal;
        }).catch(Notification.exception);
    }

    /**
     * Load the generator form via AJAX and properly execute embedded JavaScript.
     */
    loadForm() {
        const url = M.cfg.wwwroot + '/ai/placement/modgen/generate.php';
        const params = new URLSearchParams({
            courseid: this.courseid,
            sesskey: M.cfg.sesskey,
        });

        fetch(url + '?' + params.toString(), {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
            },
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (!data.success) {
                // Show detailed error information
                let errorHtml = '<div class="alert alert-danger"><h4>Error loading form</h4>';
                errorHtml += '<p><strong>Error:</strong> ' + (data.error || 'Unknown error') + '</p>';
                if (data.file) {
                    errorHtml += '<p><strong>File:</strong> ' + data.file + ':' + data.line + '</p>';
                }
                if (data.trace) {
                    errorHtml += '<details><summary>Stack Trace</summary><pre>' +
                                 (Array.isArray(data.trace) ? data.trace.join('\n') : data.trace) +
                                 '</pre></details>';
                }
                errorHtml += '</div>';
                this.modal.setBody(errorHtml);
                // eslint-disable-next-line no-console
                console.error('Form load error:', data);
                return;
            }

            if (!data.html) {
                throw new Error('No HTML returned from server');
            }

            // Use Templates.replaceNodeContents which properly handles script execution
            const modalBody = this.modal.getBody()[0];

            // This properly executes the JavaScript including YUI filemanager init
            Templates.replaceNodeContents(modalBody, data.html, data.javascript || '');

            // Dispatch after a short delay to ensure scripts have executed
            return new Promise(resolve => {
                setTimeout(() => {
                    this.reactive.dispatch('formLoaded');
                    resolve();
                }, 200);
            });
        })
        .catch(error => {
            this.modal.setBody(
                '<div class="alert alert-danger">Error loading form: ' + error.message + '</div>'
            );
            Notification.exception(error);
        });
    }

    /**
     * Public method to open the modal.
     * Fully reactive - modal creation triggered by watcher.
     */
    open() {
        this.reactive.dispatch('openModal');
    }

    /**
     * Public method to close the modal.
     */
    close() {
        this.reactive.dispatch('closeModal');
    }
}

/**
 * Initialize the modal generator component.
 *
 * @param {number} courseid Course ID
 * @param {number} contextid Context ID
 * @returns {ModalGeneratorComponent} The component instance
 */
export const init = (courseid, contextid) => {
    const component = new ModalGeneratorComponent({
        element: document.body,
        reactive: reactiveInstance,
        courseid,
        contextid,
    });
    
    return component;
};
