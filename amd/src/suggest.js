import Notification from 'core/notification';
import Config from 'core/config';
import $ from 'jquery';
import Templates from 'core/templates';
import * as LoadingIndicator from 'aiplacement_modgen/loading_indicator';
import {get_string as getString} from 'core/str';

// Step constants for suggest workflow
const SUGGEST_STEPS = {
    SELECT: 1,
    SCANNING: 2,
    REVIEW: 3,
    CREATING: 4,
};

const SUGGEST_STEP_LABELS = {
    1: 'Select section',
    2: 'Suggesting',
    3: 'Review',
    4: 'Creating',
};

/**
 * Build progress header HTML for suggest workflow.
 * @param {number} currentStep Current step number
 * @returns {string} HTML for progress header
 */
const buildSuggestProgressHeader = (currentStep) => {
    const steps = [
        {num: SUGGEST_STEPS.SELECT, label: SUGGEST_STEP_LABELS[SUGGEST_STEPS.SELECT], icon: 'fa-list'},
        {num: SUGGEST_STEPS.SCANNING, label: SUGGEST_STEP_LABELS[SUGGEST_STEPS.SCANNING], icon: 'fa-search'},
        {num: SUGGEST_STEPS.REVIEW, label: SUGGEST_STEP_LABELS[SUGGEST_STEPS.REVIEW], icon: 'fa-eye'},
        {num: SUGGEST_STEPS.CREATING, label: SUGGEST_STEP_LABELS[SUGGEST_STEPS.CREATING], icon: 'fa-check'},
    ];

    let html = '<div class="modgen-progress-header mb-3">';
    html += '<div class="d-flex justify-content-between align-items-center">';

    steps.forEach((step, index) => {
        const isActive = step.num === currentStep;
        const isComplete = step.num < currentStep;
        const isPending = step.num > currentStep;

        let stepClass = 'modgen-step';
        if (isActive) {
            stepClass += ' modgen-step-active';
        }
        if (isComplete) {
            stepClass += ' modgen-step-complete';
        }
        if (isPending) {
            stepClass += ' modgen-step-pending';
        }

        let iconClass = step.icon;
        if (isComplete) {
            iconClass = 'fa-check';
        }

        html += `<div class="${stepClass} text-center flex-fill">`;
        html += `<div class="modgen-step-icon mb-1">`;
        html += `<i class="fa ${iconClass}"></i>`;
        html += `</div>`;
        html += `<div class="modgen-step-label small">${step.label}</div>`;
        html += `</div>`;

        if (index < steps.length - 1) {
            const lineClass = isComplete ? 'modgen-step-line-complete' : 'modgen-step-line';
            html += `<div class="${lineClass} flex-fill" style="height: 2px; margin-top: -1rem;"></div>`;
        }
    });

    html += '</div>';
    html += '</div>';

    return html;
};

/**
 * Update the progress header in the modal body.
 * @param {Object} root jQuery root element
 * @param {number} step Current step
 */
const updateProgressHeader = (root, step) => {
    const $header = root.find('.modgen-progress-header');
    if ($header.length) {
        $header.replaceWith(buildSuggestProgressHeader(step));
    }
};

