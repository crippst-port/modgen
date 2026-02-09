define(["exports", "core/fragment", "core/notification", "aiplacement_modgen/modal_generator_reactive", "core/str"], function (_exports, _fragment, _notification, _modal_generator_reactive, _str) {
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
  const checkCompletedJobs = courseid => {
    const notifiedJobs = JSON.parse(sessionStorage.getItem('modgen_notified_jobs') || '[]');
    fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php?' + new URLSearchParams({
      courseid: courseid,
      recent: 1,
      sesskey: M.cfg.sesskey
    })).then(response => response.json()).then(data => {
      if (data.success && data.jobs && data.jobs.length > 0) {
        let hasNewCompletions = false;
        data.jobs.forEach(job => {
          if (notifiedJobs.includes(job.id)) {
            return;
          }
          if (job.status === 'completed') {
            const result = job.result || {};
            const message = result.message || 'Background job completed successfully';
            _notification.default.addNotification({
              message: message,
              type: 'success'
            });
            hasNewCompletions = true;
            notifiedJobs.push(job.id);
          } else if (job.status === 'failed') {
            const result = job.result || {};
            _notification.default.addNotification({
              message: 'Background job failed: ' + (result.error || 'Unknown error'),
              type: 'error'
            });
            notifiedJobs.push(job.id);
          }
        });
        sessionStorage.setItem('modgen_notified_jobs', JSON.stringify(notifiedJobs));
        if (hasNewCompletions) {
          setTimeout(() => window.location.reload(), 2000);
        }
      }
    }).catch(() => {});
  };
  const init = config => {
    if (!config.courseid || !config.contextid) {
      console.error('course_toolbar.init called with invalid config:', config);
      return;
    }
    checkCompletedJobs(config.courseid);
    modalComponent = (0, _modal_generator_reactive.init)(config.courseid, config.contextid, config.currentsection || 0);
    _fragment.default.loadFragment('aiplacement_modgen', 'course_toolbar', config.contextid, {
      courseid: config.courseid,
      contextid: config.contextid,
      showgenerator: config.showgenerator ? 1 : 0,
      showexplore: config.showexplore ? 1 : 0,
      showsuggest: config.showsuggest ? 1 : 0,
      showmanagestructure: config.showmanagestructure ? 1 : 0,
      showmanagedates: config.showmanagedates ? 1 : 0,
      showtemplatefromfile: config.showtemplatefromfile ? 1 : 0,
      showtemplatefromptompt: config.showtemplatefromptompt ? 1 : 0,
      currentsection: config.currentsection || 0
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
