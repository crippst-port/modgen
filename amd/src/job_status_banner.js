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

import Templates from 'core/templates';
import {get_string as getString} from 'core/str';

/**
 * Module state.
 */
let config = null;
let pollInterval = null;
const jobStartTimes = new Map();
let dismissedJobs = [];

/** Poll interval in milliseconds. */
const POLL_INTERVAL_MS = 3000;

/**
 * Initialize the job status banner.
 *
 * @param {Object} cfg Configuration object
 * @param {number} cfg.courseid Course ID
 * @param {number} cfg.contextid Context ID
 */
export const init = (cfg) => {
    config = cfg;

    // Load dismissed jobs from sessionStorage and clean up old entries
    const dismissed = sessionStorage.getItem('modgen_dismissed_jobs');
    if (dismissed) {
        try {
            const parsed = JSON.parse(dismissed);
            // Only keep recent dismissals (last 100 jobs to prevent unbounded growth)
            dismissedJobs = Array.isArray(parsed) ? parsed.slice(-100) : [];
        } catch (error) {
            /* eslint-disable-next-line no-console */
            console.error('[ModGen Banner] Error parsing dismissed jobs:', error);
            dismissedJobs = [];
            sessionStorage.removeItem('modgen_dismissed_jobs');
        }
    }

    // Check for active jobs on page load
    checkForActiveJobs();
};

/**
 * Check for active jobs and render banner if found.
 */
const checkForActiveJobs = () => {
    fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            courseid: config.courseid,
            active: 1,
            sesskey: M.cfg.sesskey
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.jobs && data.jobs.length > 0) {
            // Filter out dismissed jobs
            const activeJobs = data.jobs.filter(job => !dismissedJobs.includes(job.id));
            
            if (activeJobs.length > 0) {
                // Track start time for each job
                activeJobs.forEach(job => {
                    if (!jobStartTimes.has(job.id)) {
                        jobStartTimes.set(job.id, Date.now());
                    }
                });
                renderBanner(activeJobs);
                startPolling(activeJobs.map(job => job.id));
            }
        }
    })
    .catch(error => {
        // Silently fail - banner just won't show
        /* eslint-disable-next-line no-console */
        console.error('[ModGen Banner] Error checking for active jobs:', error);
    });
};

/**
 * Render the job status banner.
 *
 * @param {Array} jobs Array of job objects
 */
const renderBanner = async (jobs) => {
    const jobCount = jobs.length;
    const singlejob = jobCount === 1;
    const job = jobs[0]; // Use first job for status display

    try {
        // Determine message based on job count and status
        let message = '';
        if (singlejob) {
            if (job.status === 'queued') {
                message = await getString('jobqueued_single', 'aiplacement_modgen');
            } else {
                message = await getString('jobrunning_single', 'aiplacement_modgen');
            }
        } else {
            if (job.status === 'queued') {
                message = await getString('jobsqueued_multiple', 'aiplacement_modgen', jobCount);
            } else {
                message = await getString('jobsrunning_multiple', 'aiplacement_modgen', jobCount);
            }
        }

        // Calculate elapsed time using oldest job's start time
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

        const {html} = await Templates.renderForPromise('aiplacement_modgen/job_status_banner', templateData);
        
        // Find or create banner container
        let banner = document.getElementById('modgen-job-banner');
        if (!banner) {
            // Try to insert after toolbar (if in edit mode)
            const toolbar = document.querySelector('.aiplacement-modgen-navbar');
            if (toolbar) {
                toolbar.insertAdjacentHTML('afterend', html);
            } else {
                // Fallback: insert at top of course content (for non-edit mode)
                const courseContent = document.querySelector('#page-content') ||
                                     document.querySelector('[role="main"]') ||
                                     document.querySelector('.course-content');
                if (courseContent) {
                    courseContent.insertAdjacentHTML('afterbegin', html);
                } else {
                    /* eslint-disable-next-line no-console */
                    console.warn('[ModGen Banner] No suitable insertion point found');
                    return;
                }
            }
            banner = document.getElementById('modgen-job-banner');
            if (banner) {
                attachBannerHandlers(banner);
            }
        } else {
            // Update existing banner
            banner.outerHTML = html;
            banner = document.getElementById('modgen-job-banner');
            attachBannerHandlers(banner);
        }
    } catch (error) {
        /* eslint-disable-next-line no-console */
        console.error('[ModGen Banner] Error rendering banner:', error);
        stopPolling();
    }
};

/**
 * Render completed banner.
 *
 * @param {Array} completedJobs Array of completed job objects
 */
