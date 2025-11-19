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
import Fragment from 'core/fragment';
import ModgenModal from 'aiplacement_modgen/modal';
import Notification from 'core/notification';
import ModalEvents from 'core/modal_events';

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
     * Open the modal with specific content.
     *
     * @param {StateManager} stateManager State manager
     * @param {string} formName Form name to load (e.g., 'add_theme', 'add_week')
     * @param {string} title Modal title
     */
    openModalWithForm(stateManager, formName, title) {
        stateManager.setReadOnly(false);
        stateManager.state.modal.isOpen = true;
        stateManager.state.modal.isLoading = true;
        stateManager.state.modal.formName = formName;
        stateManager.state.modal.title = title;
        stateManager.setReadOnly(true);
    }

    /**
     * Open the modal (legacy - for generator button).
     *
     * @param {StateManager} stateManager State manager
     */
    openModal(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.modal.isOpen = true;
        stateManager.state.modal.isLoading = true;
        stateManager.state.modal.formName = null;
        stateManager.state.modal.title = 'Module Generator';
        stateManager.setReadOnly(true);
    }

    /**
     * Close the modal.
     *
     * @param {StateManager} stateManager The state manager
     */
    closeModal(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.modal.isOpen = false;
        stateManager.state.modal.isLoading = false;
        stateManager.state.modal.formName = null;
        stateManager.state.modal.title = '';
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
                formName: null,
                title: '',
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
        const formName = this.reactive.state.modal.formName;
        const title = this.reactive.state.modal.title || 'Module Generator';

        // If formName is set, load form via Fragment API
        if (formName) {
            this.loadFormInModal(formName, title);
        } else {
            // Legacy behavior: show link to prompt.php
            this.showGeneratorLink(title);
        }
    }

    /**
     * Load a form in the modal via Fragment API.
     *
     * @param {string} formName Form fragment name (e.g., 'add_theme', 'add_week')
     * @param {string} title Modal title
     */
    loadFormInModal(formName, title) {
        Fragment.loadFragment('aiplacement_modgen', `form_${formName}`, this.contextid, {
            courseid: this.courseid,
        })
        .then((html) => ModgenModal.create({
            title: title,
            body: html,
            large: false,
        }))
        .then((modal) => {
            this.modal = modal;

            // Listen for modal hide/close events and update reactive state
            this.modal.getRoot().on(ModalEvents.hidden, () => {
                this.reactive.dispatch('closeModal');
            });

            this.reactive.dispatch('formLoaded');
            this.modal.show();

            return modal;
        })
        .catch(Notification.exception);
    }

    /**
     * Show generator link in modal (legacy behavior).
     *
     * @param {string} title Modal title
     */
    showGeneratorLink(title) {
        // Build the prompt.php URL
        const promptUrl = M.cfg.wwwroot + '/ai/placement/modgen/prompt.php?id=' + this.courseid;

        // Create modal with link to prompt.php
        const body = '<div class="text-center p-4">' +
                     '<p>Click the button below to open the Module Generator form.</p>' +
                     '<a href="' + promptUrl + '" class="btn btn-primary btn-lg">' +
                     'Open Module Generator' +
                     '</a>' +
                     '</div>';

        ModgenModal.create({
            title: title,
            body: body,
            large: false,
        }).then((modal) => {
            this.modal = modal;

            // Listen for modal hide/close events and update reactive state
            this.modal.getRoot().on(ModalEvents.hidden, () => {
                this.reactive.dispatch('closeModal');
            });

            this.modal.show();

            return modal;
        }).catch(Notification.exception);
    }

    /**
     * Public method to open the modal.
     * Fully reactive - modal creation triggered by watcher.
     */
    open() {
        this.reactive.dispatch('openModal');
    }

    /**
     * Public method to open the modal with a specific form.
     *
     * @param {string} formName Form fragment name (e.g., 'add_theme', 'add_week')
     * @param {string} title Modal title
     */
    openWithForm(formName, title) {
        this.reactive.dispatch('openModalWithForm', formName, title);
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
