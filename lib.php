<?php
// Core hooks and navigation for aiplacement_modgen
function aiplacement_modgen_extend_navigation_course($navigation, $course, $context) {
    $url = new moodle_url('/ai/placement/modgen/prompt.php', ['id' => $course->id]);
    $navigation->add(get_string('pluginname', 'aiplacement_modgen'), $url, navigation_node::TYPE_SETTING);
}
