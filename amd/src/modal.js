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
 * Custom modal class for the Module Generator workflow.
 *
 * @module     aiplacement_modgen/modal
 * @copyright  2025
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/modal'], function(BaseModal) {
    class ModgenModal extends BaseModal {
        /** @inheritDoc */
        static get TYPE() {
            return 'aiplacement_modgen/modal';
        }

        /** @inheritDoc */
        static get TEMPLATE() {
            return 'aiplacement_modgen/modal';
        }

        /**
         * Override the modal configuration with plugin defaults.
         *
         * @param {Moodle.modal.MoodleConfig} modalConfig
         */
        configure(modalConfig) {
            modalConfig.large = true;
            if (typeof modalConfig.removeOnClose === 'undefined') {
                modalConfig.removeOnClose = false;
            }
            super.configure(modalConfig);
        }
    }

    return ModgenModal;
});
