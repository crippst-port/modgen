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
    if (!loadingStates.has(container)) {
      loadingStates.set(container, {
        originalContent: container.innerHTML,
        originalAriaLive: container.getAttribute('aria-live'),
        originalAriaBusy: container.getAttribute('aria-busy')
      });
    }
    container.setAttribute('aria-busy', 'true');
    const {
      html
    } = await _templates.default.renderForPromise('aiplacement_modgen/loading_indicator', config);
    container.innerHTML = html;
    const loadingElement = container.querySelector('.modgen-loading');
    if (loadingElement) {
      loadingElement.setAttribute('tabindex', '-1');
      loadingElement.focus();
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
      container.innerHTML = state.originalContent;
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