export default {
    init(modal, courseid, currentsection = 0) {
        // Build AJAX URLs using Moodle config (proper ES6 module way)
        const SUGGEST_AJAX = Config.wwwroot + '/ai/placement/modgen/ajax/suggest.php';
        const CREATE_AJAX = Config.wwwroot + '/ai/placement/modgen/ajax/suggest_create.php';
        // Note: Colors are now retrieved from the server via AJAX (centralized in learning_type_colors.php)
        const root = modal.getRoot();
        
        // Current step tracking
        let currentStep = SUGGEST_STEPS.SELECT;
        
        // Add the suggest progress header at the top of the modal body
        const $body = modal.getBody();
        if ($body && $body.length) {
            $body.prepend(buildSuggestProgressHeader(currentStep));
        }
        
        // Try to make the modal dialog a bit wider for this tool so chart + list can sit side-by-side.
        try {
            const $dialog = root.closest('.modal-dialog');
            if ($dialog && $dialog.length) {
                // Remove any existing Bootstrap modal size classes (eg. modal-xl) so
                // our xxl variant takes effect cleanly.
                try {
                    $dialog.removeClass(function(index, className) {
                        return (className || '').split(/\s+/).filter(function(c) { return /^modal-/.test(c); }).join(' ');
                    });
                } catch (e) {
                    // fallback: explicitly remove common classes
                    $dialog.removeClass('modal-sm modal-lg modal-xl modal-xxl modal-fullscreen modal-fullscreen-sm-down modal-fullscreen-md-down modal-fullscreen-lg-down');
                }
                $dialog.addClass('aiplacement-modgen-xxl');
            }
        } catch (e) {
            // ignore if DOM structure differs
        }
        const $select = root.find('#suggest-section-select');
        const $results = root.find('#suggest-results');

        /**
         * Show or hide loading indicator.
         * @param {boolean} show Whether to show loading
         * @param {string} message Loading message to display
         */
        const showLoading = async(show, message = '') => {
            const resultsContainer = $results.get(0);
            if (show && resultsContainer) {
                const loadingMessage = message || await getString('generatingsuggestions', 'aiplacement_modgen');
                await LoadingIndicator.show(resultsContainer, loadingMessage);
                modal.setFooter('');
            } else if (!show && resultsContainer) {
                LoadingIndicator.hide(resultsContainer);
            }
        };
        
        /**
         * Update footer buttons based on current step.
         * @param {number} step Current step
         * @param {boolean} createEnabled Whether create button should be enabled
         */
        const updateFooterForStep = (step, createEnabled = false) => {
            let footerHtml = '';
            
            if (step === SUGGEST_STEPS.SELECT) {
                // Show scan button only
                const scanLabel = M.util.get_string('suggestactivities', 'aiplacement_modgen') || 'Scan for suggestions';
                footerHtml = '<button class="btn btn-primary" id="suggest-scan-btn-footer">' + scanLabel + '</button>';
            } else if (step === SUGGEST_STEPS.REVIEW) {
                // Show create button
                const createLabel = M.util.get_string('approveandcreate', 'aiplacement_modgen') || 'Create selected';
                const disabledAttr = createEnabled ? '' : 'disabled';
                footerHtml = '<button class="btn btn-success" id="suggest-create-footer" ' + disabledAttr + '>' + createLabel + '</button>';
            }
            // For SCANNING and CREATING steps, footer stays empty (cleared by showLoading)
            
            if (footerHtml) {
                modal.setFooter(footerHtml);
                // Re-bind event handlers
                const newFooter = modal.getFooter();
                newFooter.find('#suggest-scan-btn-footer').on('click', handleScan);
                newFooter.find('#suggest-create-footer').on('click', handleCreate);
            }
        };
        
        /**
         * Update create button enabled state in footer.
         * @param {boolean} enabled Whether button should be enabled
         */
        const updateCreateButtonState = (enabled) => {
            const newFooter = modal.getFooter();
            const $btn = newFooter.find('#suggest-create-footer');
            if ($btn.length) {
                $btn.prop('disabled', !enabled);
            }
        };

        // Chart state
        let learningTypesChart = null;
        let baseChartData = null; // { labels: [], data: [], colors: [] }
        let updateTimeout = null; // debounce handle
        let activityTypeColors = {}; // Store colors fetched from server for badge styling

        const createLearningTypesChart = (chartData) => {
            if (!chartData || !chartData.labels) {
                return;
            }

            // Initialize baseline chart data only once (server-provided baseline).
            if (!baseChartData) {
                baseChartData = {
                    labels: chartData.labels.slice(),
                    data: chartData.data.slice(),
                    colors: chartData.colors.slice()
                };
            }

            // Use the supplied chartData for rendering/update, but preserve baseline.
            const chartToApply = {
                labels: chartData.labels.slice(),
                data: chartData.data.slice(),
                colors: chartData.colors.slice()
            };

            require(['jquery', 'core/chartjs'], function($, ChartJS) {
                const canvas = document.getElementById('suggest-learning-types-chart');
                if (!canvas) {
                    return;
                }
                const ctx = canvas.getContext('2d');

                // Update existing chart in-place for smooth animation.
                if (learningTypesChart) {
                    try {
                        learningTypesChart.data.labels = chartToApply.labels;
                        if (learningTypesChart.data.datasets && learningTypesChart.data.datasets.length) {
                            learningTypesChart.data.datasets[0].data = chartToApply.data;
                            learningTypesChart.data.datasets[0].backgroundColor = chartToApply.colors;
                        } else {
                            learningTypesChart.data.datasets = [{ data: chartToApply.data, backgroundColor: chartToApply.colors, borderColor: '#fff', borderWidth: 2 }];
                        }
                        // gentle animation on update
                        if (learningTypesChart.options) {
                            learningTypesChart.options.animation = { duration: 400, easing: 'easeOutQuart' };
                        }
                        learningTypesChart.update();
                    } catch (e) {
                        try { learningTypesChart.destroy(); } catch (ex) { /* ignore */ }
                        learningTypesChart = null;
                    }
                }

                if (!learningTypesChart) {
                    const config = {
                        type: 'pie',
                        data: {
                            labels: chartToApply.labels,
                            datasets: [{ data: chartToApply.data, backgroundColor: chartToApply.colors, borderColor: '#fff', borderWidth: 2 }]
                        },
                        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } }, animation: { duration: 400, easing: 'easeOutQuart' } }
                    };

                    try {
                        learningTypesChart = new Chart(ctx, config);
                    } catch (e) {
                        console.error('Chart render failed', e);
                    }
                }

                // Render legend next to the chart (use chartToApply counts)
                const $legend = $('#suggest-learning-types-legend');
                $legend.empty();
                chartToApply.labels.forEach((label, idx) => {
                    const color = chartToApply.colors[idx] || '#ccc';
                    const count = chartToApply.data[idx] || 0;
                    const $item = $('<div/>').addClass('mb-1');
                    const $sw = $('<span/>').css({ display: 'inline-block', width: '12px', height: '12px', 'background-color': color, 'margin-right': '8px', 'vertical-align': 'middle' });
                    $item.append($sw).append(document.createTextNode(' ' + label + ': ' + count));
                    $legend.append($item);
                });
            });
        };

        // Remove the wide dialog class when modal is closed to avoid leaking style.
        try {
            const $modalEl = root.closest('.modal');
            if ($modalEl && $modalEl.length) {
                $modalEl.on('hidden.bs.modal', function() {
                    $modalEl.removeClass('aiplacement-modgen-modal-wide');
                });
            }
        } catch (e) {
            // ignore if event system not present
        }

        const updateChartWithSelections = () => {
            if (!baseChartData) {
                return;
            }
            // Deep copy base counts
            const newData = baseChartData.data.slice();
            const labels = baseChartData.labels;

            // For each selected suggestion, add 1 to the corresponding laurillard label if known
            $results.find('.suggest-item').each(function() {
                const $card = $(this);
                const $cb = $card.find('input.suggest-checkbox');
                if ($cb.length && $cb.prop('checked')) {
                    const s = $card.data('suggestion');
                    if (!s) { return; }
                    const lt = (s.laurillard_type || s.laurillardType || '').toString().trim().toLowerCase();
                    if (!lt) {
                        // Try mapping from activity.type
                        const at = (s.activity && s.activity.type) ? s.activity.type.toString().toLowerCase() : '';
                        // Map common activity types to Laurillard types
                        const mapping = {
                            'page': 'acquisition', 'book': 'acquisition', 'resource': 'acquisition', 'label': 'acquisition', 'url': 'acquisition',
                            'forum': 'discussion', 'chat': 'discussion',
                            'choice': 'inquiry', 'survey': 'inquiry', 'workshop': 'inquiry',
                            'lesson': 'practice', 'feedback': 'practice',
                            'assign': 'production', 'assignment': 'production', 'quiz': 'production', 'scorm': 'production',
                            'bigbluebuttonbn': 'collaboration', 'zoom': 'collaboration'
                        };
                        const mapped = mapping[at] || '';
                        if (mapped) {
                            lt = mapped;
                        }
                    }
                    if (lt) {
                        const idx = labels.findIndex(l => l.toString().toLowerCase() === lt);
                        if (idx >= 0) {
                            newData[idx] = (newData[idx] || 0) + 1;
                        }
                    }
                }
            });

            const newChart = { labels: baseChartData.labels, data: newData, colors: baseChartData.colors };
            createLearningTypesChart(newChart);
        };

        /**
         * Handle scan button click - fetches suggestions from AI.
         * @param {Event} ev Click event
         */
        const handleScan = async(ev) => {
            ev.preventDefault();
            const section = $select.val();
            
            // Update step and progress header
            currentStep = SUGGEST_STEPS.SCANNING;
            updateProgressHeader(root, currentStep);
            
            await showLoading(true);
            $results.empty();
            // Hide the summary until we have suggestion results to display
            root.find('#suggest-summary').hide();

            const params = new URLSearchParams();
            params.append('courseid', courseid);
            params.append('section', section);
            params.append('sesskey', Config.sesskey);

            fetch(SUGGEST_AJAX, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            }).then(r => r.text()).then(text => {
                showLoading(false);
                let data;
                try {
                    data = JSON.parse(text);
                } catch (err) {
                    // Show raw response for debugging
                    Notification.exception(new Error('Invalid JSON response from server'));
                    console.error('Suggest endpoint returned non-JSON:', text);
                    // Try to decode debug_extra_base64 if present in text
                    try {
                        const maybe = JSON.parse(text.replace(/^\s+/, ''));
                        if (maybe && maybe.debug_extra_base64) {
                            const decoded = atob(maybe.debug_extra_base64);
                            console.error('Decoded debug_extra_base64:', decoded);
                        }
                    } catch (e) {
                        // ignore
                    }
                    return;
                }
                if (data.success) {
                    const suggestions = data.suggestions || [];
                    // Initialize chart data from server-provided summary if present
                    if (data.current_learning_types) {
                        baseChartData = {
                            labels: data.current_learning_types.labels || [],
                            data: data.current_learning_types.data || [],
                            colors: data.current_learning_types.colors || []
                        };
                        // Also store activity type colors for badge styling (map labels to colors)
                        const labels = data.current_learning_types.labels || [];
                        const colors = data.current_learning_types.colors || [];
                        labels.forEach((label, idx) => {
                            const key = String(label).toLowerCase().trim();
                            activityTypeColors[key] = colors[idx] || null;
                        });
                        // Create initial chart
                        createLearningTypesChart(baseChartData);
                    } else {
                        baseChartData = null;
                    }
                    $results.empty();
                    if (!suggestions.length) {
                        $results.append('<div class="alert alert-info">' + M.util.get_string('suggest_noresults','aiplacement_modgen') + '</div>');
                        // No suggestions -> back to select step
                        currentStep = SUGGEST_STEPS.SELECT;
                        updateProgressHeader(root, currentStep);
                        updateFooterForStep(currentStep);
                        // No suggestions -> keep summary hidden
                        root.find('#suggest-summary').hide();
                        // Remove wide modal class if no suggestions
                        root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
                        return;
                    }

                    const $list = $('<div/>').addClass('list-group');
                    
                    // Render each suggestion using template
                    const renderPromises = suggestions.map(s => {
                        const activityName = (s.activity && s.activity.name ? s.activity.name : 'Activity');
                        const activityType = (s.activity && s.activity.type ? s.activity.type : '?');
                        const lauri = s.laurillard_type || s.laurillardType || '';
                        const lc = lauri ? String(lauri).toLowerCase().trim() : '';
                        const color = lc ? activityTypeColors[lc] : null;
                        
                        const context = {
                            id: s.id || '',
                            activity: {
                                name: activityName,
                                type: activityType
                            },
                            laurillard_type: lauri,
                            laurillard_color: color || '',
                            supported: s.supported !== false,
                            raw_type: s.raw_type || activityType || '',
                            rationale: s.rationale || '',
                            laurillard_rationale: s.laurillard_rationale || s.laurillardRationale || ''
                        };
                        
                        return Templates.renderForPromise('aiplacement_modgen/suggest_item', context)
                            .then(result => {
                                const $card = $(result.html);
                                $card.data('suggestion', s);
                                $list.append($card);
                                return result;
                            });
                    });
                    
                    // Wait for all templates to render
                    Promise.all(renderPromises).then(() => {
                        $results.append($list);
                        // Show summary area now that suggestion results are present
                        root.find('#suggest-summary').show();
                        // Make modal wide enough for chart + list
                        root.closest('.modal').addClass('aiplacement-modgen-modal-wide');
                        
                        // Update to review step
                        currentStep = SUGGEST_STEPS.REVIEW;
                        updateProgressHeader(root, currentStep);
                        updateFooterForStep(currentStep, false);
                        
                        // Debounced chart updater to avoid rapid re-renders when toggling
                        const scheduleChartUpdate = () => {
                            if (updateTimeout) {
                                clearTimeout(updateTimeout);
                            }
                            updateTimeout = setTimeout(() => {
                                updateChartWithSelections();
                                updateTimeout = null;
                            }, 150);
                        };

                        // Attach change handler to checkboxes to update the chart dynamically (debounced)
                        $results.find('input.suggest-checkbox').on('change', function() {
                            const isChecked = $(this).is(':checked');
                            const $item = $(this).closest('[role="option"]');
                            $item.attr('aria-selected', isChecked);
                            scheduleChartUpdate();
                            // Enable Create button only when at least one suggestion is checked
                            const anyChecked = $results.find('input.suggest-checkbox:checked').length > 0;
                            updateCreateButtonState(anyChecked);
                        });
                        // Immediately schedule an update to include any pre-checked suggestions (none by default)
                        scheduleChartUpdate();
                    }).catch(err => {
                        Notification.exception(err);
                        $results.append('<div class="alert alert-danger">Error rendering suggestions: ' + err.message + '</div>');
                        root.find('#suggest-summary').hide();
                        root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
                        // Back to select step on error
                        currentStep = SUGGEST_STEPS.SELECT;
                        updateProgressHeader(root, currentStep);
                        updateFooterForStep(currentStep);
                    });
                } else {
                    Notification.exception(new Error(data.error || 'No suggestions'));
                    $results.append('<div class="alert alert-danger">' + (data.error || 'Error fetching suggestions') + '</div>');
                    // Error or no data -> hide summary
                    root.find('#suggest-summary').hide();
                    // Remove wide modal class on error
                    root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
                    // Back to select step
                    currentStep = SUGGEST_STEPS.SELECT;
                    updateProgressHeader(root, currentStep);
                    updateFooterForStep(currentStep);
                }
            }).catch(err => {
                showLoading(false);
                Notification.exception(err);
                // On network/error, ensure summary is hidden
                root.find('#suggest-summary').hide();
                // Remove wide modal class on error
                root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
                // Back to select step
                currentStep = SUGGEST_STEPS.SELECT;
                updateProgressHeader(root, currentStep);
                updateFooterForStep(currentStep);
            });
        };

        /**
         * Handle create button click - creates selected activities.
         * @param {Event} ev Click event
         */
        const handleCreate = async(ev) => {
            ev.preventDefault();
            const selected = [];
            const skipped = [];
            $results.find('.suggest-item').each(function() {
                const $card = $(this);
                const $cb = $card.find('input.suggest-checkbox');
                if ($cb.length && $cb.prop('checked')) {
                    const s = $card.data('suggestion');
                    if (!s) {
                        return;
                    }
                    // Skip unsupported suggestions or unclear types to avoid server warnings.
                    const type = (s.activity && s.activity.type) ? String(s.activity.type).trim() : '';
                    if (s.supported === false || type === '' || type === '?') {
                        skipped.push(s.activity && (s.activity.name || s.activity.type) ? (s.activity.name || s.activity.type) : '(unknown)');
                        return;
                    }
                    
                    // Use rationale as intro/description
                    const description = s.rationale || '';
                    
                    // Clone the suggestion and add intro to the activity sub-object
                    const suggestionWithIntro = Object.assign({}, s);
                    suggestionWithIntro.activity = Object.assign({}, s.activity);
                    suggestionWithIntro.activity.intro = description;
                    
                    selected.push(suggestionWithIntro);
                }
            });

            if (selected.length === 0) {
                if (skipped.length) {
                    Notification.exception(new Error('Some selected items were skipped because their activity type is unsupported or unknown: ' + skipped.join(', ')));
                } else {
                    Notification.exception(new Error('No items selected'));
                }
                return;
            }

            const params = new URLSearchParams();
            params.append('courseid', courseid);
            params.append('section', $select.val());
            params.append('selected', JSON.stringify(selected));
            params.append('sesskey', Config.sesskey);

            // Update to creating step
            currentStep = SUGGEST_STEPS.CREATING;
            updateProgressHeader(root, currentStep);
            
            await showLoading(true);
            fetch(CREATE_AJAX, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            }).then(r => r.json()).then(data => {
                showLoading(false);
                if (data.success) {
                    // Build success HTML with created items and warnings if present
                    let html = '<div class="alert alert-success">Created ' + (data.created ? data.created.length : 0) + ' activities.</div>';
                    if (data.created && data.created.length) {
                        html += '<ul class="mt-2">';
                        data.created.forEach(c => {
                            html += '<li>' + $('<div/>').text(c).html() + '</li>';
                        });
                        html += '</ul>';
                    }
                    if (data.warnings && data.warnings.length) {
                        html += '<div class="alert alert-warning mt-2"><strong>' + (M.util.get_string('creation_warnings','aiplacement_modgen') || 'Warnings') + ':</strong><ul>';
                        data.warnings.forEach(w => {
                            html += '<li>' + $('<div/>').text(w).html() + '</li>';
                        });
                        html += '</ul></div>';
                    }
                    $results.html(html);
                    // After creating, suggestion list is replaced by result HTML -> hide summary
                    root.find('#suggest-summary').hide();
                    // Remove wide modal class after creation
                    root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
                    // Keep step at CREATING (completed state) - show close button in footer
                    modal.setFooter('<button class="btn btn-primary" data-action="hide">Close</button>');
                } else {
                    Notification.exception(new Error(data.error || 'Creation failed'));
                    $results.append('<div class="alert alert-danger">' + (data.error || 'Creation failed') + '</div>');
                    if (data.debug_extra_base64) {
                        try {
                            const decoded = atob(data.debug_extra_base64);
                            $results.append('<pre class="mt-2">' + $('<div/>').text(decoded).html() + '</pre>');
                        } catch (e) {
                            // ignore
                        }
                    }
                    // Back to review step on error
                    currentStep = SUGGEST_STEPS.REVIEW;
                    updateProgressHeader(root, currentStep);
                    updateFooterForStep(currentStep, true);
                }
            }).catch(err => {
                showLoading(false);
                Notification.exception(err);
                // Back to review step on error
                currentStep = SUGGEST_STEPS.REVIEW;
                updateProgressHeader(root, currentStep);
                updateFooterForStep(currentStep, true);
            });
        };
        
        // Initialize footer with scan button
        updateFooterForStep(SUGGEST_STEPS.SELECT);
        
        // Also listen for clicks on the original buttons in the body (in case they're still there)
        root.on('click', '#suggest-scan-btn', handleScan);
        root.on('click', '#suggest-create-selected', handleCreate);
    }
};
