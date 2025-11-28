define(["exports", "core/fragment", "core/notification", "aiplacement_modgen/modal_generator_reactive"], function (_exports, _fragment, _notification, _modal_generator_reactive) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = void 0;
  _fragment = _interopRequireDefault(_fragment);
  _notification = _interopRequireDefault(_notification);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  /**
   * Course navigation toolbar - uses Fragment API and Reactive Modal.
   *
   * @module     aiplacement_modgen/course_toolbar
   * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
   * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
   */
  let modalComponent = null;
  const init = config => {
    modalComponent = (0, _modal_generator_reactive.init)(config.courseid, config.contextid, config.currentsection || 0);
    _fragment.default.loadFragment('aiplacement_modgen', 'course_toolbar', config.contextid, {
      courseid: config.courseid,
      showgenerator: config.showgenerator ? 1 : 0,
      showexplore: config.showexplore ? 1 : 0,
      showsuggest: config.showsuggest ? 1 : 0,
      currentsection: config.currentsection
    }).then(html => {
      const regionMain = document.querySelector('#region-main');
      if (regionMain) {
        regionMain.insertAdjacentHTML('afterbegin', html);
        const collapseToggle = document.querySelector('.navbar-toggler');
        const collapseTarget = document.querySelector('#aimodgenNavbar');
        if (collapseToggle && collapseTarget && window.$ && window.$.fn && window.$.fn.collapse) {
          window.$(collapseTarget).collapse({
            toggle: false
          });
          collapseToggle.addEventListener('click', () => {
            window.$(collapseTarget).collapse('toggle');
          });
        }
        setupQuickAddButtons();
        if (config.showgenerator) {
          setupGeneratorButton(config.courseid);
        }
      }
      return html;
    }).catch(_notification.default.exception);
  };
  _exports.init = init;
  const setupQuickAddButtons = () => {
    const quickAddButtons = document.querySelectorAll('[data-action="quick-add"]');
    quickAddButtons.forEach(button => {
      button.addEventListener('click', e => {
        e.preventDefault();
        const formName = button.getAttribute('data-form');
        const title = button.getAttribute('data-title');
        if (formName && title && modalComponent) {
          modalComponent.openWithForm(formName, title);
        }
      });
    });
  };
  const setupGeneratorButton = courseid => {
    const generatorBtn = document.querySelector('[data-action="open-generator"]');
    if (!generatorBtn) {
      return;
    }
    generatorBtn.addEventListener('click', e => {
      e.preventDefault();
      window.location.href = M.cfg.wwwroot + '/ai/placement/modgen/prompt.php?id=' + courseid;
    });
  };
});
