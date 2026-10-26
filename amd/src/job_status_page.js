// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Job status page JavaScript - polls and updates job progress display.
 *
 * @module     aiplacement_modgen/job_status_page
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Poll interval in milliseconds (3 seconds).
 * @type {number}
 */
const POLL_INTERVAL = 3000;

/**
 * Auto-redirect delay after completion (3 seconds).
 * @type {number}
 */
const REDIRECT_DELAY = 3000;

/**
 * Current polling interval ID.
 * @type {number|null}
 */
let pollInterval = null;

/**
 * Configuration object.
 * @type {Object}
 */
let config = {};

/**
 * Escape a value for safe interpolation into an HTML string.
 *
 * Job result text originates from section and activity names, which are cleaned
 * with PARAM_TEXT server-side. PARAM_TEXT decodes entities, so raw angle brackets
 * can survive into the payload and must be escaped again at the point of output.
 *
 * @param {*} value Untrusted value
 * @returns {string} HTML-escaped string
 */
const escapeHtml = (value) => {
    const div = document.createElement('div');
    div.textContent = String(value === null || value === undefined ? '' : value);
    return div.innerHTML;
};

/**
 * Send the user back to the course after a short delay.
 *
 * A job that finished with problems keeps the page open so the problems can be read.
 *
 * @param {boolean} haswarnings Whether the job reported warnings
 */
const redirectToCourse = (haswarnings) => {
    if (haswarnings) {
        return;
    }
    setTimeout(() => {
        window.location.href = config.courseurl;
    }, REDIRECT_DELAY);
};

/**
 * Update the status display based on job data.
 *
 * @param {Object} job Job data from server
 */
const updateStatusDisplay = (job) => {
    const container = document.getElementById('job-status-container');
    const statusBadge = document.getElementById('status-badge');
    const returnButtonContainer = document.getElementById('return-button-container');

    if (!container) {
        return;
    }

    // Update badge
    if (statusBadge) {
        statusBadge.textContent = (config.strings.states || {})[job.status] || job.status;
        statusBadge.className = 'badge badge-' + getStatusColor(job.status);
    }

    // Build new status HTML based on current state
    let html = '';

    if (job.status === 'queued') {
        html = `
            <div class="alert alert-info d-flex align-items-center">
                <div class="spinner-border text-info mr-3" role="status">
                    <span class="sr-only">${escapeHtml(config.strings.loading)}</span>
                </div>
                <div>
                    <strong>${escapeHtml(config.strings.queued)}</strong>
                    <p class="mb-0 small">${escapeHtml(config.strings.queueddesc)}</p>
                    <p class="mb-0 small text-muted">
                        <em>${escapeHtml(config.strings.redirectnote)}</em>
                    </p>
                </div>
            </div>
        `;

        // Hide return button
        if (returnButtonContainer) {
            returnButtonContainer.style.display = 'none';
        }
    } else if (job.status === 'running') {
        html = `
            <div class="alert alert-primary d-flex align-items-center">
                <div class="spinner-border text-primary mr-3" role="status">
                    <span class="sr-only">${escapeHtml(config.strings.loading)}</span>
                </div>
                <div>
                    <strong>${escapeHtml(config.strings.running)}</strong>
                    <p class="mb-0 small">${escapeHtml(config.strings.runningdesc)}</p>
                    <p class="mb-0 small text-muted">
                        <em>${escapeHtml(config.strings.redirectnote)}</em>
                    </p>
                </div>
            </div>
        `;

        // Hide return button
        if (returnButtonContainer) {
            returnButtonContainer.style.display = 'none';
        }
    } else if (job.status === 'completed') {
        const result = job.result || {};
        const messages = result.messages || [];
        const warnings = result.warnings || [];

        let messagesList = '';
        messages.forEach(msg => {
            messagesList += `<p class="mb-1"><i class="fa fa-check text-success" aria-hidden="true"></i> ${escapeHtml(msg)}</p>`;
        });

        if (warnings.length > 0) {
            // Some items failed: keep the page open so the problems can be read.
            let warningsList = '';
            warnings.forEach(warning => {
                warningsList += '<p class="mb-1"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> ' +
                    `${escapeHtml(warning)}</p>`;
            });
            html = `
                <div class="alert alert-warning">
                    <h4 class="alert-heading">
                        <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                        ${escapeHtml(config.strings.completedwarnings)}
                    </h4>
                    ${warningsList}
                    ${messagesList}
                </div>
            `;
        } else {
            html = `
                <div class="alert alert-success">
                    <h4 class="alert-heading">
                        <i class="fa fa-check-circle" aria-hidden="true"></i>
                        ${escapeHtml(config.strings.completed)}
                    </h4>
                    ${messagesList}
                </div>
            `;
        }

        // Show return button if it exists, otherwise create it
        if (returnButtonContainer) {
            returnButtonContainer.style.display = 'block';
        } else {
            // Create button dynamically if not in template
            const buttonHtml = `
                <div class="mt-4" id="return-button-container">
                    <a href="${config.courseurl}" class="btn btn-primary">
                        <i class="fa fa-arrow-left" aria-hidden="true"></i>
                        ${escapeHtml(config.strings.returncourse)}
                    </a>
                </div>
            `;
            container.insertAdjacentHTML('afterend', buttonHtml);
        }

        // Stop polling
        stopPolling();

        redirectToCourse(warnings.length > 0);

    } else if (job.status === 'failed') {
        const result = job.result || {};
        const error = result.error || config.strings.unknownerror;
        const willRetry = result.will_retry || false;

        let retryHtml = '';
        if (willRetry) {
            retryHtml = `
                <hr>
                <p class="mb-0">
                    <i class="fa fa-refresh" aria-hidden="true"></i>
                    ${escapeHtml(config.strings.willretry)}
                </p>
            `;
            // Keep polling for retry
        } else {
            stopPolling();
        }

        html = `
            <div class="alert alert-danger">
                <h4 class="alert-heading">
                    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    ${escapeHtml(config.strings.failed)}
                </h4>
                <p><strong>${escapeHtml(config.strings.error)}:</strong> ${escapeHtml(error)}</p>
                ${retryHtml}
            </div>
        `;

        // Hide return button on failure
        if (returnButtonContainer) {
            returnButtonContainer.style.display = 'none';
        }
    }

    container.innerHTML = html;
};

