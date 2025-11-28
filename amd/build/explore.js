define([], function () {
  "use strict";

  /**
   * Module exploration - Fetch AI insights and display module analysis.
   *
   * OVERVIEW:
   * This module handles the "Explore" page which displays AI-generated insights about
   * a course module. It fetches data from the server, processes it, renders it to the page,
   * and provides functionality for generating PDF reports.
   *
   * KEY FUNCTIONS:
   * - init(): Initialize the module and load insights
   * - loadInsights(): Fetch insights from the server via AJAX
   * - renderInsights(): Display insights in various page sections
   * - renderCharts(): Display interactive charts using Chart.js
   * - downloadReport(): Generate and download PDF reports
   *
   * ARCHITECTURE:
   * The module follows a functional pattern where:
   * 1. init() is called when the page loads
   * 2. loadInsights() fetches data from the server
   * 3. Data is processed and rendered to the DOM
   * 4. Charts are rendered after a short delay to ensure DOM is ready
   * 5. Download functionality is enabled
   *
   * @module     aiplacement_modgen/explore
   * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
   * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
   */

  define(['core/templates'], function (templates) {
    'use strict';
    var currentCourseId = null;
    var reportData = null;
    function extractTextFromSection(section) {
      if (!section) {
        return '';
      }
      var text = '';
      if (section.heading) {
        text += section.heading + '\n\n';
      }
      if (section.paragraphs && Array.isArray(section.paragraphs)) {
        text += section.paragraphs.join('\n\n');
      }
      return text;
    }
    function getElement(id) {
      return document.getElementById(id) || null;
    }
    function setElementDisplay(id, displayValue) {
      var el = getElement(id);
      if (el) {
        el.style.display = displayValue;
      }
    }
    return {
      init: function (courseId, chartData, activitySummary) {
        currentCourseId = courseId;
        this.enableRefreshButton(courseId);
        this.loadInsights(courseId);
      },
      loadInsights: function (courseId, refresh) {
        var self = this;
        var ajaxUrl = M.cfg.wwwroot + '/ai/placement/modgen/ajax/explore_ajax.php?courseid=' + courseId + '&sesskey=' + M.cfg.sesskey;
        if (refresh) {
          ajaxUrl += '&refresh=1';
        }
        fetch(ajaxUrl).then(function (response) {
          if (!response.ok) {
            throw new Error('HTTP error ' + response.status);
          }
          return response.json();
        }).then(function (data) {
          if (data.error || !data.success || !data.data) {
            self.hideLoadingAndShowContent();
            return;
          }
          self.processInsights(data.data);
          self.renderAllSections(data.data);
          self.hideLoadingAndShowContent();
          self.renderChartsIfAvailable(data.data);
          self.enableDownloadButton(courseId);
        }).catch(function (error) {
          self.hideLoadingAndShowContent();
        });
      },
      processInsights: function (data) {
        reportData = {
          pedagogical: extractTextFromSection(data.pedagogical),
          learningTypes: extractTextFromSection(data.learning_types),
          improvements: this.extractImprovementsText(data.improvements),
          chartData: data.chart_data
        };
      },
      extractImprovementsText: function (improvements) {
        if (!improvements) {
          return '';
        }
        var text = '';
        if (improvements.summary) {
          text += improvements.summary + '\n\n';
        }
        if (improvements.suggestions && Array.isArray(improvements.suggestions)) {
          text += improvements.suggestions.join('\n');
        }
        return text;
      },
      renderAllSections: function (data) {
        if (data.pedagogical) {
          this.renderPedagogicalSection(data.pedagogical);
        }
        if (data.summary) {
          this.renderTemplateSection('aiplacement_modgen/insights_summary', data.summary, 'insights-summary');
        }
        if (data.workload_analysis) {
          this.renderTemplateSection('aiplacement_modgen/workload_analysis', data.workload_analysis, 'insights-workload-analysis');
        }
        if (data.improvements) {
          this.renderTemplateSection('aiplacement_modgen/improvements', data.improvements, 'insights-improvements');
        }
      },
      renderPedagogicalSection: function (pedData) {
        var section = getElement('insights-pedagogical');
        if (!section) {
          return;
        }
        var headingEl = getElement('ped-heading');
        if (headingEl) {
          headingEl.textContent = pedData.heading || '';
        }
        var contentEl = getElement('ped-content');
        if (contentEl && pedData.paragraphs && Array.isArray(pedData.paragraphs)) {
          contentEl.innerHTML = '';
          pedData.paragraphs.forEach(function (paragraph) {
            var p = document.createElement('p');
            p.textContent = paragraph;
            contentEl.appendChild(p);
          });
        }
        section.style.display = 'block';
      },
      renderTemplateSection: function (templateName, templateData, elementId) {
        templates.renderForPromise(templateName, templateData).then(function (result) {
          var element = getElement(elementId);
          if (element) {
            element.innerHTML = result.html;
          }
        }).catch(function () {});
      },
      renderChartsIfAvailable: function (data) {
        var self = this;
        if (data.chart_data && data.chart_data.hasActivities) {
          setTimeout(function () {
            self.renderLearningTypesChart(data.chart_data);
          }, 100);
        }
        if (data.section_chart_data && data.section_chart_data.hasActivities) {
          setTimeout(function () {
            self.renderSectionActivityChart(data.section_chart_data);
          }, 500);
        }
      },
      hideLoadingAndShowContent: function () {
        setElementDisplay('insights-loading', 'none');
        setElementDisplay('content-wrapper', 'block');
        this.initializeTabs();
      },
      initializeTabs: function () {
        var tabButtons = document.querySelectorAll('#myTab button[data-bs-toggle="tab"]');
        tabButtons.forEach(function (button) {
          button.addEventListener('click', function (event) {
            event.preventDefault();
            var targetId = this.getAttribute('data-bs-target');
            if (!targetId) {
              return;
            }
            tabButtons.forEach(function (btn) {
              btn.classList.remove('active');
              btn.setAttribute('aria-selected', 'false');
            });
            var allPanes = document.querySelectorAll('.tab-pane');
            allPanes.forEach(function (pane) {
              pane.classList.remove('active', 'show');
            });
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            var targetPane = document.querySelector(targetId);
            if (targetPane) {
              targetPane.classList.add('active', 'show');
            }
          });
        });
      },
      renderLearningTypesChart: function (chartData) {
        require(['jquery', 'core/chartjs'], function () {
          setTimeout(function () {
            var canvas = getElement('learning-types-chart');
            if (!canvas) {
              return;
            }
            var ctx = canvas.getContext('2d');
            if (!ctx) {
              return;
            }
            var config = {
              type: 'pie',
              data: {
                labels: chartData.labels,
                datasets: [{
                  data: chartData.data,
                  backgroundColor: chartData.colors,
                  borderColor: '#fff',
                  borderWidth: 2
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                  legend: {
                    position: 'bottom'
                  },
                  tooltip: {
                    callbacks: {
                      label: function (context) {
                        var label = context.label || '';
                        if (label) {
                          label += ': ';
                        }
                        label += context.parsed;
                        return label;
                      }
                    }
                  }
                }
              }
            };
            try {
              new Chart(ctx, config);
            } catch (e) {}
          }, 100);
        });
      },
      renderSectionActivityChart: function (chartData) {
        require(['jquery', 'core/chartjs'], function () {
          setTimeout(function () {
            var canvas = getElement('section-activity-chart');
            if (!canvas) {
              return;
            }
            var ctx = canvas.getContext('2d');
            if (!ctx) {
              return;
            }
            var maxActivities = Math.max.apply(null, chartData.data);
            var maxIndex = chartData.data.indexOf(maxActivities);
            var pointBackgroundColors = chartData.data.map(function (count, index) {
              return index === maxIndex ? 'rgb(255, 193, 7)' : 'rgb(75, 192, 192)';
            });
            var pointBorderColors = chartData.data.map(function (count, index) {
              return index === maxIndex ? 'rgb(255, 152, 0)' : 'rgb(75, 192, 192)';
            });
            var pointBorderWidths = chartData.data.map(function (count, index) {
              return index === maxIndex ? 3 : 1;
            });
            var config = {
              type: 'line',
              data: {
                labels: chartData.labels,
                datasets: [{
                  label: 'Activities per Section/Week',
                  data: chartData.data,
                  borderColor: 'rgb(75, 192, 192)',
                  backgroundColor: 'rgba(75, 192, 192, 0.1)',
                  borderWidth: 2,
                  fill: true,
                  tension: 0.4,
                  pointRadius: 6,
                  pointBackgroundColor: pointBackgroundColors,
                  pointBorderColor: pointBorderColors,
                  pointBorderWidth: pointBorderWidths
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                  legend: {
                    display: true,
                    position: 'top'
                  },
                  title: {
                    display: true,
                    text: 'Activity Distribution Across Sections/Weeks',
                    font: {
                      size: 14,
                      weight: 'bold'
                    }
                  },
                  tooltip: {
                    callbacks: {
                      label: function (context) {
                        return 'Activities: ' + context.parsed.y;
                      }
                    }
                  }
                },
                scales: {
                  y: {
                    beginAtZero: true,
                    max: maxActivities + 1,
                    title: {
                      display: true,
                      text: 'Number of Activities'
                    }
                  },
                  x: {
                    title: {
                      display: true,
                      text: 'Section/Week'
                    }
                  }
                }
              },
              plugins: [{
                id: 'highlightMaxSection',
                afterDatasetsDraw: function (chart) {
                  var xAxis = chart.scales.x;
                  var yAxis = chart.scales.y;
                  if (!xAxis || !yAxis) {
                    return;
                  }
                  var xPos = xAxis.getPixelForValue(maxIndex);
                  var width = xAxis.width / chartData.labels.length;
                  ctx.fillStyle = 'rgba(255, 193, 7, 0.2)';
                  ctx.fillRect(xPos - width / 2, yAxis.top, width, yAxis.height);
                  ctx.fillStyle = 'rgba(255, 152, 0, 1)';
                  ctx.font = 'bold 12px Arial';
                  ctx.textAlign = 'center';
                  ctx.fillText('Highest', xPos, yAxis.top - 15);
                }
              }]
            };
            try {
              new Chart(ctx, config);
            } catch (e) {}
          }, 250);
        });
      },
      enableDownloadButton: function (courseId) {
        var self = this;
        var downloadBtn = getElement('download-report-btn');
        if (downloadBtn) {
          downloadBtn.disabled = false;
          downloadBtn.title = 'Download the module exploration report as PDF';
          downloadBtn.addEventListener('click', function () {
            self.downloadReport(courseId);
          });
        }
      },
      downloadReport: function (courseId) {
        if (!reportData) {
          alert('Report data is not available. Please wait for the insights to load.');
          return;
        }
        var pdfUrl = M.cfg.wwwroot + '/ai/placement/modgen/ajax/download_report_pdf.php?courseid=' + courseId;
        fetch(pdfUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(reportData)
        }).then(function (response) {
          if (!response.ok) {
            throw new Error('HTTP error ' + response.status);
          }
          return response.blob();
        }).then(function (blob) {
          var url = window.URL.createObjectURL(blob);
          var link = document.createElement('a');
          link.href = url;
          link.download = 'module_exploration_report.pdf';
          document.body.appendChild(link);
          link.click();
          window.URL.revokeObjectURL(url);
          document.body.removeChild(link);
        }).catch(function (error) {
          alert('Error downloading PDF: ' + error.message);
        });
      },
      refreshInsights: function (courseId) {
        setElementDisplay('insights-loading', 'block');
        setElementDisplay('content-wrapper', 'none');
        this.loadInsights(courseId, true);
      },
      enableRefreshButton: function (courseId) {
        var self = this;
        var refreshBtn = getElement('refresh-insights-btn');
        if (refreshBtn) {
          refreshBtn.addEventListener('click', function () {
            self.refreshInsights(courseId);
          });
        }
      }
    };
  });
});
