import Notification from 'core/notification';
import Config from 'core/config';
import $ from 'jquery';

export default {
    init(modal, courseid, currentsection = 0) {
        // Build AJAX URLs using Moodle config (proper ES6 module way)
        const SUGGEST_AJAX = Config.wwwroot + '/ai/placement/modgen/ajax/suggest.php';
        const CREATE_AJAX = Config.wwwroot + '/ai/placement/modgen/ajax/suggest_create.php';
        // Colour mapping for Laurillard learning types — keep consistent with Explore report palette
        const LAURILLARD_COLORS = {
            'acquisition': 'rgba(66, 139, 202, 0.9)',   // blue (Narrative)
            'inquiry': 'rgba(255, 152, 0, 0.9)',        // orange (Interactive)
            'practice': 'rgba(255, 193, 7, 0.9)',       // yellow (Adaptive)
            'discussion': 'rgba(40, 167, 69, 0.9)',     // green (Dialogic)
            'collaboration': 'rgba(75, 192, 192, 0.9)', // teal (custom within palette)
            'production': 'rgba(220, 53, 69, 0.9)',     // red (Productive)
        };
        const root = modal.getRoot();
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
        const $loading = root.find('#suggest-loading');
        const $results = root.find('#suggest-results');
        const $createBtn = root.find('#suggest-create-selected');

        const showLoading = (show) => {
            if ($loading && $loading.length) {
                $loading.toggle(show);
            }
        };

        // Chart state
        let learningTypesChart = null;
        let baseChartData = null; // { labels: [], data: [], colors: [] }
        let updateTimeout = null; // debounce handle

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
            $results.find('.list-group-item').each(function() {
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

        root.on('click', '#suggest-scan-btn', (ev) => {
            ev.preventDefault();
            const section = $select.val();
            showLoading(true);
            $results.empty();
            // Hide the summary until we have suggestion results to display
            root.find('#suggest-summary').hide();
            $createBtn.prop('disabled', true);

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
                        // Create initial chart
                        createLearningTypesChart(baseChartData);
                    } else {
                        baseChartData = null;
                    }
                    $results.empty();
                    if (!suggestions.length) {
                        $results.append('<div class="alert alert-info">' + M.util.get_string('suggest_noresults','aiplacement_modgen') + '</div>');
                        $createBtn.prop('disabled', true);
                        // No suggestions -> keep summary hidden
                        root.find('#suggest-summary').hide();
                        // Remove wide modal class if no suggestions
                        root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
                        return;
                    }

                    const $list = $('<div/>').addClass('list-group');
                    suggestions.forEach(s => {
                        const id = s.id || '';
                        const $card = $('<div/>').addClass('list-group-item');
                        const $cb = $('<input/>').attr('type','checkbox').addClass('mr-2 suggest-checkbox').val(id);
                        const activityName = (s.activity && s.activity.name ? s.activity.name : 'Activity');
                        const activityType = (s.activity && s.activity.type ? s.activity.type : '?');
                        const $title = $('<strong/>').text(activityName + ' (' + activityType + ')');

                        // Show Laurillard learning type badge if provided
                        const lauri = s.laurillard_type || s.laurillardType || '';
                        if (lauri) {
                            const lc = String(lauri).toLowerCase().trim();
                            const color = LAURILLARD_COLORS[lc] || null;
                            const $lauriBadge = $('<span/>')
                                .addClass('ml-2')
                                .attr('title', lauri)
                                .text(lauri);
                            if (color) {
                                $lauriBadge.css({
                                    'background-color': color,
                                    'color': '#fff',
                                    'padding': '0.25em 0.5em',
                                    'border-radius': '0.25rem',
                                    'font-size': '0.75em'
                                });
                            } else {
                                $lauriBadge.addClass('badge badge-info');
                            }
                            $title.append($lauriBadge);
                        }

                        // If suggestion is unsupported, show a visible badge and leave checkbox unchecked.
                        if (s.supported === false) {
                            const raw = s.raw_type || activityType || '';
                            const $badge = $('<span/>')
                                .addClass('badge badge-warning ml-2')
                                .attr('title', raw)
                                .text(M.util.get_string('unsupported_label','aiplacement_modgen') || 'Unsupported');
                            $title.append($badge);
                        }

                        const $rationale = $('<p/>').addClass('mb-0 small text-muted').text(s.rationale || '');
                        // Show laurillard rationale beneath the main rationale, if present
                        const lauriRationale = s.laurillard_rationale || s.laurillardRationale || '';
                        const $lauriRationale = lauriRationale ? $('<p/>').addClass('mb-0 small font-italic text-muted').text(lauriRationale) : $();
                        $card.append($cb).append($title).append('<br/>').append($rationale).append($lauriRationale);
                        $card.data('suggestion', s);
                        $list.append($card);
                    });

                    $results.append($list);
                    // Show summary area now that suggestion results are present
                    root.find('#suggest-summary').show();
                    // Make modal wide enough for chart + list
                    root.closest('.modal').addClass('aiplacement-modgen-modal-wide');
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
                        scheduleChartUpdate();
                        // Enable Create button only when at least one suggestion is checked
                        const anyChecked = $results.find('input.suggest-checkbox:checked').length > 0;
                        $createBtn.prop('disabled', !anyChecked);
                    });
                    // Immediately schedule an update to include any pre-checked suggestions (none by default)
                    scheduleChartUpdate();
                    // Ensure create button is disabled until user selects items
                    $createBtn.prop('disabled', true);
                } else {
                    Notification.exception(new Error(data.error || 'No suggestions'));
                    $results.append('<div class="alert alert-danger">' + (data.error || 'Error fetching suggestions') + '</div>');
                    // Error or no data -> hide summary
                    root.find('#suggest-summary').hide();
                    // Remove wide modal class on error
                    root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
                }
            }).catch(err => {
                showLoading(false);
                Notification.exception(err);
                // On network/error, ensure summary is hidden
                root.find('#suggest-summary').hide();
                // Remove wide modal class on error
                root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
            });
        });

        root.on('click', '#suggest-create-selected', (ev) => {
            ev.preventDefault();
            const selected = [];
            const skipped = [];
            $results.find('.list-group-item').each(function() {
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
                    selected.push(s);
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

            showLoading(true);
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
                    $createBtn.prop('disabled', true);
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
                }
            }).catch(err => {
                showLoading(false);
                Notification.exception(err);
            });
        });
    }
};
