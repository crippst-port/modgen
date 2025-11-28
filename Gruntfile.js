module.exports = function(grunt) {
    'use strict';

    // Project configuration
    grunt.initConfig({
        pkg: grunt.file.readJSON('package.json'),

        // Babel for ES6 transpilation
        babel: {
            options: {
                sourceMaps: false,
                comments: false,
                presets: [['@babel/preset-env', {
                    targets: {
                        browsers: [
                            ">0.25%",
                            "last 2 versions",
                            "not ie <= 10",
                            "not op_mini all",
                            "not Opera > 0",
                            "not dead"
                        ]
                    },
                    modules: 'amd',
                    useBuiltIns: false
                }]],
                plugins: [
                    '@babel/plugin-syntax-dynamic-import',
                    '@babel/plugin-syntax-import-meta',
                    ['@babel/plugin-proposal-class-properties', {'loose': false}],
                    '@babel/plugin-proposal-json-strings'
                ]
            },
            dist: {
                files: {
                    'amd/build/explore.js': 'amd/src/explore.js',
                    'amd/build/embedded_prompt.js': 'amd/src/embedded_prompt.js',
                    'amd/build/embedded_results.js': 'amd/src/embedded_results.js',
                    'amd/build/fab.js': 'amd/src/fab.js',
                    'amd/build/modal.js': 'amd/src/modal.js',
                    'amd/build/course_nav.js': 'amd/src/course_nav.js',
                    'amd/build/course_toolbar.js': 'amd/src/course_toolbar.js',
                    'amd/build/modal_generator_reactive.js': 'amd/src/modal_generator_reactive.js'
                    , 'amd/build/suggest.js': 'amd/src/suggest.js'
                }
            }
        },

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
                    'amd/build/explore.min.js': ['amd/build/explore.js'],
                    'amd/build/embedded_prompt.min.js': ['amd/build/embedded_prompt.js'],
                    'amd/build/embedded_results.min.js': ['amd/build/embedded_results.js'],
                    'amd/build/fab.min.js': ['amd/build/fab.js'],
                    'amd/build/modal.min.js': ['amd/build/modal.js'],
                    'amd/build/course_nav.min.js': ['amd/build/course_nav.js'],
                    'amd/build/course_toolbar.min.js': ['amd/build/course_toolbar.js'],
                    'amd/build/modal_generator_reactive.min.js': ['amd/build/modal_generator_reactive.js']
                    , 'amd/build/suggest.min.js': ['amd/build/suggest.js']
                }
            }
        },

        // Watch task for development
        watch: {
            scripts: {
                files: ['amd/src/**/*.js'],
                tasks: ['babel', 'uglify'],
                options: {
                    spawn: false,
                    livereload: true
                }
            }
        }
    });

    // Load the plugins
    grunt.loadNpmTasks('grunt-babel');
    grunt.loadNpmTasks('grunt-contrib-uglify');
    grunt.loadNpmTasks('grunt-contrib-watch');

    // Default task
    grunt.registerTask('default', ['babel', 'uglify']);

    // Development task (watch mode)
    grunt.registerTask('dev', ['watch']);

    // Build task (explicit minification)
    grunt.registerTask('build', ['babel', 'uglify']);
};
