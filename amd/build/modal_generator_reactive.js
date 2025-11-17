define(["exports", "core/reactive", "core/event_dispatcher", "core/templates", "aiplacement_modgen/modal", "core/notification", "core/modal_events"], function (_exports, _reactive, _event_dispatcher, _templates, _modal, _notification, _modal_events) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = void 0;
  _templates = _interopRequireDefault(_templates);
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
    openModal(stateManager) {
      stateManager.setReadOnly(false);
      stateManager.state.modal.isOpen = true;
      stateManager.state.modal.isLoading = true;
      stateManager.setReadOnly(true);
    }
    closeModal(stateManager) {
      stateManager.setReadOnly(false);
      stateManager.state.modal.isOpen = false;
      stateManager.state.modal.isLoading = false;
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
        isLoading: false
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
      _modal.default.create({
        title: 'Module Generator',
        body: '<div class="d-flex justify-content-center p-5"><div class="spinner-border" role="status">' + '<span class="sr-only">Loading...</span></div></div>',
        large: true
      }).then(modal => {
        this.modal = modal;
        this.modal.getRoot().on(_modal_events.default.hidden, () => {
          this.reactive.dispatch('closeModal');
        });
        this.modal.show();
        this.loadForm();
        return modal;
      }).catch(_notification.default.exception);
    }
    loadForm() {
      const url = M.cfg.wwwroot + '/ai/placement/modgen/generate.php';
      const params = new URLSearchParams({
        courseid: this.courseid,
        sesskey: M.cfg.sesskey
      });
      fetch(url + '?' + params.toString(), {
        method: 'GET',
        headers: {
          'Accept': 'application/json'
        }
      }).then(response => {
        if (!response.ok) {
          throw new Error('HTTP error ' + response.status);
        }
        return response.json();
      }).then(data => {
        if (!data.success) {
          let errorHtml = '<div class="alert alert-danger"><h4>Error loading form</h4>';
          errorHtml += '<p><strong>Error:</strong> ' + (data.error || 'Unknown error') + '</p>';
          if (data.file) {
            errorHtml += '<p><strong>File:</strong> ' + data.file + ':' + data.line + '</p>';
          }
          if (data.trace) {
            errorHtml += '<details><summary>Stack Trace</summary><pre>' + (Array.isArray(data.trace) ? data.trace.join('\n') : data.trace) + '</pre></details>';
          }
          errorHtml += '</div>';
          this.modal.setBody(errorHtml);
          console.error('Form load error:', data);
          return;
        }
        if (!data.html) {
          throw new Error('No HTML returned from server');
        }
        const modalBody = this.modal.getBody()[0];
        _templates.default.replaceNodeContents(modalBody, data.html, data.javascript || '');
        return new Promise(resolve => {
          setTimeout(() => {
            this.reactive.dispatch('formLoaded');
            resolve();
          }, 200);
        });
      }).catch(error => {
        this.modal.setBody('<div class="alert alert-danger">Error loading form: ' + error.message + '</div>');
        _notification.default.exception(error);
      });
    }
    open() {
      this.reactive.dispatch('openModal');
    }
    close() {
      this.reactive.dispatch('closeModal');
    }
  }
  const init = (courseid, contextid) => {
    const component = new ModalGeneratorComponent({
      element: document.body,
      reactive: reactiveInstance,
      courseid,
      contextid
    });
    return component;
  };
  _exports.init = init;
});
