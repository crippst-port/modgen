define(["exports", "core/ajax", "core/notification", "core/str"], function (_exports, _ajax, _notification, _str) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = void 0;
  _ajax = _interopRequireDefault(_ajax);
  _notification = _interopRequireDefault(_notification);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  /**
   * Job status page JavaScript - polls and updates job progress display.
   *
   * @module     aiplacement_modgen/job_status_page
   * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
   * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
   */
  const POLL_INTERVAL = 3000;
  const REDIRECT_DELAY = 3000;
  let pollInterval = null;
  let config = {};
  const updateStatusDisplay = job => {
    const container = document.getElementById('job-status-container');
    const statusBadge = document.getElementById('status-badge');
    const returnButtonContainer = document.getElementById('return-button-container');
    if (!container) {
      return;
    }
    if (statusBadge) {
      statusBadge.textContent = job.status;
      statusBadge.className = 'badge badge-' + getStatusColor(job.status);
    }
    let html = '';
    if (job.status === 'queued') {
      html = "\n            <div class=\"alert alert-info d-flex align-items-center\">\n                <div class=\"spinner-border text-info me-3\" role=\"status\">\n                    <span class=\"sr-only\">Loading...</span>\n                </div>\n                <div>\n                    <strong>Queued</strong>\n                    <p class=\"mb-0 small\">Your job is waiting to be processed...</p>\n                    <p class=\"mb-0 small text-muted\"><em>This page will automatically redirect back to the course when creation is complete.</em></p>\n                </div>\n            </div>\n        ";
      if (returnButtonContainer) {
        returnButtonContainer.style.display = 'none';
      }
    } else if (job.status === 'running') {
      html = "\n            <div class=\"alert alert-primary d-flex align-items-center\">\n                <div class=\"spinner-border text-primary me-3\" role=\"status\">\n                    <span class=\"sr-only\">Loading...</span>\n                </div>\n                <div>\n                    <strong>Creating sections...</strong>\n                    <p class=\"mb-0 small\">Please wait while your course structure is being created.</p>\n                    <p class=\"mb-0 small text-muted\"><em>This page will automatically redirect back to the course when creation is complete.</em></p>\n                </div>\n            </div>\n        ";
      if (returnButtonContainer) {
        returnButtonContainer.style.display = 'none';
      }
    } else if (job.status === 'completed') {
      const result = job.result || {};
      const messages = result.messages || [];
      let messagesList = '';
      messages.forEach(msg => {
        messagesList += "<p class=\"mb-1\"><i class=\"fa fa-check text-success\"></i> ".concat(msg, "</p>");
      });
      html = "\n            <div class=\"alert alert-success\">\n                <h4 class=\"alert-heading\">\n                    <i class=\"fa fa-check-circle\"></i>\n                    Completed successfully!\n                </h4>\n                ".concat(messagesList, "\n                <hr>\n                <p class=\"mb-0\">Redirecting to your course in a few seconds...</p>\n            </div>\n        ");
      if (returnButtonContainer) {
        returnButtonContainer.style.display = 'block';
      } else {
        const buttonHtml = "\n                <div class=\"mt-4\" id=\"return-button-container\">\n                    <a href=\"".concat(config.courseurl, "\" class=\"btn btn-primary\">\n                        <i class=\"fa fa-arrow-left\"></i>\n                        Return to course home\n                    </a>\n                </div>\n            ");
        container.insertAdjacentHTML('afterend', buttonHtml);
      }
      stopPolling();
      setTimeout(() => {
        window.location.href = config.courseurl;
      }, REDIRECT_DELAY);
    } else if (job.status === 'failed') {
      const result = job.result || {};
      const error = result.error || 'Unknown error occurred';
      const willRetry = result.will_retry || false;
      let retryHtml = '';
      if (willRetry) {
        retryHtml = "\n                <hr>\n                <p class=\"mb-0\">\n                    <i class=\"fa fa-refresh\"></i>\n                    This job will automatically retry in a moment...\n                </p>\n            ";
      } else {
        stopPolling();
      }
      html = "\n            <div class=\"alert alert-danger\">\n                <h4 class=\"alert-heading\">\n                    <i class=\"fa fa-exclamation-triangle\"></i>\n                    Job failed\n                </h4>\n                <p><strong>Error:</strong> ".concat(error, "</p>\n                ").concat(retryHtml, "\n            </div>\n        ");
      if (returnButtonContainer) {
        returnButtonContainer.style.display = 'none';
      }
    }
    container.innerHTML = html;
  };
  const getStatusColor = status => {
    switch (status) {
      case 'queued':
        return 'info';
      case 'running':
        return 'primary';
      case 'completed':
        return 'success';
      case 'failed':
        return 'danger';
      default:
        return 'secondary';
    }
  };
  const pollJobStatus = () => {
    const url = M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php?sesskey=' + M.cfg.sesskey + '&jobid=' + config.jobid;
    fetch(url).then(response => {
      return response.json();
    }).then(data => {
      if (data.success && data.status) {
        updateStatusDisplay(data);
        if (data.status === 'completed') {
          stopPolling();
          setTimeout(() => {
            window.location.href = config.courseurl;
          }, REDIRECT_DELAY);
        } else if (data.status === 'failed' && !data.will_retry) {
          stopPolling();
        }
      }
    }).catch(error => {
      console.error('[Job Status Page] Fetch error:', error);
    });
  };
  const startPolling = () => {
    pollJobStatus();
    pollInterval = setInterval(pollJobStatus, POLL_INTERVAL);
  };
  const stopPolling = () => {
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = null;
    }
  };
  const init = cfg => {
    config = cfg;
    if (cfg.initialstatus === 'completed') {
      setTimeout(() => {
        window.location.href = config.courseurl;
      }, REDIRECT_DELAY);
    } else if (cfg.initialstatus === 'failed') {
      const result = document.querySelector('[data-jobid]');
      if (result && result.dataset.willretry) {
        startPolling();
      }
    } else {
      startPolling();
    }
    window.addEventListener('beforeunload', stopPolling);
  };
  _exports.init = init;
});
