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

define(['core/modal_events', 'aiplacement_modgen/modal', 'core/str'], function(ModalEvents, ModgenModal, Str) {
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

    const FALLBACK_LOADING_MESSAGE = 'Thinking...';

    let modalPromise = null;
    let modalInstance = null;
    let shouldRefresh = false;
    let reloadTriggered = false;
    let footerButtonBindings = [];
    let needsContentReload = true;

    const getModalUrl = (baseUrl, params) => {
        const url = new URL(baseUrl, window.location.origin);
        url.searchParams.set('ajax', '1');
        if (params.embedded) {
            url.searchParams.set('embedded', '1');
        }
        if (typeof M !== 'undefined' && M.cfg && M.cfg.sesskey && !url.searchParams.has('sesskey')) {
            url.searchParams.set('sesskey', M.cfg.sesskey);
        }
        return url;
    };

    const executeInlineScripts = () => {
        if (!modalInstance) {
            return;
        }

        const body = modalInstance.getBody();
        const bodyNode = body && body.length ? body.get(0) : null;
        if (!bodyNode) {
            return;
        }

        const scripts = bodyNode.querySelectorAll('script');
        scripts.forEach((script) => {
            const replacement = document.createElement('script');
            if (script.type) {
                replacement.type = script.type;
            }
            if (script.src) {
                replacement.src = script.src;
                replacement.async = false;
            } else {
                replacement.textContent = script.textContent;
            }
            script.replaceWith(replacement);
        });

        if (typeof M !== 'undefined' && M.form && typeof M.form.updateFormState === 'function') {
            const forms = bodyNode.querySelectorAll('form.mform');
            forms.forEach((form) => {
                try {
                    M.form.updateFormState(form.getAttribute('id'));
                } catch (error) {
                    // eslint-disable-next-line no-console
                    console.warn('Failed to refresh form state', error);
                }
            });
        }

    };

    const collectSubmitButtons = (form) => {
        const bindings = [];
        const submitters = form.querySelectorAll('button[type="submit"], input[type="submit"]');

        submitters.forEach((submitter) => {
            const originalClasses = (submitter.className || '').split(/\s+/).filter(Boolean);
            const classes = new Set(originalClasses);
            classes.add('btn');
            const hasVariant = Array.from(classes).some((cls) => cls.indexOf('btn-') === 0);
            if (!hasVariant) {
                classes.add('btn-secondary');
            }
            if (submitter.classList.contains('btn-primary')) {
                classes.delete('btn-secondary');
                classes.add('btn-primary');
            }

            const labelSource = submitter.tagName === 'INPUT'
                ? (submitter.value || submitter.getAttribute('value') || submitter.getAttribute('aria-label') || submitter.name || '')
                : submitter.textContent;
            const label = (labelSource || '').trim() || 'Submit';

            bindings.push({
                label,
                classes: Array.from(classes).join(' '),
                form,
                submitter,
            });

            const container = submitter.closest('.form-submit, .form-actions, .form-buttons, .buttons');
            if (container) {
                container.classList.add('aiplacement-modgen__hidden-submit');
                container.setAttribute('aria-hidden', 'true');
                container.hidden = true;
            } else {
                submitter.classList.add('aiplacement-modgen__hidden-submit');
                submitter.setAttribute('aria-hidden', 'true');
                submitter.hidden = true;
            }
        });

        return bindings;
    };

    const updateFooterButtons = (buttonBindings) => {
        footerButtonBindings = buttonBindings || [];

        if (!modalInstance) {
            return;
        }

        const footer = modalInstance.getFooter();
        const footerNode = footer && footer.length ? footer.get(0) : null;
        if (!footerNode) {
            return;
        }

        const submitButtons = footerNode.querySelectorAll('[data-action="aiplacement-modgen-submit"]');
        submitButtons.forEach((button, index) => {
            if (!button.hasAttribute('data-button-index')) {
                button.dataset.buttonIndex = String(index);
            }
        });
    };

    const bindCloseButtons = () => {
        if (!modalInstance) {
            return;
        }

        const nodes = [];
        const body = modalInstance.getBody();
        const footer = modalInstance.getFooter();

        if (body && body.length) {
            nodes.push(body.get(0));
        }
        if (footer && footer.length) {
            nodes.push(footer.get(0));
        }

        if (!nodes.length) {
            return;
        }

        nodes.forEach((node) => {
            const buttons = node.querySelectorAll('[data-action="aiplacement-modgen-close"]');
            buttons.forEach((button) => {
                if (button.dataset.modgenCloseBound === '1') {
                    return;
                }
                button.dataset.modgenCloseBound = '1';
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    shouldRefresh = true;
                    modalInstance.hide();
                });
            });
        });
    };

    const setupKeepWeekLabelsToggle = (form) => {
        if (form.dataset.modgenWeekToggle === '1') {
            return;
        }

        const moduleselect = form.querySelector('select[name="moduletype"]');
        const keepweekitem = form.querySelector('#fitem_id_keepweeklabels');
        if (!moduleselect || !keepweekitem) {
            return;
        }

        const checkbox = keepweekitem.querySelector('input[name="keepweeklabels"]');

        const updateVisibility = () => {
            const isWeekly = moduleselect.value === 'weekly';
            keepweekitem.style.display = isWeekly ? '' : 'none';
            keepweekitem.setAttribute('aria-hidden', isWeekly ? 'false' : 'true');
            if (!isWeekly && checkbox) {
                checkbox.checked = false;
            }
        };

        moduleselect.addEventListener('change', updateVisibility);
        updateVisibility();
        form.dataset.modgenWeekToggle = '1';
    };

    const enhanceForms = (params) => {
        if (!modalInstance) {
            return [];
        }

        const body = modalInstance.getBody();
        const bodyNode = body && body.length ? body.get(0) : null;
        if (!bodyNode) {
            return [];
        }

        const bindings = [];
        const forms = bodyNode.querySelectorAll('form');
        forms.forEach((form) => {
            if (form.dataset.modgenEnhanced !== '1') {
                form.dataset.modgenEnhanced = '1';
                form.addEventListener('submit', (event) => {
                    event.preventDefault();
                    const formData = new FormData(form);
                    formData.append('ajax', '1');
                    if (params.embedded) {
                        formData.append('embedded', '1');
                    }
                    if (!formData.has('sesskey') && typeof M !== 'undefined' && M.cfg && M.cfg.sesskey) {
                        formData.append('sesskey', M.cfg.sesskey);
                    }
                    loadContent(params, formData);
                });

                setupKeepWeekLabelsToggle(form);
            }

            bindings.push(...collectSubmitButtons(form));
        });

        return bindings;
    };

    const showError = (message) => {
        if (!modalInstance) {
            return;
        }
        const safeMessage = message || 'Unable to load content.';
        modalInstance.setBody('<div class="alert alert-danger" role="alert">' + safeMessage + '</div>');
        modalInstance.setFooter('');
    };

    const setLoadingState = () => {
        if (!modalInstance) {
            return;
        }

        const markup = '' +
            '<div class="aiplacement-modgen__loading" role="status" aria-live="polite">' +
            '<span class="spinner-border" aria-hidden="true"></span>' +
            '<p class="aiplacement-modgen__loading-message"></p>' +
            '</div>';

        const updateMessage = (text) => {
            const body = modalInstance.getBody();
            const bodyNode = body && body.length ? body.get(0) : null;
            if (!bodyNode) {
                return;
            }
            const messageNode = bodyNode.querySelector('.aiplacement-modgen__loading-message');
            if (messageNode) {
                messageNode.textContent = text;
            }
        };

        modalInstance.setBody(markup);
        modalInstance.setFooter('');
        updateMessage(FALLBACK_LOADING_MESSAGE);

        if (Str && typeof Str.get_string === 'function') {
            Str.get_string('processing', 'aiplacement_modgen').then((message) => {
                updateMessage(message);
            }).catch(() => {
                Str.get_string('loadingthinking', 'aiplacement_modgen').then((message) => {
                    updateMessage(message);
                }).catch(() => {
                    updateMessage(FALLBACK_LOADING_MESSAGE);
                });
            });
        }
    };

    const processPayload = (payload, params) => {
        if (!modalInstance) {
            return;
        }

        if (!payload || typeof payload !== 'object') {
            showError('Unexpected response from server.');
            return;
        }

        if (payload.error) {
            showError(payload.error);
            return;
        }

        if (payload.title) {
            modalInstance.setTitle(payload.title);
        }

        const bodyHtml = payload.body || '';
        const footerHtml = payload.footer || '';
        modalInstance.setBody(bodyHtml);
        modalInstance.setFooter(footerHtml);

        shouldRefresh = shouldRefresh || Boolean(payload.refresh);

    executeInlineScripts();
    const buttonBindings = enhanceForms(params);
    updateFooterButtons(buttonBindings);
        bindCloseButtons();
        needsContentReload = false;

        if (payload.close) {
            modalInstance.hide();
        }
    };

    const loadContent = (params, formData = null) => {
        if (!modalInstance) {
            return Promise.resolve();
        }

        setLoadingState();

        const url = getModalUrl(params.url, params);
        
        // Create AbortController for timeout handling
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 300000); // 5 minutes timeout

        const options = {
            method: formData ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
            signal: controller.signal,
        };

        if (formData) {
            options.body = formData;
            // Show enhanced loading message for form submissions (likely AI processing)
            if (modalInstance) {
                const body = modalInstance.getBody();
                const bodyNode = body && body.length ? body.get(0) : null;
                if (bodyNode) {
                    const messageNode = bodyNode.querySelector('.aiplacement-modgen__loading-message');
                    if (messageNode) {
                        Str.get_string('aiprocessingdetail', 'aiplacement_modgen').then((message) => {
                            if (messageNode) {
                                messageNode.textContent = message;
                            }
                        }).catch(() => {
                            if (messageNode) {
                                messageNode.textContent = 'AI is analyzing your request and generating module content. This process may take several minutes for complex requests.';
                            }
                        });
                    }
                }
            }
        }

        return fetch(url.toString(), options)
            .then((response) => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error('Failed to load modal content.');
                }
                return response.json();
            })
            .then((payload) => {
                processPayload(payload, params);
            })
            .catch((error) => {
                clearTimeout(timeoutId);
                // eslint-disable-next-line no-console
                console.error(error);
                if (error.name === 'AbortError') {
                    showError('Your request is taking longer than expected. Please try with a shorter prompt or try again later.');
                } else {
                    showError(error.message);
                }
            });
    };

    const getModal = (params, trigger) => {
        if (!modalPromise) {
            modalPromise = ModgenModal.create({
                title: params.dialogtitle,
                body: '',
            }).then((modal) => {
                modalInstance = modal;

                modal.getRoot().on('click', '[data-action="aiplacement-modgen-submit"]', (event) => {
                    event.preventDefault();
                    const button = event.currentTarget;
                    if (!button) {
                        return;
                    }
                    const indexValue = button.getAttribute('data-button-index');
                    const index = indexValue ? parseInt(indexValue, 10) : NaN;
                    if (Number.isNaN(index)) {
                        return;
                    }
                    const binding = footerButtonBindings[index];
                    if (!binding || !binding.submitter || !binding.form) {
                        return;
                    }
                    const form = binding.form;
                    const submitter = binding.submitter;
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(submitter);
                    } else {
                        submitter.click();
                    }
                });

                modal.getRoot().on('click', '[data-action="aiplacement-modgen-reenter"]', (event) => {
                    event.preventDefault();
                    footerButtonBindings = [];
                    loadContent(params);
                });

                modal.getRoot().on(ModalEvents.shown, () => {
                    trigger.setAttribute('aria-expanded', 'true');
                    if (needsContentReload || !modalInstance.getBody().html()) {
                        loadContent(params);
                    }
                });

                modal.getRoot().on(ModalEvents.hidden, () => {
                    trigger.setAttribute('aria-expanded', 'false');
                    footerButtonBindings = [];
                    needsContentReload = true;
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
                    footerButtonBindings = [];
                    needsContentReload = true;
                    trigger.setAttribute('aria-expanded', 'false');
                });

                return modal;
            }).catch((error) => {
                modalPromise = null;
                modalInstance = null;
                shouldRefresh = false;
                reloadTriggered = false;
                footerButtonBindings = [];
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

        params.embedded = params.embedded || params.url.indexOf('embedded=1') !== -1;

        if (document.querySelector('.aiplacement-modgen__fab')) {
            return;
        }

        const trigger = createButton(params);

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            getModal(params, trigger).then((modal) => {
                modal.show();
                if (!modal.getBody().html()) {
                    loadContent(params);
                }
            }).catch((error) => {
                // eslint-disable-next-line no-console
                console.error('Failed to initialise Module Generator modal', error);
                trigger.remove();
            });
        });
    };

    return {init};
});
