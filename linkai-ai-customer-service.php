<?php
/**
 * Plugin Name: LinkAI 智能 AI 客服
 * Description: 为网站添加一个可配置的 LinkAI 智能客服悬浮聊天窗口，支持短代码与 WordPress AJAX 服务端代理。
 * Version: 1.3.41
 * Author: Jinshanjiao
 * License: GPL-2.0-or-later
 * Text Domain: linkai-ai-customer-service
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/trait-linkai-canned-replies-admin.php';
require_once __DIR__ . '/includes/trait-linkai-workspace-admin.php';
require_once __DIR__ . '/includes/trait-linkai-customer-records-admin.php';
require_once __DIR__ . '/includes/trait-linkai-online-visitors-admin.php';
require_once __DIR__ . '/includes/trait-linkai-database.php';
require_once __DIR__ . '/includes/trait-linkai-frontend-ajax.php';
require_once __DIR__ . '/includes/trait-linkai-admin-ajax.php';
require_once __DIR__ . '/includes/trait-linkai-settings-admin.php';
require_once __DIR__ . '/includes/trait-linkai-export.php';
require_once __DIR__ . '/includes/trait-linkai-updater.php';
require_once __DIR__ . '/includes/trait-linkai-parity-features.php';

final class LinkAI_AI_Customer_Service
{
    use LinkAI_Canned_Replies_Admin;
    use LinkAI_Workspace_Admin;
    use LinkAI_Customer_Records_Admin;
    use LinkAI_Online_Visitors_Admin;
    use LinkAI_Database;
    use LinkAI_Frontend_Ajax;
    use LinkAI_Admin_Ajax;
    use LinkAI_Settings_Admin;
    use LinkAI_Export;
    use LinkAI_Updater;
    use LinkAI_Parity_Features;

    private const OPTION_NAME = 'linkai_ai_customer_service_options';
    private const NONCE_ACTION = 'linkai_ai_customer_service_chat';
    private const API_ENDPOINT = 'https://api.link-ai.tech/v1/chat/completions';
    private const VERSION = '1.3.41';
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
        add_action('admin_init', [__CLASS__, 'handle_parity_admin_forms']);
        add_action('admin_post_linkai_export_customers', [__CLASS__, 'handle_export_customers_request']);
        add_action('admin_post_linkai_export_conversation', [__CLASS__, 'handle_export_conversation_request']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('wp_body_open', [__CLASS__, 'render_chat_widget'], 99);
        add_action('wp_footer', [__CLASS__, 'render_chat_widget'], 99);
        add_action('wp_head', [__CLASS__, 'render_pwa_manifest_link']);
        add_shortcode('linkai_customer_service', [__CLASS__, 'render_shortcode']);
        add_action('wp_ajax_linkai_customer_chat', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_linkai_customer_chat', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_linkai_customer_updates', [__CLASS__, 'handle_updates_request']);
        add_action('wp_ajax_nopriv_linkai_customer_updates', [__CLASS__, 'handle_updates_request']);
        add_action('wp_ajax_linkai_customer_presence', [__CLASS__, 'handle_presence_request']);
        add_action('wp_ajax_nopriv_linkai_customer_presence', [__CLASS__, 'handle_presence_request']);
        add_action('wp_ajax_linkai_customer_satisfaction', [__CLASS__, 'handle_satisfaction_request']);
        add_action('wp_ajax_nopriv_linkai_customer_satisfaction', [__CLASS__, 'handle_satisfaction_request']);
        add_action('wp_ajax_linkai_customer_trigger', [__CLASS__, 'handle_trigger_event_request']);
        add_action('wp_ajax_nopriv_linkai_customer_trigger', [__CLASS__, 'handle_trigger_event_request']);
        add_action('wp_ajax_linkai_admin_online_visitors', [__CLASS__, 'handle_admin_online_visitors_request']);
        add_action('wp_ajax_linkai_admin_visitor_path', [__CLASS__, 'handle_admin_visitor_path_request']);
        add_action('wp_ajax_linkai_admin_conversations', [__CLASS__, 'handle_admin_conversations_request']);
        add_action('wp_ajax_linkai_admin_messages', [__CLASS__, 'handle_admin_messages_request']);
        add_action('wp_ajax_linkai_admin_send_reply', [__CLASS__, 'handle_admin_send_reply_request']);
        add_action('wp_ajax_linkai_admin_toggle_ai', [__CLASS__, 'handle_admin_toggle_ai_request']);
        add_action('wp_ajax_linkai_admin_canned_replies', [__CLASS__, 'handle_admin_canned_replies_request']);
        add_action('wp_ajax_linkai_admin_assign_conversation', [__CLASS__, 'handle_admin_assign_conversation_request']);
        add_action('wp_ajax_linkai_admin_close_conversation', [__CLASS__, 'handle_admin_close_conversation_request']);
        add_action('wp_ajax_linkai_admin_update_crm', [__CLASS__, 'handle_admin_update_crm_request']);
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
        self::ensure_linkai_capabilities();
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

    public static function enqueue_admin_assets(string $hook_suffix): void
    {
        if (strpos($hook_suffix, 'linkai') === false) {
            return;
        }

        wp_enqueue_style(
            'linkai-ai-customer-service-admin',
            plugins_url('assets/admin.css', __FILE__),
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'linkai-ai-customer-service-admin-workspace',
            plugins_url('assets/admin-workspace.js', __FILE__),
            [],
            self::VERSION,
            true
        );

        wp_enqueue_script(
            'linkai-ai-customer-service-admin-visitors',
            plugins_url('assets/admin-visitors.js', __FILE__),
            [],
            self::VERSION,
            true
        );
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
        $reception_state = self::get_reception_state();

        ob_start();
        ?>
        <div class="linkai-chat <?php echo $reception_state['is_online'] ? '' : 'linkai-chat--offline'; ?>" data-welcome="<?php echo esc_attr($reception_state['message']); ?>">
            <button class="linkai-chat__toggle" type="button" aria-label="打开智能客服">
                <span class="linkai-chat__toggle-icon">AI</span>
                <span class="linkai-chat__toggle-text">在线客服</span>
            </button>
            <section class="linkai-chat__panel" aria-label="智能客服聊天窗口" hidden>
                <header class="linkai-chat__header">
                    <div>
                        <strong><?php echo esc_html($options['assistant_name']); ?></strong>
                        <span><?php echo esc_html($reception_state['label']); ?></span>
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
        $reception_state = self::get_reception_state();
        wp_localize_script('linkai-ai-customer-service', 'LinkAICustomerService', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'errorMessage' => '抱歉，智能客服暂时无法连接，请稍后再试或留下联系方式。',
            'conversationStorageKey' => 'linkai_customer_service_conversation_id',
            'visitorStorageKey' => 'linkai_customer_service_visitor_id',
            'autoRender' => $options['auto_render'] === '1',
            'assistantName' => $options['assistant_name'],
            'welcomeMessage' => $reception_state['message'],
            'receptionState' => $reception_state,
            'offlineMessage' => $options['offline_status_message'],
            'requireContact' => $options['require_contact'] === '1',
            'contactRequiredMessage' => '请先留下电话或微信，方便客服继续跟进。',
            'triggers' => self::get_active_triggers(),
        ]);
    }


}

register_activation_hook(__FILE__, ['LinkAI_AI_Customer_Service', 'activate']);
LinkAI_AI_Customer_Service::init();
