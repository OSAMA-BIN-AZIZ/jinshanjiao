<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Frontend_Ajax
{
    public static function handle_presence_request(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        global $wpdb;
        self::create_customer_tables();

        $visitor_id = isset($_POST['visitor_id']) ? sanitize_key(wp_unslash($_POST['visitor_id'])) : '';
        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        $current_url = isset($_POST['current_url']) ? esc_url_raw(wp_unslash($_POST['current_url'])) : '';
        $page_title = isset($_POST['page_title']) ? sanitize_text_field(wp_unslash($_POST['page_title'])) : '';
        $referrer = isset($_POST['referrer']) ? esc_url_raw(wp_unslash($_POST['referrer'])) : '';
        if ($visitor_id === '') {
            $visitor_id = wp_generate_uuid4();
        }

        $now = current_time('mysql');
        $visitors_table = self::visitors_table();
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, current_url FROM {$visitors_table} WHERE visitor_id = %s", $visitor_id), ARRAY_A);
        $data = [
            'conversation_id' => $conversation_id,
            'current_url' => $current_url,
            'page_title' => $page_title,
            'referrer' => $referrer,
            'ip_address' => self::get_customer_ip(),
            'country' => self::get_customer_country(),
            'device' => self::get_customer_device(),
            'browser' => self::get_customer_browser(),
            'user_agent' => self::get_customer_user_agent(),
            'last_seen_at' => $now,
        ];

        if ($existing) {
            $wpdb->update($visitors_table, $data, ['id' => (int) $existing['id']], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], ['%d']);
        } else {
            $data['visitor_id'] = $visitor_id;
            $data['first_seen_at'] = $now;
            $wpdb->insert($visitors_table, $data, ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        }

        if ($current_url !== '' && (!$existing || (string) ($existing['current_url'] ?? '') !== $current_url)) {
            $wpdb->insert(
                self::visitor_pageviews_table(),
                [
                    'visitor_id' => $visitor_id,
                    'conversation_id' => $conversation_id,
                    'page_url' => $current_url,
                    'page_title' => $page_title,
                    'referrer' => $referrer,
                    'visited_at' => $now,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s']
            );
        }

        wp_send_json_success(['visitor_id' => $visitor_id]);
    }


    public static function handle_satisfaction_request(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        $score = isset($_POST['score']) ? (int) $_POST['score'] : 0;
        $comment = isset($_POST['comment']) ? sanitize_text_field(wp_unslash($_POST['comment'])) : '';
        if ($conversation_id === '' || !in_array($score, [1, 5], true)) {
            wp_send_json_error(['message' => '评价参数无效。'], 400);
        }

        $updated = $wpdb->update(
            self::customers_table(),
            [
                'satisfaction_score' => $score,
                'satisfaction_comment' => $comment,
                'satisfaction_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['conversation_id' => $conversation_id],
            ['%d', '%s', '%s', '%s'],
            ['%s']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => '评价保存失败。'], 500);
        }

        wp_send_json_success(['message' => '感谢您的反馈。']);
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
            'reception_state' => self::get_reception_state(),
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
        if ($options['require_contact'] === '1' && $contact === '') {
            wp_send_json_error(['message' => '请先留下电话或微信，方便客服继续跟进。'], 400);
        }

        $reception_state = self::get_reception_state();
        if (empty($reception_state['is_online'])) {
            $pause_state = self::record_customer_question($conversation_id, $customer_name, $contact, $message);
            wp_send_json_success([
                'reply' => $reception_state['message'],
                'conversation_id' => $conversation_id,
                'trace_id' => '',
                'message_id' => $pause_state['message_id'],
                'ai_paused' => true,
                'offline' => true,
                'reception_state' => $reception_state,
                'human_takeover_timeout' => (int) $options['human_takeover_timeout'],
                'suggested_questions' => [],
            ]);
        }

        if (empty($options['api_key'])) {
            wp_send_json_error(['message' => '请先在后台配置 LinkAI API Key。'], 400);
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
                'reception_state' => $reception_state,
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
            'reception_state' => $reception_state,
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
                    'unread_count' => (int) ($customer->unread_count ?? 0) + 1,
                    'last_customer_message_at' => $now,
                    'updated_at' => $now,
                ],
                ['id' => $customer_id],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
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
                    'unread_count' => 1,
                    'last_customer_message_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
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
        self::maybe_send_new_message_notification($conversation_id, $customer_name, $contact, $message);

        $pause_state = self::resolve_ai_pause_state($conversation_id, !empty($customer->ai_paused));

        return ['message_id' => $message_id, 'ai_paused' => $pause_state['ai_paused'], 'pause_expired' => $pause_state['pause_expired']];
    }

    private static function maybe_send_new_message_notification(string $conversation_id, string $customer_name, string $contact, string $message): void
    {
        $options = self::get_options();
        if (($options['notify_new_messages'] ?? '0') !== '1') {
            return;
        }

        $to = sanitize_email($options['notification_email'] ?? get_option('admin_email'));
        if ($to === '' || !is_email($to)) {
            return;
        }

        $display_name = $customer_name !== '' ? $customer_name : ($contact !== '' ? $contact : '访客');
        $workspace_url = admin_url('admin.php?page=linkai-realtime-workspace');
        $subject = sprintf('[LinkAI] 新客户消息：%s', wp_trim_words($display_name, 8, ''));
        $body = implode("\n", [
            'LinkAI 收到一条新的客户消息，请及时处理。',
            '',
            '客户：' . $display_name,
            '联系方式：' . ($contact !== '' ? $contact : '未填写'),
            '会话 ID：' . $conversation_id,
            '',
            '消息内容：',
            wp_strip_all_tags($message),
            '',
            '打开实时工作台：',
            esc_url_raw($workspace_url),
        ]);

        wp_mail($to, $subject, $body);
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
                'last_staff_reply_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => (int) $customer->id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );

        return $message_id;
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

    private static function get_customer_browser(): string
    {
        $user_agent = strtolower(self::get_customer_user_agent());
        if ($user_agent === '') {
            return '';
        }
        if (strpos($user_agent, 'edg/') !== false) {
            return 'Edge';
        }
        if (strpos($user_agent, 'chrome') !== false || strpos($user_agent, 'crios') !== false) {
            return 'Chrome';
        }
        if (strpos($user_agent, 'firefox') !== false || strpos($user_agent, 'fxios') !== false) {
            return 'Firefox';
        }
        if (strpos($user_agent, 'safari') !== false) {
            return 'Safari';
        }

        return '其他';
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

}
