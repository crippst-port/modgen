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
      html = `
            <div class="alert alert-info d-flex align-items-center">
                <div class="spinner-border text-info me-3" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
                <div>
                    <strong>Queued</strong>
                    <p class="mb-0 small">Your job is waiting to be processed...</p>
                    <p class="mb-0 small text-muted"><em>This page will automatically redirect back to the course when creation is complete.</em></p>
                </div>
            </div>
        `;
      if (returnButtonContainer) {
        returnButtonContainer.style.display = 'none';
      }
    } else if (job.status === 'running') {
      html = `
            <div class="alert alert-primary d-flex align-items-center">
                <div class="spinner-border text-primary me-3" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
                <div>
                    <strong>Creating sections...</strong>
                    <p class="mb-0 small">Please wait while your course structure is being created.</p>
                    <p class="mb-0 small text-muted"><em>This page will automatically redirect back to the course when creation is complete.</em></p>
                </div>
            </div>
        `;
      if (returnButtonContainer) {
        returnButtonContainer.style.display = 'none';
      }
    } else if (job.status === 'completed') {
      const result = job.result || {};
      const messages = result.messages || [];
      let messagesList = '';
      messages.forEach(msg => {
        messagesList += `<p class="mb-1"><i class="fa fa-check text-success"></i> ${msg}</p>`;
      });
      html = `
            <div class="alert alert-success">
                <h4 class="alert-heading">
                    <i class="fa fa-check-circle"></i>
                    Completed successfully!
                </h4>
                ${messagesList}
                <hr>
                <p class="mb-0">Redirecting to your course in a few seconds...</p>
            </div>
        `;
      if (returnButtonContainer) {
        returnButtonContainer.style.display = 'block';
      } else {
        const buttonHtml = `
                <div class="mt-4" id="return-button-container">
                    <a href="${config.courseurl}" class="btn btn-primary">
                        <i class="fa fa-arrow-left"></i>
                        Return to course home
                    </a>
                </div>
            `;
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
        retryHtml = `
                <hr>
                <p class="mb-0">
                    <i class="fa fa-refresh"></i>
                    This job will automatically retry in a moment...
                </p>
            `;
      } else {
        stopPolling();
      }
      html = `
            <div class="alert alert-danger">
                <h4 class="alert-heading">
                    <i class="fa fa-exclamation-triangle"></i>
                    Job failed
                </h4>
                <p><strong>Error:</strong> ${error}</p>
                ${retryHtml}
            </div>
        `;
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
    console.log('[Job Status Page] Polling job', config.jobid);
    const url = M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php?sesskey=' + M.cfg.sesskey + '&jobid=' + config.jobid;
    console.log('[Job Status Page] Fetching:', url);
    fetch(url).then(response => {
      console.log('[Job Status Page] Response received:', response.status);
      return response.json();
    }).then(data => {
      console.log('[Job Status Page] Data:', data);
      if (data.success && data.status) {
        updateStatusDisplay(data);
        if (data.status === 'completed') {
          stopPolling();
          setTimeout(() => {
            console.log('[Job Status Page] Redirecting to:', config.courseurl);
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
    console.log('[Job Status Page] Starting polling with interval:', POLL_INTERVAL);
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
    console.log('[Job Status Page] Initializing with config:', cfg);
    config = cfg;
    if (cfg.initialstatus === 'completed') {
      console.log('[Job Status Page] Job already completed, will redirect');
      setTimeout(() => {
        window.location.href = config.courseurl;
      }, REDIRECT_DELAY);
    } else if (cfg.initialstatus === 'failed') {
      console.log('[Job Status Page] Job failed, checking if will retry');
      const result = document.querySelector('[data-jobid]');
      if (result && result.dataset.willretry) {
        startPolling();
      }
    } else {
      console.log('[Job Status Page] Job', cfg.initialstatus, '- starting polling');
      startPolling();
    }
    window.addEventListener('beforeunload', stopPolling);
  };
  _exports.init = init;
});
