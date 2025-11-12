// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * JSON download and viewer for Module Generator approval page.
 *
 * @module     aiplacement_modgen/json_handler
 * @copyright  2025
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    /**
     * Initialize JSON download and view handlers.
     */
    function init() {
        const downloadBtn = document.getElementById('aiplacement-modgen-download-json');
        const viewBtn = document.getElementById('aiplacement-modgen-view-json');
        const jsonViewer = document.getElementById('aiplacement-modgen-json-viewer');

        if (downloadBtn) {
            downloadBtn.addEventListener('click', handleDownload);
        }

        if (viewBtn) {
            viewBtn.addEventListener('click', function() {
                if (jsonViewer.style.display === 'none') {
                    jsonViewer.style.display = 'block';
                    viewBtn.textContent = '👁️ Hide JSON';
                } else {
                    jsonViewer.style.display = 'none';
                    viewBtn.textContent = '👁️ View JSON';
                }
            });
        }
    }

    /**
     * Handle JSON download.
     * @param {Event} e Click event
     */
    function handleDownload(e) {
        e.preventDefault();
        
        const jsonData = e.target.getAttribute('data-json');
        if (!jsonData) {
            return;
        }

        // Decode HTML entities
        const textarea = document.createElement('textarea');
        textarea.innerHTML = jsonData;
        const jsonContent = textarea.value;

        // Create blob and download
        const blob = new Blob([jsonContent], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'module-structure-' + new Date().toISOString().split('T')[0] + '.json';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    return {
        init: init,
    };
});
