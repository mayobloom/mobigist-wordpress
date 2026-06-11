<?php
/**
 * Plugin Name: PKB Math Tools
 * Description: Adds a MathLive Extended Editor and LaTeX snippet tools to the default WordPress Math block.
 * Version: 0.2.44
 * Author: PKB
 * Text Domain: pkb-math-tools
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PKB_MATH_TOOLS_VERSION', '0.2.44');
define('PKB_MATH_TOOLS_URL', plugin_dir_url(__FILE__));

add_action('enqueue_block_editor_assets', function (): void {
    wp_enqueue_script(
        'pkb-mathlive',
        PKB_MATH_TOOLS_URL . 'assets/vendor/mathlive/mathlive.min.js',
        [],
        '0.110.0',
        true
    );

    wp_enqueue_style(
        'pkb-mathlive-static',
        PKB_MATH_TOOLS_URL . 'assets/vendor/mathlive/mathlive-static.css',
        [],
        '0.110.0'
    );

    wp_enqueue_style(
        'pkb-mathlive-fonts',
        PKB_MATH_TOOLS_URL . 'assets/vendor/mathlive/mathlive-fonts.css',
        ['pkb-mathlive-static'],
        '0.110.0'
    );

    wp_enqueue_script(
        'pkb-math-tools-editor',
        PKB_MATH_TOOLS_URL . 'assets/js/editor.js',
        [
            'pkb-mathlive',
            'wp-block-editor',
            'wp-components',
            'wp-compose',
            'wp-data',
            'wp-element',
            'wp-hooks',
            'wp-i18n',
            'wp-plugins',
        ],
        PKB_MATH_TOOLS_VERSION,
        true
    );

    wp_enqueue_style(
        'pkb-math-tools-editor',
        PKB_MATH_TOOLS_URL . 'assets/css/editor.css',
        [],
        PKB_MATH_TOOLS_VERSION
    );
});
