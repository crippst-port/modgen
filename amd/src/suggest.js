import Notification from 'core/notification';
import $ from 'jquery';

const SUGGEST_AJAX = M.cfg.wwwroot + '/ai/placement/modgen/ajax/suggest.php';
const CREATE_AJAX = M.cfg.wwwroot + '/ai/placement/modgen/ajax/suggest_create.php';

export default {
    init(modal, courseid, currentsection = 0) {
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
        const $select = root.find('#suggest-section-select');
        const $loading = root.find('#suggest-loading');
        const $results = root.find('#suggest-results');
        const $createBtn = root.find('#suggest-create-selected');

        const showLoading = (show) => {
            if ($loading && $loading.length) {
                $loading.toggle(show);
            }
        };

        root.on('click', '#suggest-scan-btn', (ev) => {
            ev.preventDefault();
            const section = $select.val();
            showLoading(true);
            $results.empty();
            $createBtn.prop('disabled', true);

            const params = new URLSearchParams();
            params.append('courseid', courseid);
            params.append('section', section);
            params.append('sesskey', M.cfg.sesskey);

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
                    $results.empty();
                    if (!suggestions.length) {
                        $results.append('<div class="alert alert-info">' + M.util.get_string('suggest_noresults','aiplacement_modgen') + '</div>');
                        $createBtn.prop('disabled', true);
                        return;
                    }

                    const $list = $('<div/>').addClass('list-group');
                    suggestions.forEach(s => {
                        const id = s.id || '';
                        const $card = $('<div/>').addClass('list-group-item');
                        const $cb = $('<input/>').attr('type','checkbox').addClass('mr-2 suggest-checkbox').val(id);
                        if (s.supported !== false) {
                            $cb.prop('checked', true);
                        }
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
                    $createBtn.prop('disabled', false);
                } else {
                    Notification.exception(new Error(data.error || 'No suggestions'));
                    $results.append('<div class="alert alert-danger">' + (data.error || 'Error fetching suggestions') + '</div>');
                }
            }).catch(err => {
                showLoading(false);
                Notification.exception(err);
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
            params.append('sesskey', M.cfg.sesskey);

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
