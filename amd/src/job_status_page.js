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

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

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
        statusBadge.textContent = job.status;
        statusBadge.className = 'badge badge-' + getStatusColor(job.status);
    }
    
    // Build new status HTML based on current state
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
        
        // Hide return button
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
        
        // Hide return button
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
        
        // Show return button if it exists, otherwise create it
        if (returnButtonContainer) {
            returnButtonContainer.style.display = 'block';
        } else {
            // Create button dynamically if not in template
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
        
        // Stop polling
        stopPolling();
        
        // Auto-redirect after delay
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
            // Keep polling for retry
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
    console.log('[Job Status Page] Polling job', config.jobid);
    
    // Direct fetch approach
    const url = M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php?sesskey=' + 
                M.cfg.sesskey + '&jobid=' + config.jobid;
    
    console.log('[Job Status Page] Fetching:', url);
    
    fetch(url)
        .then(response => {
            console.log('[Job Status Page] Response received:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('[Job Status Page] Data:', data);
            if (data.success && data.status) {
                updateStatusDisplay(data);
                
                // If completed or failed (and not retrying), stop polling
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
        })
        .catch(error => {
            console.error('[Job Status Page] Fetch error:', error);
            // Silently fail - will retry next interval
        });
};

/**
 * Start polling for job status.
 */
const startPolling = () => {
    console.log('[Job Status Page] Starting polling with interval:', POLL_INTERVAL);
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
 */
export const init = (cfg) => {
    console.log('[Job Status Page] Initializing with config:', cfg);
    config = cfg;
    
    // If job already completed/failed, don't poll
    if (cfg.initialstatus === 'completed') {
        console.log('[Job Status Page] Job already completed, will redirect');
        // Auto-redirect after delay
        setTimeout(() => {
            window.location.href = config.courseurl;
        }, REDIRECT_DELAY);
    } else if (cfg.initialstatus === 'failed') {
        console.log('[Job Status Page] Job failed, checking if will retry');
        // Check if will retry
        const result = document.querySelector('[data-jobid]');
        if (result && result.dataset.willretry) {
            startPolling();
        }
    } else {
        console.log('[Job Status Page] Job', cfg.initialstatus, '- starting polling');
        // Start polling for queued/running jobs
        startPolling();
    }
    
    // Clean up on page unload
    window.addEventListener('beforeunload', stopPolling);
};