/**
 * Get Bootstrap color class for status.
 *
 * @param {string} status Job status
 * @returns {string} Color class
 */
const getStatusColor = (status) => {
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

/**
 * Poll job status from server.
 */
const pollJobStatus = () => {
    // Direct fetch approach
    const url = M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php?sesskey=' +
                M.cfg.sesskey + '&jobid=' + config.jobid;

    fetch(url)
        .then(response => {
            return response.json();
        })
        .then(data => {
            if (data.success && data.status) {
                updateStatusDisplay(data);

                // If completed or failed (and not retrying), stop polling
                if (data.status === 'completed') {
                    stopPolling();
                } else if (data.status === 'failed' && !(data.result && data.result.will_retry)) {
                    stopPolling();
                }
            }
        })
        .catch(error => {
            window.console.error('[Job Status Page] Fetch error:', error);
            // Silently fail - will retry next interval
        });
};

/**
 * Start polling for job status.
 */
const startPolling = () => {
    // Initial poll
    pollJobStatus();

    // Set up interval
    pollInterval = setInterval(pollJobStatus, POLL_INTERVAL);
};

/**
 * Stop polling for job status.
 */
const stopPolling = () => {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
};

/**
 * Initialize the job status page.
 *
 * @param {Object} cfg Configuration object
 * @param {number} cfg.jobid Job ID to monitor
 * @param {number} cfg.courseid Course ID
 * @param {string} cfg.courseurl URL to course page
 * @param {string} cfg.initialstatus Initial job status
 * @param {boolean} cfg.haswarnings Whether a completed job reported warnings
 * @param {boolean} cfg.willretry Whether a failed job will be retried by Moodle
 * @param {Object} cfg.strings Language strings: completed, completedwarnings
 */
export const init = (cfg) => {
    config = cfg;

    // If job already completed/failed, don't poll
    if (cfg.initialstatus === 'completed') {
        redirectToCourse(Boolean(cfg.haswarnings));
    } else if (cfg.initialstatus === 'failed') {
        // Keep watching a failed job that Moodle will retry.
        if (cfg.willretry) {
            startPolling();
        }
    } else {
        // Start polling for queued/running jobs
        startPolling();
    }

    // Clean up on page unload
    window.addEventListener('beforeunload', stopPolling);
};
