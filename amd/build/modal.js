define(["exports", "core/modal"], function (_exports, _modal) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.default = void 0;
  _modal = _interopRequireDefault(_modal);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  /**
   * Custom modal class for the Module Generator workflow.
   *
   * @module     aiplacement_modgen/modal
   * @copyright  2025
   * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
   */
  class ModgenModal extends _modal.default {
    static get TYPE() {
      return 'aiplacement_modgen/modal';
    }
    static get TEMPLATE() {
      return 'aiplacement_modgen/modal';
    }
    configure(modalConfig) {
      modalConfig.large = true;
      if (typeof modalConfig.removeOnClose === 'undefined') {
        modalConfig.removeOnClose = false;
      }
      super.configure(modalConfig);
    }
  }
  _exports.default = ModgenModal;
});
