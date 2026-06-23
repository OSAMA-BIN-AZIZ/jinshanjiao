<?php
/**
 * Plugin Name: LinkAI 智能 AI 客服
 * Description: 为网站添加一个可配置的 LinkAI 智能客服悬浮聊天窗口，支持短代码与 WordPress AJAX 服务端代理。
 * Version: 1.3.20
 * Author: Jinshanjiao
 * License: GPL-2.0-or-later
 * Text Domain: linkai-ai-customer-service
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LinkAI_AI_Customer_Service
{
    private const OPTION_NAME = 'linkai_ai_customer_service_options';
    private const NONCE_ACTION = 'linkai_ai_customer_service_chat';
    private const API_ENDPOINT = 'https://api.link-ai.tech/v1/chat/completions';
    private const VERSION = '1.3.20';
    private const PLUGIN_FILE = __FILE__;
    private const PLUGIN_DIRECTORY_NAME = 'jinshanjiao-main';
    private static $auto_widget_rendered = false;

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_notices', [__CLASS__, 'render_missing_key_notice']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [__CLASS__, 'handle_update_cache_clear']);
        add_action('admin_init', [__CLASS__, 'handle_permission_fix']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('wp_body_open', [__CLASS__, 'render_chat_widget'], 99);
        add_action('wp_footer', [__CLASS__, 'render_chat_widget'], 99);
        add_shortcode('linkai_customer_service', [__CLASS__, 'render_shortcode']);
        add_action('wp_ajax_linkai_customer_chat', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_linkai_customer_chat', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_linkai_customer_updates', [__CLASS__, 'handle_updates_request']);
        add_action('wp_ajax_nopriv_linkai_customer_updates', [__CLASS__, 'handle_updates_request']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'add_settings_link']);
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_plugin_update']);
        add_filter('site_transient_update_plugins', [__CLASS__, 'check_for_plugin_update']);
        add_filter('plugins_api', [__CLASS__, 'render_plugin_update_info'], 20, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'rename_github_update_source'], 10, 4);
    }

    public static function activate(): void
    {
        self::create_customer_tables();
        if (method_exists(__CLASS__, 'maybe_upgrade_customer_tables')) {
            self::maybe_upgrade_customer_tables();
        }
    }

    private static function create_customer_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $customers_table = self::customers_table();
        $messages_table = self::messages_table();

        dbDelta("CREATE TABLE {$customers_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conversation_id varchar(64) NOT NULL,
            customer_name varchar(100) NOT NULL DEFAULT '',
            contact varchar(120) NOT NULL DEFAULT '',
            ip_address varchar(45) NOT NULL DEFAULT '',
            country varchar(80) NOT NULL DEFAULT '',
            device varchar(30) NOT NULL DEFAULT '',
            user_agent text NULL,
            first_message text NULL,
            last_message text NULL,
            last_reply text NULL,
            ai_paused tinyint(1) NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'new',
            notes text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY conversation_id (conversation_id),
            KEY updated_at (updated_at),
            KEY status (status),
            KEY ai_paused (ai_paused),
            KEY ip_address (ip_address),
            KEY contact (contact)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$messages_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL,
            conversation_id varchar(64) NOT NULL,
            role varchar(20) NOT NULL,
            content longtext NOT NULL,
            trace_id varchar(120) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY customer_id (customer_id),
            KEY conversation_id (conversation_id),
            KEY created_at (created_at)
        ) {$charset_collate};");
    }

    private static function maybe_upgrade_customer_tables(): void
    {
        global $wpdb;

        $customers_table = self::customers_table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $customers_table)) !== $customers_table) {
            return;
        }

        $column = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $customers_table . ' LIKE %s', 'ai_paused'));
        if ($column === null) {
            $wpdb->query('ALTER TABLE ' . $customers_table . ' ADD ai_paused tinyint(1) NOT NULL DEFAULT 0 AFTER last_reply');
        }

        $index = $wpdb->get_var($wpdb->prepare('SHOW INDEX FROM ' . $customers_table . ' WHERE Key_name = %s', 'ai_paused'));
        if ($index === null) {
            $wpdb->query('ALTER TABLE ' . $customers_table . ' ADD INDEX ai_paused (ai_paused)');
        }
    }

    public static function add_settings_page(): void
    {
        add_menu_page(
            'LinkAI 客服',
            'LinkAI 客服',
            'manage_options',
            'linkai-ai-customer-service',
            [__CLASS__, 'render_settings_page'],
            'dashicons-format-chat',
            58
        );

        add_submenu_page(
            'linkai-ai-customer-service',
            'LinkAI 智能客服设置',
            '设置',
            'manage_options',
            'linkai-ai-customer-service',
            [__CLASS__, 'render_settings_page']
        );

        add_submenu_page(
            'linkai-ai-customer-service',
            'LinkAI 客户管理',
            '客户管理',
            'manage_options',
            'linkai-customer-records',
            [__CLASS__, 'render_customer_records_page']
        );
    }


    private static function settings_page_url(): string
    {
        return admin_url('admin.php?page=linkai-ai-customer-service');
    }

    private static function customer_records_page_url(): string
    {
        return admin_url('admin.php?page=linkai-customer-records');
    }

    public static function add_settings_link(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::settings_page_url()),
            esc_html__('设置 API Key', 'linkai-ai-customer-service')
        );
        $customers_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::customer_records_page_url()),
            esc_html__('客户管理', 'linkai-ai-customer-service')
        );
        array_unshift($links, $customers_link, $settings_link);

        return $links;
    }

    public static function render_missing_key_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_settings_page = $screen && in_array($screen->id, ['toplevel_page_linkai-ai-customer-service', 'settings_page_linkai-ai-customer-service'], true);
        $options = self::get_options();
        if (!empty($options['api_key']) || $is_settings_page) {
            return;
        }

        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('LinkAI 智能客服需要先配置 API Key 才能回复访客消息。', 'linkai-ai-customer-service'),
            esc_url(self::settings_page_url()),
            esc_html__('现在去设置', 'linkai-ai-customer-service')
        );
    }

    public static function register_settings(): void
    {
        register_setting('linkai_ai_customer_service', self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_options'],
            'default' => self::default_options(),
        ]);
    }

    public static function sanitize_options(array $input): array
    {
        $defaults = self::default_options();
        $current = self::get_options();
        $api_key = isset($input['api_key']) ? trim(sanitize_text_field($input['api_key'])) : '';

        if (!empty($input['clear_api_key'])) {
            $api_key = '';
        } elseif ($api_key === '') {
            $api_key = $current['api_key'];
        }

        return [
            'api_key' => $api_key,
            'app_code' => isset($input['app_code']) ? sanitize_text_field($input['app_code']) : $defaults['app_code'],
            'model' => isset($input['model']) ? sanitize_text_field($input['model']) : $defaults['model'],
            'temperature' => isset($input['temperature']) ? min(1, max(0, (float) $input['temperature'])) : $defaults['temperature'],
            'assistant_name' => isset($input['assistant_name']) ? sanitize_text_field($input['assistant_name']) : $defaults['assistant_name'],
            'welcome_message' => isset($input['welcome_message']) ? sanitize_textarea_field($input['welcome_message']) : $defaults['welcome_message'],
            'system_prompt' => isset($input['system_prompt']) ? sanitize_textarea_field($input['system_prompt']) : $defaults['system_prompt'],
            'auto_render' => !empty($input['auto_render']) ? '1' : '0',
            'human_takeover_timeout' => isset($input['human_takeover_timeout']) ? min(1440, max(0, (int) $input['human_takeover_timeout'])) : $defaults['human_takeover_timeout'],
            'update_repo_url' => isset($input['update_repo_url']) ? esc_url_raw(trim($input['update_repo_url'])) : $defaults['update_repo_url'],
            'update_branch' => isset($input['update_branch']) ? self::sanitize_update_branch($input['update_branch']) : $defaults['update_branch'],
        ];
    }



    public static function handle_permission_fix(): void
    {
        if (!current_user_can('manage_options') || empty($_POST['linkai_fix_permissions'])) {
            return;
        }

        check_admin_referer('linkai_fix_permissions');
        $fixed = self::chmod_plugin_directory(dirname(self::PLUGIN_FILE));

        wp_safe_redirect(add_query_arg(['permission_fixed' => $fixed ? '1' : '0'], self::settings_page_url()));
        exit;
    }

    private static function chmod_plugin_directory(string $plugin_dir): bool
    {
        $dir_mode = defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : 0755;
        $file_mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
        $success = self::chmod_path($plugin_dir, $dir_mode);

        if (!is_dir($plugin_dir) || !is_readable($plugin_dir)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($plugin_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $mode = $item->isDir() ? $dir_mode : $file_mode;
            $success = self::chmod_path($path, $mode) && $success;
        }

        return $success && is_writable($plugin_dir);
    }

    private static function chmod_path(string $path, int $mode): bool
    {
        return file_exists($path) && @chmod($path, $mode);
    }

    public static function handle_update_cache_clear(): void
    {
        if (!current_user_can('manage_options') || empty($_POST['linkai_clear_update_cache'])) {
            return;
        }

        check_admin_referer('linkai_clear_update_cache');
        self::delete_update_cache();
        delete_site_transient('update_plugins');

        wp_safe_redirect(add_query_arg(['update_cache_cleared' => '1'], self::settings_page_url()));
        exit;
    }

    private static function delete_update_cache(): void
    {
        $options = self::get_options();
        $repo = self::parse_github_repo($options['update_repo_url']);
        if (!$repo) {
            return;
        }

        $branch = !empty($options['update_branch']) ? $options['update_branch'] : 'main';
        delete_site_transient(self::get_update_cache_key($repo, $branch));
    }

    private static function get_update_cache_key(array $repo, string $branch): string
    {
        return 'linkai_ai_customer_service_update_' . md5($repo['owner'] . '/' . $repo['name'] . '/' . $branch);
    }

    public static function check_for_plugin_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $plugin_basename = plugin_basename(self::PLUGIN_FILE);
        $update = self::get_github_update_data(self::should_force_update_check());
        if (!$update || !version_compare($update['version'], self::VERSION, '>')) {
            if (isset($transient->response[$plugin_basename])) {
                unset($transient->response[$plugin_basename]);
            }
            return $transient;
        }

        $transient->response[$plugin_basename] = (object) [
            'slug' => self::PLUGIN_DIRECTORY_NAME,
            'plugin' => $plugin_basename,
            'new_version' => $update['version'],
            'url' => $update['repo_url'],
            'package' => $update['zip_url'],
            'tested' => get_bloginfo('version'),
            'requires' => '5.8',
        ];

        return $transient;
    }

    public static function render_plugin_update_info($result, string $action, object $args)
    {
        $plugin_slug = self::PLUGIN_DIRECTORY_NAME;
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $plugin_slug) {
            return $result;
        }

        $update = self::get_github_update_data();
        if (!$update) {
            return $result;
        }

        return (object) [
            'name' => 'LinkAI 智能 AI 客服',
            'slug' => $plugin_slug,
            'version' => $update['version'],
            'author' => 'Jinshanjiao',
            'homepage' => $update['repo_url'],
            'download_link' => $update['zip_url'],
            'requires' => '5.8',
            'tested' => get_bloginfo('version'),
            'sections' => [
                'description' => '从 GitHub 仓库分支下载并更新 LinkAI 智能 AI 客服插件。',
                'changelog' => !empty($update['changelog']) ? nl2br(esc_html($update['changelog'])) : '请查看 GitHub 仓库提交记录。',
            ],
        ];
    }

    public static function rename_github_update_source($source, $remote_source, $upgrader, $hook_extra)
    {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(self::PLUGIN_FILE)) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return new WP_Error('linkai_update_filesystem_unavailable', 'LinkAI 更新失败：WordPress 文件系统不可用，无法检查更新包。');
        }
        if (!$wp_filesystem->exists($source)) {
            return new WP_Error('linkai_update_source_missing', sprintf('LinkAI 更新失败：解压后的更新目录不存在：%s', $source));
        }

        $plugin_file = basename(self::PLUGIN_FILE);
        $source = untrailingslashit($source);

        if (!$wp_filesystem->exists(trailingslashit($source) . $plugin_file)) {
            $found_source = self::find_update_source_with_plugin_file($source, $plugin_file);
            if ($found_source !== '') {
                $source = untrailingslashit($found_source);
            }
        }

        $target = self::get_update_target_path($source, $remote_source);

        if (!$wp_filesystem->exists(trailingslashit($source) . $plugin_file)) {
            return new WP_Error(
                'linkai_update_plugin_file_missing',
                sprintf(
                    'LinkAI 更新包无效：解压后没有找到插件主文件 %1$s。当前解压目录：%2$s。目录内容：%3$s。请确认 ZIP 解压后包含 %4$s/%1$s。',
                    $plugin_file,
                    $source,
                    self::describe_update_source($source),
                    self::PLUGIN_DIRECTORY_NAME
                )
            );
        }

        if ($source === $target) {
            return $source;
        }

        if ($wp_filesystem->exists($target) && !$wp_filesystem->delete($target, true)) {
            return new WP_Error(
                'linkai_update_target_delete_failed',
                sprintf('LinkAI 更新失败：无法删除临时目标目录 %s。请检查 wp-content/upgrade 目录权限。', $target)
            );
        }

        if ($wp_filesystem->move($source, $target, true)) {
            return $target;
        }

        return new WP_Error(
            'linkai_update_source_move_failed',
            sprintf('LinkAI 更新失败：已找到插件主文件，但无法把 %1$s 移动为 %2$s。请检查 wp-content/upgrade 目录权限。', $source, $target)
        );
    }


    private static function get_update_target_path(string $source, string $remote_source): string
    {
        $source = untrailingslashit($source);
        $remote_source = untrailingslashit($remote_source);

        if (basename($remote_source) === self::PLUGIN_DIRECTORY_NAME) {
            return $remote_source;
        }

        if (basename($source) === self::PLUGIN_DIRECTORY_NAME) {
            return $source;
        }

        return untrailingslashit(trailingslashit($remote_source) . self::PLUGIN_DIRECTORY_NAME);
    }

    private static function describe_update_source(string $source): string
    {
        global $wp_filesystem;
        $entries = $wp_filesystem ? $wp_filesystem->dirlist($source) : [];
        if (!is_array($entries) || empty($entries)) {
            return '空目录或无法读取目录';
        }

        return implode(', ', array_slice(array_keys($entries), 0, 12));
    }

    private static function find_update_source_with_plugin_file(string $source, string $plugin_file): string
    {
        global $wp_filesystem;
        $entries = $wp_filesystem->dirlist($source);
        if (!is_array($entries)) {
            return '';
        }

        foreach ($entries as $name => $entry) {
            if (($entry['type'] ?? '') !== 'd') {
                continue;
            }

            $candidate = trailingslashit($source) . $name;
            if ($wp_filesystem->exists(trailingslashit($candidate) . $plugin_file)) {
                return $candidate;
            }
        }

        return '';
    }

    private static function should_force_update_check(): bool
    {
        global $pagenow;

        return is_admin() && in_array($pagenow, ['plugins.php', 'update-core.php', 'plugin-install.php'], true);
    }

    private static function get_github_update_data(bool $force_refresh = false): ?array
    {
        $options = self::get_options();
        $repo = self::parse_github_repo($options['update_repo_url']);
        $branch = !empty($options['update_branch']) ? $options['update_branch'] : 'main';
        if (!$repo) {
            return null;
        }

        $cache_key = self::get_update_cache_key($repo, $branch);
        $cached = get_site_transient($cache_key);
        if (!$force_refresh && is_array($cached)) {
            return $cached;
        }

        $plugin_file_url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/linkai-ai-customer-service.php',
            rawurlencode($repo['owner']),
            rawurlencode($repo['name']),
            rawurlencode($branch)
        );
        $response = wp_remote_get($plugin_file_url, ['timeout' => 15]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $remote_plugin = wp_remote_retrieve_body($response);
        if (!preg_match('/^[ \t\/*#@]*Version:\s*([0-9A-Za-z.\-_]+)/mi', $remote_plugin, $matches)) {
            return null;
        }

        $data = [
            'version' => $matches[1],
            'repo_url' => sprintf('https://github.com/%s/%s', $repo['owner'], $repo['name']),
            'zip_url' => self::github_branch_zip_url($repo, $branch),
            'changelog' => self::extract_remote_changelog($remote_plugin),
        ];
        set_site_transient($cache_key, $data, MINUTE_IN_SECONDS);

        return $data;
    }


    private static function github_branch_zip_url(array $repo, string $branch): string
    {
        return sprintf(
            'https://codeload.github.com/%s/%s/zip/refs/heads/%s',
            rawurlencode($repo['owner']),
            rawurlencode($repo['name']),
            str_replace('%2F', '/', rawurlencode($branch))
        );
    }

    private static function sanitize_update_branch(string $branch): string
    {
        $branch = preg_replace('/[^A-Za-z0-9._\/-]/', '', trim($branch));

        return $branch !== '' ? $branch : 'main';
    }

    private static function parse_github_repo(string $repo_url): ?array
    {
        if ($repo_url === '') {
            return null;
        }

        if (preg_match('#github\.com[:/]([^/]+)/([^/.]+)(?:\.git)?/?#i', $repo_url, $matches)) {
            return [
                'owner' => sanitize_key($matches[1]),
                'name' => sanitize_key($matches[2]),
            ];
        }

        return null;
    }

    private static function extract_remote_changelog(string $remote_plugin): string
    {
        if (preg_match('/^[ \t\/*#@]*Version:\s*([^\n]+)$/mi', $remote_plugin, $matches)) {
            return '远程版本：' . trim($matches[1]);
        }

        return '';
    }

    public static function register_assets(): void
    {
        wp_register_style(
            'linkai-ai-customer-service',
            plugins_url('assets/customer-service.css', __FILE__),
            [],
            self::VERSION
        );

        wp_register_script(
            'linkai-ai-customer-service',
            plugins_url('assets/customer-service.js', __FILE__),
            [],
            self::VERSION,
            true
        );

        if (self::get_options()['auto_render'] === '1') {
            self::enqueue_widget_assets();
        }
    }

    public static function render_chat_widget(): void
    {
        $options = self::get_options();
        if ($options['auto_render'] !== '1' || self::$auto_widget_rendered) {
            return;
        }

        self::$auto_widget_rendered = true;
        echo self::render_shortcode();
    }

    public static function render_shortcode(): string
    {
        self::enqueue_widget_assets();
        $options = self::get_options();

        ob_start();
        ?>
        <div class="linkai-chat" data-welcome="<?php echo esc_attr($options['welcome_message']); ?>">
            <button class="linkai-chat__toggle" type="button" aria-label="打开智能客服">
                <span class="linkai-chat__toggle-icon">AI</span>
                <span class="linkai-chat__toggle-text">在线客服</span>
            </button>
            <section class="linkai-chat__panel" aria-label="智能客服聊天窗口" hidden>
                <header class="linkai-chat__header">
                    <div>
                        <strong><?php echo esc_html($options['assistant_name']); ?></strong>
                        <span>通常几秒内回复</span>
                    </div>
                    <button class="linkai-chat__close" type="button" aria-label="关闭智能客服">×</button>
                </header>
                <div class="linkai-chat__messages" role="log" aria-live="polite"></div>
                <form class="linkai-chat__form">
                    <div class="linkai-chat__customer-fields">
                        <input class="linkai-chat__customer-input" name="customer_name" type="text" maxlength="50" placeholder="姓名（选填）" autocomplete="name">
                        <input class="linkai-chat__customer-input" name="contact" type="text" maxlength="80" placeholder="电话/微信（选填）" autocomplete="tel">
                    </div>
                    <div class="linkai-chat__composer">
                        <textarea class="linkai-chat__input" name="message" rows="1" placeholder="请输入您的问题，例如：你们有哪些汽车配件？" required></textarea>
                        <button class="linkai-chat__send" type="submit">发送</button>
                    </div>
                </form>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function enqueue_widget_assets(): void
    {
        wp_enqueue_style('linkai-ai-customer-service');
        wp_enqueue_script('linkai-ai-customer-service');
        $options = self::get_options();
        wp_localize_script('linkai-ai-customer-service', 'LinkAICustomerService', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'errorMessage' => '抱歉，智能客服暂时无法连接，请稍后再试或留下联系方式。',
            'conversationStorageKey' => 'linkai_customer_service_conversation_id',
            'autoRender' => $options['auto_render'] === '1',
            'assistantName' => $options['assistant_name'],
            'welcomeMessage' => $options['welcome_message'],
        ]);
    }

    public static function handle_updates_request(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        $after_id = isset($_POST['after_id']) ? max(0, (int) $_POST['after_id']) : 0;
        if ($conversation_id === '') {
            wp_send_json_error(['message' => '会话不存在。'], 400);
        }

        $customers_table = self::customers_table();
        $messages_table = self::messages_table();
        $customer = $wpdb->get_row($wpdb->prepare("SELECT ai_paused FROM {$customers_table} WHERE conversation_id = %s", $conversation_id));
        if (!$customer) {
            wp_send_json_success(['messages' => [], 'ai_paused' => false]);
        }

        $messages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, role, content, created_at FROM {$messages_table} WHERE conversation_id = %s AND id > %d AND role IN ('assistant', 'human') ORDER BY id ASC LIMIT 20",
                $conversation_id,
                $after_id
            ),
            ARRAY_A
        );

        wp_send_json_success([
            'messages' => array_map([__CLASS__, 'format_frontend_message'], $messages),
            'ai_paused' => !empty($customer->ai_paused),
        ]);
    }

    private static function format_frontend_message(array $message): array
    {
        return [
            'id' => (int) $message['id'],
            'role' => $message['role'] === 'human' ? 'assistant' : $message['role'],
            'content' => wp_kses_post($message['content']),
            'created_at' => $message['created_at'],
            'source' => $message['role'],
        ];
    }

    public static function handle_chat_request(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $options = self::get_options();
        if (empty($options['api_key'])) {
            wp_send_json_error(['message' => '请先在后台配置 LinkAI API Key。'], 400);
        }

        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $history_json = isset($_POST['history']) ? wp_unslash($_POST['history']) : '[]';
        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        if ($conversation_id === '') {
            $conversation_id = wp_generate_uuid4();
        }
        if ($message === '') {
            wp_send_json_error(['message' => '请输入咨询内容。'], 400);
        }

        $history = json_decode($history_json, true);
        if (!is_array($history)) {
            $history = [];
        }

        $messages = [];
        if (!empty($options['system_prompt'])) {
            $messages[] = ['role' => 'system', 'content' => $options['system_prompt']];
        }

        foreach (array_slice($history, -8) as $item) {
            if (!isset($item['role'], $item['content']) || !in_array($item['role'], ['user', 'assistant'], true)) {
                continue;
            }
            $content = trim(sanitize_textarea_field((string) $item['content']));
            if ($content !== '') {
                $messages[] = ['role' => $item['role'], 'content' => $content];
            }
        }

        $pause_state = self::record_customer_question($conversation_id, $customer_name, $contact, $message);
        if (!empty($pause_state['ai_paused'])) {
            wp_send_json_success([
                'reply' => '人工客服已接管此会话，AI 自动回复已暂停。请稍候，客服会继续跟进。',
                'conversation_id' => $conversation_id,
                'trace_id' => '',
                'message_id' => $pause_state['message_id'],
                'ai_paused' => true,
                'human_takeover_timeout' => (int) $options['human_takeover_timeout'],
                'suggested_questions' => [],
            ]);
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $body = [
            'messages' => $messages,
            'temperature' => (float) $options['temperature'],
        ];

        if (!empty($options['app_code'])) {
            $body['app_code'] = $options['app_code'];
        }
        if (!empty($options['model'])) {
            $body['model'] = $options['model'];
        }

        $response = wp_remote_post(self::API_ENDPOINT, [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $options['api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status_code < 200 || $status_code >= 300) {
            $api_message = $data['error']['message'] ?? 'LinkAI 接口调用失败。';
            wp_send_json_error(['message' => $api_message], $status_code ?: 502);
        }

        $reply = $data['choices'][0]['message']['content'] ?? '';
        if ($reply === '') {
            wp_send_json_error(['message' => 'LinkAI 未返回有效回复。'], 502);
        }

        $trace_id = $data['trace_id'] ?? '';
        $message_ids = self::record_assistant_reply($conversation_id, $reply, (string) $trace_id);

        wp_send_json_success([
            'reply' => wp_kses_post($reply),
            'conversation_id' => $conversation_id,
            'trace_id' => $trace_id,
            'message_id' => $message_ids['assistant_id'],
            'ai_paused' => false,
            'human_takeover_timeout' => (int) $options['human_takeover_timeout'],
            'suggested_questions' => $data['suggested_questions'] ?? [],
        ]);
    }

    private static function record_customer_question(string $conversation_id, string $customer_name, string $contact, string $message): array
    {
        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $now = current_time('mysql');
        $customers_table = self::customers_table();
        $messages_table = self::messages_table();
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$customers_table} WHERE conversation_id = %s", $conversation_id));

        if ($customer) {
            $customer_id = (int) $customer->id;
            $wpdb->update(
                $customers_table,
                [
                    'customer_name' => $customer_name !== '' ? $customer_name : $customer->customer_name,
                    'contact' => $contact !== '' ? $contact : $customer->contact,
                    'ip_address' => self::get_customer_ip(),
                    'country' => self::get_customer_country(),
                    'device' => self::get_customer_device(),
                    'user_agent' => self::get_customer_user_agent(),
                    'last_message' => $message,
                    'updated_at' => $now,
                ],
                ['id' => $customer_id],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $customers_table,
                [
                    'conversation_id' => $conversation_id,
                    'customer_name' => $customer_name,
                    'contact' => $contact,
                    'ip_address' => self::get_customer_ip(),
                    'country' => self::get_customer_country(),
                    'device' => self::get_customer_device(),
                    'user_agent' => self::get_customer_user_agent(),
                    'first_message' => $message,
                    'last_message' => $message,
                    'last_reply' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            $customer_id = (int) $wpdb->insert_id;
            $customer = (object) ['ai_paused' => 0];
        }

        $wpdb->insert(
            $messages_table,
            [
                'customer_id' => $customer_id,
                'conversation_id' => $conversation_id,
                'role' => 'user',
                'content' => $message,
                'trace_id' => '',
                'created_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );

        $message_id = (int) $wpdb->insert_id;
        $pause_state = self::resolve_ai_pause_state($conversation_id, !empty($customer->ai_paused));

        return ['message_id' => $message_id, 'ai_paused' => $pause_state['ai_paused'], 'pause_expired' => $pause_state['pause_expired']];
    }

    private static function resolve_ai_pause_state(string $conversation_id, bool $is_paused): array
    {
        if (!$is_paused) {
            return ['ai_paused' => false, 'pause_expired' => false];
        }

        $options = self::get_options();
        $timeout_minutes = (int) $options['human_takeover_timeout'];
        if ($timeout_minutes <= 0) {
            return ['ai_paused' => true, 'pause_expired' => false];
        }

        global $wpdb;
        $messages_table = self::messages_table();
        $last_human_at = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$messages_table} WHERE conversation_id = %s AND role = 'human' ORDER BY id DESC LIMIT 1", $conversation_id));
        if (empty($last_human_at)) {
            return ['ai_paused' => true, 'pause_expired' => false];
        }

        $last_human_timestamp = strtotime((string) $last_human_at);
        if (!$last_human_timestamp || current_time('timestamp') - $last_human_timestamp < $timeout_minutes * MINUTE_IN_SECONDS) {
            return ['ai_paused' => true, 'pause_expired' => false];
        }

        $customers_table = self::customers_table();
        $wpdb->update(
            $customers_table,
            ['ai_paused' => 0, 'updated_at' => current_time('mysql')],
            ['conversation_id' => $conversation_id],
            ['%d', '%s'],
            ['%s']
        );

        return ['ai_paused' => false, 'pause_expired' => true];
    }

    private static function record_assistant_reply(string $conversation_id, string $reply, string $trace_id): array
    {
        return ['assistant_id' => self::insert_staff_message($conversation_id, 'assistant', $reply, $trace_id, false)];
    }

    private static function insert_staff_message(string $conversation_id, string $role, string $content, string $trace_id = '', bool $pause_ai = true): int
    {
        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $now = current_time('mysql');
        $customers_table = self::customers_table();
        $messages_table = self::messages_table();
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$customers_table} WHERE conversation_id = %s", $conversation_id));
        if (!$customer) {
            return 0;
        }

        $wpdb->insert(
            $messages_table,
            [
                'customer_id' => (int) $customer->id,
                'conversation_id' => $conversation_id,
                'role' => $role,
                'content' => $content,
                'trace_id' => $trace_id,
                'created_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
        $message_id = (int) $wpdb->insert_id;

        $wpdb->update(
            $customers_table,
            [
                'last_reply' => $content,
                'ai_paused' => $pause_ai ? 1 : (int) $customer->ai_paused,
                'updated_at' => $now,
            ],
            ['id' => (int) $customer->id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        return $message_id;
    }

    public static function render_customer_records_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();
        $customers_table = self::customers_table();
        $messages_table = self::messages_table();

        if (isset($_POST['linkai_customer_update_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['linkai_customer_update_nonce'])), 'linkai_update_customer')) {
            $posted_conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
            if ($posted_conversation_id !== '') {
                $wpdb->update(
                    $customers_table,
                    [
                        'customer_name' => isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '',
                        'contact' => isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '',
                        'status' => isset($_POST['status']) ? self::sanitize_customer_status(wp_unslash($_POST['status'])) : 'new',
                        'notes' => isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '',
                        'updated_at' => current_time('mysql'),
                    ],
                    ['conversation_id' => $posted_conversation_id],
                    ['%s', '%s', '%s', '%s', '%s'],
                    ['%s']
                );
                wp_safe_redirect(add_query_arg(['conversation_id' => $posted_conversation_id, 'updated' => '1'], self::customer_records_page_url()));
                exit;
            }
        }

        if (isset($_POST['linkai_human_reply_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['linkai_human_reply_nonce'])), 'linkai_human_reply')) {
            $posted_conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
            $human_reply = isset($_POST['human_reply']) ? sanitize_textarea_field(wp_unslash($_POST['human_reply'])) : '';
            if ($posted_conversation_id !== '' && $human_reply !== '') {
                self::insert_staff_message($posted_conversation_id, 'human', $human_reply, '', true);
                wp_safe_redirect(add_query_arg(['conversation_id' => $posted_conversation_id, 'human_replied' => '1'], self::customer_records_page_url()));
                exit;
            }
        }

        if (isset($_POST['linkai_ai_pause_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['linkai_ai_pause_nonce'])), 'linkai_ai_pause')) {
            $posted_conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
            $ai_paused = !empty($_POST['ai_paused']) ? 1 : 0;
            if ($posted_conversation_id !== '') {
                $wpdb->update(
                    $customers_table,
                    ['ai_paused' => $ai_paused, 'updated_at' => current_time('mysql')],
                    ['conversation_id' => $posted_conversation_id],
                    ['%d', '%s'],
                    ['%s']
                );
                wp_safe_redirect(add_query_arg(['conversation_id' => $posted_conversation_id, 'ai_pause_updated' => '1'], self::customer_records_page_url()));
                exit;
            }
        }

        $conversation_id = isset($_GET['conversation_id']) ? sanitize_key(wp_unslash($_GET['conversation_id'])) : '';
        $customers = $wpdb->get_results("SELECT * FROM {$customers_table} ORDER BY updated_at DESC LIMIT 100");
        $selected = null;
        $messages = [];
        if ($conversation_id !== '') {
            $selected = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$customers_table} WHERE conversation_id = %s", $conversation_id));
            $messages = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$messages_table} WHERE conversation_id = %s ORDER BY created_at ASC", $conversation_id));
        }
        ?>
        <div class="wrap">
            <h1>LinkAI 客户管理</h1>
            <p>这里会保存访客在智能客服中留下的姓名、电话/微信，以及完整聊天记录；人工主动回复会自动暂停该会话的 AI 回复，避免客户同时收到 AI 与人工的重复答复。</p>
            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>客户资料已更新。</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['human_replied'])) : ?>
                <div class="notice notice-success is-dismissible"><p>人工回复已发送，AI 自动回复已暂停。</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['ai_pause_updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>AI 接管状态已更新。</p></div>
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:minmax(360px, 1fr) 1.2fr;gap:24px;align-items:start;">
                <table class="widefat striped">
                    <thead><tr><th>客户</th><th>联系方式</th><th>IP/国家</th><th>设备</th><th>状态</th><th>最后咨询</th><th>更新时间</th></tr></thead>
                    <tbody>
                    <?php if (empty($customers)) : ?>
                        <tr><td colspan="7">暂无客户记录。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($customers as $customer) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url(add_query_arg(['conversation_id' => $customer->conversation_id], self::customer_records_page_url())); ?>"><?php echo esc_html($customer->customer_name ?: '未留姓名'); ?></a></td>
                            <td><?php echo esc_html($customer->contact ?: '未留联系方式'); ?></td>
                            <td><?php echo esc_html(trim(($customer->ip_address ?? '') . ' ' . ($customer->country ?? '')) ?: '未知'); ?></td>
                            <td><?php echo esc_html($customer->device ?: '未知'); ?></td>
                            <td><?php echo esc_html(self::customer_status_label($customer->status ?? 'new')); ?></td>
                            <td><?php echo esc_html(wp_trim_words($customer->last_message, 18)); ?></td>
                            <td><?php echo esc_html($customer->updated_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="postbox" style="padding:16px;">
                    <?php if ($selected) : ?>
                        <h2><?php echo esc_html($selected->customer_name ?: '未留姓名'); ?></h2>
                        <p><strong>AI 回复状态：</strong><?php echo !empty($selected->ai_paused) ? '<span style="color:#b32d2e;">已暂停（人工接管）</span>' : '<span style="color:#008a20;">自动回复中</span>'; ?></p>
                        <form method="post" style="margin-bottom:16px;padding:12px;border:1px solid #dcdcde;background:#fff;">
                            <?php wp_nonce_field('linkai_human_reply', 'linkai_human_reply_nonce'); ?>
                            <input type="hidden" name="conversation_id" value="<?php echo esc_attr($selected->conversation_id); ?>">
                            <p><label><strong>主动人工回复：</strong><br><textarea name="human_reply" class="large-text" rows="4" placeholder="输入后会发送到访客聊天窗口，并自动暂停 AI 回复"></textarea></label></p>
                            <p class="description">人工回复后，AI 会暂停；如果客户再次留言且 <?php echo esc_html((string) $options['human_takeover_timeout']); ?> 分钟内没有新的人工回复，AI 会自动恢复接待。</p>
                            <?php submit_button('发送人工回复并暂停 AI', 'primary', 'submit', false); ?>
                        </form>
                        <form method="post" style="margin-bottom:16px;">
                            <?php wp_nonce_field('linkai_ai_pause', 'linkai_ai_pause_nonce'); ?>
                            <input type="hidden" name="conversation_id" value="<?php echo esc_attr($selected->conversation_id); ?>">
                            <label><input type="checkbox" name="ai_paused" value="1" <?php checked(!empty($selected->ai_paused)); ?>> 暂停此会话的 AI 自动回复</label>
                            <?php submit_button('更新 AI 状态', 'secondary', 'submit', false); ?>
                        </form>
                        <form method="post" style="margin-bottom:16px;">
                            <?php wp_nonce_field('linkai_update_customer', 'linkai_customer_update_nonce'); ?>
                            <input type="hidden" name="conversation_id" value="<?php echo esc_attr($selected->conversation_id); ?>">
                            <p><label><strong>姓名：</strong><br><input type="text" class="regular-text" name="customer_name" value="<?php echo esc_attr($selected->customer_name); ?>"></label></p>
                            <p><label><strong>联系方式：</strong><br><input type="text" class="regular-text" name="contact" value="<?php echo esc_attr($selected->contact); ?>"></label></p>
                            <p><label><strong>跟进状态：</strong><br><select name="status"><?php foreach (self::customer_statuses() as $status_key => $status_label) : ?><option value="<?php echo esc_attr($status_key); ?>" <?php selected($selected->status ?? 'new', $status_key); ?>><?php echo esc_html($status_label); ?></option><?php endforeach; ?></select></label></p>
                            <p><label><strong>客户备注：</strong><br><textarea name="notes" class="large-text" rows="4"><?php echo esc_textarea($selected->notes ?? ''); ?></textarea></label></p>
                            <?php submit_button('保存客户资料', 'primary', 'submit', false); ?>
                        </form>
                        <p><strong>IP/国家：</strong><?php echo esc_html(trim(($selected->ip_address ?? '') . ' ' . ($selected->country ?? '')) ?: '未知'); ?></p>
                        <p><strong>设备：</strong><?php echo esc_html($selected->device ?: '未知'); ?></p>
                        <p><strong>User Agent：</strong><code><?php echo esc_html($selected->user_agent ?: '未知'); ?></code></p>
                        <p><strong>会话 ID：</strong><code><?php echo esc_html($selected->conversation_id); ?></code></p>
                        <hr>
                        <?php foreach ($messages as $chat_message) : ?>
                            <p><strong><?php echo $chat_message->role === 'user' ? '客户' : ($chat_message->role === 'human' ? '人工客服' : 'AI客服'); ?>：</strong><?php echo nl2br(esc_html($chat_message->content)); ?></p>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p>点击左侧客户可以查看完整聊天记录。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }



    private static function get_customer_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded_for = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
            $ips = array_map('trim', explode(',', $forwarded_for));
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '';
    }

    private static function get_customer_country(): string
    {
        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'GEOIP_COUNTRY_NAME'] as $key) {
            if (!empty($_SERVER[$key])) {
                return sanitize_text_field(wp_unslash($_SERVER[$key]));
            }
        }

        return '';
    }

    private static function get_customer_user_agent(): string
    {
        return !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    }

    private static function get_customer_device(): string
    {
        $user_agent = strtolower(self::get_customer_user_agent());
        if ($user_agent === '') {
            return '';
        }
        if (strpos($user_agent, 'bot') !== false || strpos($user_agent, 'spider') !== false || strpos($user_agent, 'crawler') !== false) {
            return '机器人';
        }
        if (strpos($user_agent, 'ipad') !== false || strpos($user_agent, 'tablet') !== false) {
            return '平板';
        }
        if (strpos($user_agent, 'mobile') !== false || strpos($user_agent, 'iphone') !== false || strpos($user_agent, 'android') !== false) {
            return '手机';
        }

        return '电脑';
    }

    private static function customer_statuses(): array
    {
        return [
            'new' => '新客户',
            'contacted' => '已联系',
            'quoted' => '已报价',
            'closed' => '已成交',
            'invalid' => '无效',
        ];
    }

    private static function sanitize_customer_status(string $status): string
    {
        $status = sanitize_key($status);

        return array_key_exists($status, self::customer_statuses()) ? $status : 'new';
    }

    private static function customer_status_label(string $status): string
    {
        $statuses = self::customer_statuses();

        return $statuses[$status] ?? $statuses['new'];
    }

    private static function customers_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'linkai_customers';
    }

    private static function messages_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'linkai_chat_messages';
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = self::get_options();
        ?>
        <div class="wrap">
            <h1>LinkAI 智能客服</h1>
            <p>使用 LinkAI 通用对话接口为网站访客提供汽车配件咨询、售前问答和售后引导。API Key 仅保存在 WordPress 后台，前端通过 AJAX 代理调用。</p>
            <?php if (empty($options['api_key'])) : ?>
                <div class="notice notice-warning inline"><p>请先填写 LinkAI API Key，否则前台智能客服无法调用 AI 回复。</p></div>
            <?php else : ?>
                <div class="notice notice-success inline"><p>API Key 已保存。为了安全，输入框不会回显完整密钥；如需更换，请直接输入新的 API Key 并保存。</p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('linkai_ai_customer_service'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="linkai-api-key">API Key</label></th>
                        <td>
                            <input id="linkai-api-key" name="<?php echo esc_attr(self::OPTION_NAME); ?>[api_key]" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo empty($options['api_key']) ? esc_attr('请输入 LinkAI API Key') : esc_attr('已保存，留空则不修改'); ?>" />
                            <p class="description">API Key 只保存在 WordPress 数据库中，不会输出到前端页面。留空保存时会保留当前已保存的 API Key。</p>
                            <?php if (!empty($options['api_key'])) : ?>
                                <label><input name="<?php echo esc_attr(self::OPTION_NAME); ?>[clear_api_key]" type="checkbox" value="1" /> 清除已保存的 API Key</label>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-app-code">应用 Code</label></th>
                        <td><input id="linkai-app-code" name="<?php echo esc_attr(self::OPTION_NAME); ?>[app_code]" type="text" class="regular-text" value="<?php echo esc_attr($options['app_code']); ?>" /><p class="description">可选。填写 LinkAI 应用、工作流或超级 AI 助理的 code。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-model">模型编码</label></th>
                        <td><input id="linkai-model" name="<?php echo esc_attr(self::OPTION_NAME); ?>[model]" type="text" class="regular-text" value="<?php echo esc_attr($options['model']); ?>" /><p class="description">可选。不填时使用应用默认模型。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-temperature">温度</label></th>
                        <td><input id="linkai-temperature" name="<?php echo esc_attr(self::OPTION_NAME); ?>[temperature]" type="number" min="0" max="1" step="0.1" value="<?php echo esc_attr((string) $options['temperature']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-assistant-name">客服名称</label></th>
                        <td><input id="linkai-assistant-name" name="<?php echo esc_attr(self::OPTION_NAME); ?>[assistant_name]" type="text" class="regular-text" value="<?php echo esc_attr($options['assistant_name']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-welcome-message">欢迎语</label></th>
                        <td><textarea id="linkai-welcome-message" name="<?php echo esc_attr(self::OPTION_NAME); ?>[welcome_message]" class="large-text" rows="3"><?php echo esc_textarea($options['welcome_message']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-system-prompt">系统提示词</label></th>
                        <td><textarea id="linkai-system-prompt" name="<?php echo esc_attr(self::OPTION_NAME); ?>[system_prompt]" class="large-text" rows="6"><?php echo esc_textarea($options['system_prompt']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">显示方式</th>
                        <td><label><input name="<?php echo esc_attr(self::OPTION_NAME); ?>[auto_render]" type="checkbox" value="1" <?php checked($options['auto_render'], '1'); ?> /> 在全站右下角自动显示</label><p class="description">也可以关闭自动显示，使用短代码 <code>[linkai_customer_service]</code> 放到指定页面。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-human-timeout">人工接管超时</label></th>
                        <td><input id="linkai-human-timeout" name="<?php echo esc_attr(self::OPTION_NAME); ?>[human_takeover_timeout]" type="number" min="0" max="1440" step="1" value="<?php echo esc_attr((string) $options['human_takeover_timeout']); ?>" /> 分钟<p class="description">人工主动回复会暂停 AI。客户再次留言时，如果超过这个时间仍没有人工继续回复，AI 会自动恢复接待；填 0 表示一直暂停，直到后台手动恢复。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-update-repo-url">GitHub 更新仓库</label></th>
                        <td><input id="linkai-update-repo-url" name="<?php echo esc_attr(self::OPTION_NAME); ?>[update_repo_url]" type="url" class="regular-text" placeholder="https://github.com/OSAMA-BIN-AZIZ/jinshanjiao" value="<?php echo esc_attr($options['update_repo_url']); ?>" /><p class="description">可选。填写插件所在 GitHub 仓库后，WordPress 后台「插件」页面可以检测并一键更新。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-update-branch">更新分支</label></th>
                        <td><input id="linkai-update-branch" name="<?php echo esc_attr(self::OPTION_NAME); ?>[update_branch]" type="text" class="regular-text" value="<?php echo esc_attr($options['update_branch']); ?>" /><p class="description">默认 main。你在 GitHub 更新代码并提高插件 Version 后，WordPress 会从此分支下载 zip 包更新；更新过程会保留当前插件目录名（例如 jinshanjiao-main），避免目录名不一致导致文件系统错误。</p></td>
                    </tr>
                </table>
                <?php submit_button('保存设置'); ?>
            </form>

            <hr>
            <h2>前台显示排查</h2>
            <p>如果登录后台后前台能看到客服图标，但未登录访客看不到，通常不是插件渲染失败，而是首页缓存、CDN 缓存或静态缓存还在返回旧 HTML。请清理 WordPress 缓存插件、服务器缓存和 CDN 缓存，并确认首页没有被单独缓存为旧版本。</p>

            <hr>
            <h2>更新排查</h2>
            <?php if (isset($_GET['update_cache_cleared'])) : ?>
                <div class="notice notice-success inline"><p>更新缓存已清除。请回到「插件」页面重新检查更新。</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['permission_fixed'])) : ?>
                <?php if ($_GET['permission_fixed'] === '1') : ?>
                    <div class="notice notice-success inline"><p>已尝试修复插件目录权限。请重新检查下方状态并再次更新。</p></div>
                <?php else : ?>
                    <div class="notice notice-error inline"><p>无法自动修复插件目录权限。通常表示 PHP/WordPress 用户不是该目录所有者，需要通过主机面板、FTP 或 SSH 修改所有者/权限。</p></div>
                <?php endif; ?>
            <?php endif; ?>
            <p>插件页会自动刷新 GitHub 远程版本；如果后台检测不到更新，请先看下方“当前插件版本”和“GitHub 远程版本”：只有远程 Version 高于当前 Version 时，WordPress 才会显示更新。若提示「无法安装这个包」，说明 WordPress 已下载 ZIP 但解压后没有识别到有效插件文件，或下载到的不是 ZIP；可复制“更新包下载地址”到浏览器确认 ZIP 内是否包含 linkai-ai-customer-service.php。</p>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin: 12px 0;">
                <form method="post">
                    <?php wp_nonce_field('linkai_clear_update_cache'); ?>
                    <?php submit_button('清除更新缓存', 'secondary', 'linkai_clear_update_cache', false); ?>
                </form>
                <form method="post">
                    <?php wp_nonce_field('linkai_fix_permissions'); ?>
                    <?php submit_button('尝试修复插件权限', 'secondary', 'linkai_fix_permissions', false); ?>
                </form>
            </div>
            <?php self::render_update_diagnostics(); ?>
        </div>
        <?php
    }


    private static function render_update_diagnostics(): void
    {
        $plugin_dir = dirname(self::PLUGIN_FILE);
        $update = self::get_github_update_data(true);
        $remote_version = $update['version'] ?? '未检测到（请确认 GitHub 仓库、分支和网络访问）';
        $update_status = !empty($update['version']) && version_compare($update['version'], self::VERSION, '>')
            ? '有可用更新'
            : '没有可用更新：只有 GitHub 远程 Version 高于当前 Version 时，WordPress 才会显示更新';
        $diagnostics = [
            '当前插件版本' => self::VERSION,
            'GitHub 远程版本' => $remote_version,
            '更新判断' => $update_status,
            '更新包下载地址' => $update['zip_url'] ?? '未生成',
            '标准插件目录名' => self::PLUGIN_DIRECTORY_NAME,
            '当前插件目录' => $plugin_dir,
            '插件目录是否可写' => is_writable($plugin_dir) ? '是' : '否：请把 wp-content/plugins/' . basename($plugin_dir) . ' 的所有者/权限调整为 WordPress 可写',
            'wp-content/plugins 是否可写' => is_writable(WP_PLUGIN_DIR) ? '是' : '否：WordPress 无法替换插件目录',
            'WordPress 文件系统方式' => function_exists('get_filesystem_method') ? get_filesystem_method([], WP_PLUGIN_DIR) : '未知',
            '当前插件路径' => plugin_basename(self::PLUGIN_FILE),
        ];
        ?>
        <table class="widefat striped" style="max-width: 900px; margin-top: 12px;">
            <tbody>
            <?php foreach ($diagnostics as $label => $value) : ?>
                <tr>
                    <th scope="row" style="width: 220px;"><?php echo esc_html($label); ?></th>
                    <td><code><?php echo esc_html($value); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function get_options(): array
    {
        $options = wp_parse_args(get_option(self::OPTION_NAME, []), self::default_options());
        if (empty($options['update_repo_url'])) {
            $options['update_repo_url'] = self::default_options()['update_repo_url'];
        }

        return $options;
    }

    private static function default_options(): array
    {
        return [
            'api_key' => '',
            'app_code' => '',
            'model' => '',
            'temperature' => 0.4,
            'assistant_name' => '金三角智能客服',
            'welcome_message' => '您好，我是金三角智能客服。您可以咨询汽车配件型号、适配车型、库存、报价和售后问题。',
            'system_prompt' => '你是金三角汽车配件网站的智能客服。请使用中文，回答要专业、简洁、友好。优先帮助用户确认配件名称、车型、年份、发动机型号、采购数量和联系方式；不确定时不要编造库存或价格，应引导用户留下联系方式，由人工客服确认。',
            'auto_render' => '1',
            'human_takeover_timeout' => 3,
            'update_repo_url' => 'https://github.com/OSAMA-BIN-AZIZ/jinshanjiao',
            'update_branch' => 'main',
        ];
    }
}

register_activation_hook(__FILE__, ['LinkAI_AI_Customer_Service', 'activate']);
LinkAI_AI_Customer_Service::init();
