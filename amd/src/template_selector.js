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
 * Template selector JavaScript for CSV Template Library.
 *
 * @module     aiplacement_modgen/template_selector
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    /**
     * Initialize the template selector.
     *
     * @param {Object} options Configuration options
     * @param {string} options.downloadUrl Base URL for template downloads
     * @param {number} options.courseid Course ID for context
     */
    var init = function(options) {
        var downloadUrl = options.downloadUrl || M.cfg.wwwroot + '/ai/placement/modgen/download_template.php';
        var courseid = options.courseid || 0;

        // Wait for DOM to be ready
        var initializeElements = function() {
            // Get form elements (Moodle adds id_ prefix to form element names)
            var templateSelect = document.getElementById('id_selected_template_id');
            var downloadBtn = document.getElementById('id_download_template');

            // Only handle download button if template selector exists
            if (!templateSelect || !downloadBtn) {
                return;
            }

            /**
             * Update download button state based on selected template.
             */
            function updateDownloadButton() {
                var selectedValue = templateSelect.value;
                var isTemplateSelected = selectedValue && selectedValue !== '0';
                downloadBtn.disabled = !isTemplateSelected;
            }

            /**
             * Handle download button click.
             * Downloads the template file without triggering navigation warnings.
             *
             * @param {Event} e Click event
             */
            function handleDownload(e) {
                e.preventDefault();
                var templateId = templateSelect.value;
                if (templateId && templateId !== '0') {
                    var url = downloadUrl + '?id=' + templateId;
                    if (courseid) {
                        url += '&courseid=' + courseid;
                    }
                    // Use temporary anchor element to trigger download without page navigation
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = '';
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }
            }

            // Attach event listeners
            templateSelect.addEventListener('change', updateDownloadButton);
            downloadBtn.addEventListener('click', handleDownload);

            // Initialize download button state
            updateDownloadButton();
        };

        // Initialize when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeElements);
        } else {
            // DOM already loaded, but give it a tiny delay to ensure Moodle's form is ready
            setTimeout(initializeElements, 100);
        }
    };

    return {
        init: init
    };
});
