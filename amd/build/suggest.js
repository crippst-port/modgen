define(["exports", "core/notification", "jquery"], function (_exports, _notification, _jquery) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.default = void 0;
  _notification = _interopRequireDefault(_notification);
  _jquery = _interopRequireDefault(_jquery);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  const SUGGEST_AJAX = M.cfg.wwwroot + '/ai/placement/modgen/ajax/suggest.php';
  const CREATE_AJAX = M.cfg.wwwroot + '/ai/placement/modgen/ajax/suggest_create.php';
  var _default = _exports.default = {
    init(modal, courseid) {
      let currentsection = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : 0;
      const LAURILLARD_COLORS = {
        'acquisition': 'rgba(66, 139, 202, 0.9)',
        'inquiry': 'rgba(255, 152, 0, 0.9)',
        'practice': 'rgba(255, 193, 7, 0.9)',
        'discussion': 'rgba(40, 167, 69, 0.9)',
        'collaboration': 'rgba(75, 192, 192, 0.9)',
        'production': 'rgba(220, 53, 69, 0.9)'
      };
      const root = modal.getRoot();
      try {
        const $dialog = root.closest('.modal-dialog');
        if ($dialog && $dialog.length) {
          try {
            $dialog.removeClass(function (index, className) {
              return (className || '').split(/\s+/).filter(function (c) {
                return /^modal-/.test(c);
              }).join(' ');
            });
          } catch (e) {
            $dialog.removeClass('modal-sm modal-lg modal-xl modal-xxl modal-fullscreen modal-fullscreen-sm-down modal-fullscreen-md-down modal-fullscreen-lg-down');
          }
          $dialog.addClass('aiplacement-modgen-xxl');
        }
      } catch (e) {}
      const $select = root.find('#suggest-section-select');
      const $loading = root.find('#suggest-loading');
      const $results = root.find('#suggest-results');
      const $createBtn = root.find('#suggest-create-selected');
      const showLoading = show => {
        if ($loading && $loading.length) {
          $loading.toggle(show);
        }
      };
      let learningTypesChart = null;
      let baseChartData = null;
      let updateTimeout = null;
      const createLearningTypesChart = chartData => {
        if (!chartData || !chartData.labels) {
          return;
        }
        if (!baseChartData) {
          baseChartData = {
            labels: chartData.labels.slice(),
            data: chartData.data.slice(),
            colors: chartData.colors.slice()
          };
        }
        const chartToApply = {
          labels: chartData.labels.slice(),
          data: chartData.data.slice(),
          colors: chartData.colors.slice()
        };
        require(['jquery', 'core/chartjs'], function ($, ChartJS) {
          const canvas = document.getElementById('suggest-learning-types-chart');
          if (!canvas) {
            return;
          }
          const ctx = canvas.getContext('2d');
          if (learningTypesChart) {
            try {
              learningTypesChart.data.labels = chartToApply.labels;
              if (learningTypesChart.data.datasets && learningTypesChart.data.datasets.length) {
                learningTypesChart.data.datasets[0].data = chartToApply.data;
                learningTypesChart.data.datasets[0].backgroundColor = chartToApply.colors;
              } else {
                learningTypesChart.data.datasets = [{
                  data: chartToApply.data,
                  backgroundColor: chartToApply.colors,
                  borderColor: '#fff',
                  borderWidth: 2
                }];
              }
              if (learningTypesChart.options) {
                learningTypesChart.options.animation = {
                  duration: 400,
                  easing: 'easeOutQuart'
                };
              }
              learningTypesChart.update();
            } catch (e) {
              try {
                learningTypesChart.destroy();
              } catch (ex) {}
              learningTypesChart = null;
            }
          }
          if (!learningTypesChart) {
            const config = {
              type: 'pie',
              data: {
                labels: chartToApply.labels,
                datasets: [{
                  data: chartToApply.data,
                  backgroundColor: chartToApply.colors,
                  borderColor: '#fff',
                  borderWidth: 2
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                  legend: {
                    display: false
                  }
                },
                animation: {
                  duration: 400,
                  easing: 'easeOutQuart'
                }
              }
            };
            try {
              learningTypesChart = new Chart(ctx, config);
            } catch (e) {
              console.error('Chart render failed', e);
            }
          }
          const $legend = $('#suggest-learning-types-legend');
          $legend.empty();
          chartToApply.labels.forEach((label, idx) => {
            const color = chartToApply.colors[idx] || '#ccc';
            const count = chartToApply.data[idx] || 0;
            const $item = $('<div/>').addClass('mb-1');
            const $sw = $('<span/>').css({
              display: 'inline-block',
              width: '12px',
              height: '12px',
              'background-color': color,
              'margin-right': '8px',
              'vertical-align': 'middle'
            });
            $item.append($sw).append(document.createTextNode(' ' + label + ': ' + count));
            $legend.append($item);
          });
        });
      };
      try {
        const $modalEl = root.closest('.modal');
        if ($modalEl && $modalEl.length) {
          $modalEl.on('hidden.bs.modal', function () {
            const $dialog = root.closest('.modal-dialog');
            if ($dialog && $dialog.length) {
              $dialog.removeClass('aiplacement-modgen-xxl');
              try {
                $dialog.each(function () {
                  this.style.removeProperty('max-width');
                });
              } catch (e) {}
            }
          });
        }
      } catch (e) {}
      const updateChartWithSelections = () => {
        if (!baseChartData) {
          return;
        }
        const newData = baseChartData.data.slice();
        const labels = baseChartData.labels;
        $results.find('.list-group-item').each(function () {
          const $card = (0, _jquery.default)(this);
          const $cb = $card.find('input.suggest-checkbox');
          if ($cb.length && $cb.prop('checked')) {
            const s = $card.data('suggestion');
            if (!s) {
              return;
            }
            const lt = (s.laurillard_type || s.laurillardType || '').toString().trim().toLowerCase();
            if (!lt) {
              const at = s.activity && s.activity.type ? s.activity.type.toString().toLowerCase() : '';
              const mapping = {
                'page': 'acquisition',
                'book': 'acquisition',
                'resource': 'acquisition',
                'label': 'acquisition',
                'url': 'acquisition',
                'forum': 'discussion',
                'chat': 'discussion',
                'choice': 'inquiry',
                'survey': 'inquiry',
                'workshop': 'inquiry',
                'lesson': 'practice',
                'feedback': 'practice',
                'assign': 'production',
                'assignment': 'production',
                'quiz': 'production',
                'scorm': 'production',
                'bigbluebuttonbn': 'collaboration',
                'zoom': 'collaboration'
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
        const newChart = {
          labels: baseChartData.labels,
          data: newData,
          colors: baseChartData.colors
        };
        createLearningTypesChart(newChart);
      };
      root.on('click', '#suggest-scan-btn', ev => {
        ev.preventDefault();
        const section = $select.val();
        showLoading(true);
        $results.empty();
        root.find('#suggest-summary').hide();
        $createBtn.prop('disabled', true);
        const params = new URLSearchParams();
        params.append('courseid', courseid);
        params.append('section', section);
        params.append('sesskey', M.cfg.sesskey);
        fetch(SUGGEST_AJAX, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: params.toString()
        }).then(r => r.text()).then(text => {
          showLoading(false);
          let data;
          try {
            data = JSON.parse(text);
          } catch (err) {
            _notification.default.exception(new Error('Invalid JSON response from server'));
            console.error('Suggest endpoint returned non-JSON:', text);
            try {
              const maybe = JSON.parse(text.replace(/^\s+/, ''));
              if (maybe && maybe.debug_extra_base64) {
                const decoded = atob(maybe.debug_extra_base64);
                console.error('Decoded debug_extra_base64:', decoded);
              }
            } catch (e) {}
            return;
          }
          if (data.success) {
            const suggestions = data.suggestions || [];
            if (data.current_learning_types) {
              baseChartData = {
                labels: data.current_learning_types.labels || [],
                data: data.current_learning_types.data || [],
                colors: data.current_learning_types.colors || []
              };
              createLearningTypesChart(baseChartData);
            } else {
              baseChartData = null;
            }
            $results.empty();
            if (!suggestions.length) {
              $results.append('<div class="alert alert-info">' + M.util.get_string('suggest_noresults', 'aiplacement_modgen') + '</div>');
              $createBtn.prop('disabled', true);
              root.find('#suggest-summary').hide();
              try {
                const $dialog = root.closest('.modal-dialog');
                if ($dialog && $dialog.length) {
                  $dialog.each(function () {
                    try {
                      this.style.removeProperty('max-width');
                    } catch (e) {}
                  });
                }
              } catch (e) {}
              return;
            }
            const $list = (0, _jquery.default)('<div/>').addClass('list-group');
            suggestions.forEach(s => {
              const id = s.id || '';
              const $card = (0, _jquery.default)('<div/>').addClass('list-group-item');
              const $cb = (0, _jquery.default)('<input/>').attr('type', 'checkbox').addClass('mr-2 suggest-checkbox').val(id);
              const activityName = s.activity && s.activity.name ? s.activity.name : 'Activity';
              const activityType = s.activity && s.activity.type ? s.activity.type : '?';
              const $title = (0, _jquery.default)('<strong/>').text(activityName + ' (' + activityType + ')');
              const lauri = s.laurillard_type || s.laurillardType || '';
              if (lauri) {
                const lc = String(lauri).toLowerCase().trim();
                const color = LAURILLARD_COLORS[lc] || null;
                const $lauriBadge = (0, _jquery.default)('<span/>').addClass('ml-2').attr('title', lauri).text(lauri);
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
              if (s.supported === false) {
                const raw = s.raw_type || activityType || '';
                const $badge = (0, _jquery.default)('<span/>').addClass('badge badge-warning ml-2').attr('title', raw).text(M.util.get_string('unsupported_label', 'aiplacement_modgen') || 'Unsupported');
                $title.append($badge);
              }
              const $rationale = (0, _jquery.default)('<p/>').addClass('mb-0 small text-muted').text(s.rationale || '');
              const lauriRationale = s.laurillard_rationale || s.laurillardRationale || '';
              const $lauriRationale = lauriRationale ? (0, _jquery.default)('<p/>').addClass('mb-0 small font-italic text-muted').text(lauriRationale) : (0, _jquery.default)();
              $card.append($cb).append($title).append('<br/>').append($rationale).append($lauriRationale);
              $card.data('suggestion', s);
              $list.append($card);
            });
            $results.append($list);
            root.find('#suggest-summary').show();
            try {
              const $dialog = root.closest('.modal-dialog');
              if ($dialog && $dialog.length) {
                $dialog.each(function () {
                  try {
                    this.style.setProperty('max-width', '1200px', 'important');
                  } catch (e) {}
                });
              }
            } catch (e) {}
            const scheduleChartUpdate = () => {
              if (updateTimeout) {
                clearTimeout(updateTimeout);
              }
              updateTimeout = setTimeout(() => {
                updateChartWithSelections();
                updateTimeout = null;
              }, 150);
            };
            $results.find('input.suggest-checkbox').on('change', function () {
              scheduleChartUpdate();
              const anyChecked = $results.find('input.suggest-checkbox:checked').length > 0;
              $createBtn.prop('disabled', !anyChecked);
            });
            scheduleChartUpdate();
            $createBtn.prop('disabled', true);
          } else {
            _notification.default.exception(new Error(data.error || 'No suggestions'));
            $results.append('<div class="alert alert-danger">' + (data.error || 'Error fetching suggestions') + '</div>');
            root.find('#suggest-summary').hide();
            try {
              const $dialog = root.closest('.modal-dialog');
              if ($dialog && $dialog.length) {
                $dialog.each(function () {
                  try {
                    this.style.removeProperty('max-width');
                  } catch (e) {}
                });
              }
            } catch (e) {}
          }
        }).catch(err => {
          showLoading(false);
          _notification.default.exception(err);
          root.find('#suggest-summary').hide();
          try {
            const $dialog = root.closest('.modal-dialog');
            if ($dialog && $dialog.length) {
              $dialog.each(function () {
                try {
                  this.style.removeProperty('max-width');
                } catch (e) {}
              });
            }
          } catch (e) {}
        });
      });
      root.on('click', '#suggest-create-selected', ev => {
        ev.preventDefault();
        const selected = [];
        const skipped = [];
        $results.find('.list-group-item').each(function () {
          const $card = (0, _jquery.default)(this);
          const $cb = $card.find('input.suggest-checkbox');
          if ($cb.length && $cb.prop('checked')) {
            const s = $card.data('suggestion');
            if (!s) {
              return;
            }
            const type = s.activity && s.activity.type ? String(s.activity.type).trim() : '';
            if (s.supported === false || type === '' || type === '?') {
              skipped.push(s.activity && (s.activity.name || s.activity.type) ? s.activity.name || s.activity.type : '(unknown)');
              return;
            }
            selected.push(s);
          }
        });
        if (selected.length === 0) {
          if (skipped.length) {
            _notification.default.exception(new Error('Some selected items were skipped because their activity type is unsupported or unknown: ' + skipped.join(', ')));
          } else {
            _notification.default.exception(new Error('No items selected'));
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
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: params.toString()
        }).then(r => r.json()).then(data => {
          showLoading(false);
          if (data.success) {
            let html = '<div class="alert alert-success">Created ' + (data.created ? data.created.length : 0) + ' activities.</div>';
            if (data.created && data.created.length) {
              html += '<ul class="mt-2">';
              data.created.forEach(c => {
                html += '<li>' + (0, _jquery.default)('<div/>').text(c).html() + '</li>';
              });
              html += '</ul>';
            }
            if (data.warnings && data.warnings.length) {
              html += '<div class="alert alert-warning mt-2"><strong>' + (M.util.get_string('creation_warnings', 'aiplacement_modgen') || 'Warnings') + ':</strong><ul>';
              data.warnings.forEach(w => {
                html += '<li>' + (0, _jquery.default)('<div/>').text(w).html() + '</li>';
              });
              html += '</ul></div>';
            }
            $results.html(html);
            root.find('#suggest-summary').hide();
            try {
              const $dialog = root.closest('.modal-dialog');
              if ($dialog && $dialog.length) {
                $dialog.each(function () {
                  try {
                    this.style.removeProperty('max-width');
                  } catch (e) {}
                });
              }
            } catch (e) {}
            $createBtn.prop('disabled', true);
          } else {
            _notification.default.exception(new Error(data.error || 'Creation failed'));
            $results.append('<div class="alert alert-danger">' + (data.error || 'Creation failed') + '</div>');
            if (data.debug_extra_base64) {
              try {
                const decoded = atob(data.debug_extra_base64);
                $results.append('<pre class="mt-2">' + (0, _jquery.default)('<div/>').text(decoded).html() + '</pre>');
              } catch (e) {}
            }
          }
        }).catch(err => {
          showLoading(false);
          _notification.default.exception(err);
        });
      });
    }
  };
});
