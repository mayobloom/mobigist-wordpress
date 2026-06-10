<?php
/**
 * Plugin Name: PKB Math Tools
 * Description: Adds a LaTeX snippet toolkit for the core Math block editor.
 * Version: 0.1.13
 * Author: PKB
 * Text Domain: pkb-math-tools
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PKB_MATH_TOOLS_VERSION', '0.1.13');
define('PKB_MATH_TOOLS_URL', plugin_dir_url(__FILE__));

add_action('enqueue_block_editor_assets', function (): void {
    wp_enqueue_script(
        'pkb-math-tools-editor',
        PKB_MATH_TOOLS_URL . 'assets/js/editor.js',
        [
            'wp-block-editor',
            'wp-components',
            'wp-compose',
            'wp-element',
            'wp-hooks',
            'wp-i18n',
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
