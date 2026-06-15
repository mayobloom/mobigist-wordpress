<?php
/**
 * Plugin Name: PKB Interactive Embed
 * Description: Safe responsive iframe embeds for interactive model pages.
 * Version: 0.2.1
 * Author: PKB
 * Text Domain: pkb-interactive-embed
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PKB_INTERACTIVE_EMBED_VERSION', '0.2.1');
define('PKB_INTERACTIVE_EMBED_URL', plugin_dir_url(__FILE__));

final class PKB_Interactive_Embed
{
    private const OPTION_ALLOWED_DOMAINS = 'pkb_interactive_embed_allowed_domains';
    private const OPTION_DEFAULT_HEIGHT = 'pkb_interactive_embed_default_height';
    private const OPTION_DEFAULT_MOBILE_HEIGHT = 'pkb_interactive_embed_default_mobile_height';
    private const OPTION_DEFAULT_ASPECT_RATIO = 'pkb_interactive_embed_default_aspect_ratio';

    private static ?PKB_Interactive_Embed $instance = null;

    public static function instance(): PKB_Interactive_Embed
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_assets'], 5);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('init', [$this, 'register_block']);
        add_action('enqueue_block_editor_assets', [$this, 'localize_editor_assets']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public static function activate(): void
    {
        add_option(self::OPTION_ALLOWED_DOMAINS, "mayobloom.github.io\nmodels.mobigist.com");
        add_option(self::OPTION_DEFAULT_HEIGHT, 680);
        add_option(self::OPTION_DEFAULT_MOBILE_HEIGHT, 540);
        add_option(self::OPTION_DEFAULT_ASPECT_RATIO, '');
    }

    public function register_shortcodes(): void
    {
        add_shortcode('interactive_graph', [$this, 'render_shortcode']);
    }

    public function register_block(): void
    {
        register_block_type('pkb/interactive-graph', [
            'api_version' => 2,
            'editor_script' => 'pkb-interactive-embed-editor',
            'editor_style' => 'pkb-interactive-embed',
            'style' => 'pkb-interactive-embed',
            'attributes' => [
                'src' => ['type' => 'string', 'default' => ''],
                'title' => ['type' => 'string', 'default' => ''],
                'caption' => ['type' => 'string', 'default' => ''],
                'height' => ['type' => 'number', 'default' => (int) get_option(self::OPTION_DEFAULT_HEIGHT, 680)],
                'mobileHeight' => ['type' => 'number', 'default' => (int) get_option(self::OPTION_DEFAULT_MOBILE_HEIGHT, 540)],
                'aspectRatio' => ['type' => 'string', 'default' => (string) get_option(self::OPTION_DEFAULT_ASPECT_RATIO, '')],
                'linkLabel' => ['type' => 'string', 'default' => __('Open interactive model', 'pkb-interactive-embed')],
                'fallback' => ['type' => 'string', 'default' => __('This interactive model cannot be embedded from the provided source.', 'pkb-interactive-embed')],
                'allowScroll' => ['type' => 'boolean', 'default' => true],
            ],
            'render_callback' => [$this, 'render_block'],
        ]);
    }

    public function register_assets(): void
    {
        wp_register_style(
            'pkb-interactive-embed',
            PKB_INTERACTIVE_EMBED_URL . 'assets/css/frontend.css',
            [],
            PKB_INTERACTIVE_EMBED_VERSION
        );

        wp_register_script(
            'pkb-interactive-embed-editor',
            PKB_INTERACTIVE_EMBED_URL . 'assets/js/editor.js',
            [
                'wp-block-editor',
                'wp-blocks',
                'wp-components',
                'wp-element',
                'wp-i18n',
                'wp-server-side-render',
            ],
            PKB_INTERACTIVE_EMBED_VERSION,
            true
        );
    }

    public function localize_editor_assets(): void
    {
        wp_localize_script('pkb-interactive-embed-editor', 'pkbInteractiveEmbedDefaults', [
            'height' => (int) get_option(self::OPTION_DEFAULT_HEIGHT, 680),
            'mobileHeight' => (int) get_option(self::OPTION_DEFAULT_MOBILE_HEIGHT, 540),
            'aspectRatio' => (string) get_option(self::OPTION_DEFAULT_ASPECT_RATIO, ''),
            'allowedDomains' => $this->allowed_domains(),
        ]);
    }

    public function add_settings_page(): void
    {
        add_options_page(
            __('Interactive Embeds', 'pkb-interactive-embed'),
            __('Interactive Embeds', 'pkb-interactive-embed'),
            'manage_options',
            'pkb-interactive-embed',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting('pkb_interactive_embed', self::OPTION_ALLOWED_DOMAINS, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_allowed_domains'],
            'default' => "mayobloom.github.io\nmodels.mobigist.com",
        ]);
        register_setting('pkb_interactive_embed', self::OPTION_DEFAULT_HEIGHT, [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_positive_integer'],
            'default' => 680,
        ]);
        register_setting('pkb_interactive_embed', self::OPTION_DEFAULT_MOBILE_HEIGHT, [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_positive_integer'],
            'default' => 540,
        ]);
        register_setting('pkb_interactive_embed', self::OPTION_DEFAULT_ASPECT_RATIO, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_aspect_ratio'],
            'default' => '',
        ]);
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Interactive Embeds', 'pkb-interactive-embed'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('pkb_interactive_embed'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_ALLOWED_DOMAINS); ?>"><?php echo esc_html__('Allowed domains', 'pkb-interactive-embed'); ?></label>
                        </th>
                        <td>
                            <textarea id="<?php echo esc_attr(self::OPTION_ALLOWED_DOMAINS); ?>" name="<?php echo esc_attr(self::OPTION_ALLOWED_DOMAINS); ?>" rows="6" class="large-text code"><?php echo esc_textarea((string) get_option(self::OPTION_ALLOWED_DOMAINS, '')); ?></textarea>
                            <p class="description"><?php echo esc_html__('Enter one hostname per line. Example: mayobloom.github.io', 'pkb-interactive-embed'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_DEFAULT_HEIGHT); ?>"><?php echo esc_html__('Default height', 'pkb-interactive-embed'); ?></label>
                        </th>
                        <td>
                            <input id="<?php echo esc_attr(self::OPTION_DEFAULT_HEIGHT); ?>" name="<?php echo esc_attr(self::OPTION_DEFAULT_HEIGHT); ?>" type="number" min="120" step="1" value="<?php echo esc_attr((string) get_option(self::OPTION_DEFAULT_HEIGHT, 680)); ?>">
                            <p class="description"><?php echo esc_html__('Desktop iframe height in pixels.', 'pkb-interactive-embed'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_DEFAULT_MOBILE_HEIGHT); ?>"><?php echo esc_html__('Default mobile height', 'pkb-interactive-embed'); ?></label>
                        </th>
                        <td>
                            <input id="<?php echo esc_attr(self::OPTION_DEFAULT_MOBILE_HEIGHT); ?>" name="<?php echo esc_attr(self::OPTION_DEFAULT_MOBILE_HEIGHT); ?>" type="number" min="120" step="1" value="<?php echo esc_attr((string) get_option(self::OPTION_DEFAULT_MOBILE_HEIGHT, 540)); ?>">
                            <p class="description"><?php echo esc_html__('Iframe height under 640px viewport width.', 'pkb-interactive-embed'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_DEFAULT_ASPECT_RATIO); ?>"><?php echo esc_html__('Default aspect ratio', 'pkb-interactive-embed'); ?></label>
                        </th>
                        <td>
                            <input id="<?php echo esc_attr(self::OPTION_DEFAULT_ASPECT_RATIO); ?>" name="<?php echo esc_attr(self::OPTION_DEFAULT_ASPECT_RATIO); ?>" type="text" value="<?php echo esc_attr((string) get_option(self::OPTION_DEFAULT_ASPECT_RATIO, '')); ?>" placeholder="16:9">
                            <p class="description"><?php echo esc_html__('Optional. Use width:height format such as 16:9. Height settings are used when empty.', 'pkb-interactive-embed'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function sanitize_allowed_domains($value): string
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value) ?: [];
        $domains = [];

        foreach ($lines as $line) {
            $host = strtolower(trim($line));
            $host = preg_replace('#^https?://#', '', $host);
            $host = preg_replace('#/.*$#', '', $host);
            $host = sanitize_text_field($host);
            if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host)) {
                $domains[] = $host;
            }
        }

        return implode("\n", array_values(array_unique($domains)));
    }

    public function sanitize_positive_integer($value): int
    {
        return max(120, min(3000, absint($value)));
    }

    public function sanitize_aspect_ratio($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^\d+(\.\d+)?:\d+(\.\d+)?$/', $value)) {
            return '';
        }
        return sanitize_text_field($value);
    }

    public function render_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'src' => '',
            'title' => '',
            'caption' => '',
            'height' => (string) get_option(self::OPTION_DEFAULT_HEIGHT, 680),
            'mobile_height' => (string) get_option(self::OPTION_DEFAULT_MOBILE_HEIGHT, 540),
            'aspect_ratio' => (string) get_option(self::OPTION_DEFAULT_ASPECT_RATIO, ''),
            'link_label' => __('Open interactive model', 'pkb-interactive-embed'),
            'fallback' => __('This interactive model cannot be embedded from the provided source.', 'pkb-interactive-embed'),
            'allow_scroll' => 'true',
        ], (array) $atts, 'interactive_graph');

        return $this->render_embed([
            'src' => (string) $atts['src'],
            'title' => (string) $atts['title'],
            'caption' => (string) $atts['caption'],
            'height' => $atts['height'],
            'mobile_height' => $atts['mobile_height'],
            'aspect_ratio' => (string) $atts['aspect_ratio'],
            'link_label' => (string) $atts['link_label'],
            'fallback' => (string) $atts['fallback'],
            'allow_scroll' => $this->normalize_boolean($atts['allow_scroll']),
        ]);
    }

    public function render_block(array $attributes): string
    {
        return $this->render_embed([
            'src' => (string) ($attributes['src'] ?? ''),
            'title' => (string) ($attributes['title'] ?? ''),
            'caption' => (string) ($attributes['caption'] ?? ''),
            'height' => $attributes['height'] ?? get_option(self::OPTION_DEFAULT_HEIGHT, 680),
            'mobile_height' => $attributes['mobileHeight'] ?? get_option(self::OPTION_DEFAULT_MOBILE_HEIGHT, 540),
            'aspect_ratio' => (string) ($attributes['aspectRatio'] ?? get_option(self::OPTION_DEFAULT_ASPECT_RATIO, '')),
            'link_label' => (string) ($attributes['linkLabel'] ?? __('Open interactive model', 'pkb-interactive-embed')),
            'fallback' => (string) ($attributes['fallback'] ?? __('This interactive model cannot be embedded from the provided source.', 'pkb-interactive-embed')),
            'allow_scroll' => (bool) ($attributes['allowScroll'] ?? true),
        ]);
    }

    private function render_embed(array $atts): string
    {
        $src = esc_url_raw((string) $atts['src'], ['https']);
        if (!$this->is_allowed_src($src)) {
            return $this->render_fallback((string) $atts['fallback']);
        }

        $height = $this->sanitize_positive_integer($atts['height']);
        $mobile_height = $this->sanitize_positive_integer($atts['mobile_height']);
        $aspect_ratio = $this->sanitize_aspect_ratio((string) $atts['aspect_ratio']);
        $allow_scroll = (bool) $atts['allow_scroll'];
        $style = sprintf('--pkb-embed-height:%dpx;--pkb-embed-mobile-height:%dpx;', $height, $mobile_height);
        $classes = 'pkb-interactive-embed';

        if ($aspect_ratio !== '') {
            $style .= '--pkb-embed-aspect-ratio:' . esc_attr(str_replace(':', ' / ', $aspect_ratio)) . ';';
            $classes .= ' has-aspect-ratio';
        }

        wp_enqueue_style('pkb-interactive-embed');

        ob_start();
        ?>
        <figure class="<?php echo esc_attr($classes); ?>" style="<?php echo esc_attr($style); ?>">
            <?php if ((string) $atts['title'] !== '') : ?>
                <figcaption class="pkb-interactive-embed__title"><?php echo esc_html((string) $atts['title']); ?></figcaption>
            <?php endif; ?>
            <div class="pkb-interactive-embed__frame">
                <iframe
                    src="<?php echo esc_url($src); ?>"
                    title="<?php echo esc_attr((string) ($atts['title'] ?: __('Interactive model', 'pkb-interactive-embed'))); ?>"
                    loading="lazy"
                    scrolling="<?php echo esc_attr($allow_scroll ? 'auto' : 'no'); ?>"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allowfullscreen
                ></iframe>
            </div>
            <?php if ((string) $atts['caption'] !== '' || (string) $atts['link_label'] !== '') : ?>
                <figcaption class="pkb-interactive-embed__caption">
                    <?php if ((string) $atts['caption'] !== '') : ?>
                        <span><?php echo esc_html((string) $atts['caption']); ?></span>
                    <?php endif; ?>
                    <?php if ((string) $atts['link_label'] !== '') : ?>
                        <a href="<?php echo esc_url($src); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) $atts['link_label']); ?></a>
                    <?php endif; ?>
                </figcaption>
            <?php endif; ?>
        </figure>
        <?php
        return (string) ob_get_clean();
    }

    private function normalize_boolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function is_allowed_src(string $src): bool
    {
        if ($src === '') {
            return false;
        }

        $host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
        $scheme = strtolower((string) wp_parse_url($src, PHP_URL_SCHEME));
        if ($host === '' || $scheme !== 'https') {
            return false;
        }

        return in_array($host, $this->allowed_domains(), true);
    }

    private function allowed_domains(): array
    {
        $value = (string) get_option(self::OPTION_ALLOWED_DOMAINS, '');
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        return array_values(array_filter(array_map(static fn($line) => strtolower(trim($line)), $lines)));
    }

    private function render_fallback(string $message): string
    {
        wp_enqueue_style('pkb-interactive-embed');
        return sprintf(
            '<div class="pkb-interactive-embed-fallback">%s</div>',
            esc_html($message)
        );
    }
}

PKB_Interactive_Embed::instance();
register_activation_hook(__FILE__, ['PKB_Interactive_Embed', 'activate']);
