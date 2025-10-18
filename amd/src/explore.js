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

    return {
        /**
         * Initialize the exploration insights loader.
         *
         * @param {number} cid The course ID
         * @param {object} chartData Optional chart data
         */
        init: function(cid, chartData) {
            courseid = cid;
            var container = document.getElementById('exploration-insights');
            if (container) {
                this.loadInsights(container, cid);
            }
            
            // Render chart if data is provided
            if (chartData && chartData.hasActivities) {
                this.renderLearningTypesChart(chartData);
            }
            
            // Set up download button - initially disabled
            var downloadBtn = document.getElementById('download-report-btn');
            if (downloadBtn) {
                downloadBtn.disabled = true;
                downloadBtn.title = 'Report will be available after insights load';
            }
        },

        /**
         * Fetch insights from the server via AJAX using Moodle's AJAX API.
         *
         * @param {number} cid The course ID
         */
        loadInsights: function(container, cid) {
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
                        container.innerHTML = '<div class="alert alert-danger" role="alert">' + 
                            self.escapeHtml(data.error) + '</div>';
                    } else if (data.success && data.data) {
                        // Store report data for PDF generation
                        reportData = {
                            pedagogical: data.data.pedagogical || '',
                            learning_types: data.data.learning_types || '',
                            activities: data.data.activities || '',
                            improvements: data.data.improvements || '',
                            chart_data: data.data.chart_data || null
                        };
                        
                        // Render the template with the returned data
                        require(['core/templates'], function(templates) {
                            templates.render('aiplacement_modgen/exploration_insights', data.data)
                                .then(function(html) {
                                    container.innerHTML = html;
                                    console.log('Chart data:', data.data.chart_data);
                                    // Render the learning types chart if data is available
                                    if (data.data.chart_data && data.data.chart_data.hasActivities) {
                                        console.log('Rendering chart with data:', data.data.chart_data);
                                        self.renderLearningTypesChart(data.data.chart_data);
                                    }
                                    
                                    // Enable download button now that data is loaded
                                    self.enableDownloadButton(cid);
                                })
                                .catch(function(error) {
                                    console.error('Template render error:', error);
                                    container.innerHTML = '<div class="alert alert-danger" role="alert">Failed to render template.</div>';
                                });
                        });
                    } else {
                        console.error('Unexpected response format:', data);
                        container.innerHTML = '<div class="alert alert-warning" role="alert">Unexpected response format.</div>';
                    }
                })
                .catch(function(error) {
                    console.error('AJAX error:', error);
                    container.innerHTML = '<div class="alert alert-danger" role="alert">' +
                        'Failed to load module insights: ' + self.escapeHtml(error.toString()) + '</div>';
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

