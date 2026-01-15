define(["exports", "core/config", "core/str"], function (_exports, _config, _str) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = void 0;
  _config = _interopRequireDefault(_config);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  /**
   * AI-generated activity list page functionality.
   *
   * @module     aiplacement_modgen/aigen_list
   * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
   * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
   */const toggleVisibility = async button => {
    const cmid = button.dataset.cmid;
    const currentlyVisible = button.dataset.visible === '1';
    const newVisible = currentlyVisible ? 0 : 1;
    button.disabled = true;
    const icon = button.querySelector('i');
    const originalIconClass = icon.className;
    icon.className = 'fa fa-spinner fa-spin';
    try {
      const response = await fetch(`${_config.default.wwwroot}/ai/placement/modgen/ajax/toggle_visibility.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
          cmid: cmid,
          visible: newVisible,
          sesskey: _config.default.sesskey
        })
      });
      const data = await response.json();
      if (data.success) {
        const row = button.closest('tr');
        const statusCell = row.querySelector('.visibility-status');
        const activityName = row.querySelector('.activity-name');
        if (newVisible) {
          button.dataset.visible = '1';
          button.title = await (0, _str.get_string)('aigen_hide_activity', 'aiplacement_modgen');
          icon.className = 'fa fa-eye-slash';
          statusCell.innerHTML = `<span class="badge badge-success">${await (0, _str.get_string)('visible')}</span>`;
          activityName.classList.remove('text-muted');
        } else {
          button.dataset.visible = '0';
          button.title = await (0, _str.get_string)('aigen_show_activity', 'aiplacement_modgen');
          icon.className = 'fa fa-eye';
          statusCell.innerHTML = `<span class="badge badge-secondary">${await (0, _str.get_string)('hidden', 'aiplacement_modgen')}</span>`;
          activityName.classList.add('text-muted');
        }
      } else {
        icon.className = originalIconClass;
      }
    } catch (error) {
      console.error('Error toggling visibility:', error);
      icon.className = originalIconClass;
    } finally {
      button.disabled = false;
    }
  };
  const init = () => {
    document.querySelectorAll('.visibility-toggle').forEach(button => {
      button.addEventListener('click', e => {
        e.preventDefault();
        toggleVisibility(button);
      });
    });
  };
  _exports.init = init;
});
