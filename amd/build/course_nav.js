define([], function () {
  "use strict";

  /**
   * Course navigation bar for Module Assistant
   *
   * @module     aiplacement_modgen/course_nav
   * @copyright  2025 Tom Cripps <tom.cripps@port.ac.uk>
   * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
   */
  define(['jquery', 'core/templates', 'core/modal_factory'], function ($, Templates, ModalFactory) {
    var init = function (config) {
      Templates.renderForPromise('aiplacement_modgen/course_nav', config).then(function (data) {
        var html = data.html;
        var js = data.js;
        var region = $('#region-main');
        if (region.length && html) {
          region.prepend(html);
          if (js && js.trim && js.trim()) {
            Templates.runTemplateJS(js);
          }
          if (config.showgenerator) {
            var generatorBtn = $('.aiplacement-modgen-nav__btn[href*="modal.php"]');
            generatorBtn.on('click', function (e) {
              e.preventDefault();
              openGeneratorModal(config.generatorurl, config.navtitle);
            });
          }
        } else {
          window.console.error('Could not find #region-main or HTML is undefined');
        }
      }).catch(function (error) {
        window.console.error('Failed to render course navigation:', error);
      });
    };
    var openGeneratorModal = function (url, title) {
      ModalFactory.create({
        type: ModalFactory.types.DEFAULT,
        title: title,
        body: Templates.render('aiplacement_modgen/modal', {}),
        large: true
      }).then(function (modal) {
        modal.show();
        modal.setBody('<div class="text-center p-5"><i class="fa fa-spinner fa-spin fa-3x"></i></div>');
        $.ajax({
          url: url,
          method: 'GET',
          dataType: 'html'
        }).done(function (response) {
          modal.setBody(response);
        }).fail(function () {
          modal.setBody('<div class="alert alert-danger">Failed to load generator</div>');
        });
        return modal;
      }).catch(function (error) {
        window.console.error('Failed to create modal:', error);
      });
    };
    return {
      init: init
    };
  });
});
