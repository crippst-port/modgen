/**
 * Module exploration - fetch insights via AJAX.
 *
 * @module     aiplacement_modgen/explore
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/templates'], function(ajax, templates) {
    'use strict';

    var reportData = null;
    var courseid = null;
    var activitySummary = null;
    var chartData = null;

    return {
        /**
         * Initialize the exploration insights loader.
         *
         * @param {number} cid The course ID
         * @param {object} chData Chart data passed from explore.php
         * @param {array} actSummary Activity summary array
         */
        init: function(cid, chData, actSummary) {
            courseid = cid;
            activitySummary = actSummary;
            chartData = chData;
            
            // Load insights via AJAX
            this.loadInsights(cid);
        },

        /**
         * Fetch insights from the server via AJAX and update the template.
         *
         * @param {number} cid The course ID
         */
        loadInsights: function(cid) {
            var self = this;
            var ajaxUrl = M.cfg.wwwroot + '/ai/placement/modgen/ajax/explore_ajax.php?courseid=' + cid;
            
            fetch(ajaxUrl)
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP error ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.error) {
                        console.error('AJAX error:', data.error);
                    } else if (data.success && data.data) {
                        // Convert insights to text format for PDF
                        var pedagogicalText = '';
                        if (data.data.pedagogical) {
                            if (data.data.pedagogical.heading) {
                                pedagogicalText += data.data.pedagogical.heading + '\n\n';
                            }
                            if (data.data.pedagogical.paragraphs) {
                                pedagogicalText += data.data.pedagogical.paragraphs.join('\n\n');
                            }
                        }
                        
                        var learningTypesText = '';
                        if (data.data.learning_types) {
                            if (data.data.learning_types.heading) {
                                learningTypesText += data.data.learning_types.heading + '\n\n';
                            }
                            if (data.data.learning_types.paragraphs) {
                                learningTypesText += data.data.learning_types.paragraphs.join('\n\n');
                            }
                        }
                        
                        var improvementsText = '';
                        if (data.data.improvements) {
                            if (data.data.improvements.summary) {
                                improvementsText += data.data.improvements.summary + '\n\n';
                            }
                            if (data.data.improvements.suggestions) {
                                improvementsText += data.data.improvements.suggestions.join('\n');
                            }
                        }
                        
                        // Store report data for PDF generation
                        reportData = {
                            pedagogical: pedagogicalText,
                            learning_types: learningTypesText,
                            improvements: improvementsText,
                            chart_data: data.data.chart_data || chartData
                        };
                        
                        // Render pedagogical section
                        if (data.data.pedagogical) {
                            var pedSection = document.getElementById('insights-pedagogical');
                            if (pedSection) {
                                document.getElementById('ped-heading').textContent = data.data.pedagogical.heading || '';
                                var pedContent = document.getElementById('ped-content');
                                pedContent.innerHTML = '';
                                if (data.data.pedagogical.paragraphs) {
                                    data.data.pedagogical.paragraphs.forEach(function(para) {
                                        var p = document.createElement('p');
                                        p.textContent = para;
                                        pedContent.appendChild(p);
                                    });
                                }
                                pedSection.style.display = 'block';
                            }
                        }
                        
                        // Render summary section using Moodle template rendering
                        if (data.data.summary) {
                            templates.renderForPromise('aiplacement_modgen/insights_summary', data.data.summary)
                                .then(function(result) {
                                    var summaryDiv = document.getElementById('insights-summary');
                                    if (summaryDiv) {
                                        summaryDiv.innerHTML = result.html;
                                    }
                                    return;
                                })
                                .catch(function(err) {
                                    console.error('Error rendering summary template:', err);
                                });
                        }
                        
                        // Render learning types section
                        if (data.data.learning_types) {
                            var ltSection = document.getElementById('insights-learning-types');
                            if (ltSection) {
                                document.getElementById('lt-heading').textContent = data.data.learning_types.heading || '';
                                var ltContent = document.getElementById('lt-content');
                                ltContent.innerHTML = '';
                                if (data.data.learning_types.paragraphs) {
                                    data.data.learning_types.paragraphs.forEach(function(para) {
                                        var p = document.createElement('p');
                                        p.textContent = para;
                                        ltContent.appendChild(p);
                                    });
                                }
                                ltSection.style.display = 'block';
                            }
                        }
                        
                        // Render improvements section
                        if (data.data.improvements) {
                            var impSection = document.getElementById('insights-improvements');
                            if (impSection) {
                                var impSummary = document.getElementById('imp-summary');
                                impSummary.innerHTML = '';
                                if (data.data.improvements.summary) {
                                    var p = document.createElement('p');
                                    p.textContent = data.data.improvements.summary;
                                    impSummary.appendChild(p);
                                }
                                
                                var impList = document.getElementById('imp-list');
                                impList.innerHTML = '';
                                if (data.data.improvements.suggestions) {
                                    data.data.improvements.suggestions.forEach(function(suggestion) {
                                        var li = document.createElement('li');
                                        li.textContent = suggestion;
                                        impList.appendChild(li);
                                    });
                                }
                                impSection.style.display = 'block';
                            }
                        }
                        
                        // Hide spinner and show content
                        var spinner = document.getElementById('insights-loading');
                        var contentWrapper = document.getElementById('content-wrapper');
                        if (spinner) {
                            spinner.style.display = 'none';
                        }
                        if (contentWrapper) {
                            contentWrapper.style.display = 'block';
                        }
                        
                        // Render chart after all insights are loaded
                        if (chartData && chartData.hasActivities) {
                            setTimeout(function() {
                                self.renderLearningTypesChart(chartData);
                            }, 100);
                        }
                        
                        // Enable download button
                        self.enableDownloadButton(cid);
                    } else {
                        console.error('Unexpected response format:', data);
                        var spinner = document.getElementById('insights-loading');
                        var contentWrapper = document.getElementById('content-wrapper');
                        if (spinner) {
                            spinner.style.display = 'none';
                        }
                        if (contentWrapper) {
                            contentWrapper.style.display = 'block';
                        }
                    }
                })
                .catch(function(error) {
                    console.error('AJAX error:', error);
                    var spinner = document.getElementById('insights-loading');
                    var contentWrapper = document.getElementById('content-wrapper');
                    if (spinner) {
                        spinner.style.display = 'none';
                    }
                    if (contentWrapper) {
                        contentWrapper.style.display = 'block';
                    }
                });
        },

        /**
         * Render the learning types pie chart using Chart.js.
         *
         * @param {object} chartData The chart data
         */
        renderLearningTypesChart: function(chartData) {
            var self = this;
            require(['jquery', 'core/chartjs'], function($, chartjs) {
                // Use a timeout to ensure the canvas is in the DOM
                setTimeout(function() {
                    var canvas = document.getElementById('learning-types-chart');
                    if (!canvas) {
                        console.error('Chart canvas not found');
                        return;
                    }

                    var ctx = canvas.getContext('2d');
                    if (!ctx) {
                        console.error('Could not get canvas context');
                        return;
                    }

                    var config = {
                        type: 'pie',
                        data: {
                            labels: chartData.labels,
                            datasets: [
                                {
                                    data: chartData.data,
                                    backgroundColor: chartData.colors,
                                    borderColor: '#fff',
                                    borderWidth: 2,
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
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
                        var chart = new Chart(ctx, config);
                        console.log('Chart rendered successfully');
                    } catch (e) {
                        console.error('Chart rendering error:', e);
                    }
                }, 100);
            });
        },

        /**
         * Enable the download button and attach click handler.
         *
         * @param {number} cid The course ID
         */
        enableDownloadButton: function(cid) {
            var self = this;
            var downloadBtn = document.getElementById('download-report-btn');
            if (downloadBtn) {
                downloadBtn.disabled = false;
                downloadBtn.title = 'Download the module exploration report as PDF';
                downloadBtn.addEventListener('click', function() {
                    self.downloadReport(cid);
                });
            }
        },

        /**
         * Download the report as PDF.
         *
         * @param {number} cid The course ID
         */
        downloadReport: function(cid) {
            if (!reportData) {
                alert('Report data is not available. Please wait for the insights to load.');
                return;
            }
            
            // Send request to PDF endpoint
            fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/download_report_pdf.php?courseid=' + cid, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(reportData)
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP error ' + response.status);
                }
                return response.blob();
            })
            .then(function(blob) {
                // Create download link
                var url = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'module_exploration_report.pdf';
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            })
            .catch(function(error) {
                console.error('Download error:', error);
                alert('Error downloading PDF: ' + error.message);
            });
        },

        /**
         * Download the report as PDF (old method - kept for compatibility).
         *
         * @param {number} cid The course ID
         * @param {object} chartData The chart data
         * @deprecated Use downloadReport instead
         */
        downloadReportLegacy: function(cid, chartData) {
            this.downloadReport(cid);
        },

        /**
         * Escape HTML to prevent XSS.
         *
         * @param {string} text The text to escape
         * @returns {string} Escaped HTML
         */
        escapeHtml: function(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) {
                return map[m];
            });
        }
    };
});