const renderCompletedBanner = async (completedJobs) => {
    const jobCount = completedJobs.length;
    const failedJobs = completedJobs.filter(job => job.status === 'failed');
    const hasFailures = failedJobs.length > 0;
    
    try {
        let message = '';
        if (hasFailures) {
            if (jobCount === 1) {
                message = await getString('jobfailed_single', 'aiplacement_modgen');
            } else {
                message = await getString('jobsfailed_multiple', 'aiplacement_modgen', failedJobs.length);
            }
        } else {
            if (jobCount === 1) {
                message = await getString('jobcompleted_single', 'aiplacement_modgen');
            } else {
                message = await getString('jobscompleted_multiple', 'aiplacement_modgen', jobCount);
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

        const {html} = await Templates.renderForPromise('aiplacement_modgen/job_status_banner', templateData);
        
        let banner = document.getElementById('modgen-job-banner');
        if (banner) {
            // Update existing banner
            banner.outerHTML = html;
            banner = document.getElementById('modgen-job-banner');
            attachBannerHandlers(banner);
        } else {
            // Banner doesn't exist - create it (same logic as renderBanner)
            const toolbar = document.querySelector('.aiplacement-modgen-navbar');
            if (toolbar) {
                toolbar.insertAdjacentHTML('afterend', html);
            } else {
                // Fallback: insert at top of course content
                const courseContent = document.querySelector('#page-content') ||
                                     document.querySelector('[role="main"]') ||
                                     document.querySelector('.course-content');
                if (courseContent) {
                    courseContent.insertAdjacentHTML('afterbegin', html);
                }
            }
            banner = document.getElementById('modgen-job-banner');
            if (banner) {
                attachBannerHandlers(banner);
            }
        }
        
        // Clean up start times for completed jobs
        completedJobs.forEach(job => {
            jobStartTimes.delete(job.id);
        });
    } catch (error) {
        /* eslint-disable-next-line no-console */
        console.error('[ModGen Banner] Error rendering completed banner:', error);
        stopPolling();
    }
};

/**
 * Attach event handlers to banner buttons.
 *
 * @param {HTMLElement} banner Banner element
 */
const attachBannerHandlers = (banner) => {
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

/**
 * Start polling for job status updates.
 *
 * @param {Array} jobIds Array of job IDs to poll
 */
const startPolling = (jobIds) => {
    // Clear any existing interval
    if (pollInterval) {
        clearInterval(pollInterval);
    }

    // Poll at regular intervals
    pollInterval = setInterval(() => {
        pollJobStatus(jobIds);
    }, POLL_INTERVAL_MS);

    // Also poll immediately
    pollJobStatus(jobIds);
};

/**
 * Poll job status and update banner.
 *
 * @param {Array} jobIds Array of job IDs to poll
 */
const pollJobStatus = (jobIds) => {
    /* eslint-disable-next-line no-console */
    console.log('[ModGen Banner] Polling job status for:', jobIds);

    // Check all jobs in parallel
    const promises = jobIds.map(jobId => 
        fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                jobid: jobId,
                sesskey: M.cfg.sesskey
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                return {
                    id: data.id,
                    status: data.status,
                    result: data.result
                };
            }
            return null;
        })
        .catch(error => {
            /* eslint-disable-next-line no-console */
            console.error('[ModGen Banner] Error polling job:', jobId, error);
            return null;
        })
    );

    Promise.all(promises).then(results => {
        /* eslint-disable-next-line no-console */
        console.log('[ModGen Banner] Poll results:', results);
        
        const validResults = results.filter(r => r !== null);
        const activeJobs = validResults.filter(job => job.status === 'queued' || job.status === 'running');
        const completedJobs = validResults.filter(job => job.status === 'completed' || job.status === 'failed');

        /* eslint-disable-next-line no-console */
        console.log('[ModGen Banner] Active jobs:', activeJobs.length, 'Completed jobs:', completedJobs.length);

        if (activeJobs.length > 0) {
            // Still have active jobs - update banner
            renderBanner(activeJobs.map(r => ({id: r.id, status: r.status})));
        } else if (completedJobs.length > 0) {
            // All jobs completed - stop polling and show completion
            /* eslint-disable-next-line no-console */
            console.log('[ModGen Banner] Stopping polling, showing completion banner');
            stopPolling();
            renderCompletedBanner(completedJobs);
        } else {
            // No jobs found - might have been deleted or error occurred
            stopPolling();
            dismissBanner();
        }
    });
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
 * Dismiss the banner and remember dismissed jobs.
 */
const dismissBanner = () => {
    const banner = document.getElementById('modgen-job-banner');
    if (banner) {
        banner.remove();
    }

    stopPolling();

    // Note: We don't add to dismissedJobs here because user might manually dismiss
    // before completion. The banner will reappear on next page load if jobs still active.
};

/**
 * Format elapsed time in human-readable format.
 *
 * @param {number} seconds Elapsed seconds
 * @return {string} Formatted time string
 */
const formatElapsedTime = (seconds) => {
    if (seconds < 60) {
        return seconds + 's';
    }
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return minutes + 'm ' + remainingSeconds + 's';
};
