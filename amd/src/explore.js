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

define(['core/templates'], function(templates) {
    'use strict';

    // ============================================================================
    // MODULE CONFIGURATION AND STATE
    // ============================================================================

    // Store the current course ID for use in event handlers
    var currentCourseId = null;

    // Store report data for PDF generation
    var reportData = null;

    // ============================================================================
    // PRIVATE HELPER FUNCTIONS
    // ============================================================================

    /**
     * Extract text from a section object (heading + paragraphs).
     * Used when processing pedagogical, learning types, and other sections.
     *
     * @param {Object} section - Section object with 'heading' and 'paragraphs' properties
     * @returns {String} Formatted text with heading on first line, paragraphs separated by double newlines
     */
    function extractTextFromSection(section) {
        if (!section) {
            return '';
        }

        var text = '';

        // Add heading if present
        if (section.heading) {
            text += section.heading + '\n\n';
        }

        // Add paragraphs if present
        if (section.paragraphs && Array.isArray(section.paragraphs)) {
            text += section.paragraphs.join('\n\n');
        }

        return text;
    }

    /**
     * Get a DOM element or return null if not found.
     * Provides safe access to DOM elements.
     *
     * @param {String} id - The element ID
     * @returns {Element|null} The DOM element or null
     */
    function getElement(id) {
        return document.getElementById(id) || null;
    }

    /**
     * Set the display property of an element.
     * Helper to show or hide elements.
     *
     * @param {String} id - The element ID
     * @param {String} displayValue - 'block', 'none', etc.
     */
    function setElementDisplay(id, displayValue) {
        var el = getElement(id);
        if (el) {
            el.style.display = displayValue;
        }
    }

    // ============================================================================
    // PUBLIC API
    // ============================================================================

    return {
        /**
         * Initialize the exploration module.
         * This is called automatically when the explore.php page loads.
         *
         * @param {Number} courseId - The course ID to load insights for
         * @param {Object} chartData - Pre-calculated chart data (not used in refactored version)
         * @param {Array} activitySummary - Pre-calculated activity summary (not used in refactored version)
         */
        init: function(courseId, chartData, activitySummary) {
            // Store course ID for later use
            currentCourseId = courseId;

            // Enable refresh button
            this.enableRefreshButton(courseId);

            // Load insights from the server
            this.loadInsights(courseId);
        },

        /**
         * Fetch insights from the AJAX endpoint and render them on the page.
         *
         * PROCESS:
         * 1. Construct AJAX URL with course ID
         * 2. Fetch JSON data from server
         * 3. Validate response
         * 4. Extract and process data into sections
         * 5. Render sections to the DOM
         * 6. Render interactive charts
         * 7. Show the content and hide loading spinner
         *
         * @param {Number} courseId - The course ID
         * @param {Boolean} refresh - Force refresh from AI (bypass cache)
         */
        loadInsights: function(courseId, refresh) {
            var self = this;
            var ajaxUrl = M.cfg.wwwroot + '/ai/placement/modgen/ajax/explore_ajax.php?courseid=' + courseId;
            if (refresh) {
                ajaxUrl += '&refresh=1';
            }

            // Fetch data from server
            fetch(ajaxUrl)
                .then(function(response) {
                    // Check HTTP status
                    if (!response.ok) {
                        throw new Error('HTTP error ' + response.status);
                    }
                    return response.json();
                })
                .then(function(data) {
                    // Validate response structure
                    if (data.error || !data.success || !data.data) {
                        self.hideLoadingAndShowContent();
                        return;
                    }

                    // Process the insights data
                    self.processInsights(data.data);

                    // Render all sections to the DOM
                    self.renderAllSections(data.data);

                    // Hide loading spinner and show content
                    self.hideLoadingAndShowContent();

                    // Render charts (with delay to ensure DOM is ready)
                    self.renderChartsIfAvailable(data.data);

                    // Enable the PDF download button
                    self.enableDownloadButton(courseId);
                })
                .catch(function(error) {
                    // On any error, just show the page as-is
                    self.hideLoadingAndShowContent();
                });
        },

        /**
         * Process insights data into a format suitable for PDF generation.
         * Extracts text from each section and stores it in reportData.
         *
         * @param {Object} data - The raw insights data from server
         */
        processInsights: function(data) {
            reportData = {
                pedagogical: extractTextFromSection(data.pedagogical),
                learningTypes: extractTextFromSection(data.learning_types),
                improvements: this.extractImprovementsText(data.improvements),
                chartData: data.chart_data
            };
        },

        /**
         * Extract text from improvements section (different format than other sections).
         *
         * @param {Object} improvements - Improvements object with 'summary' and 'suggestions'
         * @returns {String} Formatted improvements text
         */
        extractImprovementsText: function(improvements) {
            if (!improvements) {
                return '';
            }

            var text = '';

            // Add summary if present
            if (improvements.summary) {
                text += improvements.summary + '\n\n';
            }

            // Add suggestions if present
            if (improvements.suggestions && Array.isArray(improvements.suggestions)) {
                text += improvements.suggestions.join('\n');
            }

            return text;
        },

        /**
         * Render all insight sections to the page.
         * Handles pedagogical section, summary, workload analysis, improvements, etc.
         *
         * @param {Object} data - The insights data
         */
        renderAllSections: function(data) {
            // Render pedagogical section (custom rendering)
            if (data.pedagogical) {
                this.renderPedagogicalSection(data.pedagogical);
            }

            // Render summary section (using Moodle template engine)
            if (data.summary) {
                this.renderTemplateSection('aiplacement_modgen/insights_summary', data.summary, 'insights-summary');
            }

            // Render workload analysis section (using Moodle template engine)
            if (data.workload_analysis) {
                this.renderTemplateSection('aiplacement_modgen/workload_analysis', data.workload_analysis, 'insights-workload-analysis');
            }

            // Render improvements section (using Moodle template engine)
            if (data.improvements) {
                this.renderTemplateSection('aiplacement_modgen/improvements', data.improvements, 'insights-improvements');
            }
        },

        /**
         * Render the pedagogical section with heading and paragraphs.
         * This section is rendered manually rather than using templates.
         *
         * @param {Object} pedData - The pedagogical insights object
         */
        renderPedagogicalSection: function(pedData) {
            var section = getElement('insights-pedagogical');
            if (!section) {
                return;
            }

            // Set heading text
            var headingEl = getElement('ped-heading');
            if (headingEl) {
                headingEl.textContent = pedData.heading || '';
            }

            // Render paragraphs as individual <p> elements
            var contentEl = getElement('ped-content');
            if (contentEl && pedData.paragraphs && Array.isArray(pedData.paragraphs)) {
                contentEl.innerHTML = '';
                pedData.paragraphs.forEach(function(paragraph) {
                    var p = document.createElement('p');
                    p.textContent = paragraph;
                    contentEl.appendChild(p);
                });
            }

            // Make section visible
            section.style.display = 'block';
        },

        /**
         * Render a section using Moodle's template rendering engine.
         * This is more flexible than manual rendering and allows for complex HTML.
         *
         * @param {String} templateName - Name of the template (e.g., 'aiplacement_modgen/insights_summary')
         * @param {Object} templateData - Data to pass to the template
         * @param {String} elementId - ID of the DOM element to render into
         */
        renderTemplateSection: function(templateName, templateData, elementId) {
            templates.renderForPromise(templateName, templateData)
                .then(function(result) {
                    var element = getElement(elementId);
                    if (element) {
                        element.innerHTML = result.html;
                    }
                })
                .catch(function() {
                    // Template rendering failed - fail silently
                });
        },

        /**
         * Render charts if chart data is available.
         * Charts are rendered with a delay to ensure DOM elements are ready.
         *
         * @param {Object} data - The insights data
         */
        renderChartsIfAvailable: function(data) {
            var self = this;

            // Render learning types pie chart
            if (data.chart_data && data.chart_data.hasActivities) {
                setTimeout(function() {
                    self.renderLearningTypesChart(data.chart_data);
                }, 100);
            }

            // Render section activity bar chart
            if (data.section_chart_data && data.section_chart_data.hasActivities) {
                setTimeout(function() {
                    self.renderSectionActivityChart(data.section_chart_data);
                }, 500);
            }
        },

        /**
         * Hide the loading spinner and show the main content.
         * This is called after insights are loaded (successfully or not).
         */
        hideLoadingAndShowContent: function() {
            setElementDisplay('insights-loading', 'none');
            setElementDisplay('content-wrapper', 'block');

            // Initialize Bootstrap 5 tabs after content is shown
            this.initializeTabs();
        },

        /**
         * Initialize Bootstrap 5 tabs functionality using vanilla JavaScript.
         * This manually handles tab switching by managing active/show classes.
         */
        initializeTabs: function() {
            // Get all tab trigger buttons
            var tabButtons = document.querySelectorAll('#myTab button[data-bs-toggle="tab"]');

            // Add click handler to each tab button
            tabButtons.forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault();

                    // Get target tab pane ID from data-bs-target attribute
                    var targetId = this.getAttribute('data-bs-target');
                    if (!targetId) {
                        return;
                    }

                    // Remove active/show classes from all buttons and panes
                    tabButtons.forEach(function(btn) {
                        btn.classList.remove('active');
                        btn.setAttribute('aria-selected', 'false');
                    });

                    // Remove active/show classes from all tab panes
                    var allPanes = document.querySelectorAll('.tab-pane');
                    allPanes.forEach(function(pane) {
                        pane.classList.remove('active', 'show');
                    });

                    // Add active class to clicked button
                    this.classList.add('active');
                    this.setAttribute('aria-selected', 'true');

                    // Add active and show classes to target pane
                    var targetPane = document.querySelector(targetId);
                    if (targetPane) {
                        targetPane.classList.add('active', 'show');
                    }
                });
            });
        },

        /**
         * Render the learning types pie chart using Chart.js.
         * Shows the distribution of activity types by learning type.
         *
         * @param {Object} chartData - Chart configuration object with labels, data, colors
         */
        renderLearningTypesChart: function(chartData) {
            require(['jquery', 'core/chartjs'], function() {
                // Use setTimeout to ensure canvas element is in DOM
                setTimeout(function() {
                    var canvas = getElement('learning-types-chart');
                    if (!canvas) {
                        return;
                    }

                    var ctx = canvas.getContext('2d');
                    if (!ctx) {
                        return;
                    }

                    // Configure pie chart
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
                        // eslint-disable-next-line no-undef
                        new Chart(ctx, config);
                    } catch (e) {
                        // Chart rendering failed - fail silently
                    }
                }, 100);
            });
        },

        /**
         * Render the section activity bar chart using Chart.js.
         * Shows the number of activities per course section.
         *
         * @param {Object} chartData - Chart configuration object with labels, data, colors
         */
        renderSectionActivityChart: function(chartData) {
            require(['jquery', 'core/chartjs'], function() {
                // Use setTimeout to ensure canvas element is in DOM
                setTimeout(function() {
                    var canvas = getElement('section-activity-chart');
                    if (!canvas) {
                        return;
                    }

                    var ctx = canvas.getContext('2d');
                    if (!ctx) {
                        return;
                    }

                    // Configure bar chart
                    var config = {
                        type: 'bar',
                        data: {
                            labels: chartData.labels,
                            datasets: [
                                {
                                    label: 'Activities',
                                    data: chartData.data,
                                    backgroundColor: chartData.backgroundColor,
                                    borderColor: chartData.borderColor,
                                    borderWidth: 1,
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            indexAxis: 'y',
                            plugins: {
                                legend: {
                                    display: false,
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return 'Activities: ' + context.parsed.x;
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1,
                                    }
                                }
                            }
                        }
                    };

                    try {
                        // eslint-disable-next-line no-undef
                        new Chart(ctx, config);
                    } catch (e) {
                        // Chart rendering failed - fail silently
                    }
                }, 100);
            });
        },

        /**
         * Enable the PDF download button and attach click handler.
         * The button remains disabled until insights are loaded.
         *
         * @param {Number} courseId - The course ID for download link
         */
        enableDownloadButton: function(courseId) {
            var self = this;
            var downloadBtn = getElement('download-report-btn');

            if (downloadBtn) {
                downloadBtn.disabled = false;
                downloadBtn.title = 'Download the module exploration report as PDF';
                downloadBtn.addEventListener('click', function() {
                    self.downloadReport(courseId);
                });
            }
        },

        /**
         * Download the insights report as a PDF file.
         *
         * PROCESS:
         * 1. Check if report data is available
         * 2. Send POST request with report data to PDF generation endpoint
         * 3. Receive PDF blob from server
         * 4. Create temporary download link
         * 5. Trigger download in browser
         * 6. Clean up temporary resources
         *
         * @param {Number} courseId - The course ID
         */
        downloadReport: function(courseId) {
            if (!reportData) {
                alert('Report data is not available. Please wait for the insights to load.');
                return;
            }

            var pdfUrl = M.cfg.wwwroot + '/ai/placement/modgen/ajax/download_report_pdf.php?courseid=' + courseId;

            fetch(pdfUrl, {
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
                    // Create a temporary download link
                    var url = window.URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = 'module_exploration_report.pdf';

                    // Trigger download
                    document.body.appendChild(link);
                    link.click();

                    // Clean up
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(link);
                })
                .catch(function(error) {
                    alert('Error downloading PDF: ' + error.message);
                });
        },

        /**
         * Refresh insights by forcing new AI analysis and clearing cache.
         * This will re-call the AI service and save new data.
         *
         * @param {Number} courseId - The course ID
         */
        refreshInsights: function(courseId) {
            // Show loading spinner
            setElementDisplay('insights-loading', 'block');
            setElementDisplay('content-wrapper', 'none');

            // Load insights with refresh flag set to 1
            this.loadInsights(courseId, true);
        },

        /**
         * Attach click handler to refresh button if it exists.
         * Enables users to manually refresh insights.
         *
         * @param {Number} courseId - The course ID
         */
        enableRefreshButton: function(courseId) {
            var self = this;
            var refreshBtn = getElement('refresh-insights-btn');

            if (refreshBtn) {
                refreshBtn.addEventListener('click', function() {
                    self.refreshInsights(courseId);
                });
            }
        }
    };
});

