<?php
/**
 * Plugin Name: LinkAI 智能 AI 客服
 * Description: 为网站添加一个可配置的 LinkAI 智能客服悬浮聊天窗口，支持短代码与 WordPress AJAX 服务端代理。
 * Version: 1.0.0
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
    private const VERSION = '1.0.0';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('wp_footer', [__CLASS__, 'render_chat_widget']);
        add_shortcode('linkai_customer_service', [__CLASS__, 'render_shortcode']);
        add_action('wp_ajax_linkai_customer_chat', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_linkai_customer_chat', [__CLASS__, 'handle_chat_request']);
    }

    public static function add_settings_page(): void
    {
        add_options_page(
            'LinkAI 智能客服',
            'LinkAI 智能客服',
            'manage_options',
            'linkai-ai-customer-service',
            [__CLASS__, 'render_settings_page']
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

        return [
            'api_key' => isset($input['api_key']) ? sanitize_text_field($input['api_key']) : $defaults['api_key'],
            'app_code' => isset($input['app_code']) ? sanitize_text_field($input['app_code']) : $defaults['app_code'],
            'model' => isset($input['model']) ? sanitize_text_field($input['model']) : $defaults['model'],
            'temperature' => isset($input['temperature']) ? min(1, max(0, (float) $input['temperature'])) : $defaults['temperature'],
            'assistant_name' => isset($input['assistant_name']) ? sanitize_text_field($input['assistant_name']) : $defaults['assistant_name'],
            'welcome_message' => isset($input['welcome_message']) ? sanitize_textarea_field($input['welcome_message']) : $defaults['welcome_message'],
            'system_prompt' => isset($input['system_prompt']) ? sanitize_textarea_field($input['system_prompt']) : $defaults['system_prompt'],
            'auto_render' => !empty($input['auto_render']) ? '1' : '0',
        ];
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
    }

    public static function render_chat_widget(): void
    {
        $options = self::get_options();
        if ($options['auto_render'] !== '1') {
            return;
        }

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
                    <textarea class="linkai-chat__input" name="message" rows="1" placeholder="请输入您的问题，例如：你们有哪些汽车配件？" required></textarea>
                    <button class="linkai-chat__send" type="submit">发送</button>
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
        wp_localize_script('linkai-ai-customer-service', 'LinkAICustomerService', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'errorMessage' => '抱歉，智能客服暂时无法连接，请稍后再试或留下联系方式。',
        ]);
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

        wp_send_json_success([
            'reply' => wp_kses_post($reply),
            'trace_id' => $data['trace_id'] ?? '',
            'suggested_questions' => $data['suggested_questions'] ?? [],
        ]);
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
            <form method="post" action="options.php">
                <?php settings_fields('linkai_ai_customer_service'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="linkai-api-key">API Key</label></th>
                        <td><input id="linkai-api-key" name="<?php echo esc_attr(self::OPTION_NAME); ?>[api_key]" type="password" class="regular-text" value="<?php echo esc_attr($options['api_key']); ?>" autocomplete="off" /></td>
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
                </table>
                <?php submit_button('保存设置'); ?>
            </form>
        </div>
        <?php
    }

    private static function get_options(): array
    {
        return wp_parse_args(get_option(self::OPTION_NAME, []), self::default_options());
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
        ];
    }
}

LinkAI_AI_Customer_Service::init();
