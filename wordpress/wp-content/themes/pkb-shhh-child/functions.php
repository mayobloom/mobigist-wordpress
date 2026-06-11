<?php
/**
 * Theme integration for Personal Knowledge Blog.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PKB_SHHH_CHILD_VERSION', '0.1.44');

function pkb_shhh_child_is_staff_user(): bool
{
    return is_user_logged_in()
        && (current_user_can('manage_options') || current_user_can('pkb_moderate_members') || current_user_can('edit_posts'));
}

add_action('after_setup_theme', function (): void {
    add_theme_support('editor-styles');
    add_editor_style([
        'assets/css/fonts.css',
        'assets/css/editor.css',
    ]);
});

add_action('wp_enqueue_scripts', function (): void {
    wp_enqueue_style(
        'pkb-local-fonts',
        get_stylesheet_directory_uri() . '/assets/css/fonts.css',
        [],
        PKB_SHHH_CHILD_VERSION
    );

    wp_enqueue_style(
        'pkb-shhh-child',
        get_stylesheet_directory_uri() . '/assets/css/theme.css',
        ['pkb-local-fonts'],
        PKB_SHHH_CHILD_VERSION
    );

    wp_enqueue_script(
        'pkb-shhh-child',
        get_stylesheet_directory_uri() . '/assets/js/theme.js',
        [],
        PKB_SHHH_CHILD_VERSION,
        true
    );

}, 20);

add_action('enqueue_block_editor_assets', function (): void {
    wp_enqueue_style(
        'pkb-local-fonts-editor',
        get_stylesheet_directory_uri() . '/assets/css/fonts.css',
        [],
        PKB_SHHH_CHILD_VERSION
    );

    wp_enqueue_style(
        'pkb-shhh-child-editor',
        get_stylesheet_directory_uri() . '/assets/css/editor.css',
        ['pkb-local-fonts-editor'],
        PKB_SHHH_CHILD_VERSION
    );
}, 20);

add_shortcode('pkb_brand_nav', function (): string {
    $home_url = home_url('/');
    $about_url = home_url('/category/about/');
    $graph_url = home_url('/graph-view/');
    $site_name = get_bloginfo('name') ?: 'Mobigist';

    return sprintf(
        '<span class="pkb-brand-nav" role="navigation" aria-label="Site navigation"><a class="pkb-brand-title" href="%s" aria-label="Home">%s</a><span class="pkb-brand-links"><a href="%s">Graph View</a><span class="pkb-brand-link-separator" aria-hidden="true">•</span><a href="%s">About</a></span></span>',
        esc_url($home_url),
        esc_html($site_name),
        esc_url($graph_url),
        esc_url($about_url)
    );
});

add_shortcode('pkb_child_category_nav', function (): string {
    if (!is_category()) {
        return '';
    }

    $current = get_queried_object();
    if (!$current instanceof WP_Term) {
        return '';
    }

    $children = get_terms([
        'taxonomy' => 'category',
        'hide_empty' => false,
        'parent' => $current->term_id,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    if (is_wp_error($children) || !$children) {
        return '';
    }

    usort($children, function (WP_Term $a, WP_Term $b): int {
        $a_order = get_term_meta($a->term_id, 'pkb_header_order', true);
        $b_order = get_term_meta($b->term_id, 'pkb_header_order', true);
        $a_order = $a_order === '' ? 100 : absint($a_order);
        $b_order = $b_order === '' ? 100 : absint($b_order);

        if ($a_order !== $b_order) {
            return $a_order <=> $b_order;
        }

        return strcasecmp($a->name, $b->name);
    });

    ob_start();
    printf(
        '<p class="pkb-child-category-label"><span aria-hidden="true">↓</span><span>Browse &#039;%s&#039; by subcategory</span><span aria-hidden="true">↓</span></p>',
        esc_html($current->name)
    );
    echo '<nav class="pkb-child-category-nav pkb-primary-nav" aria-label="Child categories">';
    foreach ($children as $child) {
        printf(
            '<a href="%s">%s <span class="pkb-meta">(%d)</span></a>',
            esc_url(get_term_link($child)),
            esc_html($child->name),
            (int) $child->count
        );
    }
    echo '</nav>';

    return (string) ob_get_clean();
});

add_shortcode('pkb_user_summary', function (array $atts = []): string {
    $atts = shortcode_atts([
        'mode' => 'summary',
    ], $atts, 'pkb_user_summary');

    if ($atts['mode'] === 'name') {
        if (!is_user_logged_in()) {
            return '방문자';
        }

        $user = wp_get_current_user();
        return esc_html($user->display_name ?: $user->user_login);
    }

    if (!is_user_logged_in()) {
        return sprintf(
            '<nav class="pkb-user-summary pkb-user-summary-guest" aria-label="Account"><a class="pkb-user-summary-name-link" href="%s">로그인</a><span class="pkb-user-summary-separator" aria-hidden="true">·</span><a class="pkb-user-summary-name-link" href="%s">회원가입</a></nav>',
            esc_url(home_url('/login/')),
            esc_url(home_url('/register/'))
        );
    }

    $user = wp_get_current_user();
    $name = $user->display_name ?: $user->user_login;
    $avatar = get_avatar(
        $user->ID,
        28,
        '',
        $name,
        [
            'class' => 'pkb-user-summary-avatar',
            'extra_attr' => 'loading="lazy"',
        ]
    );

    return sprintf(
        '<nav class="pkb-user-summary" aria-label="Account">%s<span class="pkb-user-summary-greeting">반가워요, </span><a class="pkb-user-summary-name-link" href="%s"><span class="pkb-user-summary-name">%s</span></a><span class="pkb-user-summary-greeting"> 님.</span></nav>',
        $avatar,
        esc_url(home_url('/account/')),
        esc_html($name)
    );
});

add_filter('body_class', function (array $classes): array {
    $classes[] = 'pkb-shhh-child';
    if (pkb_shhh_child_is_staff_user()) {
        $classes[] = 'pkb-has-adminbar-toggle';
    }
    return $classes;
});

add_action('wp_footer', function (): void {
    if (!pkb_shhh_child_is_staff_user()) {
        return;
    }
    ?>
    <button class="pkb-adminbar-toggle" type="button" aria-expanded="false" aria-controls="wpadminbar">Admin 열기</button>
    <script>
    (function () {
        var button = document.querySelector('.pkb-adminbar-toggle');
        if (!button || !document.body) {
            return;
        }

        function setOpen(open) {
            document.body.classList.toggle('pkb-adminbar-open', open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.textContent = open ? 'Admin 닫기' : 'Admin 열기';
            try {
                window.localStorage.setItem('pkbAdminBarOpen', open ? '1' : '0');
            } catch (error) {
                // Storage can be unavailable in restricted browser modes.
            }
        }

        setOpen(false);
        button.addEventListener('click', function () {
            setOpen(!document.body.classList.contains('pkb-adminbar-open'));
        });
    })();
    </script>
    <?php
}, 100);

add_action('wp_body_open', function (): void {
    ?>
    <button class="pkb-back-to-top" type="button" aria-label="페이지 맨 위로 이동" onclick="window.scrollTo({top:0,behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});" style="position:fixed;right:16px;bottom:18px;z-index:40;display:inline-flex;width:42px;height:42px;align-items:center;justify-content:center;">
        <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false" style="display:block;width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;">
            <path d="M6 11l6-6 6 6"></path>
            <path d="M6 18l6-6 6 6"></path>
        </svg>
    </button>
    <?php
}, 20);

add_filter('render_block', function (string $block_content, array $block): string {
    if (is_admin() || ($block['blockName'] ?? '') !== 'core/paragraph') {
        return $block_content;
    }

    if (strpos($block_content, 'Designed with') === false || strpos($block_content, 'WordPress') === false) {
        return $block_content;
    }

    return sprintf(
        '<p class="pkb-site-footer-text">Mobigist : Get the Mobility Gist © 2026. Designed with <a href="https://wordpress.org/" rel="nofollow">WordPress</a>.<br><a href="%s">서비스 이용약관</a><span aria-hidden="true"> · </span><a href="%s">개인정보처리방침</a></p>',
        esc_url(home_url('/terms-of-service/')),
        esc_url(home_url('/privacy-policy/'))
    );
}, 10, 2);
