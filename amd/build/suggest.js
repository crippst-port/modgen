define(["exports", "core/notification", "core/config", "jquery", "core/templates"], function (_exports, _notification, _config, _jquery, _templates) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.default = void 0;
  _notification = _interopRequireDefault(_notification);
  _config = _interopRequireDefault(_config);
  _jquery = _interopRequireDefault(_jquery);
  _templates = _interopRequireDefault(_templates);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  const SUGGEST_STEPS = {
    SELECT: 1,
    SCANNING: 2,
    REVIEW: 3,
    CREATING: 4
  };
  const SUGGEST_STEP_LABELS = {
    1: 'Select section',
    2: 'Suggesting',
    3: 'Review',
    4: 'Creating'
  };
  const buildSuggestProgressHeader = currentStep => {
    const steps = [{
      num: SUGGEST_STEPS.SELECT,
      label: SUGGEST_STEP_LABELS[SUGGEST_STEPS.SELECT],
      icon: 'fa-list'
    }, {
      num: SUGGEST_STEPS.SCANNING,
      label: SUGGEST_STEP_LABELS[SUGGEST_STEPS.SCANNING],
      icon: 'fa-search'
    }, {
      num: SUGGEST_STEPS.REVIEW,
      label: SUGGEST_STEP_LABELS[SUGGEST_STEPS.REVIEW],
      icon: 'fa-eye'
    }, {
      num: SUGGEST_STEPS.CREATING,
      label: SUGGEST_STEP_LABELS[SUGGEST_STEPS.CREATING],
      icon: 'fa-check'
    }];
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
  const updateProgressHeader = (root, step) => {
    const $header = root.find('.modgen-progress-header');
    if ($header.length) {
      $header.replaceWith(buildSuggestProgressHeader(step));
    }
  };
  var _default = _exports.default = {
    init(modal, courseid) {
      let currentsection = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : 0;
      const SUGGEST_AJAX = _config.default.wwwroot + '/ai/placement/modgen/ajax/suggest.php';
      const CREATE_AJAX = _config.default.wwwroot + '/ai/placement/modgen/ajax/suggest_create.php';
      const root = modal.getRoot();
      let currentStep = SUGGEST_STEPS.SELECT;
      const $body = modal.getBody();
      if ($body && $body.length) {
        $body.prepend(buildSuggestProgressHeader(currentStep));
      }
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
      const showLoading = show => {
        if ($loading && $loading.length) {
          $loading.toggle(show);
        }
        if (show) {
          modal.setFooter('');
        }
      };
      const updateFooterForStep = function (step) {
        let createEnabled = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : false;
        let footerHtml = '';
        if (step === SUGGEST_STEPS.SELECT) {
          const scanLabel = M.util.get_string('suggestactivities', 'aiplacement_modgen') || 'Scan for suggestions';
          footerHtml = '<button class="btn btn-primary" id="suggest-scan-btn-footer">' + scanLabel + '</button>';
        } else if (step === SUGGEST_STEPS.REVIEW) {
          const createLabel = M.util.get_string('approveandcreate', 'aiplacement_modgen') || 'Create selected';
          const disabledAttr = createEnabled ? '' : 'disabled';
          footerHtml = '<button class="btn btn-success" id="suggest-create-footer" ' + disabledAttr + '>' + createLabel + '</button>';
        }
        if (footerHtml) {
          modal.setFooter(footerHtml);
          const newFooter = modal.getFooter();
          newFooter.find('#suggest-scan-btn-footer').on('click', handleScan);
          newFooter.find('#suggest-create-footer').on('click', handleCreate);
        }
      };
      const updateCreateButtonState = enabled => {
        const newFooter = modal.getFooter();
        const $btn = newFooter.find('#suggest-create-footer');
        if ($btn.length) {
          $btn.prop('disabled', !enabled);
        }
      };
      let learningTypesChart = null;
      let baseChartData = null;
      let updateTimeout = null;
      let activityTypeColors = {};
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
            $modalEl.removeClass('aiplacement-modgen-modal-wide');
          });
        }
      } catch (e) {}
      const updateChartWithSelections = () => {
        if (!baseChartData) {
          return;
        }
        const newData = baseChartData.data.slice();
        const labels = baseChartData.labels;
        $results.find('.suggest-item').each(function () {
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
      const handleScan = ev => {
        ev.preventDefault();
        const section = $select.val();
        currentStep = SUGGEST_STEPS.SCANNING;
        updateProgressHeader(root, currentStep);
        showLoading(true);
        $results.empty();
        root.find('#suggest-summary').hide();
        const params = new URLSearchParams();
        params.append('courseid', courseid);
        params.append('section', section);
        params.append('sesskey', _config.default.sesskey);
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
              const labels = data.current_learning_types.labels || [];
              const colors = data.current_learning_types.colors || [];
              labels.forEach((label, idx) => {
                const key = String(label).toLowerCase().trim();
                activityTypeColors[key] = colors[idx] || null;
              });
              createLearningTypesChart(baseChartData);
            } else {
              baseChartData = null;
            }
            $results.empty();
            if (!suggestions.length) {
              $results.append('<div class="alert alert-info">' + M.util.get_string('suggest_noresults', 'aiplacement_modgen') + '</div>');
              currentStep = SUGGEST_STEPS.SELECT;
              updateProgressHeader(root, currentStep);
              updateFooterForStep(currentStep);
              root.find('#suggest-summary').hide();
              root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
              return;
            }
            const $list = (0, _jquery.default)('<div/>').addClass('list-group');
            const renderPromises = suggestions.map(s => {
              const activityName = s.activity && s.activity.name ? s.activity.name : 'Activity';
              const activityType = s.activity && s.activity.type ? s.activity.type : '?';
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
              return _templates.default.renderForPromise('aiplacement_modgen/suggest_item', context).then(result => {
                const $card = (0, _jquery.default)(result.html);
                $card.data('suggestion', s);
                $list.append($card);
                return result;
              });
            });
            Promise.all(renderPromises).then(() => {
              $results.append($list);
              root.find('#suggest-summary').show();
              root.closest('.modal').addClass('aiplacement-modgen-modal-wide');
              currentStep = SUGGEST_STEPS.REVIEW;
              updateProgressHeader(root, currentStep);
              updateFooterForStep(currentStep, false);
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
                const isChecked = (0, _jquery.default)(this).is(':checked');
                const $item = (0, _jquery.default)(this).closest('[role="option"]');
                $item.attr('aria-selected', isChecked);
                scheduleChartUpdate();
                const anyChecked = $results.find('input.suggest-checkbox:checked').length > 0;
                updateCreateButtonState(anyChecked);
              });
              scheduleChartUpdate();
            }).catch(err => {
              _notification.default.exception(err);
              $results.append('<div class="alert alert-danger">Error rendering suggestions: ' + err.message + '</div>');
              root.find('#suggest-summary').hide();
              root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
              currentStep = SUGGEST_STEPS.SELECT;
              updateProgressHeader(root, currentStep);
              updateFooterForStep(currentStep);
            });
          } else {
            _notification.default.exception(new Error(data.error || 'No suggestions'));
            $results.append('<div class="alert alert-danger">' + (data.error || 'Error fetching suggestions') + '</div>');
            root.find('#suggest-summary').hide();
            root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
            currentStep = SUGGEST_STEPS.SELECT;
            updateProgressHeader(root, currentStep);
            updateFooterForStep(currentStep);
          }
        }).catch(err => {
          showLoading(false);
          _notification.default.exception(err);
          root.find('#suggest-summary').hide();
          root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
          currentStep = SUGGEST_STEPS.SELECT;
          updateProgressHeader(root, currentStep);
          updateFooterForStep(currentStep);
        });
      };
      const handleCreate = ev => {
        ev.preventDefault();
        const selected = [];
        const skipped = [];
        $results.find('.suggest-item').each(function () {
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
            const description = s.rationale || '';
            const suggestionWithIntro = Object.assign({}, s);
            suggestionWithIntro.activity = Object.assign({}, s.activity);
            suggestionWithIntro.activity.intro = description;
            selected.push(suggestionWithIntro);
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
        params.append('sesskey', _config.default.sesskey);
        currentStep = SUGGEST_STEPS.CREATING;
        updateProgressHeader(root, currentStep);
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
            root.closest('.modal').removeClass('aiplacement-modgen-modal-wide');
            modal.setFooter('<button class="btn btn-primary" data-action="hide">Close</button>');
          } else {
            _notification.default.exception(new Error(data.error || 'Creation failed'));
            $results.append('<div class="alert alert-danger">' + (data.error || 'Creation failed') + '</div>');
            if (data.debug_extra_base64) {
              try {
                const decoded = atob(data.debug_extra_base64);
                $results.append('<pre class="mt-2">' + (0, _jquery.default)('<div/>').text(decoded).html() + '</pre>');
              } catch (e) {}
            }
            currentStep = SUGGEST_STEPS.REVIEW;
            updateProgressHeader(root, currentStep);
            updateFooterForStep(currentStep, true);
          }
        }).catch(err => {
          showLoading(false);
          _notification.default.exception(err);
          currentStep = SUGGEST_STEPS.REVIEW;
          updateProgressHeader(root, currentStep);
          updateFooterForStep(currentStep, true);
        });
      };
      updateFooterForStep(SUGGEST_STEPS.SELECT);
      root.on('click', '#suggest-scan-btn', handleScan);
      root.on('click', '#suggest-create-selected', handleCreate);
    }
  };
});
