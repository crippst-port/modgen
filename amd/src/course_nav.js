// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Course navigation bar for Module Assistant
 *
 * @module     aiplacement_modgen/course_nav
 * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/templates', 'core/modal_factory'], function($, Templates, ModalFactory) {
    
    /**
     * Initialize the course navigation bar
     * @param {Object} config Configuration object
     */
    var init = function(config) {
        window.console.log('Course nav init called with config:', config);
        
        // Render the navigation bar template
        Templates.renderForPromise('aiplacement_modgen/course_nav', config)
            .then(function(data) {
                window.console.log('Template promise resolved, data:', data);
                
                var html = data.html;
                var js = data.js;
                
                window.console.log('HTML length:', html ? html.length : 'undefined', 'JS:', js);
                
                // Insert nav at the top of the page content
                var region = $('#region-main');
                window.console.log('Found #region-main:', region.length, 'elements');
                
                if (region.length && html) {
                    region.prepend(html);
                    window.console.log('HTML prepended to region-main');
                    
                    // Verify it was added
                    var navBar = $('.aiplacement-modgen-nav');
                    window.console.log('Nav bar now in DOM:', navBar.length, 'Display:', navBar.css('display'));
                    
                    // Only run template JS if it exists
                    if (js && js.trim && js.trim()) {
                        Templates.runTemplateJS(js);
                    }
                    
                    // Handle generator button click to open modal
                    if (config.showgenerator) {
                        var generatorBtn = $('.aiplacement-modgen-nav__btn[href*="modal.php"]');
                        generatorBtn.on('click', function(e) {
                            e.preventDefault();
                            openGeneratorModal(config.generatorurl, config.navtitle);
                        });
                    }
                    
                    window.console.log('Navigation bar inserted successfully');
                } else {
                    window.console.error('Could not find #region-main or HTML is undefined');
                }
            })
            .catch(function(error) {
                window.console.error('Failed to render course navigation:', error);
            });
    };

    /**
     * Open the generator modal
     * @param {String} url Modal URL
     * @param {String} title Modal title
     */
    var openGeneratorModal = function(url, title) {
        ModalFactory.create({
            type: ModalFactory.types.DEFAULT,
            title: title,
            body: Templates.render('aiplacement_modgen/modal', {}),
            large: true
        }).then(function(modal) {
            modal.show();
            
            // Load modal content via AJAX
            modal.setBody('<div class="text-center p-5"><i class="fa fa-spinner fa-spin fa-3x"></i></div>');
            
            $.ajax({
                url: url,
                method: 'GET',
                dataType: 'html'
            }).done(function(response) {
                modal.setBody(response);
            }).fail(function() {
                modal.setBody('<div class="alert alert-danger">Failed to load generator</div>');
            });
            
            return modal;
        }).catch(function(error) {
            window.console.error('Failed to create modal:', error);
        });
    };

    return {
        init: init
    };
});
