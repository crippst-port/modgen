/**
 * JSON download for Module Generator approval page.
 *
 * @module     aiplacement_modgen/json_handler
 * @copyright  2025
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    /**
     * Initialize JSON download handler.
     */
    function init() {
        const downloadLink = document.querySelector('.aiplacement-modgen-json-download');
        if (downloadLink) {
            downloadLink.addEventListener('click', handleDownload);
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
