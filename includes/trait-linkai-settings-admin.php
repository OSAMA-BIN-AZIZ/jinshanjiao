<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Settings_Admin
{
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
            'LinkAI 实时工作台',
            '实时工作台',
            'manage_options',
            'linkai-realtime-workspace',
            [__CLASS__, 'render_realtime_workspace_page']
        );

        add_submenu_page(
            'linkai-ai-customer-service',
            'LinkAI 在线访客',
            '在线访客',
            'manage_options',
            'linkai-online-visitors',
            [__CLASS__, 'render_online_visitors_page']
        );

        add_submenu_page(
            'linkai-ai-customer-service',
            'LinkAI 快捷回复',
            '快捷回复',
            'manage_options',
            'linkai-canned-replies',
            [__CLASS__, 'render_canned_replies_page']
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

    private static function realtime_workspace_page_url(): string
    {
        return admin_url('admin.php?page=linkai-realtime-workspace');
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
        $workspace_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::realtime_workspace_page_url()),
            esc_html__('实时工作台', 'linkai-ai-customer-service')
        );
        array_unshift($links, $customers_link, $workspace_link, $settings_link);

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
            'require_contact' => !empty($input['require_contact']) ? '1' : '0',
            'notify_new_messages' => !empty($input['notify_new_messages']) ? '1' : '0',
            'notification_email' => !empty($input['notification_email']) && is_email($input['notification_email']) ? sanitize_email($input['notification_email']) : $defaults['notification_email'],
            'human_takeover_timeout' => isset($input['human_takeover_timeout']) ? min(1440, max(0, (int) $input['human_takeover_timeout'])) : $defaults['human_takeover_timeout'],
            'update_repo_url' => isset($input['update_repo_url']) ? esc_url_raw(trim($input['update_repo_url'])) : $defaults['update_repo_url'],
            'update_branch' => isset($input['update_branch']) ? self::sanitize_update_branch($input['update_branch']) : $defaults['update_branch'],
        ];
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
                        <th scope="row">访客联系信息</th>
                        <td><label><input name="<?php echo esc_attr(self::OPTION_NAME); ?>[require_contact]" type="checkbox" value="1" <?php checked($options['require_contact'], '1'); ?> /> 发送消息前必须填写电话/微信</label><p class="description">开启后，访客未填写联系方式时前端和服务端都会阻止发送，便于人工客服后续跟进。</p></td>
                    </tr>
                    <tr>
                        <th scope="row">新消息邮件通知</th>
                        <td>
                            <label><input name="<?php echo esc_attr(self::OPTION_NAME); ?>[notify_new_messages]" type="checkbox" value="1" <?php checked($options['notify_new_messages'], '1'); ?> /> 客户发送新消息时发送邮件通知</label>
                            <p><input id="linkai-notification-email" name="<?php echo esc_attr(self::OPTION_NAME); ?>[notification_email]" type="email" class="regular-text" value="<?php echo esc_attr($options['notification_email']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" /></p>
                            <p class="description">用于补齐类似 tawk.to 的基础提醒能力。服务器需配置可用的 WordPress 邮件发送能力；如果没有收到邮件，请检查 SMTP/主机邮件配置。</p>
                        </td>
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
            'require_contact' => '0',
            'notify_new_messages' => '0',
            'notification_email' => (string) get_option('admin_email'),
            'human_takeover_timeout' => 3,
            'update_repo_url' => 'https://github.com/OSAMA-BIN-AZIZ/jinshanjiao',
            'update_branch' => 'main',
        ];
    }

}
