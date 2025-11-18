define(["exports", "core/reactive", "core/event_dispatcher", "aiplacement_modgen/modal", "core/notification", "core/modal_events"], function (_exports, _reactive, _event_dispatcher, _modal, _notification, _modal_events) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = void 0;
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
      const promptUrl = M.cfg.wwwroot + '/ai/placement/modgen/prompt.php?id=' + this.courseid;
      const body = '<div class="text-center p-4">' + '<p>Click the button below to open the Module Generator form.</p>' + '<a href="' + promptUrl + '" class="btn btn-primary btn-lg">' + 'Open Module Generator' + '</a>' + '</div>';
      _modal.default.create({
        title: 'Module Generator',
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
