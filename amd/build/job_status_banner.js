define(["exports", "core/templates", "core/str"], function (_exports, _templates, _str) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = void 0;
  _templates = _interopRequireDefault(_templates);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  /**
   * Job status banner component for tracking background section creation tasks.
   *
   * This module displays a persistent banner below the assistant toolbar that tracks
   * background job status. Features:
   * - Polls active/queued jobs every 3 seconds
   * - Shows count if multiple jobs running
   * - Displays elapsed time and spinner for in-progress jobs
   * - Shows success state with reload button when complete
   * - Remains visible until user dismisses
   * - Persists across page reloads until job completes
   *
   * @module     aiplacement_modgen/job_status_banner
   * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
   * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
   */
  let config = null;
  let pollInterval = null;
  const jobStartTimes = new Map();
  let dismissedJobs = [];
  const POLL_INTERVAL_MS = 3000;
  const init = cfg => {
    config = cfg;
    const dismissed = sessionStorage.getItem('modgen_dismissed_jobs');
    if (dismissed) {
      try {
        const parsed = JSON.parse(dismissed);
        dismissedJobs = Array.isArray(parsed) ? parsed.slice(-100) : [];
      } catch (error) {
        console.error('[ModGen Banner] Error parsing dismissed jobs:', error);
        dismissedJobs = [];
        sessionStorage.removeItem('modgen_dismissed_jobs');
      }
    }
    checkForActiveJobs();
  };
  _exports.init = init;
  const checkForActiveJobs = () => {
    fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: new URLSearchParams({
        courseid: config.courseid,
        active: 1,
        sesskey: M.cfg.sesskey
      })
    }).then(response => response.json()).then(data => {
      if (data.success && data.jobs && data.jobs.length > 0) {
        const activeJobs = data.jobs.filter(job => !dismissedJobs.includes(job.id));
        if (activeJobs.length > 0) {
          activeJobs.forEach(job => {
            if (!jobStartTimes.has(job.id)) {
              jobStartTimes.set(job.id, Date.now());
            }
          });
          renderBanner(activeJobs);
          startPolling(activeJobs.map(job => job.id));
        }
      }
    }).catch(error => {
      console.error('[ModGen Banner] Error checking for active jobs:', error);
    });
  };
  const renderBanner = async jobs => {
    const jobCount = jobs.length;
    const singlejob = jobCount === 1;
    const job = jobs[0];
    try {
      let message = '';
      if (singlejob) {
        if (job.status === 'queued') {
          message = await (0, _str.get_string)('jobqueued_single', 'aiplacement_modgen');
        } else {
          message = await (0, _str.get_string)('jobrunning_single', 'aiplacement_modgen');
        }
      } else {
        if (job.status === 'queued') {
          message = await (0, _str.get_string)('jobsqueued_multiple', 'aiplacement_modgen', jobCount);
        } else {
          message = await (0, _str.get_string)('jobsrunning_multiple', 'aiplacement_modgen', jobCount);
        }
      }
      let elapsed = 0;
      if (jobStartTimes.has(job.id)) {
        elapsed = Math.floor((Date.now() - jobStartTimes.get(job.id)) / 1000);
      }
      const elapsedStr = elapsed > 0 ? formatElapsedTime(elapsed) : '';
      const templateData = {
        jobcount: jobCount,
        singlejob: singlejob,
        status: job.status,
        message: message,
        elapsed: elapsedStr,
        alerttype: 'info',
        showspinner: true,
        showreload: false,
        showdismiss: true,
        courseid: config.courseid,
        wwwroot: M.cfg.wwwroot
      };
      const {
        html
      } = await _templates.default.renderForPromise('aiplacement_modgen/job_status_banner', templateData);
      let banner = document.getElementById('modgen-job-banner');
      if (!banner) {
        const toolbar = document.querySelector('.aiplacement-modgen-navbar');
        if (toolbar) {
          toolbar.insertAdjacentHTML('afterend', html);
        } else {
          const courseContent = document.querySelector('#page-content') || document.querySelector('[role="main"]') || document.querySelector('.course-content');
          if (courseContent) {
            courseContent.insertAdjacentHTML('afterbegin', html);
          } else {
            console.warn('[ModGen Banner] No suitable insertion point found');
            return;
          }
        }
        banner = document.getElementById('modgen-job-banner');
        if (banner) {
          attachBannerHandlers(banner);
        }
      } else {
        banner.outerHTML = html;
        banner = document.getElementById('modgen-job-banner');
        attachBannerHandlers(banner);
      }
    } catch (error) {
      console.error('[ModGen Banner] Error rendering banner:', error);
      stopPolling();
    }
  };
  const renderCompletedBanner = async completedJobs => {
    const jobCount = completedJobs.length;
    const failedJobs = completedJobs.filter(job => job.status === 'failed');
    const hasFailures = failedJobs.length > 0;
    try {
      let message = '';
      if (hasFailures) {
        if (jobCount === 1) {
          message = await (0, _str.get_string)('jobfailed_single', 'aiplacement_modgen');
        } else {
          message = await (0, _str.get_string)('jobsfailed_multiple', 'aiplacement_modgen', failedJobs.length);
        }
      } else {
        if (jobCount === 1) {
          message = await (0, _str.get_string)('jobcompleted_single', 'aiplacement_modgen');
        } else {
          message = await (0, _str.get_string)('jobscompleted_multiple', 'aiplacement_modgen', jobCount);
        }
      }
      const templateData = {
        jobcount: jobCount,
        singlejob: jobCount === 1,
        status: hasFailures ? 'failed' : 'completed',
        message: message,
        elapsed: '',
        alerttype: hasFailures ? 'danger' : 'success',
        showspinner: false,
        showreload: true,
        showdismiss: true,
        courseid: config.courseid,
        wwwroot: M.cfg.wwwroot
      };
      const {
        html
      } = await _templates.default.renderForPromise('aiplacement_modgen/job_status_banner', templateData);
      let banner = document.getElementById('modgen-job-banner');
      if (banner) {
        banner.outerHTML = html;
        banner = document.getElementById('modgen-job-banner');
        attachBannerHandlers(banner);
      } else {
        const toolbar = document.querySelector('.aiplacement-modgen-navbar');
        if (toolbar) {
          toolbar.insertAdjacentHTML('afterend', html);
        } else {
          const courseContent = document.querySelector('#page-content') || document.querySelector('[role="main"]') || document.querySelector('.course-content');
          if (courseContent) {
            courseContent.insertAdjacentHTML('afterbegin', html);
          }
        }
        banner = document.getElementById('modgen-job-banner');
        if (banner) {
          attachBannerHandlers(banner);
        }
      }
      completedJobs.forEach(job => {
        jobStartTimes.delete(job.id);
      });
    } catch (error) {
      console.error('[ModGen Banner] Error rendering completed banner:', error);
      stopPolling();
    }
  };
  const attachBannerHandlers = banner => {
    if (!banner) {
      return;
    }
    const reloadBtn = banner.querySelector('[data-action="reload-page"]');
    if (reloadBtn) {
      reloadBtn.addEventListener('click', () => {
        window.location.reload();
      });
    }
    const dismissBtn = banner.querySelector('[data-action="dismiss-banner"]');
    if (dismissBtn) {
      dismissBtn.addEventListener('click', () => {
        dismissBanner();
      });
    }
  };
  const startPolling = jobIds => {
    if (pollInterval) {
      clearInterval(pollInterval);
    }
    pollInterval = setInterval(() => {
      pollJobStatus(jobIds);
    }, POLL_INTERVAL_MS);
    pollJobStatus(jobIds);
  };
  const pollJobStatus = jobIds => {
    console.log('[ModGen Banner] Polling job status for:', jobIds);
    const promises = jobIds.map(jobId => fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: new URLSearchParams({
        jobid: jobId,
        sesskey: M.cfg.sesskey
      })
    }).then(response => response.json()).then(data => {
      if (data.success) {
        return {
          id: data.id,
          status: data.status,
          result: data.result
        };
      }
      return null;
    }).catch(error => {
      console.error('[ModGen Banner] Error polling job:', jobId, error);
      return null;
    }));
    Promise.all(promises).then(results => {
      console.log('[ModGen Banner] Poll results:', results);
      const validResults = results.filter(r => r !== null);
      const activeJobs = validResults.filter(job => job.status === 'queued' || job.status === 'running');
      const completedJobs = validResults.filter(job => job.status === 'completed' || job.status === 'failed');
      console.log('[ModGen Banner] Active jobs:', activeJobs.length, 'Completed jobs:', completedJobs.length);
      if (activeJobs.length > 0) {
        renderBanner(activeJobs.map(r => ({
          id: r.id,
          status: r.status
        })));
      } else if (completedJobs.length > 0) {
        console.log('[ModGen Banner] Stopping polling, showing completion banner');
        stopPolling();
        renderCompletedBanner(completedJobs);
      } else {
        stopPolling();
        dismissBanner();
      }
    });
  };
  const stopPolling = () => {
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = null;
    }
  };
  const dismissBanner = () => {
    const banner = document.getElementById('modgen-job-banner');
    if (banner) {
      banner.remove();
    }
    stopPolling();
  };
  const formatElapsedTime = seconds => {
    if (seconds < 60) {
      return seconds + 's';
    }
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return minutes + 'm ' + remainingSeconds + 's';
  };
});
