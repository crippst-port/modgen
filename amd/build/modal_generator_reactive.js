define(["exports", "core/reactive", "core/event_dispatcher", "core/fragment", "aiplacement_modgen/modal", "core/notification", "core/modal_events"], function (_exports, _reactive, _event_dispatcher, _fragment, _modal, _notification, _modal_events) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = void 0;
  _fragment = _interopRequireDefault(_fragment);
  _modal = _interopRequireDefault(_modal);
  _notification = _interopRequireDefault(_notification);
  _modal_events = _interopRequireDefault(_modal_events);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  /**
   * Reactive modal generator component.
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
      stateManager.setReadOnly(true);
    }
    formLoaded(stateManager) {
      stateManager.setReadOnly(false);
      stateManager.state.modal.isLoading = false;
      stateManager.setReadOnly(true);
    }
  }
  const reactiveInstance = new _reactive.Reactive({
    name: 'ModalGenerator',
    eventName: eventTypes.stateChanged,
    eventDispatch: notifyStateChanged,
    state: {
      modal: {
        isOpen: false,
        isLoading: false,
        formName: null,
        title: ''
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
    loadFormInModal(formName, title) {
      _fragment.default.loadFragment('aiplacement_modgen', "form_".concat(formName), this.contextid, {
        courseid: this.courseid,
        contextid: this.contextid
      }).then(html => _modal.default.create({
        title: title,
        body: html,
        large: false
      })).then(modal => {
        this.modal = modal;
        this.modal.getRoot().on(_modal_events.default.hidden, () => {
          this.reactive.dispatch('closeModal');
        });
        this.setupFormSubmission(modal, formName);
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
      let footerHtml = '<div class="aiplacement-modgen-form-footer-buttons">';
      actionButtons.forEach((button, index) => {
        const label = button.tagName === 'INPUT' ? button.value || button.getAttribute('aria-label') || 'Submit' : button.textContent.trim();
        const classes = (button.className || 'btn btn-secondary').trim();
        footerHtml += "<button type=\"button\" class=\"".concat(classes, "\" data-form-button-index=\"").concat(index, "\">\n                ").concat(label, "\n            </button>");
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
        let current = btn.parentElement;
        while (current && current !== form) {
          const children = Array.from(current.children).filter(child => {
            return window.getComputedStyle(child).display !== 'none';
          });
          const onlyHasButtons = children.every(child => {
            const isButton = child.tagName === 'BUTTON' || child.tagName === 'INPUT' && (child.type === 'submit' || child.type === 'button');
            const isButtonContainer = child.classList.toString().includes('button') || child.classList.toString().includes('submit') || child.classList.toString().includes('action') || child.classList.toString().includes('form-');
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
    handlePromptSubmission(modal, formData) {
      modal.setBody('<div class="text-center p-5">' + '<div class="spinner-border" role="status">' + '<span class="sr-only">Loading...</span>' + '</div>' + '<p class="mt-2">Generating content... this may take a minute.</p>' + '</div>');
      formData.append('ajax', '1');
      formData.append('embedded', '1');
      formData.append('courseid', this.courseid);
      fetch(M.cfg.wwwroot + '/ai/placement/modgen/prompt.php', {
        method: 'POST',
        body: formData
      }).then(response => response.json()).then(data => {
        if (data.body) {
          modal.setBody(data.body);
          if (data.footer) {
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
        } else if (data.error) {
          modal.setBody('<div class="alert alert-danger">' + data.error + '</div>');
        }
      }).catch(error => {
        _notification.default.exception(error);
      });
    }
    setupFormSubmission(modal, formName) {
      const modalRoot = modal.getRoot();
      let clickedButton = null;
      modalRoot.on('click', 'input[type="submit"]', function () {
        clickedButton = this.getAttribute('name');
      });
      modalRoot.on('submit', 'form', e => {
        e.preventDefault();
        if (clickedButton === 'cancel') {
          modal.destroy();
          return;
        }
        const form = e.target;
        const formData = new FormData(form);
        if (formName === 'template_from_prompt') {
          this.handlePromptSubmission(modal, formData);
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
        modal.setBody('<div class="text-center p-5">' + '<div class="spinner-border" role="status">' + '<span class="sr-only">Loading...</span>' + '</div>' + '</div>');
        fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/create_sections.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams(params)
        }).then(response => response.json()).then(data => {
          if (data.success) {
            let successHtml = '<div class="alert alert-success">';
            successHtml += '<p>' + data.message + '</p>';
            if (data.messages && data.messages.length > 0) {
              successHtml += '<ul>';
              data.messages.forEach(msg => {
                successHtml += '<li>' + msg + '</li>';
              });
              successHtml += '</ul>';
            }
            successHtml += '<p class="mt-3">';
            successHtml += '<button type="button" class="btn btn-primary" id="reload-page-btn">';
            successHtml += 'Return to course';
            successHtml += '</button>';
            successHtml += '</p>';
            successHtml += '</div>';
            modal.setBody(successHtml);
            modal.getRoot().find('#reload-page-btn').on('click', () => {
              window.location.reload();
            });
          } else {
            const errorHtml = '<div class="alert alert-danger">' + '<p>' + (data.error || 'An error occurred') + '</p>' + '</div>';
            modal.setBody(errorHtml);
          }
          return data;
        }).catch(error => {
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
