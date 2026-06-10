<?php
/**
 * Plugin Name: PKB Code Block
 * Description: Gutenberg code block with language search, syntax highlighting, and line numbers.
 * Version: 0.1.9
 * Author: PKB
 * Text Domain: pkb-code-block
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PKB_CODE_BLOCK_VERSION', '0.1.9');
define('PKB_CODE_BLOCK_FILE', __FILE__);
define('PKB_CODE_BLOCK_DIR', plugin_dir_path(__FILE__));
define('PKB_CODE_BLOCK_URL', plugin_dir_url(__FILE__));

final class PKB_Code_Block
{
    private const LANGUAGES = [
        'plain' => 'Plain Text',
        'markup' => 'HTML / XML',
        'css' => 'CSS',
        'javascript' => 'JavaScript',
        'typescript' => 'TypeScript',
        'python' => 'Python',
        'r' => 'R',
        'sql' => 'SQL',
        'bash' => 'Bash',
        'json' => 'JSON',
        'php' => 'PHP',
        'java' => 'Java',
        'cpp' => 'C++',
        'c' => 'C',
        'csharp' => 'C#',
        'ruby' => 'Ruby',
        'go' => 'Go',
        'rust' => 'Rust',
        'yaml' => 'YAML',
        'markdown' => 'Markdown',
        'latex' => 'LaTeX',
    ];

    private const LANGUAGE_DEPENDENCIES = [
        'markup' => [],
        'css' => [],
        'clike' => [],
        'javascript' => ['clike'],
        'typescript' => ['javascript'],
        'python' => [],
        'r' => [],
        'sql' => [],
        'bash' => [],
        'json' => [],
        'php' => ['markup', 'clike'],
        'java' => ['clike'],
        'cpp' => ['c'],
        'c' => ['clike'],
        'csharp' => ['clike'],
        'ruby' => [],
        'go' => ['clike'],
        'rust' => [],
        'yaml' => [],
        'markdown' => ['markup'],
        'latex' => [],
    ];

    public static function init(): void
    {
        add_action('init', [self::class, 'register_block']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue_editor_assets']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_assets']);
    }

    public static function register_block(): void
    {
        self::register_assets();

        register_block_type('pkb/code-block', [
            'api_version' => 2,
            'editor_script' => 'pkb-code-block-editor',
            'editor_style' => 'pkb-code-block',
            'style' => 'pkb-code-block',
            'render_callback' => [self::class, 'render_block'],
            'attributes' => [
                'code' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'language' => [
                    'type' => 'string',
                    'default' => 'plain',
                ],
            ],
        ]);
    }

    public static function enqueue_editor_assets(): void
    {
        self::enqueue_prism_assets();
        wp_enqueue_script('pkb-code-block-editor');
        wp_enqueue_style('pkb-code-block');
    }

    public static function enqueue_frontend_assets(): void
    {
        self::enqueue_prism_assets();
        wp_enqueue_script('pkb-code-block-frontend');
    }

    private static function register_assets(): void
    {
        wp_register_style(
            'pkb-code-block-prism',
            PKB_CODE_BLOCK_URL . 'vendor/prism/themes/prism.min.css',
            [],
            '1.29.0'
        );
        wp_register_style(
            'pkb-code-block-prism-line-numbers',
            PKB_CODE_BLOCK_URL . 'vendor/prism/plugins/line-numbers/prism-line-numbers.min.css',
            ['pkb-code-block-prism'],
            '1.29.0'
        );
        wp_register_style(
            'pkb-code-block',
            PKB_CODE_BLOCK_URL . 'assets/css/code-block.css',
            ['pkb-code-block-prism-line-numbers'],
            PKB_CODE_BLOCK_VERSION
        );

        wp_register_script(
            'pkb-code-block-prism-bundle',
            PKB_CODE_BLOCK_URL . 'assets/js/prism-bundle.js',
            [],
            PKB_CODE_BLOCK_VERSION,
            true
        );

        wp_register_script(
            'pkb-code-block-prism-line-numbers',
            PKB_CODE_BLOCK_URL . 'vendor/prism/plugins/line-numbers/prism-line-numbers.min.js',
            ['pkb-code-block-prism-bundle'],
            '1.29.0',
            true
        );

        wp_register_script(
            'pkb-code-block-editor',
            PKB_CODE_BLOCK_URL . 'assets/js/editor.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'pkb-code-block-prism-bundle'],
            PKB_CODE_BLOCK_VERSION,
            true
        );

        wp_localize_script('pkb-code-block-editor', 'PKBCodeBlock', [
            'languages' => self::LANGUAGES,
        ]);

        wp_register_script(
            'pkb-code-block-frontend',
            PKB_CODE_BLOCK_URL . 'assets/js/frontend.js',
            ['pkb-code-block-prism-bundle'],
            PKB_CODE_BLOCK_VERSION,
            true
        );
    }

    private static function enqueue_prism_assets(): void
    {
        self::register_assets();

        wp_enqueue_style('pkb-code-block');
        wp_enqueue_script('pkb-code-block-prism-bundle');
    }

    private static function register_language(string $language, array &$deps): void
    {
        if ($language === 'plain') {
            return;
        }

        $handle = 'pkb-code-block-prism-' . $language;
        if (wp_script_is($handle, 'registered')) {
            if (!in_array($handle, $deps, true)) {
                $deps[] = $handle;
            }
            return;
        }

        foreach (self::LANGUAGE_DEPENDENCIES[$language] ?? [] as $dependency) {
            self::register_language($dependency, $deps);
        }

        wp_register_script(
            $handle,
            PKB_CODE_BLOCK_URL . 'vendor/prism/components/prism-' . $language . '.min.js',
            $deps,
            '1.29.0',
            true
        );

        $deps[] = $handle;
    }

    public static function render_block(array $attributes, string $content = ''): string
    {
        $language = sanitize_key($attributes['language'] ?? 'plain');
        if (!isset(self::LANGUAGES[$language])) {
            $language = 'plain';
        }

        $code = (string) ($attributes['code'] ?? '');
        $label = self::LANGUAGES[$language];
        $class = $language === 'plain' ? 'language-none' : 'language-' . $language;
        $line_numbers = self::render_line_numbers($code);

        return sprintf(
            '<figure class="pkb-code-block" data-language="%s"><figcaption class="pkb-code-block-controls"><span class="pkb-code-block-language">%s</span><button class="pkb-code-copy-button" type="button">Copy to Clipboard</button></figcaption><div class="pkb-code-stage"><div class="pkb-code-line-gutter" aria-hidden="true">%s</div><pre><code class="%s">%s</code></pre></div></figure>',
            esc_attr($language),
            esc_html($label),
            $line_numbers,
            esc_attr($class),
            esc_html($code)
        );
    }

    private static function render_line_numbers(string $code): string
    {
        $line_count = max(1, substr_count($code, "\n") + 1);
        $numbers = '';

        for ($i = 1; $i <= $line_count; $i++) {
            $numbers .= '<span>' . esc_html((string) $i) . '</span>';
        }

        return $numbers;
    }
}

PKB_Code_Block::init();
