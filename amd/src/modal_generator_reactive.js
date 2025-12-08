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
 * This module provides a reactive modal system for the AI Module Generator plugin.
 * It uses Moodle's core Reactive framework to manage state and trigger UI updates.
 *
 * ## Architecture Overview
 *
 * The module follows Moodle's reactive pattern with three main parts:
 *
 * 1. **State** - Centralized state object tracking modal visibility, loading status,
 *    current form, and workflow step. State changes trigger automatic UI updates.
 *
 * 2. **Mutations** - Named functions that modify state (openModal, closeModal, setStep).
 *    All state changes go through mutations to ensure consistency.
 *
 * 3. **Component** - ModalGeneratorComponent extends BaseComponent and watches for
 *    state changes, updating the UI accordingly (creating/destroying modals, etc).
 *
 * ## Workflow Steps
 *
 * The AI generation workflow has four steps tracked by the progress header:
 * - PROMPT (1): User enters their generation prompt
 * - GENERATING (2): AI is processing the request
 * - PREVIEW (3): User reviews generated content before creation
 * - CREATING (4): Activities are being created in Moodle
 *
 * ## Form Types
 *
 * The modal can load different forms via Moodle's Fragment API:
 * - `add_theme`: Create sections by theme names
 * - `add_week`: Create sections by week range
 * - `template_from_prompt`: AI-powered content generation from prompt
 * - `suggest`: AI activity suggestions for existing sections
 * - `prompt`: Legacy prompt form
 *
 * ## Usage
 *
 * ```javascript
 * import {init} from 'aiplacement_modgen/modal_generator_reactive';
 *
 * // Initialize the component
 * const generator = init(courseid, contextid, currentsection);
 *
 * // Open with a specific form
 * generator.openWithForm('template_from_prompt', 'Generate from Template');
 *
 * // Or open the default generator
 * generator.open();
 * ```
 *
 * @module     aiplacement_modgen/modal_generator_reactive
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// =============================================================================
// IMPORTS
// =============================================================================

import {Reactive, BaseComponent} from 'core/reactive';
import {dispatchEvent} from 'core/event_dispatcher';
import Fragment from 'core/fragment';
import ModgenModal from 'aiplacement_modgen/modal';
import Notification from 'core/notification';
import ModalEvents from 'core/modal_events';

// =============================================================================
// CONSTANTS & EVENT TYPES
// =============================================================================

/**
 * Event types for modal generator.
 * Used by the reactive system to notify components of state changes.
 */
const eventTypes = {
    stateChanged: 'aiplacement_modgen/statechanged',
};

/**
 * Dispatch state change event.
 * This wrapper function matches the signature expected by Moodle's reactive StateManager.
 * When state changes occur, this dispatches a custom event that components can listen to.
 *
 * @param {Object} detail Event detail containing action and state
 * @param {HTMLElement} container The element to dispatch the event on
 * @returns {CustomEvent}
 */
const notifyStateChanged = (detail, container) => {
    return dispatchEvent(eventTypes.stateChanged, detail, container);
};

// =============================================================================
// STATE MUTATIONS
// =============================================================================

