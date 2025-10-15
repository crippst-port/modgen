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
 * Floating action button launcher for the Module Generator modal.
 *
 * @module     aiplacement_modgen/fab
 * @copyright  2025
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/modal', 'core/modal_events'], function(Modal, ModalEvents) {
    /**
     * Create the floating action button element.
     *
     * @param {Object} params
     * @returns {HTMLElement}
     */
    const createButton = (params) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.classList.add('btn', 'btn-primary', 'aiplacement-modgen__fab');
        button.setAttribute('aria-label', params.arialabel);
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('aria-expanded', 'false');
        button.textContent = params.buttonlabel;
        document.body.appendChild(button);
        return button;
    };

    /**
     * Build the modal that hosts the prompt UI.
     *
     * @param {Object} params
     * @param {HTMLElement} trigger
     * @returns {Promise}
     */
    let modalPromise = null;
    let modalInstance = null;
    let messageListenerRegistered = false;
    let shouldRefresh = false;
    let reloadTriggered = false;

    const MESSAGE_TYPES = {
        READY: 'aiplacement_modgen_ready',
        CLOSE: 'aiplacement_modgen_close',
        REFRESH: 'aiplacement_modgen_refresh',
    };

    const handleMessage = (event) => {
        if (!event || event.origin !== window.location.origin || !event.data) {
            return;
        }

        if (event.data.type === MESSAGE_TYPES.READY) {
            shouldRefresh = true;
            return;
        }

        if (event.data.type === MESSAGE_TYPES.REFRESH) {
            shouldRefresh = true;
            if (modalInstance) {
                modalInstance.hide();
            }
            return;
        }

        if (event.data.type === MESSAGE_TYPES.CLOSE && modalInstance) {
            if (shouldRefresh) {
                // Ensure we reload once the modal is fully hidden.
                modalInstance.hide();
                return;
            }
            modalInstance.hide();
        }
    };

    const getModal = (params, trigger) => {
        if (!modalPromise) {
            modalPromise = Modal.create({
                title: params.dialogtitle,
                body: '',
                large: true,
            }, trigger).then((modal) => {
                modalInstance = modal;
                const body = modal.getBody();
                body.empty();

                const iframe = document.createElement('iframe');
                iframe.className = 'aiplacement-modgen__iframe';
                iframe.setAttribute('title', params.dialogtitle);
                iframe.setAttribute('allow', 'clipboard-write');
                iframe.setAttribute('loading', 'lazy');
                body.append(iframe);

                modal.getRoot().on(ModalEvents.shown, () => {
                    trigger.setAttribute('aria-expanded', 'true');
                    if (!iframe.src) {
                        iframe.src = params.url;
                    }
                });

                modal.getRoot().on(ModalEvents.hidden, () => {
                    trigger.setAttribute('aria-expanded', 'false');
                    if (shouldRefresh && !reloadTriggered) {
                        reloadTriggered = true;
                        window.location.reload();
                    }
                });

                modal.getRoot().on(ModalEvents.destroyed, () => {
                    modalPromise = null;
                    modalInstance = null;
                    shouldRefresh = false;
                    reloadTriggered = false;
                    trigger.setAttribute('aria-expanded', 'false');
                });

                return modal;
            }).catch((error) => {
                modalPromise = null;
                modalInstance = null;
                shouldRefresh = false;
                reloadTriggered = false;
                throw error;
            });
        }

        return modalPromise;
    };

    /**
     * Initialise the floating action button modal launcher.
     *
     * @param {Object} params
     */
    const init = (params) => {
        if (!params || !params.url) {
            return;
        }

        if (document.querySelector('.aiplacement-modgen__fab')) {
            return;
        }

        const trigger = createButton(params);

        if (!messageListenerRegistered) {
            window.addEventListener('message', handleMessage);
            messageListenerRegistered = true;
        }

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            getModal(params, trigger).then((modal) => {
                modal.show();
            }).catch((error) => {
                // eslint-disable-next-line no-console
                console.error('Failed to initialise Module Generator modal', error);
                trigger.remove();
            });
        });
    };

    return {init};
});
