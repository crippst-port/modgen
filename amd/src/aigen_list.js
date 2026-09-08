/**
 * AI-generated activity list page functionality.
 *
 * @module     aiplacement_modgen/aigen_list
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';
import {get_string as getString} from 'core/str';

/**
 * Toggle the visibility of an activity.
 *
 * @param {HTMLElement} button The toggle button clicked
 */
const toggleVisibility = async(button) => {
    const cmid = button.dataset.cmid;
    const currentlyVisible = button.dataset.visible === '1';
    const newVisible = currentlyVisible ? 0 : 1;
    
    // Disable button during request.
    button.disabled = true;
    const icon = button.querySelector('i');
    const originalIconClass = icon.className;
    icon.className = 'fa fa-spinner fa-spin';
    
    try {
        const response = await fetch(`${Config.wwwroot}/ai/placement/modgen/ajax/toggle_visibility.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                cmid: cmid,
                visible: newVisible,
                sesskey: Config.sesskey,
            }),
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update the UI.
            const row = button.closest('tr');
            const statusCell = row.querySelector('.visibility-status');
            const activityName = row.querySelector('.activity-name');
            
            if (newVisible) {
                // Now visible.
                button.dataset.visible = '1';
                button.title = await getString('aigen_hide_activity', 'aiplacement_modgen');
                icon.className = 'fa fa-eye-slash';
                
                // Safe: set using DOM
const badge = document.createElement('span');
badge.className = 'badge badge-success';
badge.textContent = await getString('visible');
statusCell.innerHTML = '';
statusCell.appendChild(badge);
                activityName.classList.remove('text-muted');
            } else {
                // Now hidden.
                button.dataset.visible = '0';
                button.title = await getString('aigen_show_activity', 'aiplacement_modgen');
                icon.className = 'fa fa-eye';
                
                // Safe: set using DOM
const badge = document.createElement('span');
badge.className = 'badge badge-secondary';
badge.textContent = await getString('hidden', 'aiplacement_modgen');
statusCell.innerHTML = '';
statusCell.appendChild(badge);
                activityName.classList.add('text-muted');
            }
        } else {
            // Restore original icon on error.
            icon.className = originalIconClass;
        }
    } catch (error) {
        // eslint-disable-next-line no-console
        console.error('Error toggling visibility:', error);
        icon.className = originalIconClass;
    } finally {
        button.disabled = false;
    }
};

/**
 * Initialize the aigen list page.
 */
export const init = () => {
    // Attach click handlers to all visibility toggle buttons.
    document.querySelectorAll('.visibility-toggle').forEach(button => {
        button.addEventListener('click', (e) => {
            e.preventDefault();
            toggleVisibility(button);
        });
    });
};
