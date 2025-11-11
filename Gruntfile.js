module.exports = function(grunt) {
    'use strict';

    // Project configuration
    grunt.initConfig({
        pkg: grunt.file.readJSON('package.json'),

        // Uglify task for minifying JavaScript
        uglify: {
            options: {
                banner: '/*! <%= pkg.name %> - v<%= pkg.version %> - ' +
                    '<%= grunt.template.today("yyyy-mm-dd") %>\n' +
                    ' * @license <%= pkg.license %> or later\n */\n',
                compress: {
                    sequences: true,
                    dead_code: true,
                    conditionals: true,
                    booleans: true,
                    unused: true,
                    if_return: true,
                    join_vars: true
                },
                mangle: true,
                output: {
                    comments: false
                }
            },
            dist: {
                files: {
                    'amd/build/explore.min.js': ['amd/src/explore.js'],
                    'amd/build/embedded_prompt.min.js': ['amd/src/embedded_prompt.js'],
                    'amd/build/embedded_results.min.js': ['amd/src/embedded_results.js'],
                    'amd/build/fab.min.js': ['amd/src/fab.js'],
                    'amd/build/modal.min.js': ['amd/src/modal.js']
                }
            }
        },

        // Watch task for development
        watch: {
            scripts: {
                files: ['amd/src/**/*.js'],
                tasks: ['uglify'],
                options: {
                    spawn: false,
                    livereload: true
                }
            }
        }
    });

    // Load the plugins
    grunt.loadNpmTasks('grunt-contrib-uglify');
    grunt.loadNpmTasks('grunt-contrib-watch');

    // Default task
    grunt.registerTask('default', ['uglify']);

    // Development task (watch mode)
    grunt.registerTask('dev', ['watch']);

    // Build task (explicit minification)
    grunt.registerTask('build', ['uglify']);
};
