<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Parity_Features
{
    private static function ensure_linkai_capabilities(): void
    {
        foreach (['administrator', 'shop_manager'] as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('manage_linkai');
            }
        }
    }

    public static function handle_parity_admin_forms(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['linkai_save_trigger'])) {
            check_admin_referer('linkai_save_trigger');
            self::save_trigger_from_request();
            wp_safe_redirect(add_query_arg(['saved' => '1'], admin_url('admin.php?page=linkai-triggers')));
            exit;
        }

        if (isset($_POST['linkai_save_kb_article'])) {
            check_admin_referer('linkai_save_kb_article');
            self::save_kb_article_from_request();
            wp_safe_redirect(add_query_arg(['saved' => '1'], admin_url('admin.php?page=linkai-knowledge-base')));
            exit;
        }
    }

    public static function render_triggers_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::create_customer_tables();
        global $wpdb;
        $triggers = $wpdb->get_results('SELECT * FROM ' . self::triggers_table() . ' ORDER BY is_active DESC, id DESC LIMIT 100', ARRAY_A);
        ?>
        <div class="wrap">
            <h1>LinkAI 触发器</h1>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success"><p>触发器已保存。</p></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('linkai_save_trigger'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="linkai-trigger-title">名称</label></th><td><input id="linkai-trigger-title" name="title" class="regular-text" required></td></tr>
                    <tr><th scope="row"><label for="linkai-trigger-url">页面 URL 包含</label></th><td><input id="linkai-trigger-url" name="url_contains" class="regular-text" placeholder="/product 或留空表示全站"></td></tr>
                    <tr><th scope="row"><label for="linkai-trigger-delay">停留秒数</label></th><td><input id="linkai-trigger-delay" name="delay_seconds" type="number" min="1" max="600" value="8"></td></tr>
                    <tr><th scope="row"><label for="linkai-trigger-message">主动消息</label></th><td><textarea id="linkai-trigger-message" name="message" class="large-text" rows="3" required></textarea></td></tr>
                    <tr><th scope="row">启用</th><td><label><input name="is_active" type="checkbox" value="1" checked> 启用此触发器</label></td></tr>
                </table>
                <?php submit_button('新增触发器', 'primary', 'linkai_save_trigger'); ?>
            </form>
            <h2>已配置触发器</h2>
            <table class="widefat striped"><thead><tr><th>名称</th><th>URL 包含</th><th>延迟</th><th>状态</th><th>消息</th></tr></thead><tbody>
            <?php foreach ($triggers ?: [] as $trigger) : ?>
                <tr><td><?php echo esc_html($trigger['title']); ?></td><td><?php echo esc_html($trigger['url_contains'] ?: '全站'); ?></td><td><?php echo esc_html((string) $trigger['delay_seconds']); ?> 秒</td><td><?php echo !empty($trigger['is_active']) ? '启用' : '停用'; ?></td><td><?php echo esc_html(wp_trim_words($trigger['message'], 18)); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public static function render_knowledge_base_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::create_customer_tables();
        global $wpdb;
        $articles = $wpdb->get_results('SELECT * FROM ' . self::knowledge_base_table() . ' ORDER BY updated_at DESC LIMIT 100', ARRAY_A);
        ?>
        <div class="wrap">
            <h1>LinkAI 知识库</h1>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success"><p>知识库文章已保存。</p></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('linkai_save_kb_article'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="linkai-kb-title">标题</label></th><td><input id="linkai-kb-title" name="title" class="regular-text" required></td></tr>
                    <tr><th scope="row"><label for="linkai-kb-keywords">关键词</label></th><td><input id="linkai-kb-keywords" name="keywords" class="regular-text" placeholder="逗号分隔，例如：报价,库存,退换货"></td></tr>
                    <tr><th scope="row"><label for="linkai-kb-content">内容</label></th><td><textarea id="linkai-kb-content" name="content" class="large-text" rows="6" required></textarea></td></tr>
                    <tr><th scope="row">启用</th><td><label><input name="is_active" type="checkbox" value="1" checked> 前台与 AI 均可引用</label></td></tr>
                </table>
                <?php submit_button('新增知识库文章', 'primary', 'linkai_save_kb_article'); ?>
            </form>
            <h2>已发布文章</h2>
            <table class="widefat striped"><thead><tr><th>标题</th><th>关键词</th><th>状态</th><th>更新时间</th></tr></thead><tbody>
            <?php foreach ($articles ?: [] as $article) : ?>
                <tr><td><?php echo esc_html($article['title']); ?></td><td><?php echo esc_html($article['keywords']); ?></td><td><?php echo !empty($article['is_active']) ? '启用' : '停用'; ?></td><td><?php echo esc_html($article['updated_at']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public static function render_reports_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::create_customer_tables();
        global $wpdb;
        $customers = self::customers_table();
        $messages = self::messages_table();
        $today = gmdate('Y-m-d 00:00:00', current_time('timestamp'));
        $stats = [
            '今日会话数' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$customers} WHERE created_at >= %s", $today)),
            '未回复会话数' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$customers} WHERE unread_count > 0"),
            'AI 回复量' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$messages} WHERE role = 'assistant'"),
            '人工回复量' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$messages} WHERE role = 'human'"),
            '满意评价数' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$customers} WHERE satisfaction_score >= 5"),
            '不满意评价数' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$customers} WHERE satisfaction_score > 0 AND satisfaction_score < 5"),
        ];
        ?>
        <div class="wrap">
            <h1>LinkAI 报表</h1>
            <p>基础运营统计，用于观察会话量、未读量、AI/人工接待量和满意度。</p>
            <table class="widefat striped" style="max-width:720px"><tbody>
            <?php foreach ($stats as $label => $value) : ?>
                <tr><th scope="row"><?php echo esc_html($label); ?></th><td><strong><?php echo esc_html((string) $value); ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public static function handle_trigger_event_request(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        $trigger_id = isset($_POST['trigger_id']) ? max(0, (int) $_POST['trigger_id']) : 0;
        $current_url = isset($_POST['current_url']) ? esc_url_raw(wp_unslash($_POST['current_url'])) : '';
        if ($conversation_id === '' || $trigger_id <= 0) {
            wp_send_json_success(['recorded' => false]);
        }

        $trigger = $wpdb->get_row(
            $wpdb->prepare('SELECT id, url_contains, message FROM ' . self::triggers_table() . ' WHERE id = %d AND is_active = 1 LIMIT 1', $trigger_id),
            ARRAY_A
        );
        if (!$trigger || trim((string) $trigger['message']) === '') {
            wp_send_json_success(['recorded' => false]);
        }

        $url_contains = (string) ($trigger['url_contains'] ?? '');
        if ($url_contains !== '' && ($current_url === '' || strpos($current_url, $url_contains) === false)) {
            wp_send_json_success(['recorded' => false]);
        }

        $trace_id = 'trigger:' . (int) $trigger['id'];
        $already_recorded = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::messages_table() . ' WHERE conversation_id = %s AND trace_id = %s',
            $conversation_id,
            $trace_id
        ));
        if ($already_recorded > 0) {
            wp_send_json_success(['recorded' => false, 'duplicate' => true]);
        }

        $message = wp_trim_words(sanitize_textarea_field((string) $trigger['message']), 120, '…');
        $message_id = self::insert_staff_message($conversation_id, 'assistant', $message, $trace_id, false);
        wp_send_json_success(['recorded' => $message_id > 0]);
    }

    private static function save_trigger_from_request(): void
    {
        global $wpdb;
        self::create_customer_tables();
        $now = current_time('mysql');
        $wpdb->insert(self::triggers_table(), [
            'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'url_contains' => sanitize_text_field(wp_unslash($_POST['url_contains'] ?? '')),
            'delay_seconds' => min(600, max(1, (int) ($_POST['delay_seconds'] ?? 8))),
            'message' => sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%d', '%s', '%d', '%s', '%s']);
    }

    private static function save_kb_article_from_request(): void
    {
        global $wpdb;
        self::create_customer_tables();
        $now = current_time('mysql');
        $wpdb->insert(self::knowledge_base_table(), [
            'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'keywords' => sanitize_text_field(wp_unslash($_POST['keywords'] ?? '')),
            'content' => sanitize_textarea_field(wp_unslash($_POST['content'] ?? '')),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%d', '%s', '%s']);
    }

    private static function get_active_triggers(): array
    {
        global $wpdb;
        self::create_customer_tables();
        $rows = $wpdb->get_results('SELECT id, title, url_contains, delay_seconds, message FROM ' . self::triggers_table() . ' WHERE is_active = 1 ORDER BY id DESC LIMIT 20', ARRAY_A);
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'url_contains' => (string) $row['url_contains'],
                'delay_seconds' => (int) $row['delay_seconds'],
                'message' => (string) $row['message'],
            ];
        }, $rows ?: []);
    }

    private static function find_knowledge_base_matches(string $question, int $limit = 3): array
    {
        global $wpdb;
        self::create_customer_tables();
        $question = trim($question);
        if ($question === '') {
            return [];
        }
        $like = '%' . $wpdb->esc_like($question) . '%';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT title, content FROM ' . self::knowledge_base_table() . ' WHERE is_active = 1 AND (title LIKE %s OR keywords LIKE %s OR content LIKE %s) ORDER BY updated_at DESC LIMIT %d', $like, $like, $like, $limit), ARRAY_A);
        if (empty($rows)) {
            $wpdb->insert(self::kb_search_logs_table(), ['query_text' => $question, 'results_count' => 0, 'created_at' => current_time('mysql')], ['%s', '%d', '%s']);
            return [];
        }
        return $rows;
    }

    public static function render_pwa_manifest_link(): void
    {
        echo '<link rel="manifest" href="' . esc_url(plugins_url('assets/manifest.webmanifest', self::PLUGIN_FILE)) . '">' . "\n";
        echo '<meta name="theme-color" content="#d71920">' . "\n";
    }
}
