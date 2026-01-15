define(["exports", "core/reactive", "core/event_dispatcher", "core/fragment", "aiplacement_modgen/modal", "core/notification", "core/modal_events", "core/templates", "core/str"], function (_exports, _reactive, _event_dispatcher, _fragment, _modal, _notification, _modal_events, _templates, _str) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = void 0;
  _fragment = _interopRequireDefault(_fragment);
  _modal = _interopRequireDefault(_modal);
  _notification = _interopRequireDefault(_notification);
  _modal_events = _interopRequireDefault(_modal_events);
  _templates = _interopRequireDefault(_templates);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
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
  const eventTypes = {
    stateChanged: 'aiplacement_modgen/statechanged'
  };
  const notifyStateChanged = (detail, container) => {
    return (0, _event_dispatcher.dispatchEvent)(eventTypes.stateChanged, detail, container);
  };
  class ModalMutations {
    openModalWithForm(stateManager, formName, title) {
      stateManager.setReadOnly(false);
      stateManager.state.modal.isOpen = true;
      stateManager.state.modal.isLoading = true;
      stateManager.state.modal.formName = formName;
      stateManager.state.modal.title = title;
      stateManager.setReadOnly(true);
    }
    openModal(stateManager) {
      stateManager.setReadOnly(false);
      stateManager.state.modal.isOpen = true;
      stateManager.state.modal.isLoading = true;
      stateManager.state.modal.formName = null;
      stateManager.state.modal.title = 'Module Generator';
      stateManager.setReadOnly(true);
    }
    closeModal(stateManager) {
      stateManager.setReadOnly(false);
      stateManager.state.modal.isOpen = false;
      stateManager.state.modal.isLoading = false;
      stateManager.state.modal.formName = null;
      stateManager.state.modal.title = '';
      stateManager.state.modal.currentStep = STEPS.PROMPT;
      stateManager.setReadOnly(true);
    }
    setStep(stateManager, step) {
      stateManager.setReadOnly(false);
      stateManager.state.modal.currentStep = step;
      stateManager.setReadOnly(true);
    }
    formLoaded(stateManager) {
      stateManager.setReadOnly(false);
      stateManager.state.modal.isLoading = false;
      stateManager.setReadOnly(true);
    }
  }
  const STEPS = {
    PROMPT: 1,
    GENERATING: 2,
    PREVIEW: 3,
    CREATING: 4
  };
  const STEP_LABELS = {
    1: 'Enter prompt',
    2: 'Generating',
    3: 'Review',
    4: 'Creating'
  };
  const reactiveInstance = new _reactive.Reactive({
    name: 'ModalGenerator',
    eventName: eventTypes.stateChanged,
    eventDispatch: notifyStateChanged,
    state: {
      modal: {
        isOpen: false,
        isLoading: false,
        formName: null,
        title: '',
        currentStep: STEPS.PROMPT
      },
      form: {
        isValid: false,
        isDirty: false,
        isSubmitting: false
      }
    },
    mutations: new ModalMutations()
  });
  class ModalGeneratorComponent extends _reactive.BaseComponent {
    create(descriptor) {
      this.courseid = descriptor.courseid;
      this.contextid = descriptor.contextid;
      this.currentsection = descriptor.currentsection || 0;
      this.modal = null;
    }
    stateReady() {}
    getWatchers() {
      return [{
        watch: 'modal.isOpen:updated',
        handler: this.handleModalStateChange
      }, {
        watch: 'modal.isLoading:updated',
        handler: this.handleLoadingChange
      }];
    }
    handleModalStateChange(_ref) {
      let {
        state
      } = _ref;
      if (state.modal.isOpen && !this.modal) {
        this.createModal();
      } else if (!state.modal.isOpen && this.modal) {
        this.modal.destroy();
        this.modal = null;
      } else if (state.modal.isOpen && this.modal) {
        this.modal.show();
      }
    }
    handleLoadingChange(_ref2) {
      let {
        state
      } = _ref2;
      if (this.modal && state.modal.isLoading) {
        this.modal.setBody('<div class="spinner-border" role="status"><span class="sr-only">Loading...</span></div>');
        this.modal.setFooter('');
      }
    }
    createModal() {
      const formName = this.reactive.state.modal.formName;
      const title = this.reactive.state.modal.title || 'Module Generator';
      if (formName) {
        this.loadFormInModal(formName, title);
      } else {
        this.showGeneratorLink(title);
      }
    }
    isAiWorkflowForm(formName) {
      const aiWorkflowForms = ['template_from_prompt', 'prompt', 'suggest'];
      return aiWorkflowForms.includes(formName);
    }
    loadFormInModal(formName, title) {
      _fragment.default.loadFragment('aiplacement_modgen', `form_${formName}`, this.contextid, {
        courseid: this.courseid,
        contextid: this.contextid
      }).then(html => {
        const bodyHtml = this.isAiWorkflowForm(formName) && formName !== 'suggest' ? this.buildProgressHeader(STEPS.PROMPT) + html : html;
        return _modal.default.create({
          title: title,
          body: bodyHtml,
          large: false
        });
      }).then(modal => {
        this.modal = modal;
        this.modal.getRoot().on(_modal_events.default.hidden, () => {
          this.reactive.dispatch('closeModal');
          window.location.reload();
        });
        this.setupFormSubmission(modal, formName);
        if (formName === 'dates_for_sections') {
          this.setupDatesPreview(modal);
        }
        this.reactive.dispatch('formLoaded');
        this.updateFooterFromForm(modal);
        if (formName === 'suggest') {
          try {
            require(['aiplacement_modgen/suggest'], suggest => {
              const mod = suggest && typeof suggest.init === 'function' ? suggest : suggest && suggest.default ? suggest.default : null;
              if (mod && typeof mod.init === 'function') {
                mod.init(modal, this.courseid, this.currentsection);
              }
            });
          } catch (e) {
            _notification.default.exception(e);
          }
        }
        this.modal.show();
        return modal;
      }).catch(_notification.default.exception);
    }
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
      const buttons = form.querySelectorAll('button, input[type="submit"], input[type="button"]');
      if (buttons.length === 0) {
        return;
      }
      const actionButtons = Array.from(buttons).filter(btn => {
        if (btn.style.display === 'none' || btn.hidden) {
          return false;
        }
        if (btn.getAttribute('type') === 'hidden') {
          return false;
        }
        return true;
      });
      if (actionButtons.length === 0) {
        return;
      }
      let footerHtml = '<div class="aiplacement-modgen-form-footer-buttons d-flex justify-content-end">';
      actionButtons.forEach((button, index) => {
        const label = button.tagName === 'INPUT' ? button.value || button.getAttribute('aria-label') || 'Submit' : button.textContent.trim();
        const classes = (button.className || 'btn btn-secondary').trim();
        footerHtml += `<button type="button" class="${classes} ${index > 0 ? 'ml-2' : ''}" data-form-button-index="${index}">
                ${label}
            </button>`;
      });
      footerHtml += '</div>';
      modal.setFooter(footerHtml);
      const footer = modal.getFooter();
      const footerNode = footer && footer.length ? footer.get(0) : null;
      if (!footerNode) {
        return;
      }
      footerNode.querySelectorAll('[data-form-button-index]').forEach((footerBtn, index) => {
        footerBtn.addEventListener('click', e => {
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
      actionButtons.forEach(btn => {
        btn.style.display = 'none';
      });
      const buttonContainers = form.querySelectorAll('.fitem_actionbuttons, .fitem_fgroup, [id*="fgroup_id_buttonar"], [id*="fitem_id_buttonar"]');
      buttonContainers.forEach(container => {
        const hasNonButtonContent = Array.from(container.querySelectorAll('*')).some(el => {
          return el.tagName !== 'BUTTON' && el.tagName !== 'INPUT' && !el.classList.contains('form-group') && !el.classList.contains('fitem') && el.textContent.trim().length > 0;
        });
        if (!hasNonButtonContent) {
          container.style.display = 'none';
        }
      });
    }
    handlePromptSubmission(modal, formData) {
      this.reactive.dispatch('setStep', STEPS.GENERATING);
      modal.setBody(this.buildProgressHeader(STEPS.GENERATING) + '<div class="text-center p-5">' + '<div class="spinner-border" role="status">' + '<span class="sr-only">Loading...</span>' + '</div>' + '<p class="mt-2">Generating content... this may take a minute.</p>' + '</div>');
      modal.setFooter('');
      formData.append('ajax', '1');
      formData.append('embedded', '1');
      formData.append('courseid', this.courseid);
      fetch(M.cfg.wwwroot + '/ai/placement/modgen/prompt.php', {
        method: 'POST',
        body: formData
      }).then(response => response.json()).then(data => {
        if (data.body) {
          const step = data.refresh ? STEPS.CREATING : STEPS.PREVIEW;
          this.reactive.dispatch('setStep', step);
          modal.setBody(this.buildProgressHeader(step) + data.body);
          if (data.buttons && data.buttons.length > 0) {
            this.renderFooterButtons(modal, data.buttons);
          } else if (data.footer) {
            modal.setFooter(data.footer);
          } else {
            setTimeout(() => {
              this.updateFooterFromForm(modal);
            }, 100);
          }
          modal.getRoot().find('[data-action="aiplacement-modgen-close"]').on('click', () => {
            modal.destroy();
            if (data.refresh) {
              window.location.reload();
            }
          });
          const newForm = modal.getRoot().find('form');
          if (newForm.length) {
            newForm.on('submit', e => {
              e.preventDefault();
              const approvalFormData = new FormData(e.target);
              this.handlePromptSubmission(modal, approvalFormData);
            });
          }
          if (data.buttons && data.buttons.length > 0) {
            setTimeout(() => {
              this.hideFormButtons(modal);
            }, 50);
          }
        } else if (data.error) {
          modal.setBody('<div class="alert alert-danger">' + data.error + '</div>');
        }
      }).catch(error => {
        _notification.default.exception(error);
      });
    }
    async renderFooterButtons(modal, buttons) {
      let useLanguageStrings = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : false;
      const buttonsWithLabels = await Promise.all(buttons.map(async (btn, index) => {
        let label = btn.label;
        if (useLanguageStrings) {
          try {
            label = await (0, _str.get_string)(btn.label, 'aiplacement_modgen');
          } catch (error) {
            label = btn.label;
          }
        }
        return {
          label: label,
          action: btn.action,
          class: btn.class || 'btn-secondary',
          index: index
        };
      }));
      const {
        html
      } = await _templates.default.renderForPromise('aiplacement_modgen/modal_footer_buttons', {
        buttons: buttonsWithLabels
      });
      modal.setFooter(html);
      const footer = modal.getFooter();
      const footerNode = footer && footer.length ? footer.get(0) : null;
      if (!footerNode) {
        return;
      }
      footerNode.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', e => {
          e.preventDefault();
          const action = btn.getAttribute('data-action');
          this.handleFooterButtonAction(modal, action);
        });
      });
    }
    handleFooterButtonAction(modal, action) {
      const body = modal.getBody();
      const bodyNode = body && body.length ? body.get(0) : null;
      const form = bodyNode ? bodyNode.querySelector('form') : null;
      switch (action) {
        case 'submit':
          this.reactive.dispatch('setStep', STEPS.CREATING);
          modal.setBody(this.buildProgressHeader(STEPS.CREATING) + '<div class=\"text-center p-5\">' + '<div class=\"spinner-border\" role=\"status\">' + '<span class=\"sr-only\">Creating...</span>' + '</div>' + '<p class=\"mt-2\">Creating activities... please wait.</p>' + '</div>');
          modal.setFooter('');
          if (form) {
            const formData = new FormData(form);
            this.handlePromptSubmission(modal, formData);
          }
          break;
        case 'regenerate':
          this.reactive.dispatch('setStep', STEPS.PROMPT);
          this.reactive.dispatch('openModalWithForm', 'template_from_prompt', 'Template from prompt');
          break;
        case 'return-to-course':
          window.location.reload();
          break;
        case 'close':
          modal.destroy();
          break;
        default:
          if (form) {
            const formBtn = form.querySelector(`[name="${action}"], [data-action="${action}"]`);
            if (formBtn) {
              formBtn.click();
            }
          }
      }
    }
    async showSuccess(modal, message) {
      let details = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : [];
      const {
        html: bodyHtml
      } = await _templates.default.renderForPromise('aiplacement_modgen/success_message', {
        message: message,
        details: details.length > 0 ? details : null
      });
      modal.setBody(bodyHtml);
      await this.renderFooterButtons(modal, [{
        label: 'closemodgenmodal',
        action: 'return-to-course',
        class: 'btn-primary'
      }], true);
    }
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
      const buttonContainers = form.querySelectorAll('.form-submit, .form-buttons, .buttons, [class*="buttonar"]');
      buttonContainers.forEach(container => {
        container.style.display = 'none';
      });
      const buttons = form.querySelectorAll('button, input[type="submit"], input[type="button"]');
      buttons.forEach(btn => {
        btn.style.display = 'none';
        btn.style.visibility = 'hidden';
        btn.setAttribute('aria-hidden', 'true');
      });
    }
    buildProgressHeader(currentStep) {
      const steps = [{
        num: STEPS.PROMPT,
        label: STEP_LABELS[STEPS.PROMPT],
        icon: 'fa-edit'
      }, {
        num: STEPS.GENERATING,
        label: STEP_LABELS[STEPS.GENERATING],
        icon: 'fa-cog'
      }, {
        num: STEPS.PREVIEW,
        label: STEP_LABELS[STEPS.PREVIEW],
        icon: 'fa-eye'
      }, {
        num: STEPS.CREATING,
        label: STEP_LABELS[STEPS.CREATING],
        icon: 'fa-check'
      }];
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
        if (index < steps.length - 1) {
          const lineClass = isComplete ? 'modgen-step-line-complete' : 'modgen-step-line';
          html += `<div class="${lineClass} flex-fill" style="height: 2px; margin-top: -1rem;"></div>`;
        }
      });
      html += '</div>';
      html += '</div>';
      return html;
    }
    handleDatesForSectionsSubmission(modal, formData, buttonName) {
      const isRemove = buttonName === 'removedates';
      const body = modal.getBody();
      const bodyNode = body && body.length ? body.get(0) : null;
      const selectedSections = [];
      if (bodyNode) {
        const checkboxes = bodyNode.querySelectorAll('.section-checkbox:checked');
        checkboxes.forEach(cb => {
          selectedSections.push(cb.value);
        });
      }
      if (!selectedSections || selectedSections.length === 0) {
        modal.setBody('<div class="alert alert-danger">Please select at least one section</div>');
        return;
      }
      const action = isRemove ? 'Removing dates from' : 'Applying dates to';
      const endpoint = isRemove ? '/ai/placement/modgen/ajax/remove_section_dates.php' : '/ai/placement/modgen/ajax/apply_section_dates.php';
      modal.setBody('<div class="text-center p-5">' + '<div class="spinner-border" role="status">' + '<span class="sr-only">' + action + ' sections...</span>' + '</div>' + '<p class="mt-3">' + action + ' sections...</p>' + '</div>');
      const includeparents = 1;
      const params = new URLSearchParams();
      params.append('courseid', this.courseid);
      params.append('selectedsections', JSON.stringify(selectedSections));
      if (!isRemove) {
        params.append('includeparents', includeparents);
      }
      params.append('sesskey', M.cfg.sesskey);
      fetch(M.cfg.wwwroot + endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params.toString()
      }).then(response => response.json()).then(data => {
        if (data.success) {
          this.showSuccess(modal, data.message);
        } else {
          const errorHtml = '<div class="alert alert-danger">' + '<p>' + (data.error || 'An error occurred') + '</p>' + '</div>';
          modal.setBody(errorHtml);
        }
      }).catch(error => {
        window.console.error('Error processing dates:', error);
        modal.setBody('<div class="alert alert-danger">An error occurred while processing dates</div>');
      });
    }
    setupDatesPreview(modal) {
      const modalRoot = modal.getRoot();
      let debounceTimer = null;
      const previewDates = () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          const body = modal.getBody();
          const bodyNode = body && body.length ? body.get(0) : null;
          if (!bodyNode) {
            return;
          }
          const excluded = [];
          bodyNode.querySelectorAll('.section-checkbox:not(:checked)').forEach(cb => {
            excluded.push(parseInt(cb.dataset.sectionId, 10));
          });
          const params = new URLSearchParams({
            courseid: this.courseid,
            excludedsections: JSON.stringify(excluded),
            includeparents: 1,
            sesskey: M.cfg.sesskey
          });
          fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/preview_section_dates.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: params.toString()
          }).then(response => response.json()).then(data => {
            if (!data.success || !data.sections) {
              return;
            }
            data.sections.forEach(section => {
              const cell = bodyNode.querySelector(`tr[data-section-id="${section.id}"] .date-prefix`);
              if (cell) {
                cell.textContent = section.formatted_date ? section.formatted_date + ' ' : '';
                return;
              }
              const label = bodyNode.querySelector(`label[for="section-${section.id}"] .date-prefix`);
              if (label) {
                label.textContent = section.formatted_date ? section.formatted_date + ' ' : '';
              }
            });
          }).catch(error => {
            window.console.error('Error previewing dates:', error);
          });
        }, 250);
      };
      modalRoot.on('change', '.section-checkbox', previewDates);
    }
    setupFormSubmission(modal, formName) {
      const modalRoot = modal.getRoot();
      let clickedButton = null;
      modalRoot.on('click', 'input[type="submit"], button[type="submit"], input[name="cancel"], button[name="cancel"]', function () {
        clickedButton = this.getAttribute('name');
      });
      modalRoot.on('click', '[data-form-button-index]', function () {
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
      modalRoot.on('submit', 'form', e => {
        e.preventDefault();
        const submitter = e.originalEvent?.submitter;
        const buttonName = submitter?.getAttribute('name') || clickedButton;
        if (buttonName === 'cancel') {
          modal.destroy();
          clickedButton = null;
          return;
        }
        clickedButton = null;
        const form = e.target;
        const formData = new FormData(form);
        if (formName === 'template_from_prompt') {
          this.handlePromptSubmission(modal, formData);
          return;
        }
        if (formName === 'dates_for_sections') {
          this.handleDatesForSectionsSubmission(modal, formData, buttonName);
          return;
        }
        const action = formName === 'add_theme' ? 'create_themes' : 'create_weeks';
        const params = {
          courseid: this.courseid,
          sesskey: M.cfg.sesskey,
          parentsection: this.currentsection
        };
        formData.forEach((value, key) => {
          if (!key.startsWith('_qf__') && key !== 'submitbutton' && key !== 'courseid' && key !== 'action') {
            params[key] = value;
          }
        });
        params.action = action;
        const body = modal.getBody();
        const bodyNode = body && body.length ? body.get(0) : null;
        if (bodyNode) {
          const buttons = bodyNode.querySelectorAll('input[type="submit"], button[type="submit"], input[name="cancel"], button[name="cancel"]');
          buttons.forEach(button => button.disabled = true);
        }
        fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/create_sections.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams(params)
        }).then(response => response.json()).then(data => {
          if (data.success) {
            modal.setBody('<div class="text-center p-5">' + '<div class="spinner-border" role="status">' + '<span class="sr-only">Loading...</span>' + '</div>' + '</div>');
            const details = data.messages && data.messages.length > 0 ? data.messages : [];
            this.showSuccess(modal, data.message, details);
          } else {
            const body = modal.getBody();
            const bodyNode = body && body.length ? body.get(0) : null;
            if (bodyNode) {
              const buttons = bodyNode.querySelectorAll('input[type="submit"], button[type="submit"], input[name="cancel"], button[name="cancel"]');
              buttons.forEach(button => button.disabled = false);
              const existingError = bodyNode.querySelector('.form-error-message');
              if (existingError) {
                existingError.remove();
              }
              const errorDiv = document.createElement('div');
              errorDiv.className = 'alert alert-danger form-error-message';
              errorDiv.innerHTML = '<p>' + (data.error || 'An error occurred') + '</p>';
              bodyNode.insertBefore(errorDiv, bodyNode.firstChild);
              bodyNode.scrollTop = 0;
            }
          }
          return data;
        }).catch(error => {
          const body = modal.getBody();
          const bodyNode = body && body.length ? body.get(0) : null;
          if (bodyNode) {
            const buttons = bodyNode.querySelectorAll('input[type="submit"], button[type="submit"], input[name="cancel"], button[name="cancel"]');
            buttons.forEach(button => button.disabled = false);
          }
          _notification.default.exception(error);
        });
      });
    }
    showGeneratorLink(title) {
      const promptUrl = M.cfg.wwwroot + '/ai/placement/modgen/prompt.php?id=' + this.courseid;
      const body = '<div class="text-center p-4">' + '<p>Click the button below to open the Module Generator form.</p>' + '<a href="' + promptUrl + '" class="btn btn-primary btn-lg">' + 'Open Module Generator' + '</a>' + '</div>';
      _modal.default.create({
        title: title,
        body: body,
        large: false
      }).then(modal => {
        this.modal = modal;
        this.modal.getRoot().on(_modal_events.default.hidden, () => {
          this.reactive.dispatch('closeModal');
          window.location.reload();
        });
        this.modal.show();
        return modal;
      }).catch(_notification.default.exception);
    }
    open() {
      this.reactive.dispatch('openModal');
    }
    openWithForm(formName, title) {
      this.reactive.dispatch('openModalWithForm', formName, title);
    }
    close() {
      this.reactive.dispatch('closeModal');
    }
  }
  const init = function (courseid, contextid) {
    let currentsection = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : 0;
    const component = new ModalGeneratorComponent({
      element: document.body,
      reactive: reactiveInstance,
      courseid,
      contextid,
      currentsection
    });
    return component;
  };
  _exports.init = init;
});
