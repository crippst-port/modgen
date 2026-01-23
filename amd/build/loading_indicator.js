define(["exports", "core/templates", "core/str"], function (_exports, _templates, _str) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.updateInModal = _exports.update = _exports.showInModal = _exports.show = _exports.hideFromModal = _exports.hide = void 0;
  _templates = _interopRequireDefault(_templates);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
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
  const loadingStates = new WeakMap();
  const show = async function (container, message) {
    let options = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : {};
    if (!container) {
      return;
    }
    const config = {
      showProgress: options.showProgress || false,
      progress: options.progress || 0,
      size: options.size || 'medium',
      message: message || (await (0, _str.get_string)('processingrequest', 'aiplacement_modgen'))
    };
    if (options.overlay) {
      if (!loadingStates.has(container)) {
        loadingStates.set(container, {
          isOverlay: true,
          originalPosition: container.style.position,
          originalAriaLive: container.getAttribute('aria-live'),
          originalAriaBusy: container.getAttribute('aria-busy')
        });
      }
      container.setAttribute('aria-busy', 'true');
      if (!container.style.position || container.style.position === 'static') {
        container.style.position = 'relative';
      }
      let overlayElement = container.querySelector('.modgen-loading-overlay');
      if (!overlayElement) {
        const {
          html
        } = await _templates.default.renderForPromise('aiplacement_modgen/loading_indicator', config);
        overlayElement = document.createElement('div');
        overlayElement.className = 'modgen-loading-overlay';
        overlayElement.style.cssText = 'position: absolute; top: 0; left: 0; right: 0; bottom: 0; ' + 'background: rgba(255, 255, 255, 0.9); z-index: 1000; ' + 'display: flex; align-items: center; justify-content: center;';
        overlayElement.innerHTML = html;
        container.appendChild(overlayElement);
      } else {
        const {
          html
        } = await _templates.default.renderForPromise('aiplacement_modgen/loading_indicator', config);
        overlayElement.innerHTML = html;
      }
      const loadingElement = overlayElement.querySelector('.modgen-loading');
      if (loadingElement) {
        loadingElement.setAttribute('tabindex', '-1');
        loadingElement.focus({
          preventScroll: true
        });
      }
    } else {
      if (!loadingStates.has(container)) {
        loadingStates.set(container, {
          isOverlay: false,
          originalContent: container.innerHTML,
          originalAriaLive: container.getAttribute('aria-live'),
          originalAriaBusy: container.getAttribute('aria-busy')
        });
      }
      container.setAttribute('aria-busy', 'true');
      const {
        html
      } = await _templates.default.renderForPromise('aiplacement_modgen/loading_indicator', config);
      const doc = document.createElement('div');
      doc.innerHTML = html;
      container.innerHTML = '';
      while (doc.firstChild) {
        container.appendChild(doc.firstChild);
      }
      const loadingElement = container.querySelector('.modgen-loading');
      if (loadingElement) {
        loadingElement.setAttribute('tabindex', '-1');
        loadingElement.focus();
      }
    }
  };
  _exports.show = show;
  const update = function (container) {
    let message = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : null;
    let progress = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : null;
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
      progressBar.style.width = "".concat(Math.min(100, Math.max(0, progress)), "%");
      progressBar.setAttribute('aria-valuenow', progress);
    }
  };
  _exports.update = update;
  const hide = container => {
    if (!container) {
      return;
    }
    const state = loadingStates.get(container);
    if (state) {
      if (state.isOverlay) {
        const overlayElement = container.querySelector('.modgen-loading-overlay');
        if (overlayElement) {
          overlayElement.remove();
        }
        if (state.originalPosition) {
          container.style.position = state.originalPosition;
        } else {
          container.style.position = '';
        }
      } else {
        container.innerHTML = state.originalContent;
      }
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
      loadingStates.delete(container);
    } else {
      container.removeAttribute('aria-busy');
      const overlayElement = container.querySelector('.modgen-loading-overlay');
      if (overlayElement) {
        overlayElement.remove();
      }
    }
  };
  _exports.hide = hide;
  const showInModal = async function (modal, message) {
    let options = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : {};
    if (!modal || !modal.getBody) {
      return;
    }
    const bodyElement = modal.getBody()[0];
    if (!bodyElement) {
      return;
    }
    if (modal.setFooter) {
      modal.setFooter('');
    }
    await show(bodyElement, message, options);
  };
  _exports.showInModal = showInModal;
  const hideFromModal = modal => {
    if (!modal || !modal.getBody) {
      return;
    }
    const bodyElement = modal.getBody()[0];
    if (bodyElement) {
      hide(bodyElement);
    }
  };
  _exports.hideFromModal = hideFromModal;
  const updateInModal = function (modal) {
    let message = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : null;
    let progress = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : null;
    if (!modal || !modal.getBody) {
      return;
    }
    const bodyElement = modal.getBody()[0];
    if (bodyElement) {
      update(bodyElement, message, progress);
    }
  };
  _exports.updateInModal = updateInModal;
});
