define([], function () {
  "use strict";

  /**
   * Embedded results helpers for closing the parent modal.
   *
   * @module     aiplacement_modgen/embedded_results
   * @copyright  2025
   * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
   */
  define([], function () {
    const READY_MESSAGE = 'aiplacement_modgen_ready';
    const CLOSE_MESSAGE = 'aiplacement_modgen_close';
    const REFRESH_MESSAGE = 'aiplacement_modgen_refresh';
    const notifyParent = event => {
      event.preventDefault();
      try {
        window.parent.postMessage({
          type: REFRESH_MESSAGE
        }, window.location.origin);
        window.parent.postMessage({
          type: CLOSE_MESSAGE
        }, window.location.origin);
      } catch (error) {
        console.error('Failed to notify parent window', error);
      }
    };
    const init = () => {
      try {
        window.parent.postMessage({
          type: READY_MESSAGE
        }, window.location.origin);
      } catch (error) {
        console.error('Failed to notify parent window about readiness', error);
      }
      const button = document.querySelector('[data-action="aiplacement-modgen-close"]');
      if (!button) {
        return;
      }
      button.addEventListener('click', notifyParent);
    };
    return {
      init
    };
  });
});
