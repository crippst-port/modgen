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

// Step constants for progress tracking
const STEPS = {
    PROMPT: 1,
    GENERATING: 2,
    PREVIEW: 3,
    CREATING: 4,
};

const STEP_LABELS = {
    1: 'Enter prompt',
    2: 'Generating',
    3: 'Review',
    4: 'Creating',
};

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
        this.currentsection = descriptor.currentsection || 0;
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
     *
     * @param {string} formName Form fragment name
     * @returns {boolean} True if this is an AI workflow form
     */
    isAiWorkflowForm(formName) {
        const aiWorkflowForms = ['template_from_prompt', 'prompt', 'suggest'];
        return aiWorkflowForms.includes(formName);
    }

    /**
     * Load a form in the modal using Fragment API to render moodleform.
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

    /**
     * Extract form buttons and display them in the modal footer.
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

    /**
     * Handle submission for the prompt form.
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
     * @param {Object} modal The modal instance
     * @param {string} action The button action
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

    /**
     * Build a progress header showing the current step in the workflow.
     *
     * @param {number} currentStep The current step number
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

    /**
     * Setup form submission handler for modal forms.
     *
     * Submits form via AJAX to create_sections.php endpoint.
     *
     * @param {Object} modal The modal instance
     * @param {string} formName Form name ('add_theme' or 'add_week')
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
                // Always refresh page when modal closes to update UI
                window.location.reload();
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
 * @param {number} currentsection Current section number
 * @returns {ModalGeneratorComponent} The component instance
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
