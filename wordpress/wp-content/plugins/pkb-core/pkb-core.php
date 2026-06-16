<?php
/**
 * Plugin Name: PKB Core
 * Description: Core functionality for the Personal Knowledge Blog.
 * Version: 0.1.66
 * Author: PKB
 * Text Domain: pkb-core
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PKB_CORE_VERSION', '0.1.65');
define('PKB_CORE_FILE', __FILE__);
define('PKB_CORE_DIR', plugin_dir_path(__FILE__));
define('PKB_CORE_URL', plugin_dir_url(__FILE__));

final class PKB_Core
{
    public const HEADER_CATEGORY_SHOW_META = 'pkb_show_in_header';
    public const HEADER_CATEGORY_ORDER_META = 'pkb_header_order';
    private const CATEGORY_TREE_COUNT_TRANSIENT_PREFIX = 'pkb_cat_tree_count_';

    private static ?PKB_Core $instance = null;
    private ?string $category_header_order_sort = null;

    public static function instance(): PKB_Core
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('init', [$this, 'block_xmlrpc_requests'], 0);
        add_action('init', [$this, 'register_post_meta']);
        add_action('init', [$this, 'handle_comment_forms']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('add_meta_boxes', [$this, 'add_access_meta_box']);
        add_action('save_post', [$this, 'save_post_access'], 10, 2);
        add_action('save_post', [$this, 'index_internal_links'], 30, 2);
        add_action('save_post_post', [self::class, 'flush_category_tree_count_cache']);
        add_action('delete_post', [self::class, 'flush_category_tree_count_cache']);
        add_action('set_object_terms', [self::class, 'flush_category_tree_count_cache']);
        add_action('pre_get_posts', [$this, 'filter_restricted_posts']);
        add_action('template_redirect', [$this, 'guard_single_post_access']);
        add_filter('authenticate', [$this, 'block_sanctioned_login'], 30, 3);
        add_filter('the_content', [$this, 'render_internal_links'], 12);
        add_filter('the_content', [$this, 'add_heading_anchors'], 13);
        add_filter('the_content', [$this, 'append_partial_graph'], 40);
        add_filter('render_block_core/post-date', [$this, 'append_post_date_meta'], 10, 2);
        add_filter('render_block_core/comment-date', [$this, 'append_comment_date_actions'], 10, 3);
        add_filter('render_block_core/post-comments-form', [$this, 'render_logged_out_comment_prompt'], 10, 3);
        add_filter('comment_form_defaults', [$this, 'customize_comment_form_defaults']);
        add_filter('comment_text', [$this, 'append_comment_like'], 20, 3);
        add_filter('comments_open', [$this, 'require_login_for_comments'], 10, 2);
        add_filter('pre_comment_approved', [$this, 'approve_logged_in_member_comment'], 10, 2);
        add_action('pre_comment_on_post', [$this, 'guard_comment_submission']);
        add_action('pre_get_posts', [$this, 'apply_search_sort']);
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_init', [$this, 'capture_category_header_order_sort_request'], 0);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [self::class, 'ensure_default_header_category_meta']);
        add_action('admin_init', [$this, 'handle_moderation_form']);
        add_action('category_add_form_fields', [$this, 'render_category_nav_add_fields']);
        add_action('category_edit_form_fields', [$this, 'render_category_nav_edit_fields']);
        add_action('created_category', [$this, 'save_category_nav_fields']);
        add_action('edited_category', [$this, 'save_category_nav_fields']);
        add_action('created_category', [self::class, 'flush_category_tree_count_cache']);
        add_action('edited_category', [self::class, 'flush_category_tree_count_cache']);
        add_action('delete_category', [self::class, 'flush_category_tree_count_cache']);
        add_filter('manage_edit-category_columns', [$this, 'add_category_nav_columns']);
        add_filter('manage_category_custom_column', [$this, 'render_category_nav_column'], 10, 3);
        add_filter('get_terms', [$this, 'sort_category_admin_terms_by_header_order'], 10, 4);
        add_action('phpmailer_init', [$this, 'configure_smtp']);
        add_filter('wp_mail_from', [$this, 'mail_from']);
        add_filter('wp_mail_from_name', [$this, 'mail_from_name']);
        add_filter('posts_clauses', [$this, 'search_like_sort_clauses'], 10, 2);
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('pvc_display_views_count', '__return_false');
        add_action('pkb_before_user_account_delete', [$this, 'delete_user_owned_data']);
        add_filter('pkb_liked_posts_markup', [$this, 'liked_posts_markup_filter'], 10, 2);
        add_filter('pkb_user_comments_markup', [$this, 'user_comments_markup_filter'], 10, 2);
    }

    public static function activate(): void
    {
        self::create_tables();
        self::create_roles();
        self::create_categories();
        self::ensure_default_header_category_meta();
        self::create_pages();
        self::set_default_options();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function block_xmlrpc_requests(): void
    {
        if (!defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST) {
            return;
        }

        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo 'XML-RPC is disabled.';
        exit;
    }

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pkb_' . $name;
    }

    private static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::table('post_likes') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_post (user_id, post_id),
            KEY post_id (post_id)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('comment_likes') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            comment_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_comment (user_id, comment_id),
            KEY comment_id (comment_id)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('internal_links') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_post_id BIGINT UNSIGNED NOT NULL,
            target_post_id BIGINT UNSIGNED NULL,
            target_heading_slug VARCHAR(220) NULL,
            original_link_text TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY source_post_id (source_post_id),
            KEY target_post_id (target_post_id)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('moderation') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            moderator_id BIGINT UNSIGNED NOT NULL,
            sanction_type VARCHAR(60) NOT NULL,
            reason TEXT NOT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset;");
    }

    private static function create_roles(): void
    {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('pkb_read_special');
            $admin->add_cap('pkb_moderate_members');
            $admin->add_cap('pkb_manage_graph');
        }
    }

    private static function create_categories(): void
    {
        foreach (['News', 'Review', 'Academic', 'Note', 'About'] as $name) {
            if (!term_exists($name, 'category')) {
                wp_insert_term($name, 'category');
            }
        }
    }

    public static function ensure_default_header_category_meta(): void
    {
        if (get_option('pkb_header_category_meta_initialized')) {
            return;
        }

        $defaults = [
            'News' => 10,
            'Review' => 20,
            'Academic' => 30,
            'Note' => 40,
        ];

        foreach (['News', 'Review', 'Academic', 'Note', 'About'] as $name) {
            $term = get_term_by('name', $name, 'category');
            if (!$term || is_wp_error($term)) {
                continue;
            }

            $order = $defaults[$name] ?? 999;
            update_term_meta($term->term_id, self::HEADER_CATEGORY_SHOW_META, isset($defaults[$name]) ? '1' : '0');
            update_term_meta($term->term_id, self::HEADER_CATEGORY_ORDER_META, (string) $order);
        }

        update_option('pkb_header_category_meta_initialized', '1', false);
    }

    public static function category_tree_post_count(int $term_id): int
    {
        $term_id = absint($term_id);
        if (!$term_id) {
            return 0;
        }

        $cache_key = self::CATEGORY_TREE_COUNT_TRANSIENT_PREFIX . $term_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return absint($cached);
        }

        $term_ids = [$term_id];
        $children = get_term_children($term_id, 'category');
        if (!is_wp_error($children) && $children) {
            $term_ids = array_merge($term_ids, array_map('absint', $children));
        }
        $term_ids = array_values(array_unique(array_filter($term_ids)));
        if (!$term_ids) {
            return 0;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
        $sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'category'
                AND tt.term_id IN ($placeholders)
                AND p.post_type = 'post'
                AND p.post_status = 'publish'
        ";
        $count = absint($wpdb->get_var($wpdb->prepare($sql, $term_ids)));

        set_transient($cache_key, $count, 10 * MINUTE_IN_SECONDS);
        return $count;
    }

    public static function flush_category_tree_count_cache(): void
    {
        global $wpdb;

        $like = $wpdb->esc_like('_transient_' . self::CATEGORY_TREE_COUNT_TRANSIENT_PREFIX) . '%';
        $timeout_like = $wpdb->esc_like('_transient_timeout_' . self::CATEGORY_TREE_COUNT_TRANSIENT_PREFIX) . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like,
            $timeout_like
        ));
    }

    public function render_category_nav_add_fields(): void
    {
        ?>
        <div class="form-field">
            <label for="pkb_show_in_header">상단 헤더 표시</label>
            <label>
                <input type="checkbox" id="pkb_show_in_header" name="pkb_show_in_header" value="1">
                블로그 상단 헤더 카테고리에 표시
            </label>
            <p>체크하면 사이트 상단 카테고리 줄에 이 카테고리가 표시됩니다.</p>
        </div>
        <div class="form-field">
            <label for="pkb_header_order">헤더 표시 순서</label>
            <input type="number" id="pkb_header_order" name="pkb_header_order" value="100" min="0" step="1">
            <p>낮은 숫자일수록 왼쪽에 표시됩니다. 같은 값이면 이름순으로 정렬됩니다.</p>
        </div>
        <?php
    }

    public function render_category_nav_edit_fields(WP_Term $term): void
    {
        $show = get_term_meta($term->term_id, self::HEADER_CATEGORY_SHOW_META, true) === '1';
        $order = get_term_meta($term->term_id, self::HEADER_CATEGORY_ORDER_META, true);
        $order = $order === '' ? '100' : $order;
        ?>
        <tr class="form-field">
            <th scope="row"><label for="pkb_show_in_header">상단 헤더 표시</label></th>
            <td>
                <label>
                    <input type="checkbox" id="pkb_show_in_header" name="pkb_show_in_header" value="1" <?php checked($show); ?>>
                    블로그 상단 헤더 카테고리에 표시
                </label>
                <p class="description">체크하면 사이트 상단 카테고리 줄에 이 카테고리가 표시됩니다.</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="pkb_header_order">헤더 표시 순서</label></th>
            <td>
                <input type="number" id="pkb_header_order" name="pkb_header_order" value="<?php echo esc_attr($order); ?>" min="0" step="1">
                <p class="description">낮은 숫자일수록 왼쪽에 표시됩니다. 같은 값이면 이름순으로 정렬됩니다.</p>
            </td>
        </tr>
        <?php
    }

    public function save_category_nav_fields(int $term_id): void
    {
        if (!current_user_can('manage_categories')) {
            return;
        }

        $show = !empty($_POST['pkb_show_in_header']) ? '1' : '0';
        $order = isset($_POST['pkb_header_order']) ? absint(wp_unslash($_POST['pkb_header_order'])) : 100;

        update_term_meta($term_id, self::HEADER_CATEGORY_SHOW_META, $show);
        update_term_meta($term_id, self::HEADER_CATEGORY_ORDER_META, (string) $order);
    }

    public function add_category_nav_columns(array $columns): array
    {
        $current = strtolower((string) ($_GET['pkb_header_order_sort'] ?? ''));
        $next = $current === 'asc' ? 'desc' : 'asc';
        $indicator = $current === 'asc' ? '↑' : ($current === 'desc' ? '↓' : '');
        $url = add_query_arg([
            'taxonomy' => 'category',
            'pkb_header_order_sort' => $next,
        ], admin_url('edit-tags.php'));

        $columns['pkb_header_nav'] = '상단 헤더';
        $columns['pkb_header_order'] = sprintf(
            '<a href="%s"><span>헤더 순서</span>%s</a>',
            esc_url($url),
            $indicator === '' ? '' : ' <span aria-hidden="true">' . esc_html($indicator) . '</span>'
        );

        return $columns;
    }

    public function render_category_nav_column(string $content, string $column_name, int $term_id): string
    {
        if ($column_name === 'pkb_header_nav') {
            return get_term_meta($term_id, self::HEADER_CATEGORY_SHOW_META, true) === '1' ? '표시' : '-';
        }

        if ($column_name === 'pkb_header_order') {
            $order = get_term_meta($term_id, self::HEADER_CATEGORY_ORDER_META, true);
            return $order === '' ? '-' : esc_html((string) absint($order));
        }

        return $content;
    }

    public function capture_category_header_order_sort_request(): void
    {
        global $pagenow;

        if ($pagenow !== 'edit-tags.php') {
            return;
        }

        if (($_GET['taxonomy'] ?? '') !== 'category') {
            return;
        }

        $sort = strtolower((string) ($_GET['pkb_header_order_sort'] ?? ''));
        $legacy_sort_requested = ($_GET['orderby'] ?? '') === 'pkb_header_order';

        if (!in_array($sort, ['asc', 'desc'], true) && !$legacy_sort_requested) {
            return;
        }

        if (!in_array($sort, ['asc', 'desc'], true)) {
            $sort = strtolower((string) ($_GET['order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        }

        $this->category_header_order_sort = $sort === 'desc' ? 'DESC' : 'ASC';

        /*
         * WP_Terms_List_Table disables hierarchical rendering whenever orderby
         * is present in the request. Header-order sorting uses a custom query
         * parameter so the list table can keep hierarchical category rows.
         */
        unset($_GET['orderby'], $_POST['orderby'], $_REQUEST['orderby'], $_GET['order'], $_POST['order'], $_REQUEST['order']);
    }

    public function sort_category_admin_terms_by_header_order($terms, $taxonomies, array $args, WP_Term_Query $term_query)
    {
        if (!is_array($terms)) {
            return $terms;
        }

        if (!is_admin()) {
            return $terms;
        }

        global $pagenow;
        if ($pagenow !== 'edit-tags.php') {
            return $terms;
        }

        $taxonomies = is_array($taxonomies) ? $taxonomies : [$taxonomies];
        if (!in_array('category', $taxonomies, true)) {
            return $terms;
        }

        if (!$terms || !$terms[0] instanceof WP_Term) {
            return $terms;
        }

        $is_default_category_table = empty($_GET['orderby'])
            && empty($_REQUEST['orderby'])
            && empty($_GET['s']);

        if ($this->category_header_order_sort === null && !$is_default_category_table) {
            return $terms;
        }

        $direction = $this->category_header_order_sort ?? 'ASC';

        usort($terms, function (WP_Term $a, WP_Term $b) use ($direction): int {
            $a_order = get_term_meta($a->term_id, self::HEADER_CATEGORY_ORDER_META, true);
            $b_order = get_term_meta($b->term_id, self::HEADER_CATEGORY_ORDER_META, true);
            $a_order = $a_order === '' ? 100 : absint($a_order);
            $b_order = $b_order === '' ? 100 : absint($b_order);

            if ($a_order !== $b_order) {
                return $direction === 'DESC' ? $b_order <=> $a_order : $a_order <=> $b_order;
            }

            return strcasecmp($a->name, $b->name);
        });

        return $terms;
    }

    private static function create_pages(): void
    {
        $pages = [
            'graph-view' => ['Graph View', '[pkb_graph_view]'],
            'search' => ['Search', '[pkb_search]'],
        ];

        foreach ($pages as $slug => [$title, $content]) {
            if (get_page_by_path($slug)) {
                continue;
            }

            wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_name' => $slug,
                'post_title' => $title,
                'post_content' => $content,
            ]);
        }
    }

    private static function set_default_options(): void
    {
        add_option('pkb_partial_graph_depth', 2);
        add_option('pkb_graph_node_opacity', '1,0.65,0.35');
        add_option('pkb_graph_edge_opacity', '0.8,0.45,0.25');
        add_option('pkb_show_graph_to_guests', 1);

        update_option('comment_registration', 1);
        update_option('thread_comments', 1);
        update_option('thread_comments_depth', 2);
        update_option('page_comments', 1);
        update_option('comments_per_page', 10);
        update_option('comment_order', 'asc');
    }

    public function register_shortcodes(): void
    {
        add_shortcode('pkb_graph_view', [$this, 'shortcode_graph_view']);
        add_shortcode('pkb_category_nav', [$this, 'shortcode_category_nav']);
        add_shortcode('pkb_search', [$this, 'shortcode_search']);
    }

    public function register_post_meta(): void
    {
        register_post_meta('post', '_pkb_access_level', [
            'type' => 'string',
            'single' => true,
            'default' => 'public',
            'show_in_rest' => true,
            'sanitize_callback' => [$this, 'sanitize_access_level'],
            'auth_callback' => fn () => current_user_can('edit_posts'),
        ]);
    }

    public function sanitize_access_level(string $level): string
    {
        return in_array($level, ['public', 'special', 'admin'], true) ? $level : 'public';
    }

    public function enqueue_frontend_assets(): void
    {
        wp_enqueue_style('pkb-core', PKB_CORE_URL . 'assets/css/pkb-core.css', [], PKB_CORE_VERSION);
        wp_enqueue_script('pkb-mathjax-config', PKB_CORE_URL . 'assets/js/mathjax-config.js', [], PKB_CORE_VERSION, false);
        wp_enqueue_script('mathjax', 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js', ['pkb-mathjax-config'], '3', true);
        wp_enqueue_script('d3', 'https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js', [], '7', true);
        wp_enqueue_script('pkb-graph', PKB_CORE_URL . 'assets/js/graph.js', ['d3'], PKB_CORE_VERSION, true);
        wp_enqueue_script('pkb-frontend', PKB_CORE_URL . 'assets/js/frontend.js', ['wp-api-fetch'], PKB_CORE_VERSION, true);
        wp_localize_script('pkb-frontend', 'PKB', $this->script_data());
        wp_localize_script('pkb-graph', 'PKB', $this->script_data());
    }

    public function enqueue_editor_assets(): void
    {
        wp_enqueue_script('pkb-mathjax-config', PKB_CORE_URL . 'assets/js/mathjax-config.js', [], PKB_CORE_VERSION, false);
        wp_enqueue_script('mathjax', 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js', ['pkb-mathjax-config'], '3', true);
        wp_enqueue_style('pkb-core', PKB_CORE_URL . 'assets/css/pkb-core.css', [], PKB_CORE_VERSION);
    }

    private function script_data(): array
    {
        return [
            'restUrl' => esc_url_raw(rest_url('pkb/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'currentPostId' => get_queried_object_id(),
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => esc_url_raw(add_query_arg('redirect_to', $this->current_url(), home_url('/login/'))),
            'loginRequiredMessage' => '좋아요를 누르려면 로그인이 필요합니다. 로그인하시겠습니까?',
            'graph' => [
                'depth' => (int) get_option('pkb_partial_graph_depth', 2),
                'nodeOpacity' => array_map('floatval', explode(',', (string) get_option('pkb_graph_node_opacity', '1,0.65,0.35'))),
                'edgeOpacity' => array_map('floatval', explode(',', (string) get_option('pkb_graph_edge_opacity', '0.8,0.45,0.25'))),
            ],
        ];
    }

    private function current_url(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? wp_parse_url(home_url('/'), PHP_URL_HOST)));
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));

        return esc_url_raw($scheme . $host . $uri);
    }

    public function block_sanctioned_login($user, string $username, string $password)
    {
        if ($user instanceof WP_User && $this->is_user_sanctioned($user->ID, 'login')) {
            return new WP_Error('pkb_sanctioned', '로그인이 제한된 계정입니다.');
        }

        return $user;
    }

    public function delete_user_owned_data(int $user_id): void
    {
        global $wpdb;

        $comments = get_comments(['user_id' => $user_id, 'fields' => 'ids', 'number' => 0]);
        foreach ($comments as $comment_id) {
            wp_delete_comment((int) $comment_id, true);
        }

        $wpdb->delete(self::table('post_likes'), ['user_id' => $user_id], ['%d']);
        $wpdb->delete(self::table('comment_likes'), ['user_id' => $user_id], ['%d']);
    }

    private function liked_posts_markup(int $user_id): string
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id FROM " . self::table('post_likes') . " WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
            $user_id
        ));

        if (!$rows) {
            return '<p class="pkb-meta">좋아요한 글이 없습니다.</p>';
        }

        $out = '<ul class="pkb-liked-posts">';
        foreach ($rows as $row) {
            $out .= '<li><a href="' . esc_url(get_permalink((int) $row->post_id)) . '">' . esc_html(get_the_title((int) $row->post_id)) . '</a></li>';
        }
        $out .= '</ul>';

        return $out;
    }

    public function liked_posts_markup_filter(string $fallback, int $user_id): string
    {
        return $this->liked_posts_markup($user_id) ?: $fallback;
    }

    public function user_comments_markup_filter(string $fallback, int $user_id): string
    {
        $comments = get_comments([
            'user_id' => $user_id,
            'status' => 'all',
            'number' => 20,
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
        ]);

        $comments = array_values(array_filter($comments, fn (WP_Comment $comment): bool => $this->can_read_post((int) $comment->comment_post_ID)));
        if (!$comments) {
            return $fallback;
        }

        $out = '<div class="pkb-user-comments">';
        foreach ($comments as $comment) {
            $status = $this->comment_status_label($comment);
            $out .= '<article class="pkb-user-comment">';
            $out .= '<div class="pkb-user-comment-meta"><a href="' . esc_url(get_comment_link($comment)) . '">' . esc_html(get_the_title((int) $comment->comment_post_ID)) . '</a> <span aria-hidden="true">·</span> ' . esc_html(get_comment_date('Y-m-d', $comment)) . ' <span aria-hidden="true">·</span> ' . esc_html($status);
            $out .= $this->own_comment_controls($comment);
            $out .= '</div>';
            $out .= '<div class="pkb-user-comment-content">' . wp_kses_post(wpautop($comment->comment_content)) . '</div>';
            $out .= '</article>';
        }
        $out .= '</div>';

        return $out;
    }

    private function comment_status_label(WP_Comment $comment): string
    {
        return match ((string) $comment->comment_approved) {
            '1' => '승인됨',
            '0' => '승인 대기',
            'spam' => '스팸',
            'trash' => '삭제됨',
            default => '상태 미확인',
        };
    }

    public function shortcode_graph_view(): string
    {
        if (!$this->can_view_graph()) {
            return '<p class="pkb-form-message">Graph View를 볼 권한이 없습니다.</p>';
        }

        return '<div class="pkb-graph pkb-graph-full" data-pkb-graph="full"></div>';
    }

    public function shortcode_search(): string
    {
        $query_text = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $selected_tags = $this->search_selected_tags();
        $tag_mode = sanitize_key(wp_unslash($_GET['tag_mode'] ?? 'or'));
        $tag_mode = $tag_mode === 'and' ? 'and' : 'or';
        $sort = sanitize_key(wp_unslash($_GET['sort'] ?? 'newest'));
        $sort = in_array($sort, ['newest', 'oldest', 'title', 'views', 'likes', 'comments'], true) ? $sort : 'newest';

        $tags = get_terms([
            'taxonomy' => 'post_tag',
            'hide_empty' => true,
            'orderby' => 'count',
            'order' => 'DESC',
        ]);

        ob_start();
        ?>
        <section class="pkb-search-page">
            <form class="pkb-search-form" method="get" action="<?php echo esc_url(home_url('/search/')); ?>">
                <label class="pkb-search-field">
                    <span>Search</span>
                    <input type="search" name="q" value="<?php echo esc_attr($query_text); ?>" autocomplete="off" placeholder="검색어를 입력해 주세요">
                </label>
                <label class="pkb-search-filter">
                    <span>Sort</span>
                    <select name="sort">
                        <option value="newest" <?php selected($sort, 'newest'); ?>>최신순</option>
                        <option value="oldest" <?php selected($sort, 'oldest'); ?>>오래된순</option>
                        <option value="title" <?php selected($sort, 'title'); ?>>제목순</option>
                        <option value="views" <?php selected($sort, 'views'); ?>>조회수 많은 순</option>
                        <option value="likes" <?php selected($sort, 'likes'); ?>>좋아요순</option>
                        <option value="comments" <?php selected($sort, 'comments'); ?>>댓글 많은 순</option>
                    </select>
                </label>
                <label class="pkb-search-filter">
                    <span>Tag</span>
                    <select class="pkb-search-tag-select" name="tag_select" data-placeholder="태그 선택">
                        <option value="">태그 선택</option>
                        <?php if (!is_wp_error($tags)) : ?>
                            <?php foreach ($tags as $term) : ?>
                                <option value="<?php echo esc_attr($term->slug); ?>"><?php echo esc_html(sprintf('%s (%s)', $term->name, number_format_i18n((int) $term->count))); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </label>
                <button type="submit">검색</button>
                <div class="pkb-search-tag-panel">
                    <div class="pkb-search-selected-tags" aria-live="polite">
                        <?php foreach ($selected_tags as $slug) : ?>
                            <?php $term = get_term_by('slug', $slug, 'post_tag'); ?>
                            <?php if ($term instanceof WP_Term) : ?>
                                <span class="pkb-search-tag-chip" data-tag="<?php echo esc_attr($slug); ?>">
                                    <span>#<?php echo esc_html($term->name); ?></span>
                                    <button type="button" aria-label="<?php echo esc_attr($term->name . ' 태그 제거'); ?>">×</button>
                                    <input type="hidden" name="tags[]" value="<?php echo esc_attr($slug); ?>">
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <fieldset class="pkb-search-tag-mode" <?php echo count($selected_tags) < 2 ? 'hidden' : ''; ?>>
                        <legend>Tag condition</legend>
                        <label><input type="radio" name="tag_mode" value="or" <?php checked($tag_mode, 'or'); ?>> OR</label>
                        <label><input type="radio" name="tag_mode" value="and" <?php checked($tag_mode, 'and'); ?>> AND</label>
                    </fieldset>
                </div>
            </form>

            <?php if ($query_text === '' && $selected_tags === []) : ?>
                <p class="pkb-form-note">검색어를 입력하거나 태그를 선택해 주세요.</p>
            <?php else : ?>
                <?php echo $this->search_results_markup($query_text, $selected_tags, $tag_mode, $sort); ?>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function search_selected_tags(): array
    {
        $raw = $_GET['tags'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        if (isset($_GET['tag']) && !is_array($_GET['tag'])) {
            $raw[] = $_GET['tag'];
        }

        $tags = [];
        foreach ($raw as $item) {
            $slug = sanitize_title(wp_unslash($item));
            if ($slug !== '' && get_term_by('slug', $slug, 'post_tag')) {
                $tags[] = $slug;
            }
        }

        return array_values(array_unique($tags));
    }

    private function search_results_markup(string $query_text, array $tags, string $tag_mode, string $sort): string
    {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            's' => $query_text,
            'ignore_sticky_posts' => true,
        ];

        if ($tags !== []) {
            $args['tax_query'] = [[
                'taxonomy' => 'post_tag',
                'field' => 'slug',
                'terms' => $tags,
                'operator' => $tag_mode === 'and' ? 'AND' : 'IN',
            ]];
        }

        if ($sort === 'oldest') {
            $args['orderby'] = 'date';
            $args['order'] = 'ASC';
        } elseif ($sort === 'title') {
            $args['orderby'] = 'title';
            $args['order'] = 'ASC';
        } elseif ($sort === 'comments') {
            $args['orderby'] = 'comment_count';
            $args['order'] = 'DESC';
        } elseif ($sort === 'views') {
            $args['pkb_sort_views'] = 1;
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
        } elseif ($sort === 'likes') {
            $args['pkb_sort_likes'] = 1;
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
        } else {
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
        }

        $query = new WP_Query($args);
        $posts = array_values(array_filter($query->posts, fn (WP_Post $post): bool => $this->can_read_post((int) $post->ID)));

        if (!$posts) {
            return '<p class="pkb-form-note">검색 결과가 없습니다.</p>';
        }

        ob_start();
        ?>
        <div class="pkb-search-results">
            <p class="pkb-search-count"><?php echo esc_html(sprintf('%d results', count($posts))); ?></p>
            <?php foreach ($posts as $post) : ?>
                <article class="pkb-search-result">
                    <div class="pkb-search-result-body">
                        <h2><a href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></h2>
                        <p class="pkb-search-excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt($post), 34)); ?></p>
                        <p class="pkb-search-meta">
                            <?php echo esc_html(get_the_date('M j, Y', $post)); ?>
                            <span aria-hidden="true">·</span>
                            <span class="pkb-meta-counts">
                                <?php echo $this->metric_count_markup($this->post_view_count((int) $post->ID), 'view', 'View', 'Views'); ?>
                                <?php echo $this->metric_count_markup($this->post_like_count((int) $post->ID), 'like', 'Like', 'Likes'); ?>
                                <?php echo $this->metric_count_markup((int) get_comments_number($post->ID), 'comment', 'Comment', 'Comments'); ?>
                            </span>
                        </p>
                        <?php echo wp_kses_post($this->search_result_terms((int) $post->ID)); ?>
                    </div>
                    <?php if (has_post_thumbnail($post)) : ?>
                        <a class="pkb-search-thumb" href="<?php echo esc_url(get_permalink($post)); ?>" aria-label="<?php echo esc_attr(get_the_title($post)); ?>">
                            <?php echo get_the_post_thumbnail($post, 'thumbnail', ['loading' => 'lazy', 'decoding' => 'async']); ?>
                        </a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function search_result_terms(int $post_id): string
    {
        $categories = get_the_category($post_id);
        $tags = get_the_tags($post_id);

        if (!$categories && !$tags) {
            return '';
        }

        $items = [];
        foreach ($categories ?: [] as $term) {
            $items[] = '<a href="' . esc_url(get_term_link($term)) . '">' . esc_html($term->name) . '</a>';
        }
        foreach ($tags ?: [] as $term) {
            $items[] = '<a href="' . esc_url(get_term_link($term)) . '">#' . esc_html($term->name) . '</a>';
        }

        return '<p class="pkb-search-terms">' . implode(' ', $items) . '</p>';
    }

    public function shortcode_category_nav(): string
    {
        if (!function_exists('pkb_render_primary_nav')) {
            return '';
        }

        ob_start();
        echo '<div class="pkb-top-nav-wrap">';
        pkb_render_primary_nav();
        echo '</div>';

        return (string) ob_get_clean();
    }

    public function add_access_meta_box(): void
    {
        add_meta_box('pkb_access', '게시글 접근 권한', [$this, 'render_access_meta_box'], 'post', 'side');
    }

    public function render_access_meta_box(WP_Post $post): void
    {
        wp_nonce_field('pkb_access_meta', 'pkb_access_nonce');
        $value = get_post_meta($post->ID, '_pkb_access_level', true) ?: 'public';
        ?>
        <label for="pkb_access_level">접근 권한</label>
        <select id="pkb_access_level" name="pkb_access_level">
            <option value="public" <?php selected($value, 'public'); ?>>공개</option>
            <option value="special" <?php selected($value, 'special'); ?>>특별회원 이상</option>
            <option value="admin" <?php selected($value, 'admin'); ?>>관리자 전용</option>
        </select>
        <?php
    }

    public function save_post_access(int $post_id, WP_Post $post): void
    {
        if ($post->post_type !== 'post' || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (!isset($_POST['pkb_access_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pkb_access_nonce'])), 'pkb_access_meta')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_pkb_access_level', $this->sanitize_access_level(sanitize_text_field(wp_unslash($_POST['pkb_access_level'] ?? 'public'))));
    }

    public function filter_restricted_posts(WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        $meta_query = (array) $query->get('meta_query');
        if (!current_user_can('pkb_read_special')) {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => '_pkb_access_level',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_pkb_access_level',
                    'value' => 'public',
                ],
            ];
        } elseif (!current_user_can('manage_options')) {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => '_pkb_access_level',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_pkb_access_level',
                    'value' => ['public', 'special'],
                    'compare' => 'IN',
                ],
            ];
        }

        if ($meta_query) {
            $query->set('meta_query', $meta_query);
        }
    }

    public function guard_single_post_access(): void
    {
        if (!is_singular('post')) {
            return;
        }

        $level = get_post_meta(get_queried_object_id(), '_pkb_access_level', true) ?: 'public';
        if ($level === 'special' && !current_user_can('pkb_read_special')) {
            wp_die('특별회원 이상만 볼 수 있는 글입니다.', 403);
        }

        if ($level === 'admin' && !current_user_can('manage_options')) {
            wp_die('관리자 전용 글입니다.', 403);
        }
    }

    public function require_login_for_comments(bool $open, int $post_id): bool
    {
        return $open && is_user_logged_in();
    }

    public function render_logged_out_comment_prompt(string $block_content, array $block, ?WP_Block $instance = null): string
    {
        $like = $this->post_like_markup();

        if (is_user_logged_in()) {
            return $like . $block_content;
        }

        $post_id = (int) ($instance->context['postId'] ?? $block['context']['postId'] ?? get_the_ID());
        if ($post_id > 0 && get_post_field('comment_status', $post_id) !== 'open') {
            return $like;
        }

        $login_url = add_query_arg(
            'redirect_to',
            $post_id > 0 ? get_permalink($post_id) : home_url('/'),
            home_url('/login/')
        );

        return $like . sprintf(
            '<div class="pkb-comment-login-prompt"><p>댓글을 작성하려면 <a class="pkb-comment-login-link" href="%s">로그인</a>해 주세요.</p></div>',
            esc_url($login_url)
        );
    }

    public function customize_comment_form_defaults(array $defaults): array
    {
        $notice = '<p class="pkb-comment-form-note">비방, 욕설, 악성 댓글을 삼가고,<br>상대방의 의견을 존중하는 건전한 인터넷 문화를 만들어주세요.</p>';

        $defaults['logged_in_as'] = $notice;
        $defaults['comment_notes_before'] = $notice;
        $defaults['comment_notes_after'] = '';

        return $defaults;
    }

    public function guard_comment_submission(int $post_id): void
    {
        if (!is_user_logged_in()) {
            wp_die('로그인한 회원만 댓글을 작성할 수 있습니다.', 403);
        }

        $parent_id = absint($_POST['comment_parent'] ?? 0);
        if ($parent_id > 0) {
            $parent = get_comment($parent_id);
            if ($parent instanceof WP_Comment && (int) $parent->comment_parent > 0) {
                wp_die('답글에는 다시 답글을 달 수 없습니다.', 403);
            }
        }

        if ($this->is_user_sanctioned(get_current_user_id(), 'comment')) {
            wp_die('댓글 작성이 제한된 계정입니다.', 403);
        }
    }

    public function approve_logged_in_member_comment($approved, array $commentdata)
    {
        $user_id = (int) ($commentdata['user_ID'] ?? $commentdata['user_id'] ?? 0);
        if ($user_id > 0 && get_userdata($user_id)) {
            return 1;
        }

        return $approved;
    }

    public function handle_comment_forms(): void
    {
        if (!isset($_POST['pkb_comment_action'], $_POST['comment_id'])) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['pkb_comment_action']));
        $comment_id = absint($_POST['comment_id']);
        $comment = get_comment($comment_id);
        if (!$comment instanceof WP_Comment) {
            wp_die('댓글을 찾을 수 없습니다.', 404);
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'pkb_comment_' . $comment_id)) {
            wp_die('Invalid request.');
        }

        if (!$this->can_manage_own_comment($comment)) {
            wp_die('댓글을 수정하거나 삭제할 권한이 없습니다.', 403);
        }

        if ($this->is_user_sanctioned(get_current_user_id(), 'comment')) {
            wp_die('댓글 관리가 제한된 계정입니다.', 403);
        }

        if ($action === 'edit') {
            $content = trim(sanitize_textarea_field(wp_unslash($_POST['comment_content'] ?? '')));
            if ($content === '') {
                $this->redirect_to_comment($comment, '댓글 내용을 입력해 주세요.', 'error');
            }

            $result = wp_update_comment([
                'comment_ID' => $comment_id,
                'comment_content' => $content,
                'comment_approved' => '1',
            ], true);

            if (is_wp_error($result)) {
                $this->redirect_to_comment($comment, '댓글을 수정하지 못했습니다. 잠시 후 다시 시도해 주세요.', 'error');
            }

            $this->redirect_to_comment(get_comment($comment_id) ?: $comment, '댓글을 수정했습니다.');
        }

        if ($action === 'delete') {
            $post_url = get_permalink((int) $comment->comment_post_ID) ?: home_url('/');
            $this->delete_comment_likes($comment_id);
            wp_delete_comment($comment_id, true);
            wp_safe_redirect(add_query_arg([
                'pkb_message' => rawurlencode('댓글을 삭제했습니다.'),
                'pkb_type' => 'info',
            ], $post_url));
            exit;
        }

        wp_die('Invalid request.');
    }

    public function register_rest_routes(): void
    {
        register_rest_route('pkb/v1', '/likes/post/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => fn (WP_REST_Request $request) => $this->toggle_post_like((int) $request['id']),
            'permission_callback' => fn () => is_user_logged_in(),
        ]);

        register_rest_route('pkb/v1', '/comment-likes/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => fn (WP_REST_Request $request) => $this->toggle_comment_like((int) $request['id']),
            'permission_callback' => fn () => is_user_logged_in(),
        ]);

        register_rest_route('pkb/v1', '/graph', [
            'methods' => 'GET',
            'callback' => fn (WP_REST_Request $request) => $this->rest_graph($request),
            'permission_callback' => fn () => $this->can_view_graph(),
        ]);

        register_rest_route('pkb/v1', '/internal-links/search-posts', [
            'methods' => 'GET',
            'callback' => fn (WP_REST_Request $request) => $this->rest_search_posts($request),
            'permission_callback' => fn () => current_user_can('edit_posts'),
        ]);

        register_rest_route('pkb/v1', '/internal-links/headings/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => fn (WP_REST_Request $request) => $this->rest_post_headings((int) $request['id']),
            'permission_callback' => fn () => current_user_can('edit_posts'),
        ]);

        register_rest_route('pkb/v1', '/openapi', [
            'methods' => 'GET',
            'callback' => fn () => rest_ensure_response($this->openapi_spec()),
            'permission_callback' => fn () => current_user_can('manage_options'),
        ]);

    }

    private function openapi_spec(): array
    {
        $base_url = untrailingslashit(rest_url());

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Mobigist PKB API',
                'version' => PKB_CORE_VERSION,
                'description' => 'Admin-only OpenAPI documentation for Mobigist custom WordPress REST endpoints.',
            ],
            'servers' => [
                ['url' => $base_url],
            ],
            'components' => [
                'securitySchemes' => [
                    'wpNonce' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-WP-Nonce',
                        'description' => 'WordPress REST nonce for authenticated cookie requests.',
                    ],
                ],
                'schemas' => [
                    'LikeResponse' => [
                        'type' => 'object',
                        'required' => ['liked', 'count'],
                        'properties' => [
                            'liked' => ['type' => 'boolean'],
                            'count' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                    'GraphResponse' => [
                        'type' => 'object',
                        'required' => ['nodes', 'edges'],
                        'properties' => [
                            'nodes' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'id' => ['type' => 'integer'],
                                        'title' => ['type' => 'string'],
                                        'url' => ['type' => 'string', 'format' => 'uri'],
                                        'depth' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                            'edges' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'source' => ['type' => 'integer'],
                                        'target' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'InternalLinkPost' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'date' => ['type' => 'string'],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                        ],
                    ],
                    'Heading' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'slug' => ['type' => 'string'],
                            'hierarchy' => ['type' => 'string'],
                            'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/pkb/v1/likes/post/{id}' => [
                    'post' => [
                        'summary' => 'Toggle a post like',
                        'security' => [['wpNonce' => []]],
                        'parameters' => [[
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer'],
                            'description' => 'Post ID.',
                        ]],
                        'responses' => [
                            '200' => [
                                'description' => 'Updated like state and count.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LikeResponse']]],
                            ],
                            '401' => ['description' => 'Authentication required.'],
                        ],
                    ],
                ],
                '/pkb/v1/comment-likes/{id}' => [
                    'post' => [
                        'summary' => 'Toggle a comment like',
                        'security' => [['wpNonce' => []]],
                        'parameters' => [[
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer'],
                            'description' => 'Comment ID.',
                        ]],
                        'responses' => [
                            '200' => [
                                'description' => 'Updated like state and count.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LikeResponse']]],
                            ],
                            '401' => ['description' => 'Authentication required.'],
                        ],
                    ],
                ],
                '/pkb/v1/graph' => [
                    'get' => [
                        'summary' => 'Read graph data',
                        'parameters' => [
                            [
                                'name' => 'root',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'integer'],
                                'description' => 'Root post ID for partial graph.',
                            ],
                            [
                                'name' => 'depth',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                                'description' => 'Partial graph traversal depth.',
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Graph nodes and edges visible to the current user.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/GraphResponse']]],
                            ],
                            '403' => ['description' => 'Graph is not visible to the current user.'],
                        ],
                    ],
                ],
                '/pkb/v1/internal-links/search-posts' => [
                    'get' => [
                        'summary' => 'Search posts for editor internal-link UI',
                        'security' => [['wpNonce' => []]],
                        'parameters' => [[
                            'name' => 'q',
                            'in' => 'query',
                            'required' => false,
                            'schema' => ['type' => 'string'],
                            'description' => 'Search term.',
                        ]],
                        'responses' => [
                            '200' => [
                                'description' => 'Matching posts.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'array',
                                            'items' => ['$ref' => '#/components/schemas/InternalLinkPost'],
                                        ],
                                    ],
                                ],
                            ],
                            '403' => ['description' => 'Editor permission required.'],
                        ],
                    ],
                ],
                '/pkb/v1/internal-links/headings/{id}' => [
                    'get' => [
                        'summary' => 'Read headings from a post',
                        'security' => [['wpNonce' => []]],
                        'parameters' => [[
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'integer'],
                            'description' => 'Post ID.',
                        ]],
                        'responses' => [
                            '200' => [
                                'description' => 'Heading list.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'array',
                                            'items' => ['$ref' => '#/components/schemas/Heading'],
                                        ],
                                    ],
                                ],
                            ],
                            '403' => ['description' => 'Editor permission required.'],
                        ],
                    ],
                ],
                '/pkb/v1/openapi' => [
                    'get' => [
                        'summary' => 'Read this OpenAPI document',
                        'security' => [['wpNonce' => []]],
                        'responses' => [
                            '200' => ['description' => 'OpenAPI document.'],
                            '403' => ['description' => 'Administrator permission required.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function toggle_post_like(int $post_id): WP_REST_Response
    {
        global $wpdb;
        $user_id = get_current_user_id();
        $table = self::table('post_likes');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND post_id = %d", $user_id, $post_id));

        if ($exists) {
            $wpdb->delete($table, ['user_id' => $user_id, 'post_id' => $post_id], ['%d', '%d']);
            $liked = false;
        } else {
            $wpdb->insert($table, ['user_id' => $user_id, 'post_id' => $post_id, 'created_at' => current_time('mysql')], ['%d', '%d', '%s']);
            $liked = true;
        }

        return rest_ensure_response(['liked' => $liked, 'count' => $this->post_like_count($post_id)]);
    }

    private function toggle_comment_like(int $comment_id): WP_REST_Response
    {
        global $wpdb;
        $user_id = get_current_user_id();
        $table = self::table('comment_likes');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND comment_id = %d", $user_id, $comment_id));

        if ($exists) {
            $wpdb->delete($table, ['user_id' => $user_id, 'comment_id' => $comment_id], ['%d', '%d']);
            $liked = false;
        } else {
            $wpdb->insert($table, ['user_id' => $user_id, 'comment_id' => $comment_id, 'created_at' => current_time('mysql')], ['%d', '%d', '%s']);
            $liked = true;
        }

        return rest_ensure_response(['liked' => $liked, 'count' => $this->comment_like_count($comment_id)]);
    }

    private function post_like_count(int $post_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('post_likes') . " WHERE post_id = %d", $post_id));
    }

    private function comment_like_count(int $comment_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('comment_likes') . " WHERE comment_id = %d", $comment_id));
    }

    public function append_post_date_meta(string $block_content, array $block): string
    {
        $post_id = (int) ($block['context']['postId'] ?? get_the_ID());
        if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
            return $block_content;
        }

        $likes = $this->post_like_count($post_id);
        $comments = (int) get_comments_number($post_id);
        $views = $this->post_view_count($post_id);
        $thumbnail = $this->post_meta_thumbnail($post_id);
        $terms = is_singular('post') ? '' : $this->post_preview_terms($post_id);
        $meta = sprintf(
            '<span class="pkb-post-date-meta"><span aria-hidden="true">·</span> <span class="pkb-meta-counts">%s%s%s</span>%s</span>',
            $this->metric_count_markup($views, 'view', 'View', 'Views'),
            $this->metric_count_markup($likes, 'like', 'Like', 'Likes'),
            $this->metric_count_markup($comments, 'comment', 'Comment', 'Comments'),
            $thumbnail
        );

        $content = $block_content . $meta;
        if (str_ends_with(trim($block_content), '</div>')) {
            $content = preg_replace('/<\/div>\s*$/', $meta . '</div>', $block_content, 1) ?: $content;
        }

        return $content . $terms;
    }

    private function post_preview_terms(int $post_id): string
    {
        $tags = get_the_tags($post_id);
        if (!$tags) {
            return '';
        }

        $items = [];
        foreach ($tags as $term) {
            $items[] = '<a href="' . esc_url(get_term_link($term)) . '">#' . esc_html($term->name) . '</a>';
        }

        return '<p class="pkb-preview-terms">' . implode(' ', $items) . '</p>';
    }

    private function post_meta_thumbnail(int $post_id): string
    {
        if (!has_post_thumbnail($post_id)) {
            return '';
        }

        $thumb = get_the_post_thumbnail_url($post_id, 'thumbnail');
        $preview = get_the_post_thumbnail_url($post_id, 'medium');
        $alt = get_post_meta(get_post_thumbnail_id($post_id), '_wp_attachment_image_alt', true);

        if (!$thumb || !$preview) {
            return '';
        }

        return sprintf(
            '<span class="pkb-post-date-thumb-wrap"><span aria-hidden="true">·</span> <span class="pkb-post-date-thumb" tabindex="0"><img src="%s" alt="%s" loading="lazy" decoding="async" /><span class="pkb-post-date-thumb-preview"><img src="%s" alt="" loading="lazy" decoding="async" /></span></span></span>',
            esc_url($thumb),
            esc_attr($alt ?: get_the_title($post_id)),
            esc_url($preview)
        );
    }

    private function count_label(int $count, string $singular, string $plural): string
    {
        return sprintf('%d %s', $count, $count === 1 ? $singular : $plural);
    }

    private function post_view_count(int $post_id): int
    {
        if (function_exists('pvc_get_post_views')) {
            return max(0, (int) pvc_get_post_views($post_id));
        }

        return 0;
    }

    private function metric_count_markup(int $count, string $type, string $singular, string $plural): string
    {
        $icons = [
            'view' => '<svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M3 12s3.2-5.5 9-5.5S21 12 21 12s-3.2 5.5-9 5.5S3 12 3 12Z"></path><circle cx="12" cy="12" r="2.4"></circle></svg>',
            'like' => '<svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M20.4 5.1c-1.5-1.6-3.9-1.6-5.4 0L12 8.2 9 5.1c-1.5-1.6-3.9-1.6-5.4 0-1.5 1.6-1.5 4.1 0 5.7L12 19l8.4-8.2c1.5-1.6 1.5-4.1 0-5.7Z"></path></svg>',
            'comment' => '<svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M5 5.5h14v9H9.5L5 18.5v-13Z"></path></svg>',
        ];
        $icon = $icons[$type] ?? '';
        $label = $this->count_label($count, $singular, $plural);

        return sprintf(
            '<span class="pkb-meta-count pkb-meta-count-%s" aria-label="%s">%s<span class="pkb-meta-count-number">%d</span></span>',
            esc_attr($type),
            esc_attr($label),
            $icon,
            $count
        );
    }

    private function user_liked_post(int $post_id): bool
    {
        global $wpdb;
        return is_user_logged_in() && (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table('post_likes') . " WHERE user_id = %d AND post_id = %d",
            get_current_user_id(),
            $post_id
        ));
    }

    private function user_liked_comment(int $comment_id): bool
    {
        global $wpdb;
        return is_user_logged_in() && (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table('comment_likes') . " WHERE user_id = %d AND comment_id = %d",
            get_current_user_id(),
            $comment_id
        ));
    }

    public function append_post_like(string $content): string
    {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        return $content . $this->post_like_markup();
    }

    private function post_like_markup(?int $post_id = null): string
    {
        if (!is_singular('post')) {
            return '';
        }

        $post_id = $post_id ?: get_the_ID();
        if (!$post_id) {
            return '';
        }

        return sprintf(
            '<div class="pkb-like-wrap"><button class="pkb-like-button %s" data-pkb-like="post" data-id="%d" %s>♡ <span>%d</span></button></div>',
            $this->user_liked_post($post_id) ? 'is-liked' : '',
            $post_id,
            is_user_logged_in() ? '' : 'data-login-required="1"',
            $this->post_like_count($post_id)
        );
    }

    public function append_comment_like(string $text, $comment = null, array $args = []): string
    {
        if (!$comment instanceof WP_Comment) {
            return $text;
        }

        $comment_id = (int) $comment->comment_ID;
        $button = sprintf(
            '<div class="pkb-comment-like"><button class="pkb-like-button %s" data-pkb-like="comment" data-id="%d" %s>♡ <span>%d</span></button></div>',
            $this->user_liked_comment($comment_id) ? 'is-liked' : '',
            $comment_id,
            is_user_logged_in() ? '' : 'data-login-required="1"',
            $this->comment_like_count($comment_id)
        );

        return $text . $button;
    }

    public function append_comment_date_actions(string $block_content, array $block, ?WP_Block $instance = null): string
    {
        $comment_id = (int) ($instance->context['commentId'] ?? $block['context']['commentId'] ?? 0);
        if ($comment_id <= 0) {
            return $block_content;
        }

        $comment = get_comment($comment_id);
        if (!$comment instanceof WP_Comment || !$this->can_manage_own_comment($comment)) {
            return $block_content;
        }

        $actions = $this->own_comment_controls($comment);
        if ($actions === '') {
            return $block_content;
        }

        if (str_ends_with(trim($block_content), '</div>')) {
            return preg_replace('/<\/div>\s*$/', $actions . '</div>', $block_content, 1) ?: $block_content . $actions;
        }

        return $block_content . $actions;
    }

    private function can_manage_own_comment(WP_Comment $comment): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $user_id = get_current_user_id();
        if ((int) $comment->user_id !== $user_id) {
            return false;
        }

        if ((string) $comment->comment_approved === 'trash' || (string) $comment->comment_approved === 'spam') {
            return false;
        }

        return $this->can_read_post((int) $comment->comment_post_ID);
    }

    private function own_comment_controls(WP_Comment $comment): string
    {
        if (!$this->can_manage_own_comment($comment)) {
            return '';
        }

        $comment_id = (int) $comment->comment_ID;
        ob_start();
        ?>
        <div class="pkb-comment-controls">
            <details class="pkb-comment-edit">
                <summary>수정</summary>
                <form class="pkb-comment-edit-form" method="post">
                    <?php wp_nonce_field('pkb_comment_' . $comment_id); ?>
                    <input type="hidden" name="pkb_comment_action" value="edit">
                    <input type="hidden" name="comment_id" value="<?php echo esc_attr((string) $comment_id); ?>">
                    <textarea name="comment_content" rows="4" required><?php echo esc_textarea($comment->comment_content); ?></textarea>
                    <button type="submit">저장</button>
                </form>
            </details>
            <span aria-hidden="true">·</span>
            <form class="pkb-comment-delete-form" method="post" onsubmit="return confirm('댓글을 완전히 삭제합니다. 계속할까요?');">
                <?php wp_nonce_field('pkb_comment_' . $comment_id); ?>
                <input type="hidden" name="pkb_comment_action" value="delete">
                <input type="hidden" name="comment_id" value="<?php echo esc_attr((string) $comment_id); ?>">
                <button type="submit">삭제</button>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function redirect_to_comment(WP_Comment $comment, string $message, string $type = 'info'): void
    {
        $url = get_comment_link($comment);
        wp_safe_redirect(add_query_arg([
            'pkb_message' => rawurlencode($message),
            'pkb_type' => $type,
        ], $url));
        exit;
    }

    private function delete_comment_likes(int $comment_id): void
    {
        global $wpdb;
        $wpdb->delete(self::table('comment_likes'), ['comment_id' => $comment_id], ['%d']);
    }

    public function index_internal_links(int $post_id, WP_Post $post): void
    {
        if ($post->post_type !== 'post' || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $this->sync_hashtags($post_id, $post->post_content);

        global $wpdb;
        $table = self::table('internal_links');
        $wpdb->delete($table, ['source_post_id' => $post_id], ['%d']);

        $links = $this->extract_internal_anchor_links($post->post_content);
        if (empty($links)) {
            return;
        }

        foreach ($links as $link) {
            $wpdb->insert($table, [
                'source_post_id' => $post_id,
                'target_post_id' => $link['target_post_id'],
                'target_heading_slug' => $link['heading_slug'],
                'original_link_text' => $link['text'],
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['%d', '%d', '%s', '%s', '%s', '%s']);
        }
    }

    private function extract_internal_anchor_links(string $content): array
    {
        if (stripos($content, '<a') === false) {
            return [];
        }

        $links = [];
        $seen = [];
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $parts = wp_parse_url($href);
            if (!$parts) {
                continue;
            }

            $url_without_fragment = $href;
            if (isset($parts['fragment'])) {
                $url_without_fragment = substr($href, 0, -(strlen($parts['fragment']) + 1));
            }

            $target_id = url_to_postid($url_without_fragment);
            if (!$target_id) {
                continue;
            }

            $key = $target_id . '|' . ($parts['fragment'] ?? '') . '|' . $href;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $links[] = [
                'target_post_id' => $target_id,
                'heading_slug' => isset($parts['fragment']) ? sanitize_title(rawurldecode($parts['fragment'])) : null,
                'text' => trim($anchor->textContent) ?: $href,
            ];
        }

        return $links;
    }

    private function sync_hashtags(int $post_id, string $content): void
    {
        $clean = preg_replace('/```.*?```/s', '', $content) ?: $content;
        $clean = preg_replace('/<pre\b[^>]*>.*?<\/pre>/is', '', $clean) ?: $clean;
        $clean = preg_replace('/<code\b[^>]*>.*?<\/code>/is', '', $clean) ?: $clean;
        $clean = preg_replace('/<[^>]+>/', ' ', $clean) ?: $clean;
        $clean = wp_strip_all_tags($clean, true);
        $clean = preg_replace('/https?:\/\/\S+/i', '', $clean) ?: $clean;
        preg_match_all('/(?<![\pL\pN_])#([\pL\pN_-]{2,40})/u', $clean, $matches);

        if (empty($matches[1])) {
            return;
        }

        $tags = array_values(array_unique(array_map('sanitize_text_field', $matches[1])));
        wp_set_post_tags($post_id, $tags, true);
    }

    public function render_internal_links(string $content): string
    {
        if (is_admin()) {
            return $content;
        }

        return preg_replace_callback('/\[\[([^\]]+)\]\]/u', function (array $match): string {
            [$title, $heading] = array_pad(explode('#', $match[1], 2), 2, '');
            $target = $this->find_post_by_title(trim($title));
            if (!$target) {
                return '<span class="pkb-missing-link">' . esc_html($match[0]) . '</span>';
            }

            $url = get_permalink($target);
            if ($heading) {
                $url .= '#' . sanitize_title($heading);
            }

            return '<a class="pkb-internal-link" href="' . esc_url($url) . '">' . esc_html($match[1]) . '</a>';
        }, $content) ?: $content;
    }

    public function add_heading_anchors(string $content): string
    {
        if (is_admin() || !is_singular()) {
            return $content;
        }

        $used = [];
        return preg_replace_callback('/<h([1-6])([^>]*)>(.*?)<\/h\1>/is', function (array $match) use (&$used): string {
            $attrs = $match[2];
            if (preg_match('/\sid=(["\']).*?\1/i', $attrs)) {
                return $match[0];
            }

            $text = trim(wp_strip_all_tags($match[3]));
            if ($text === '') {
                return $match[0];
            }

            $base = sanitize_title($text);
            $slug = $base;
            $index = 2;
            while (isset($used[$slug])) {
                $slug = $base . '-' . $index;
                $index++;
            }
            $used[$slug] = true;

            return '<h' . $match[1] . $attrs . ' id="' . esc_attr($slug) . '">' . $match[3] . '</h' . $match[1] . '>';
        }, $content) ?: $content;
    }

    private function find_post_by_title(string $title): ?WP_Post
    {
        if ($title === '') {
            return null;
        }

        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => ['publish', 'private'],
            'title' => $title,
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ]);

        return $query->posts[0] ?? null;
    }

    private function extract_headings(string $content): array
    {
        $headings = [];
        preg_match_all('/<!-- wp:heading(?:\s+\{.*?\})?\s*-->\s*<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $block_matches, PREG_SET_ORDER);
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $html_matches, PREG_SET_ORDER);

        foreach (array_merge($block_matches, $html_matches) as $match) {
            $title = trim(wp_strip_all_tags($match[2]));
            if ($title === '') {
                continue;
            }

            $level = (int) $match[1];
            $headings[] = [
                'title' => $title,
                'slug' => sanitize_title($title),
                'hierarchy' => 'H' . $level,
                'level' => $level,
            ];
        }

        return array_values(array_unique($headings, SORT_REGULAR));
    }

    public function rest_search_posts(WP_REST_Request $request): WP_REST_Response
    {
        $term = sanitize_text_field((string) $request->get_param('q'));
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            's' => $term,
            'numberposts' => 10,
        ]);

        $items = array_map(fn (WP_Post $post) => [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'date' => get_the_date('y-m-d', $post),
            'url' => get_permalink($post),
        ], $posts);

        return rest_ensure_response($items);
    }

    public function rest_post_headings(int $post_id): WP_REST_Response
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return rest_ensure_response([]);
        }

        return rest_ensure_response($this->extract_headings($post->post_content));
    }

    public function rest_graph(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $root = absint($request->get_param('root'));
        $depth = max(1, min(5, absint($request->get_param('depth') ?: get_option('pkb_partial_graph_depth', 2))));
        $links = $wpdb->get_results("SELECT source_post_id, target_post_id FROM " . self::table('internal_links') . " WHERE target_post_id IS NOT NULL");

        $allowed = [];
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($posts as $post_id) {
            if ($this->can_read_post((int) $post_id)) {
                $allowed[(int) $post_id] = true;
            }
        }

        $edges = [];
        foreach ($links as $link) {
            $source = (int) $link->source_post_id;
            $target = (int) $link->target_post_id;
            if (!isset($allowed[$source], $allowed[$target])) {
                continue;
            }
            $edges[] = ['source' => $source, 'target' => $target];
        }

        $included = $allowed;
        if ($root) {
            $included = $this->graph_neighborhood($root, $edges, $depth);
        }

        $nodes = [];
        foreach (array_keys($included) as $post_id) {
            if (!isset($allowed[$post_id])) {
                continue;
            }
            $tags = get_the_terms($post_id, 'post_tag');
            $tag_names = [];
            if (!is_wp_error($tags) && is_array($tags)) {
                foreach (array_slice($tags, 0, 2) as $tag) {
                    $tag_names[] = $tag->name;
                }
            }
            $nodes[] = [
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'url' => get_permalink($post_id),
                'depth' => $root ? $this->node_distance($root, $post_id, $edges, $depth) : 0,
                'tags' => $tag_names,
                'hasMoreTags' => is_array($tags) && count($tags) > 2,
            ];
        }

        $filtered_edges = array_values(array_filter($edges, fn ($edge) => isset($included[$edge['source']], $included[$edge['target']])));

        return rest_ensure_response(['nodes' => $nodes, 'edges' => $filtered_edges]);
    }

    private function graph_neighborhood(int $root, array $edges, int $max_depth): array
    {
        $seen = [$root => true];
        $frontier = [$root];

        for ($depth = 0; $depth < $max_depth; $depth++) {
            $next = [];
            foreach ($edges as $edge) {
                if (in_array($edge['source'], $frontier, true) && !isset($seen[$edge['target']])) {
                    $seen[$edge['target']] = true;
                    $next[] = $edge['target'];
                }
                if (in_array($edge['target'], $frontier, true) && !isset($seen[$edge['source']])) {
                    $seen[$edge['source']] = true;
                    $next[] = $edge['source'];
                }
            }
            $frontier = $next;
        }

        return $seen;
    }

    private function node_distance(int $root, int $target, array $edges, int $max_depth): int
    {
        if ($root === $target) {
            return 0;
        }

        $seen = [$root => true];
        $frontier = [$root];
        for ($depth = 1; $depth <= $max_depth; $depth++) {
            $next = [];
            foreach ($edges as $edge) {
                foreach ([[$edge['source'], $edge['target']], [$edge['target'], $edge['source']]] as [$from, $to]) {
                    if (in_array($from, $frontier, true) && !isset($seen[$to])) {
                        if ($to === $target) {
                            return $depth;
                        }
                        $seen[$to] = true;
                        $next[] = $to;
                    }
                }
            }
            $frontier = $next;
        }

        return $max_depth;
    }

    private function can_read_post(int $post_id): bool
    {
        $level = get_post_meta($post_id, '_pkb_access_level', true) ?: 'public';
        if ($level === 'admin') {
            return current_user_can('manage_options');
        }
        if ($level === 'special') {
            return current_user_can('pkb_read_special');
        }
        return true;
    }

    private function can_view_graph(): bool
    {
        return (bool) get_option('pkb_show_graph_to_guests', 1) || is_user_logged_in();
    }

    public function append_partial_graph(string $content): string
    {
        if (!is_singular('post') || !in_the_loop() || !is_main_query() || !$this->can_view_graph()) {
            return $content;
        }

        return $content . '<section class="pkb-partial-graph"><h2>Graph View</h2><div class="pkb-graph" data-pkb-graph="partial" data-root="' . esc_attr((string) get_the_ID()) . '"></div></section>';
    }

    public function apply_search_sort(WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
            return;
        }

        $sort = sanitize_key($_GET['sort'] ?? 'newest');
        if ($sort === 'oldest') {
            $query->set('orderby', 'date');
            $query->set('order', 'ASC');
        } elseif ($sort === 'title') {
            $query->set('orderby', 'title');
            $query->set('order', 'ASC');
        } elseif ($sort === 'comments') {
            $query->set('orderby', 'comment_count');
            $query->set('order', 'DESC');
        } elseif ($sort === 'views') {
            $query->set('pkb_sort_views', 1);
        } elseif ($sort === 'likes') {
            $query->set('pkb_sort_likes', 1);
        } else {
            $query->set('orderby', 'date');
            $query->set('order', 'DESC');
        }
    }

    public function search_like_sort_clauses(array $clauses, WP_Query $query): array
    {
        if (is_admin() || (!$query->get('pkb_sort_likes') && !$query->get('pkb_sort_views'))) {
            return $clauses;
        }

        global $wpdb;

        if ($query->get('pkb_sort_views')) {
            $views = $wpdb->prefix . 'post_views';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $views)) !== $views) {
                return $clauses;
            }

            $clauses['join'] .= " LEFT JOIN (SELECT id AS post_id, SUM(count) AS pkb_view_count FROM $views WHERE type = 4 AND period = 'total' GROUP BY id) pkb_views ON {$wpdb->posts}.ID = pkb_views.post_id";
            $clauses['orderby'] = 'COALESCE(pkb_views.pkb_view_count, 0) DESC, ' . $wpdb->posts . '.post_date DESC';
            return $clauses;
        }

        $likes = self::table('post_likes');
        $clauses['join'] .= " LEFT JOIN (SELECT post_id, COUNT(*) AS pkb_like_count FROM $likes GROUP BY post_id) pkb_likes ON {$wpdb->posts}.ID = pkb_likes.post_id";
        $clauses['orderby'] = 'COALESCE(pkb_likes.pkb_like_count, 0) DESC, ' . $wpdb->posts . '.post_date DESC';

        return $clauses;
    }

    public function configure_smtp(PHPMailer\PHPMailer\PHPMailer $phpmailer): void
    {
        $host = getenv('AWS_SES_SMTP_HOST');
        if (!$host) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $host;
        $phpmailer->Port = (int) (getenv('AWS_SES_SMTP_PORT') ?: 587);
        $username = (string) getenv('AWS_SES_SMTP_USER');
        $phpmailer->SMTPAuth = $username !== '';
        $phpmailer->Username = $username;
        $phpmailer->Password = (string) getenv('AWS_SES_SMTP_PASSWORD');
        $phpmailer->SMTPSecure = $phpmailer->Port === 465 ? 'ssl' : ($phpmailer->Port === 1025 ? '' : 'tls');

        $from_email = getenv('AWS_SES_FROM_EMAIL');
        if ($from_email) {
            $phpmailer->setFrom($from_email, getenv('AWS_SES_FROM_NAME') ?: get_bloginfo('name'));
        }
    }

    public function mail_from(string $from_email): string
    {
        $configured = sanitize_email((string) getenv('AWS_SES_FROM_EMAIL'));
        return $configured ?: $from_email;
    }

    public function mail_from_name(string $from_name): string
    {
        $configured = trim((string) getenv('AWS_SES_FROM_NAME'));
        return $configured !== '' ? $configured : $from_name;
    }

    public function add_admin_pages(): void
    {
        add_options_page('PKB Graph', 'PKB Graph', 'pkb_manage_graph', 'pkb-graph', [$this, 'render_graph_settings']);
        add_options_page('PKB API Docs', 'PKB API Docs', 'manage_options', 'pkb-api-docs', [$this, 'render_api_docs_page']);
        add_users_page('PKB 회원 제재', 'PKB 회원 제재', 'pkb_moderate_members', 'pkb-moderation', [$this, 'render_moderation_page']);
    }

    public function render_api_docs_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.', 403);
        }

        $spec_url = esc_url_raw(rest_url('pkb/v1/openapi'));
        $nonce = wp_create_nonce('wp_rest');
        ?>
        <div class="wrap pkb-admin-api-docs">
            <h1>PKB API Docs</h1>
            <p>Mobigist custom REST API documentation. This page is available to administrators only.</p>
            <div id="pkb-swagger-ui"></div>
        </div>
        <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
        <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
        <script>
        window.addEventListener('load', function () {
            if (!window.SwaggerUIBundle) {
                return;
            }

            window.SwaggerUIBundle({
                url: <?php echo wp_json_encode($spec_url); ?>,
                dom_id: '#pkb-swagger-ui',
                deepLinking: true,
                requestInterceptor: function (request) {
                    request.headers = request.headers || {};
                    request.headers['X-WP-Nonce'] = <?php echo wp_json_encode($nonce); ?>;
                    return request;
                }
            });
        });
        </script>
        <?php
    }

    public function register_settings(): void
    {
        register_setting('pkb_graph', 'pkb_partial_graph_depth', ['type' => 'integer', 'sanitize_callback' => 'absint']);
        register_setting('pkb_graph', 'pkb_graph_node_opacity', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('pkb_graph', 'pkb_graph_edge_opacity', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('pkb_graph', 'pkb_show_graph_to_guests', ['type' => 'integer', 'sanitize_callback' => 'absint']);
    }

    public function render_graph_settings(): void
    {
        ?>
        <div class="wrap">
            <h1>PKB Graph</h1>
            <form method="post" action="options.php">
                <?php settings_fields('pkb_graph'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="pkb_partial_graph_depth">부분 그래프 단계</label></th>
                        <td><input id="pkb_partial_graph_depth" name="pkb_partial_graph_depth" type="number" min="1" max="5" value="<?php echo esc_attr((string) get_option('pkb_partial_graph_depth', 2)); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pkb_graph_node_opacity">단계별 노드 opacity</label></th>
                        <td><input id="pkb_graph_node_opacity" name="pkb_graph_node_opacity" value="<?php echo esc_attr((string) get_option('pkb_graph_node_opacity', '1,0.65,0.35')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pkb_graph_edge_opacity">단계별 엣지 opacity</label></th>
                        <td><input id="pkb_graph_edge_opacity" name="pkb_graph_edge_opacity" value="<?php echo esc_attr((string) get_option('pkb_graph_edge_opacity', '0.8,0.45,0.25')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">비회원 그래프 노출</th>
                        <td><label><input name="pkb_show_graph_to_guests" type="checkbox" value="1" <?php checked((int) get_option('pkb_show_graph_to_guests', 1), 1); ?>> 허용</label></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function handle_moderation_form(): void
    {
        if (!isset($_POST['pkb_moderation_action'])) {
            return;
        }

        if (!current_user_can('pkb_moderate_members')) {
            wp_die('권한이 없습니다.', 403);
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'pkb_moderation')) {
            wp_die('Invalid request.');
        }

        global $wpdb;
        $user_id = absint($_POST['user_id'] ?? 0);
        $type = sanitize_key($_POST['sanction_type'] ?? 'comment');
        $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
        $days = absint($_POST['days'] ?? 0);

        if (!$user_id || !in_array($type, ['comment', 'login', 'disable'], true) || $reason === '') {
            wp_safe_redirect(add_query_arg('pkb_message', rawurlencode('입력값을 확인해 주세요.'), wp_get_referer() ?: admin_url('users.php?page=pkb-moderation')));
            exit;
        }

        $wpdb->insert(self::table('moderation'), [
            'user_id' => $user_id,
            'moderator_id' => get_current_user_id(),
            'sanction_type' => $type,
            'reason' => $reason,
            'starts_at' => current_time('mysql'),
            'ends_at' => $days > 0 ? gmdate('Y-m-d H:i:s', time() + ($days * DAY_IN_SECONDS)) : null,
            'created_at' => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s']);

        wp_safe_redirect(add_query_arg('pkb_message', rawurlencode('회원 제재를 등록했습니다.'), admin_url('users.php?page=pkb-moderation')));
        exit;
    }

    private function is_user_sanctioned(int $user_id, string $action): bool
    {
        global $wpdb;
        $types = $action === 'login' ? ['login', 'disable'] : ['comment', 'disable'];
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $now = current_time('mysql');

        $sql = $wpdb->prepare(
            "SELECT id FROM " . self::table('moderation') . " WHERE user_id = %d AND sanction_type IN ($placeholders) AND (ends_at IS NULL OR ends_at > %s) LIMIT 1",
            array_merge([$user_id], $types, [$now])
        );

        return (bool) $wpdb->get_var($sql);
    }

    public function render_moderation_page(): void
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::table('moderation') . " ORDER BY created_at DESC LIMIT 50");
        ?>
        <div class="wrap">
            <h1>PKB 회원 제재</h1>
            <?php if (isset($_GET['pkb_message'])) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(wp_unslash($_GET['pkb_message'])); ?></p></div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('pkb_moderation'); ?>
                <input type="hidden" name="pkb_moderation_action" value="create">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="user_id">회원 ID</label></th>
                        <td><input id="user_id" name="user_id" type="number" min="1" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sanction_type">제재 유형</label></th>
                        <td>
                            <select id="sanction_type" name="sanction_type">
                                <option value="comment">댓글 작성 제한</option>
                                <option value="login">로그인 제한</option>
                                <option value="disable">계정 비활성화</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="days">기간</label></th>
                        <td><input id="days" name="days" type="number" min="0" value="0"> 일, 0은 영구</td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="reason">사유</label></th>
                        <td><textarea id="reason" name="reason" rows="4" class="large-text" required></textarea></td>
                    </tr>
                </table>
                <?php submit_button('제재 등록'); ?>
            </form>
            <h2>최근 제재 이력</h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>회원</th><th>유형</th><th>사유</th><th>종료</th><th>처리자</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $row->id); ?></td>
                        <td><?php echo esc_html((string) $row->user_id); ?></td>
                        <td><?php echo esc_html($row->sanction_type); ?></td>
                        <td><?php echo esc_html($row->reason); ?></td>
                        <td><?php echo esc_html($row->ends_at ?: '영구'); ?></td>
                        <td><?php echo esc_html((string) $row->moderator_id); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

function pkb_render_primary_nav(): void
{
    PKB_Core::ensure_default_header_category_meta();

    $terms = get_terms([
        'taxonomy' => 'category',
        'hide_empty' => false,
        'parent' => 0,
        'meta_query' => [
            [
                'key' => PKB_Core::HEADER_CATEGORY_SHOW_META,
                'value' => '1',
            ],
        ],
    ]);

    if (is_wp_error($terms) || !$terms) {
        return;
    }

    usort($terms, function (WP_Term $a, WP_Term $b): int {
        $a_order = absint(get_term_meta($a->term_id, PKB_Core::HEADER_CATEGORY_ORDER_META, true));
        $b_order = absint(get_term_meta($b->term_id, PKB_Core::HEADER_CATEGORY_ORDER_META, true));

        if ($a_order !== $b_order) {
            return $a_order <=> $b_order;
        }

        return strcasecmp($a->name, $b->name);
    });

    echo '<nav class="pkb-primary-nav" aria-label="Primary categories">';
    foreach ($terms as $term) {
        printf(
            '<a href="%s">%s <span class="pkb-meta">(%d)</span></a>',
            esc_url(get_term_link($term)),
            esc_html($term->name),
            PKB_Core::category_tree_post_count((int) $term->term_id)
        );
    }
    printf(
        '<span class="pkb-nav-separator" aria-hidden="true">|</span><span class="pkb-nav-tools"><a class="pkb-nav-icon-link pkb-nav-home-link" href="%s" aria-label="Home" title="Home"><svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M3 10.5 12 3l9 7.5"></path><path d="M5.5 9.5V21h13V9.5"></path><path d="M9.5 21v-6h5v6"></path></svg></a><span class="pkb-nav-separator" aria-hidden="true">·</span><a class="pkb-nav-icon-link pkb-nav-search-link" href="%s" aria-label="Search" title="Search"><svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 5 5"></path></svg></a></span>',
        esc_url(home_url('/')),
        esc_url(home_url('/search/'))
    );
    echo '</nav>';
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('pkb setup', function (): void {
        PKB_Core::activate();
        WP_CLI::success('PKB setup complete.');
    });
}

register_activation_hook(__FILE__, ['PKB_Core', 'activate']);
register_deactivation_hook(__FILE__, ['PKB_Core', 'deactivate']);
PKB_Core::instance();