/**
 * Mutation handlers for modal state.
 *
 * Mutations are the only way to modify reactive state. Each mutation:
 * 1. Sets state to read/write mode
 * 2. Makes the necessary changes
 * 3. Sets state back to read-only mode
 *
 * This ensures state changes are predictable and trackable.
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
        stateManager.state.modal.currentStep = STEPS.PROMPT;
        stateManager.setReadOnly(true);
    }

    /**
     * Set the current step in the workflow.
     *
     * @param {StateManager} stateManager The state manager
     * @param {number} step The step number
     */
    setStep(stateManager, step) {
        stateManager.setReadOnly(false);
        stateManager.state.modal.currentStep = step;
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

// =============================================================================
// WORKFLOW STEP CONSTANTS
// =============================================================================

/**
 * Step constants for progress tracking.
 * These represent the stages in the AI generation workflow.
 */
const STEPS = {
    /** User is entering their prompt */
    PROMPT: 1,
    /** AI is generating content */
    GENERATING: 2,
    /** User is previewing/reviewing generated content */
    PREVIEW: 3,
    /** Activities are being created in Moodle */
    CREATING: 4,
};

/**
 * Human-readable labels for each step.
 * Displayed in the progress header UI.
 */
const STEP_LABELS = {
    1: 'Enter prompt',
    2: 'Generating',
    3: 'Review',
    4: 'Creating',
};

// =============================================================================
// REACTIVE INSTANCE
// =============================================================================

/**
 * Create the reactive instance immediately when module loads.
 *
 * This is the central state management object. It holds:
 * - modal: State about the modal (open, loading, current form, step)
 * - form: State about form validation (valid, dirty, submitting)
 *
 * Components register watchers on this state to react to changes.
 */
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
                currentStep: STEPS.PROMPT,
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

// =============================================================================
// MODAL GENERATOR COMPONENT
// =============================================================================

/**
 * Modal Generator Component extending BaseComponent.
 *
 * This is the main UI component that:
 * - Watches for state changes via getWatchers()
 * - Creates/destroys the modal based on state
 * - Handles form loading via Fragment API
 * - Manages form submission (AJAX to create_sections.php or prompt.php)
 * - Displays progress header for AI workflow steps
 * - Extracts form buttons to modal footer for consistent UX
 */
class ModalGeneratorComponent extends BaseComponent {
    /**
     * Create method - called when component is instantiated.
     * Stores course/context info needed for form loading and AJAX calls.
     *
     * @param {Object} descriptor Component descriptor
     * @param {number} descriptor.courseid Moodle course ID
     * @param {number} descriptor.contextid Moodle context ID
     * @param {number} descriptor.currentsection Current section number (for adding content)
     */
    create(descriptor) {
        /** @type {number} Course ID for form loading and AJAX */
        this.courseid = descriptor.courseid;
        /** @type {number} Context ID for Fragment API calls */
        this.contextid = descriptor.contextid;
        /** @type {number} Current section number - new content added here */
        this.currentsection = descriptor.currentsection || 0;
        /** @type {ModgenModal|null} Reference to the active modal instance */
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
     * Watchers define which state properties to observe and which handler to call.
     *
     * Format: 'property.path:eventtype' where eventtype is 'updated', 'created', or 'deleted'
     *
     * @returns {Array} Array of watcher definitions
     */
    getWatchers() {
        return [
            // Watch modal open/close state to create/destroy modal
            {watch: 'modal.isOpen:updated', handler: this.handleModalStateChange},
            // Watch loading state to show/hide spinner
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
            // Clear footer during loading
            this.modal.setFooter('');
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
     * Check if a form uses AI generation workflow (needs progress stepper).
     * AI workflow forms show a multi-step progress header during generation.
     *
     * @param {string} formName Form fragment name
     * @returns {boolean} True if this is an AI workflow form
     */
    isAiWorkflowForm(formName) {
        // These forms involve AI generation and show the progress stepper
        const aiWorkflowForms = ['template_from_prompt', 'prompt', 'suggest'];
        return aiWorkflowForms.includes(formName);
    }

    // =========================================================================
    // FORM LOADING (Fragment API)
    // =========================================================================

    /**
     * Load a form in the modal using Fragment API to render moodleform.
     *
     * Fragment API allows us to render server-side moodleform HTML via AJAX.
     * The fragment name maps to a callback in classes/output/renderer.php.
     *
     * @param {string} formName Form fragment name (e.g., 'add_theme', 'add_week')
     * @param {string} title Modal title
     */
    loadFormInModal(formName, title) {
        // Use Fragment API to render the moodleform HTML
        Fragment.loadFragment('aiplacement_modgen', `form_${formName}`, this.contextid, {
            courseid: this.courseid,
            contextid: this.contextid,
        })
        .then((html) => {
            // Only show progress header for AI workflow forms (but not suggest - it has its own stepper)
            const bodyHtml = (this.isAiWorkflowForm(formName) && formName !== 'suggest')
                ? this.buildProgressHeader(STEPS.PROMPT) + html
                : html;
            return ModgenModal.create({
                title: title,
                body: bodyHtml,
                large: false,
            });
        })
        .then((modal) => {
            this.modal = modal;

            // Listen for modal hide/close events and update reactive state
            this.modal.getRoot().on(ModalEvents.hidden, () => {
                this.reactive.dispatch('closeModal');
                // Always refresh page when modal closes to update UI
                window.location.reload();
            });

            // Handle form submission via AJAX instead of Fragment reload
            this.setupFormSubmission(modal, formName);

            // Setup dates preview if this is the dates form
            if (formName === 'dates_for_sections') {
                this.setupDatesPreview(modal);
            }

            this.reactive.dispatch('formLoaded');

            // Extract and display form buttons in the modal footer
            this.updateFooterFromForm(modal);

            // If this is the suggest form, initialize the suggest client module
            if (formName === 'suggest') {
                try {
                    require(['aiplacement_modgen/suggest'], (suggest) => {
                        const mod = (suggest && typeof suggest.init === 'function') ? suggest : (suggest && suggest.default ? suggest.default : null);
                        if (mod && typeof mod.init === 'function') {
                            mod.init(modal, this.courseid, this.currentsection);
                        }
                    });
                } catch (e) {
                    Notification.exception(e);
                }
            }

            this.modal.show();

            return modal;
        })
        .catch(Notification.exception);
    }

    // =========================================================================
    // FOOTER BUTTON MANAGEMENT
    // =========================================================================

    /**
     * Extract form buttons and display them in the modal footer.
     *
     * Moodleforms render submit buttons within the form body, but for better UX
     * we extract them to the modal footer. This method:
     * 1. Finds all visible buttons in the form
     * 2. Creates corresponding buttons in the modal footer
     * 3. Wires click handlers to trigger the original form buttons
     * 4. Hides the original buttons
     *
     * @param {Object} modal The modal instance
     */
    updateFooterFromForm(modal) {
        const body = modal.getBody();
        const bodyNode = body && body.length ? body.get(0) : null;
        if (!bodyNode) {
            return;
        }

        const form = bodyNode.querySelector('form');
        if (!form) {
            return;
        }

        // Find all action buttons in the form
        const buttons = form.querySelectorAll('button, input[type="submit"], input[type="button"]');
        if (buttons.length === 0) {
            return;
        }

        // Filter to only visible action buttons
        const actionButtons = Array.from(buttons).filter(btn => {
            // Skip if already hidden
            if (btn.style.display === 'none' || btn.hidden) {
                return false;
            }
            // Skip if it's a data button used for other purposes
            if (btn.getAttribute('type') === 'hidden') {
                return false;
            }
            return true;
        });

        if (actionButtons.length === 0) {
            return;
        }

        // Build footer HTML with extracted buttons
        let footerHtml = '<div class="aiplacement-modgen-form-footer-buttons">';
        actionButtons.forEach((button, index) => {
            const label = button.tagName === 'INPUT' 
                ? (button.value || button.getAttribute('aria-label') || 'Submit')
                : button.textContent.trim();
            const classes = (button.className || 'btn btn-secondary').trim();
            
            footerHtml += `<button type="button" class="${classes}" data-form-button-index="${index}">
                ${label}
            </button>`;
        });
        footerHtml += '</div>';

        // Set the footer
        modal.setFooter(footerHtml);

        // Wire up footer button clicks to form submission
        const footer = modal.getFooter();
        const footerNode = footer && footer.length ? footer.get(0) : null;
        if (!footerNode) {
            return;
        }

        footerNode.querySelectorAll('[data-form-button-index]').forEach((footerBtn, index) => {
            footerBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const originalButton = actionButtons[index];
                if (originalButton && originalButton.type === 'submit') {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(originalButton);
                    } else {
                        originalButton.click();
                    }
                } else if (originalButton) {
                    originalButton.click();
                }
            });
        });

        // Hide all buttons and their containing elements
        actionButtons.forEach((btn) => {
            btn.style.display = 'none';
            
            // Walk up the DOM tree and hide containers that only contain buttons
            let current = btn.parentElement;
            while (current && current !== form) {
                // Get all visible children
                const children = Array.from(current.children).filter(child => {
                    return window.getComputedStyle(child).display !== 'none';
                });
                
                // If this container only has buttons/inputs, hide it
                const onlyHasButtons = children.every(child => {
                    const isButton = child.tagName === 'BUTTON' || 
                                   (child.tagName === 'INPUT' && (child.type === 'submit' || child.type === 'button'));
                    const isButtonContainer = child.classList.toString().includes('button') || 
                                            child.classList.toString().includes('submit') ||
                                            child.classList.toString().includes('action') ||
                                            child.classList.toString().includes('form-');
                    return isButton || isButtonContainer;
                });
                
                if (onlyHasButtons && children.length > 0) {
                    current.style.display = 'none';
                    break;
                }
                current = current.parentElement;
            }
        });
    }

    // =========================================================================
    // AI PROMPT WORKFLOW
    // =========================================================================

    /**
     * Handle submission for the prompt form.
     *
     * This is the core AI generation flow:
     * 1. Update UI to show "Generating" step with spinner
     * 2. POST form data to prompt.php via AJAX
     * 3. Display the preview/approval form on success
     * 4. Handle subsequent approval form submissions recursively
     *
     * The prompt.php endpoint returns JSON with:
     * - body: HTML content to display (preview form or results)
     * - footer: Optional footer HTML
     * - buttons: Optional array of button definitions
     * - refresh: Boolean indicating if page should reload after close
     *
     * @param {Object} modal The modal instance
     * @param {FormData} formData The form data
     */
    handlePromptSubmission(modal, formData) {
        // Update step to generating
        this.reactive.dispatch('setStep', STEPS.GENERATING);

        // Show loading indicator with progress header
        modal.setBody(this.buildProgressHeader(STEPS.GENERATING) +
            '<div class="text-center p-5">' +
            '<div class="spinner-border" role="status">' +
            '<span class="sr-only">Loading...</span>' +
            '</div>' +
            '<p class="mt-2">Generating content... this may take a minute.</p>' +
            '</div>');

        // Clear footer during generation
        modal.setFooter('');

        // Add required params for prompt.php
        formData.append('ajax', '1');
        formData.append('embedded', '1');
        formData.append('courseid', this.courseid);

        // POST to prompt.php
        fetch(M.cfg.wwwroot + '/ai/placement/modgen/prompt.php', {
            method: 'POST',
            body: formData,
        })
        .then(response => response.json())
        .then(data => {
            if (data.body) {
                // Determine which step we're at based on response
                const step = data.refresh ? STEPS.CREATING : STEPS.PREVIEW;
                this.reactive.dispatch('setStep', step);

                // Add progress header to body
                modal.setBody(this.buildProgressHeader(step) + data.body);
                
                // Handle footer - prefer server-driven buttons, then HTML footer, then extract from form
                if (data.buttons && data.buttons.length > 0) {
                    // Server-driven buttons approach
                    this.renderFooterButtons(modal, data.buttons);
                } else if (data.footer) {
                    modal.setFooter(data.footer);
                } else {
                    // Fallback: extract buttons from the form
                    setTimeout(() => {
                        this.updateFooterFromForm(modal);
                    }, 100);
                }
                
                // Check for close button action
                modal.getRoot().find('[data-action="aiplacement-modgen-close"]').on('click', () => {
                    modal.destroy();
                    if (data.refresh) {
                        window.location.reload();
                    }
                });

                // If the response contains the approval form, we need to handle its submission too.
                // The approval form usually submits to prompt.php as well.
                // We can attach a listener to the new form in the modal body.
                const newForm = modal.getRoot().find('form');
                if (newForm.length) {
                    newForm.on('submit', (e) => {
                        e.preventDefault();
                        const approvalFormData = new FormData(e.target);
                        this.handlePromptSubmission(modal, approvalFormData);
                    });
                }
                
                // Hide form buttons since we're using footer buttons
                if (data.buttons && data.buttons.length > 0) {
                    setTimeout(() => {
                        this.hideFormButtons(modal);
                    }, 50);
                }

            } else if (data.error) {
                 modal.setBody('<div class="alert alert-danger">' + data.error + '</div>');
            }
        })
        .catch(error => {
            Notification.exception(error);
        });
    }
    
    /**
     * Render footer buttons from server-provided button definitions.
     *
     * When prompt.php returns a `buttons` array, we use these definitions
     * instead of extracting buttons from the form. This gives the server
     * control over footer button appearance and actions.
     *
     * Button definition format:
     * {
     *   label: 'Button Text',
     *   class: 'btn-primary',  // Bootstrap class (without 'btn' prefix)
     *   action: 'submit'       // Action identifier for handleFooterButtonAction
     * }
     *
     * @param {Object} modal The modal instance
     * @param {Array} buttons Array of button definitions from server
     */
    renderFooterButtons(modal, buttons) {
        const footerHtml = buttons.map((btn, index) => {
            const classes = `btn ${btn.class || 'btn-secondary'}`;
            return `<button type="button" class="${classes}" data-action="${btn.action}" data-button-index="${index}">
                ${btn.label}
            </button>`;
        }).join('');
        
        modal.setFooter(footerHtml);
        
        // Wire up button handlers
        const footer = modal.getFooter();
        const footerNode = footer && footer.length ? footer.get(0) : null;
        if (!footerNode) {
            return;
        }
        
        footerNode.querySelectorAll('[data-action]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const action = btn.getAttribute('data-action');
                this.handleFooterButtonAction(modal, action);
            });
        });
    }
    
    /**
     * Handle footer button actions.
     *
     * Maps action names to specific behaviors:
     * - 'submit': Show creating step, submit the form
     * - 'regenerate': Reset to prompt step, reload form
     * - 'close': Destroy the modal
     * - Other: Try to find and click a matching button in the form
     *
     * @param {Object} modal The modal instance
     * @param {string} action The button action identifier
     */
    handleFooterButtonAction(modal, action) {
        const body = modal.getBody();
        const bodyNode = body && body.length ? body.get(0) : null;
        const form = bodyNode ? bodyNode.querySelector('form') : null;
        
        switch (action) {
            case 'submit':
                // Show creating step with loading indicator
                this.reactive.dispatch('setStep', STEPS.CREATING);
                modal.setBody(this.buildProgressHeader(STEPS.CREATING) +
                    '<div class=\"text-center p-5\">' +
                    '<div class=\"spinner-border\" role=\"status\">' +
                    '<span class=\"sr-only\">Creating...</span>' +
                    '</div>' +
                    '<p class=\"mt-2\">Creating activities... please wait.</p>' +
                    '</div>');
                modal.setFooter('');

                // Submit the form
                if (form) {
                    const formData = new FormData(form);
                    this.handlePromptSubmission(modal, formData);
                }
                break;
                
            case 'regenerate':
                // Reset step and reload the prompt form
                this.reactive.dispatch('setStep', STEPS.PROMPT);
                this.reactive.dispatch('openModalWithForm', 'template_from_prompt', 'Template from prompt');
                break;
                
            case 'close':
                modal.destroy();
                break;
                
            default:
                // For any other action, try to find and click a matching button in the form
                if (form) {
                    const formBtn = form.querySelector(`[name="${action}"], [data-action="${action}"]`);
                    if (formBtn) {
                        formBtn.click();
                    }
                }
        }
    }
    
    /**
     * Hide form buttons when using server-driven footer buttons.
     *
     * @param {Object} modal The modal instance
     */
    hideFormButtons(modal) {
        const body = modal.getBody();
        const bodyNode = body && body.length ? body.get(0) : null;
        if (!bodyNode) {
            return;
        }
        
        const form = bodyNode.querySelector('form');
        if (!form) {
            return;
        }
        
        // Hide all button containers
        const buttonContainers = form.querySelectorAll('.form-submit, .form-buttons, .buttons, [class*="buttonar"]');
        buttonContainers.forEach(container => {
            container.style.display = 'none';
        });
        
        // Also hide individual buttons not in containers
        const buttons = form.querySelectorAll('button, input[type="submit"], input[type="button"]');
        buttons.forEach(btn => {
            btn.style.display = 'none';
        });
    }

    // =========================================================================
    // PROGRESS HEADER UI
    // =========================================================================

    /**
     * Build a progress header showing the current step in the workflow.
     *
     * Creates a horizontal stepper UI with icons and labels for each step.
     * Steps can be in three states:
     * - Complete (green check): Step number < current step
     * - Active (highlighted): Step number === current step
     * - Pending (muted): Step number > current step
     *
     * CSS classes used:
     * - .modgen-progress-header: Container
     * - .modgen-step: Individual step
     * - .modgen-step-active: Currently active step
     * - .modgen-step-complete: Completed step
     * - .modgen-step-pending: Future step
     * - .modgen-step-line: Connector line between steps
     *
     * @param {number} currentStep The current step number (from STEPS constant)
     * @returns {string} HTML for the progress header
     */
    buildProgressHeader(currentStep) {
        const steps = [
            {num: STEPS.PROMPT, label: STEP_LABELS[STEPS.PROMPT], icon: 'fa-edit'},
            {num: STEPS.GENERATING, label: STEP_LABELS[STEPS.GENERATING], icon: 'fa-cog'},
            {num: STEPS.PREVIEW, label: STEP_LABELS[STEPS.PREVIEW], icon: 'fa-eye'},
            {num: STEPS.CREATING, label: STEP_LABELS[STEPS.CREATING], icon: 'fa-check'},
        ];

        let html = '<div class="modgen-progress-header mb-3">';
        html += '<div class="d-flex justify-content-between align-items-center">';

        steps.forEach((step, index) => {
            const isActive = step.num === currentStep;
            const isComplete = step.num < currentStep;
            const isPending = step.num > currentStep;

            let stepClass = 'modgen-step';
            if (isActive) {
                stepClass += ' modgen-step-active';
            }
            if (isComplete) {
                stepClass += ' modgen-step-complete';
            }
            if (isPending) {
                stepClass += ' modgen-step-pending';
            }

            // Choose icon based on state
            let iconClass = step.icon;
            if (isComplete) {
                iconClass = 'fa-check';
            }

            html += `<div class="${stepClass} text-center flex-fill">`;
            html += `<div class="modgen-step-icon mb-1">`;
            html += `<i class="fa ${iconClass}"></i>`;
            html += `</div>`;
            html += `<div class="modgen-step-label small">${step.label}</div>`;
            html += `</div>`;

            // Add connector line between steps (except after last)
            if (index < steps.length - 1) {
                const lineClass = isComplete ? 'modgen-step-line-complete' : 'modgen-step-line';
                html += `<div class="${lineClass} flex-fill" style="height: 2px; margin-top: -1rem;"></div>`;
            }
        });

        html += '</div>';
        html += '</div>';

        return html;
    }

    // =========================================================================
    // DATES FOR SECTIONS WORKFLOW
    // =========================================================================

    /**
     * Handle submission for the dates_for_sections form.
     *
     * @param {Object} modal The modal instance
     * @param {FormData} formData The form data
     */
    handleDatesForSectionsSubmission(modal, formData) {
        // Check if this is a remove dates request
        const isRemove = formData.has('removedates');
        
        // Collect selected section IDs
        const selectedSections = formData.getAll('selectedsections[]');
        const includeparents = formData.get('includeparents') ? 1 : 0;

        if (!selectedSections || selectedSections.length === 0) {
            modal.setBody('<div class="alert alert-danger">Please select at least one section</div>');
            return;
        }

        // Determine action and endpoint
        const action = isRemove ? 'Removing dates from' : 'Applying dates to';
        const endpoint = isRemove ? 
            '/ai/placement/modgen/ajax/remove_section_dates.php' : 
            '/ai/placement/modgen/ajax/apply_section_dates.php';

        // Show loading indicator
        modal.setBody('<div class="text-center p-5">' +
            '<div class="spinner-border" role="status">' +
            '<span class="sr-only">' + action + ' sections...</span>' +
            '</div>' +
            '<p class="mt-3">' + action + ' sections...</p>' +
            '</div>');

        // Build params
        const params = new URLSearchParams();
        params.append('courseid', this.courseid);
        params.append('selectedsections', JSON.stringify(selectedSections));
        if (!isRemove) {
            params.append('includeparents', includeparents);
        }
        params.append('sesskey', M.cfg.sesskey);

        // POST to appropriate endpoint
        fetch(M.cfg.wwwroot + endpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                let successHtml = '<div class="alert alert-success">';
                successHtml += '<p>' + data.message + '</p>';
                successHtml += '<p class="mt-3">';
                successHtml += '<button type="button" class="btn btn-primary" id="reload-page-btn">';
                successHtml += 'Return to course';
                successHtml += '</button>';
                successHtml += '</p>';
                successHtml += '</div>';
                
                modal.setBody(successHtml);
                
                // Add click handler to reload button
                modal.getRoot().find('#reload-page-btn').on('click', () => {
                    window.location.reload();
                });
            } else {
                const errorHtml = '<div class="alert alert-danger">' +
                    '<p>' + (data.error || 'An error occurred') + '</p>' +
                    '</div>';
                modal.setBody(errorHtml);
            }
        })
        .catch(error => {
            window.console.error('Error processing dates:', error);
            modal.setBody('<div class="alert alert-danger">An error occurred while processing dates</div>');
        });
    }

    /**
     * Setup dynamic date preview for the dates_for_sections form.
     * 
     * Listens for checkbox changes and recalculates dates in real-time.
     *
     * @param {Object} modal The modal instance
     */
    setupDatesPreview(modal) {
        const modalRoot = modal.getRoot();
        let debounceTimer = null;

        // Helper to collect excluded sections
        const getExcludedSections = () => {
            const body = modal.getBody();
            const bodyNode = body && body.length ? body.get(0) : null;
            if (!bodyNode) {
                return [];
            }

            const checkboxes = bodyNode.querySelectorAll('.dates-section-checkbox');
            const excluded = [];
            checkboxes.forEach(cb => {
                if (!cb.checked) {
                    excluded.push(parseInt(cb.dataset.sectionId));
                }
            });
            return excluded;
        };

        // Helper to get includeparents value
        const getIncludeParents = () => {
            const body = modal.getBody();
            const bodyNode = body && body.length ? body.get(0) : null;
            if (!bodyNode) {
                return 0;
            }
            const checkbox = bodyNode.querySelector('#includeparents-checkbox');
            return checkbox && checkbox.checked ? 1 : 0;
        };

        // Preview dates with debounce
        const previewDates = () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const excludedSections = getExcludedSections();
                const includeparents = getIncludeParents();

                // Build params
                const params = new URLSearchParams();
                params.append('courseid', this.courseid);
                params.append('excludedsections', JSON.stringify(excludedSections));
                params.append('includeparents', includeparents);
                params.append('sesskey', M.cfg.sesskey);

                // Fetch preview
                fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/preview_section_dates.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: params.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.sections) {
                        // Update proposed name cells
                        const body = modal.getBody();
                        const bodyNode = body && body.length ? body.get(0) : null;
                        if (!bodyNode) {
                            return;
                        }

                        data.sections.forEach(section => {
                            const row = bodyNode.querySelector(`tr[data-section-id="${section.id}"]`);
                            if (row) {
                                const proposedCell = row.querySelector('.proposed-name-cell');
                                if (proposedCell) {
                                    proposedCell.textContent = section.proposed_name;
                                }
                            }
                        });

                        // Update ARIA live region
                        const statusRegion = bodyNode.querySelector('#dates-status');
                        if (statusRegion) {
                            statusRegion.textContent = 'Dates recalculated';
                            setTimeout(() => {
                                statusRegion.textContent = '';
                            }, 1000);
                        }
                    }
                })
                .catch(error => {
                    window.console.error('Error previewing dates:', error);
                });
            }, 250); // 250ms debounce
        };

        // Listen for checkbox changes
        modalRoot.on('change', '.dates-section-checkbox, #includeparents-checkbox', () => {
            previewDates();
        });
    }

    // =========================================================================
    // FORM SUBMISSION (Non-AI workflows)
    // =========================================================================

    /**
     * Setup form submission handler for modal forms.
     *
     * Handles form submission for non-AI workflows (add_theme, add_week):
     * 1. Intercepts form submit event
     * 2. Determines which button was clicked (submit vs cancel)
     * 3. POSTs to create_sections.php AJAX endpoint
     * 4. Displays success/error message
     *
     * For AI workflows (template_from_prompt), delegates to handlePromptSubmission.
     *
     * @param {Object} modal The modal instance
     * @param {string} formName Form name ('add_theme', 'add_week', or 'template_from_prompt')
     */
    setupFormSubmission(modal, formName) {
        const modalRoot = modal.getRoot();
        
        // Track which button was clicked (works for both form buttons and footer buttons)
        let clickedButton = null;
        
        // Track clicks on original form buttons
        modalRoot.on('click', 'input[type="submit"], button[type="submit"]', function() {
            clickedButton = this.getAttribute('name');
        });
        
        // Track clicks on footer buttons that proxy to form buttons
        modalRoot.on('click', '[data-form-button-index]', function() {
            // The footer button will trigger the original button, so find it
            const index = this.getAttribute('data-form-button-index');
            const body = modal.getBody();
            const bodyNode = body && body.length ? body.get(0) : null;
            if (bodyNode) {
                const form = bodyNode.querySelector('form');
                if (form) {
                    const buttons = form.querySelectorAll('button, input[type="submit"], input[type="button"]');
                    const originalButton = buttons[index];
                    if (originalButton) {
                        clickedButton = originalButton.getAttribute('name');
                    }
                }
            }
        });
        
        modalRoot.on('submit', 'form', (e) => {
            e.preventDefault();
            
            // Check submitter first (modern browsers), then fall back to tracked button
            const submitter = e.originalEvent?.submitter;
            const buttonName = submitter?.getAttribute('name') || clickedButton;
            
            // If cancel button was clicked, just close modal
            if (buttonName === 'cancel') {
                modal.destroy();
                clickedButton = null;
                return;
            }
            
            // Reset for next submission
            clickedButton = null;
            
            const form = e.target;
            const formData = new FormData(form);

            // Handle prompt form specifically
            if (formName === 'template_from_prompt') {
                this.handlePromptSubmission(modal, formData);
                return;
            }

            // Handle dates for sections form specifically
            if (formName === 'dates_for_sections') {
                this.handleDatesForSectionsSubmission(modal, formData);
                return;
            }
            
            // Determine action based on form name
            const action = formName === 'add_theme' ? 'create_themes' : 'create_weeks';
            
            // Build params object - start with required params
            const params = {
                courseid: this.courseid,
                sesskey: M.cfg.sesskey,
                parentsection: this.currentsection, // Current section to add content within
            };
            
            // Add form fields to params (but skip internal moodleform fields)
            formData.forEach((value, key) => {
                // Skip moodleform internal fields, buttons, and action field
                if (!key.startsWith('_qf__') && key !== 'submitbutton' && key !== 'courseid' && key !== 'action') {
                    params[key] = value;
                }
            });
            
            // Set action AFTER adding form fields to ensure it's not overwritten
            params.action = action;
            
            // Show loading indicator
            modal.setBody('<div class="text-center p-5">' +
                '<div class="spinner-border" role="status">' +
                '<span class="sr-only">Loading...</span>' +
                '</div>' +
                '</div>');
            
            // POST to AJAX endpoint
            fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/create_sections.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(params),
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Build success message HTML
                    let successHtml = '<div class="alert alert-success">';
                    successHtml += '<p>' + data.message + '</p>';
                    
                    // Add detailed messages if present
                    if (data.messages && data.messages.length > 0) {
                        successHtml += '<ul>';
                        data.messages.forEach(msg => {
                            successHtml += '<li>' + msg + '</li>';
                        });
                        successHtml += '</ul>';
                    }
                    
                    // Add return to course button
                    successHtml += '<p class="mt-3">';
                    successHtml += '<button type="button" class="btn btn-primary" id="reload-page-btn">';
                    successHtml += 'Return to course';
                    successHtml += '</button>';
                    successHtml += '</p>';
                    successHtml += '</div>';
                    
                    modal.setBody(successHtml);
                    
                    // Add click handler to reload button
                    modal.getRoot().find('#reload-page-btn').on('click', () => {
                        window.location.reload();
                    });
                } else {
                    // Show error message
                    const errorHtml = '<div class="alert alert-danger">' +
                        '<p>' + (data.error || 'An error occurred') + '</p>' +
                        '</div>';
                    modal.setBody(errorHtml);
                }
                return data;
            })
            .catch(error => {
                Notification.exception(error);
            });
        });
    }

    // =========================================================================
    // LEGACY GENERATOR LINK
    // =========================================================================

    /**
     * Show generator link in modal (legacy behavior).
     *
     * For cases where no specific form is requested, shows a simple
     * modal with a link to open prompt.php in a new page.
     * This is fallback behavior - most interactions use loadFormInModal.
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
                // Always refresh page when modal closes to update UI
                window.location.reload();
            });

            this.modal.show();

            return modal;
        }).catch(Notification.exception);
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Public method to open the modal.
     * Fully reactive - modal creation triggered by watcher on state.modal.isOpen.
     */
    open() {
        this.reactive.dispatch('openModal');
    }

    /**
     * Public method to open the modal with a specific form.
     * Use this for toolbar buttons that need a specific form type.
     *
     * @param {string} formName Form fragment name (e.g., 'add_theme', 'add_week', 'template_from_prompt')
     * @param {string} title Modal title to display
     */
    openWithForm(formName, title) {
        this.reactive.dispatch('openModalWithForm', formName, title);
    }

    /**
     * Public method to close the modal.
     * Triggers state change which destroys the modal via watcher.
     */
    close() {
        this.reactive.dispatch('closeModal');
    }
}

// =============================================================================
// MODULE INITIALIZATION
// =============================================================================

/**
 * Initialize the modal generator component.
 *
 * Creates a new ModalGeneratorComponent instance attached to document.body.
 * The component uses the shared reactiveInstance for state management.
 *
 * @param {number} courseid Moodle course ID
 * @param {number} contextid Moodle context ID (usually course context)
 * @param {number} currentsection Current section number (default 0 = general section)
 * @returns {ModalGeneratorComponent} The component instance for external control
 *
 * @example
 * // In a Moodle page or another AMD module:
 * require(['aiplacement_modgen/modal_generator_reactive'], function(generator) {
 *     const component = generator.init(123, 456, 0);
 *     component.openWithForm('template_from_prompt', 'Generate Content');
 * });
 */
export const init = (courseid, contextid, currentsection = 0) => {
    const component = new ModalGeneratorComponent({
        element: document.body,
        reactive: reactiveInstance,
        courseid,
        contextid,
        currentsection,
    });
    
    return component;
};
