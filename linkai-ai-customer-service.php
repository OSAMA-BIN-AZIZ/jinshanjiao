<?php
/**
 * Plugin Name: LinkAI 智能 AI 客服
 * Description: 为网站添加一个可配置的 LinkAI 智能客服悬浮聊天窗口，支持短代码与 WordPress AJAX 服务端代理。
 * Version: 1.1.0
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
    private const VERSION = '1.1.0';
    private const PLUGIN_FILE = __FILE__;

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_notices', [__CLASS__, 'render_missing_key_notice']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('wp_footer', [__CLASS__, 'render_chat_widget']);
        add_shortcode('linkai_customer_service', [__CLASS__, 'render_shortcode']);
        add_action('wp_ajax_linkai_customer_chat', [__CLASS__, 'handle_chat_request']);
        add_action('wp_ajax_nopriv_linkai_customer_chat', [__CLASS__, 'handle_chat_request']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'add_settings_link']);
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_plugin_update']);
        add_filter('plugins_api', [__CLASS__, 'render_plugin_update_info'], 20, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'rename_github_update_source'], 10, 4);
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

    public static function add_settings_link(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=linkai-ai-customer-service')),
            esc_html__('设置 API Key', 'linkai-ai-customer-service')
        );
        array_unshift($links, $settings_link);

        return $links;
    }

    public static function render_missing_key_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_settings_page = $screen && $screen->id === 'settings_page_linkai-ai-customer-service';
        $options = self::get_options();
        if (!empty($options['api_key']) || $is_settings_page) {
            return;
        }

        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('LinkAI 智能客服需要先配置 API Key 才能回复访客消息。', 'linkai-ai-customer-service'),
            esc_url(admin_url('options-general.php?page=linkai-ai-customer-service')),
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
            'update_repo_url' => isset($input['update_repo_url']) ? esc_url_raw(trim($input['update_repo_url'])) : $defaults['update_repo_url'],
            'update_branch' => isset($input['update_branch']) ? self::sanitize_update_branch($input['update_branch']) : $defaults['update_branch'],
        ];
    }

    public static function check_for_plugin_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $update = self::get_github_update_data();
        if (!$update || !version_compare($update['version'], self::VERSION, '>')) {
            return $transient;
        }

        $plugin_basename = plugin_basename(self::PLUGIN_FILE);
        $transient->response[$plugin_basename] = (object) [
            'slug' => dirname($plugin_basename),
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
        $plugin_slug = dirname(plugin_basename(self::PLUGIN_FILE));
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
            return $source;
        }

        $target = trailingslashit($remote_source) . dirname(plugin_basename(self::PLUGIN_FILE));
        if ($source !== $target && $wp_filesystem->exists($source)) {
            if ($wp_filesystem->exists($target)) {
                $wp_filesystem->delete($target, true);
            }
            if ($wp_filesystem->move($source, $target, true)) {
                return $target;
            }
        }

        return $source;
    }

    private static function get_github_update_data(): ?array
    {
        $options = self::get_options();
        $repo = self::parse_github_repo($options['update_repo_url']);
        $branch = !empty($options['update_branch']) ? $options['update_branch'] : 'main';
        if (!$repo) {
            return null;
        }

        $cache_key = 'linkai_ai_customer_service_update_' . md5($repo['owner'] . '/' . $repo['name'] . '/' . $branch);
        $cached = get_site_transient($cache_key);
        if (is_array($cached)) {
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
            'zip_url' => sprintf('https://github.com/%s/%s/archive/refs/heads/%s.zip', $repo['owner'], $repo['name'], rawurlencode($branch)),
            'changelog' => self::extract_remote_changelog($remote_plugin),
        ];
        set_site_transient($cache_key, $data, 15 * MINUTE_IN_SECONDS);

        return $data;
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
                        <th scope="row"><label for="linkai-update-repo-url">GitHub 更新仓库</label></th>
                        <td><input id="linkai-update-repo-url" name="<?php echo esc_attr(self::OPTION_NAME); ?>[update_repo_url]" type="url" class="regular-text" placeholder="https://github.com/OSAMA-BIN-AZIZ/jinshanjiao" value="<?php echo esc_attr($options['update_repo_url']); ?>" /><p class="description">可选。填写插件所在 GitHub 仓库后，WordPress 后台「插件」页面可以检测并一键更新。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkai-update-branch">更新分支</label></th>
                        <td><input id="linkai-update-branch" name="<?php echo esc_attr(self::OPTION_NAME); ?>[update_branch]" type="text" class="regular-text" value="<?php echo esc_attr($options['update_branch']); ?>" /><p class="description">默认 main。你在 GitHub 更新代码并提高插件 Version 后，WordPress 会从此分支下载 zip 包更新。</p></td>
                    </tr>
                </table>
                <?php submit_button('保存设置'); ?>
            </form>
        </div>
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
            'update_repo_url' => 'https://github.com/OSAMA-BIN-AZIZ/jinshanjiao',
            'update_branch' => 'main',
        ];
    }
}

LinkAI_AI_Customer_Service::init();
