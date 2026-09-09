module.exports = function(grunt) {
    'use strict';

    // Moodle loads AMD modules by name: requirejs.php concatenates several modules
    // into a single response when $CFG->cachejs is on, and anonymous define() calls
    // fail there with "Mismatched anonymous define()". Babel therefore has to stamp
    // each module with its Moodle module id, e.g. aiplacement_modgen/job_status_page.
    const COMPONENT = 'aiplacement_modgen';

    /**
     * Map a source path to its Moodle AMD module id.
     *
     * @param {String} moduleName Babel's default id, derived from the source path
     *                            (e.g. "amd/src/job_status_page").
     * @returns {String} Moodle module id (e.g. "aiplacement_modgen/job_status_page").
     */
    const getModuleId = function(moduleName) {
        return COMPONENT + '/' + moduleName.replace(/^.*amd\/src\//, '').replace(/\.js$/, '');
    };

    /**
     * Babel plugin: stamp the Moodle module id onto a hand-written define().
     *
     * Sources in amd/src come in two shapes. ES6 modules are wrapped in a define()
     * by @babel/preset-env, and `moduleIds` names that wrapper. Sources that already
     * call define() themselves are left alone by babel (see sourceType: 'unambiguous'
     * below, which stops them being wrapped a second time), so their define() has to
     * be named here instead. This mirrors what Moodle core's grunt does.
     *
     * @param {Object} babel Babel instance, used for its type builders.
     * @returns {Object} Babel plugin definition.
     */
    const addModuleToDefine = function(babel) {
        const t = babel.types;
        return {
            visitor: {
                CallExpression: function(path, state) {
                    if (path.node.callee.name !== 'define') {
                        return;
                    }
                    // Already named - nothing to do.
                    if (path.node.arguments.length && t.isStringLiteral(path.node.arguments[0])) {
                        return;
                    }
                    const filename = (state.file.opts.filename || '').replace(/\\/g, '/');
                    if (!filename) {
                        return;
                    }
                    path.node.arguments.unshift(t.stringLiteral(getModuleId(filename)));
                }
            }
        };
    };

    // Project configuration
    grunt.initConfig({
        pkg: grunt.file.readJSON('package.json'),

        // Babel for ES6 transpilation
        babel: {
            options: {
                sourceMaps: false,
                comments: false,
                // 'unambiguous' keeps files that already call define() from being
                // wrapped in a second, nested define() by the AMD transform.
                sourceType: 'unambiguous',
                moduleIds: true,
                getModuleId: getModuleId,
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
                    addModuleToDefine,
                    '@babel/plugin-syntax-dynamic-import',
                    '@babel/plugin-syntax-import-meta',
                    ['@babel/plugin-proposal-class-properties', {'loose': false}],
                    '@babel/plugin-proposal-json-strings'
                ]
            },
            dist: {
                files: {
                    'amd/build/embedded_prompt.js': 'amd/src/embedded_prompt.js',
                    'amd/build/embedded_results.js': 'amd/src/embedded_results.js',
                    'amd/build/fab.js': 'amd/src/fab.js',
                    'amd/build/modal.js': 'amd/src/modal.js',
                    'amd/build/course_nav.js': 'amd/src/course_nav.js',
                    'amd/build/course_toolbar.js': 'amd/src/course_toolbar.js',
                    'amd/build/modal_generator_reactive.js': 'amd/src/modal_generator_reactive.js',
                    'amd/build/suggest.js': 'amd/src/suggest.js',
                    'amd/build/aigen_marker.js': 'amd/src/aigen_marker.js',
                    'amd/build/aigen_list.js': 'amd/src/aigen_list.js',
                    'amd/build/loading_indicator.js': 'amd/src/loading_indicator.js',
                    'amd/build/job_status_page.js': 'amd/src/job_status_page.js',
                    'amd/build/json_handler.js': 'amd/src/json_handler.js',
                    'amd/build/policy_acceptance.js': 'amd/src/policy_acceptance.js'
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
                    'amd/build/embedded_prompt.min.js': ['amd/build/embedded_prompt.js'],
                    'amd/build/embedded_results.min.js': ['amd/build/embedded_results.js'],
                    'amd/build/fab.min.js': ['amd/build/fab.js'],
                    'amd/build/modal.min.js': ['amd/build/modal.js'],
                    'amd/build/course_nav.min.js': ['amd/build/course_nav.js'],
                    'amd/build/course_toolbar.min.js': ['amd/build/course_toolbar.js'],
                    'amd/build/modal_generator_reactive.min.js': ['amd/build/modal_generator_reactive.js'],
                    'amd/build/suggest.min.js': ['amd/build/suggest.js'],
                    'amd/build/aigen_marker.min.js': ['amd/build/aigen_marker.js'],
                    'amd/build/aigen_list.min.js': ['amd/build/aigen_list.js'],
                    'amd/build/template_selector.min.js': ['amd/build/template_selector.js'],
                    'amd/build/loading_indicator.min.js': ['amd/build/loading_indicator.js'],
                    'amd/build/job_status_page.min.js': ['amd/build/job_status_page.js'],
                    'amd/build/json_handler.min.js': ['amd/build/json_handler.js'],
                    'amd/build/policy_acceptance.min.js': ['amd/build/policy_acceptance.js']
                }
            }
        },
        
        // Copy task for files that don't need transformation
        copy: {
            template_selector: {
                src: 'amd/src/template_selector.js',
                dest: 'amd/build/template_selector.js'
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
    grunt.loadNpmTasks('grunt-contrib-copy');

    // Default task
    grunt.registerTask('default', ['babel', 'copy', 'uglify']);

    // Development task (watch mode)
    grunt.registerTask('dev', ['watch']);

    // Build task (explicit minification)
    grunt.registerTask('build', ['babel', 'uglify']);
};
