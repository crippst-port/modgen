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
 * Job status poller for full-page background job tracking.
 *
 * @module     aiplacement_modgen/job_poller
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';

export const init = (jobid, courseid) => {
    const container = document.getElementById('job-status-container');
    if (!container) {
        return;
    }

    const startTime = Date.now();
    let pollCount = 0;
    const maxPolls = 120; // 6 minutes

    const updateStatus = (message, elapsed, count) => {
        container.innerHTML = `
            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
            ${message} (${elapsed}s)
            <br><small class="text-muted">Poll #${count}</small>
        `;
    };

    const checkStatus = () => {
        pollCount++;
        const elapsed = Math.floor((Date.now() - startTime) / 1000);

        /* eslint-disable-next-line no-console */
        console.log(`[ModGen] Poll #${pollCount} at ${elapsed}s - checking job ${jobid}`);

        if (pollCount > maxPolls) {
            container.className = 'alert alert-warning';
            container.innerHTML = `
                <strong>Job is taking longer than expected.</strong><br>
                Your sections are still being created in the background.<br>
                Please <a href="${M.cfg.wwwroot}/course/view.php?id=${courseid}">return to your course</a> 
                and refresh in a few minutes.
            `;
            return;
        }

        updateStatus('Creating sections in background...', elapsed, pollCount);

        fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/check_job_status.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                jobid: jobid,
                sesskey: M.cfg.sesskey
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.status === 'completed') {
                    /* eslint-disable-next-line no-console */
                    console.log('[ModGen] Job completed, redirecting to course');
                    
                    // Mark as notified
                    const notifiedJobs = JSON.parse(sessionStorage.getItem('modgen_notified_jobs') || '[]');
                    notifiedJobs.push(jobid);
                    sessionStorage.setItem('modgen_notified_jobs', JSON.stringify(notifiedJobs));
                    
                    container.className = 'alert alert-success';
                    container.innerHTML = '<strong>✓ Sections created successfully!</strong><br>Redirecting...';
                    
                    setTimeout(() => {
                        window.location.href = M.cfg.wwwroot + '/course/view.php?id=' + courseid;
                    }, 1000);
                } else if (data.status === 'failed') {
                    /* eslint-disable-next-line no-console */
                    console.error('[ModGen] Job failed:', data.result);
                    
                    const result = data.result || {};
                    container.className = 'alert alert-danger';
                    container.innerHTML = `<strong>Error:</strong> ${result.error || 'Job failed'}`;
                } else {
                    // Still running - check again
                    setTimeout(checkStatus, 3000);
                }
            } else {
                container.className = 'alert alert-danger';
                container.innerHTML = `Error checking job status: ${data.error || 'Unknown error'}`;
            }
        })
        .catch(error => {
            /* eslint-disable-next-line no-console */
            console.error('[ModGen] Network error:', error);
            container.className = 'alert alert-danger';
            container.innerHTML = 'Error checking job status. Please refresh the page.';
        });
    };

    // Start polling
    checkStatus();
};
