define(["exports", "core/fragment", "aiplacement_modgen/modal_generator_reactive", "core/notification"], function (_exports, _fragment, ModalGenerator, _notification) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = void 0;
  _fragment = _interopRequireDefault(_fragment);
  ModalGenerator = _interopRequireWildcard(ModalGenerator);
  _notification = _interopRequireDefault(_notification);
  function _interopRequireWildcard(e, t) { if ("function" == typeof WeakMap) var r = new WeakMap(), n = new WeakMap(); return (_interopRequireWildcard = function (e, t) { if (!t && e && e.__esModule) return e; var o, i, f = { __proto__: null, default: e }; if (null === e || "object" != typeof e && "function" != typeof e) return f; if (o = t ? n : r) { if (o.has(e)) return o.get(e); o.set(e, f); } for (const t in e) "default" !== t && {}.hasOwnProperty.call(e, t) && ((i = (o = Object.defineProperty) && Object.getOwnPropertyDescriptor(e, t)) && (i.get || i.set) ? o(f, t, i) : f[t] = e[t]); return f; })(e, t); }
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
    _fragment.default.loadFragment('aiplacement_modgen', 'course_toolbar', config.contextid, {
      courseid: config.courseid,
      showgenerator: config.showgenerator ? 1 : 0,
      showexplore: config.showexplore ? 1 : 0
    }).then(html => {
      const regionMain = document.querySelector('#region-main');
      if (regionMain) {
        regionMain.insertAdjacentHTML('afterbegin', html);
        if (config.showgenerator) {
          setupGeneratorButton(config.courseid, config.contextid);
        }
      }
      return html;
    }).catch(_notification.default.exception);
  };
  _exports.init = init;
  const setupGeneratorButton = (courseid, contextid) => {
    const generatorBtn = document.querySelector('[data-action="open-generator"]');
    if (!generatorBtn) {
      return;
    }
    generatorBtn.addEventListener('click', e => {
      e.preventDefault();
      if (!modalComponent) {
        modalComponent = ModalGenerator.init(courseid, contextid);
      }
      modalComponent.open();
    });
  };
});
