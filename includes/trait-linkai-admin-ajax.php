<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Admin_Ajax
{
    public static function handle_admin_online_visitors_request(): void
    {
        self::verify_admin_workspace_request();

        global $wpdb;
        self::create_customer_tables();

        $visitors_table = self::visitors_table();
        $online_after = date('Y-m-d H:i:s', current_time('timestamp') - 90);
        $visitors = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$visitors_table} WHERE last_seen_at >= %s ORDER BY last_seen_at DESC LIMIT 100", $online_after),
            ARRAY_A
        );

        wp_send_json_success([
            'visitors' => array_map([__CLASS__, 'format_admin_visitor'], $visitors ?: []),
            'server_time' => current_time('mysql'),
        ]);
    }

    public static function handle_admin_visitor_path_request(): void
    {
        self::verify_admin_workspace_request();

        global $wpdb;
        self::create_customer_tables();

        $visitor_id = isset($_POST['visitor_id']) ? sanitize_key(wp_unslash($_POST['visitor_id'])) : '';
        if ($visitor_id === '') {
            wp_send_json_error(['message' => '请选择访客。'], 400);
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT visitor_id, conversation_id, page_url, page_title, referrer, visited_at FROM " . self::visitor_pageviews_table() . " WHERE visitor_id = %s ORDER BY visited_at DESC, id DESC LIMIT 50", $visitor_id),
            ARRAY_A
        );

        wp_send_json_success([
            'pageviews' => array_map([__CLASS__, 'format_admin_pageview'], $rows ?: []),
        ]);
    }

    private static function format_admin_visitor(array $visitor): array
    {
        return [
            'visitor_id' => (string) ($visitor['visitor_id'] ?? ''),
            'conversation_id' => (string) ($visitor['conversation_id'] ?? ''),
            'current_url' => (string) ($visitor['current_url'] ?? ''),
            'page_title' => (string) ($visitor['page_title'] ?? ''),
            'referrer' => (string) ($visitor['referrer'] ?? ''),
            'ip_address' => (string) ($visitor['ip_address'] ?? ''),
            'country' => (string) ($visitor['country'] ?? ''),
            'device' => (string) ($visitor['device'] ?? ''),
            'browser' => (string) ($visitor['browser'] ?? ''),
            'first_seen_at' => (string) ($visitor['first_seen_at'] ?? ''),
            'last_seen_at' => (string) ($visitor['last_seen_at'] ?? ''),
        ];
    }

    private static function format_admin_pageview(array $pageview): array
    {
        return [
            'visitor_id' => (string) ($pageview['visitor_id'] ?? ''),
            'conversation_id' => (string) ($pageview['conversation_id'] ?? ''),
            'page_url' => (string) ($pageview['page_url'] ?? ''),
            'page_title' => (string) ($pageview['page_title'] ?? ''),
            'referrer' => (string) ($pageview['referrer'] ?? ''),
            'visited_at' => (string) ($pageview['visited_at'] ?? ''),
        ];
    }

    public static function handle_admin_conversations_request(): void
    {
        self::verify_admin_workspace_request();

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $customers_table = self::customers_table();
        $customers = $wpdb->get_results("SELECT id, conversation_id, customer_name, contact, ip_address, country, device, last_message, last_reply, status, ai_paused, unread_count, last_customer_message_at, last_staff_reply_at, assigned_user_id, assigned_at, closed_at, priority, tags, follow_up_at, notes, updated_at FROM {$customers_table} ORDER BY updated_at DESC LIMIT 100", ARRAY_A);

        wp_send_json_success([
            'conversations' => array_map([__CLASS__, 'format_admin_conversation'], $customers ?: []),
            'server_time' => current_time('mysql'),
        ]);
    }

    public static function handle_admin_messages_request(): void
    {
        self::verify_admin_workspace_request();

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        if ($conversation_id === '') {
            wp_send_json_error(['message' => '请选择会话。'], 400);
        }

        $customers_table = self::customers_table();
        $messages_table = self::messages_table();
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$customers_table} WHERE conversation_id = %s", $conversation_id), ARRAY_A);
        if (!$customer) {
            wp_send_json_error(['message' => '会话不存在。'], 404);
        }

        $messages = $wpdb->get_results(
            $wpdb->prepare("SELECT id, role, content, trace_id, created_at FROM {$messages_table} WHERE conversation_id = %s ORDER BY id ASC LIMIT 300", $conversation_id),
            ARRAY_A
        );

        $wpdb->update(
            $customers_table,
            ['unread_count' => 0],
            ['conversation_id' => $conversation_id],
            ['%d'],
            ['%s']
        );
        $customer['unread_count'] = 0;

        wp_send_json_success([
            'conversation' => self::format_admin_conversation($customer),
            'messages' => array_map([__CLASS__, 'format_admin_message'], $messages ?: []),
        ]);
    }

    public static function handle_admin_send_reply_request(): void
    {
        self::verify_admin_workspace_request();

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        $reply = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        if ($conversation_id === '' || $reply === '') {
            wp_send_json_error(['message' => '请输入人工回复内容。'], 400);
        }

        $message_id = self::insert_staff_message($conversation_id, 'human', $reply, '', true);
        if ($message_id <= 0) {
            wp_send_json_error(['message' => '会话不存在，无法发送。'], 404);
        }

        wp_send_json_success([
            'message_id' => $message_id,
            'ai_paused' => true,
        ]);
    }

    public static function handle_admin_toggle_ai_request(): void
    {
        self::verify_admin_workspace_request();

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        $ai_paused = !empty($_POST['ai_paused']) ? 1 : 0;
        if ($conversation_id === '') {
            wp_send_json_error(['message' => '请选择会话。'], 400);
        }

        $updated = $wpdb->update(
            self::customers_table(),
            ['ai_paused' => $ai_paused, 'updated_at' => current_time('mysql')],
            ['conversation_id' => $conversation_id],
            ['%d', '%s'],
            ['%s']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'AI 状态更新失败。'], 500);
        }

        wp_send_json_success(['ai_paused' => (bool) $ai_paused]);
    }

    public static function handle_admin_assign_conversation_request(): void
    {
        self::verify_admin_workspace_request();

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        $assigned_user_id = isset($_POST['assigned_user_id']) ? max(0, (int) $_POST['assigned_user_id']) : 0;
        $priority = isset($_POST['priority']) ? self::sanitize_priority(wp_unslash($_POST['priority'])) : 'normal';
        if ($conversation_id === '') {
            wp_send_json_error(['message' => '请选择会话。'], 400);
        }
        if ($assigned_user_id > 0 && !get_user_by('id', $assigned_user_id)) {
            wp_send_json_error(['message' => '客服用户不存在。'], 400);
        }

        $updated = $wpdb->update(
            self::customers_table(),
            [
                'assigned_user_id' => $assigned_user_id,
                'assigned_at' => $assigned_user_id > 0 ? current_time('mysql') : null,
                'priority' => $priority,
                'updated_at' => current_time('mysql'),
            ],
            ['conversation_id' => $conversation_id],
            ['%d', '%s', '%s', '%s'],
            ['%s']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => '分配会话失败。'], 500);
        }

        wp_send_json_success(['assigned_user_id' => $assigned_user_id, 'priority' => $priority]);
    }

    public static function handle_admin_close_conversation_request(): void
    {
        self::verify_admin_workspace_request();

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        $closed = !empty($_POST['closed']);
        if ($conversation_id === '') {
            wp_send_json_error(['message' => '请选择会话。'], 400);
        }

        $updated = $wpdb->update(
            self::customers_table(),
            ['closed_at' => $closed ? current_time('mysql') : null, 'updated_at' => current_time('mysql')],
            ['conversation_id' => $conversation_id],
            ['%s', '%s'],
            ['%s']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => '会话状态更新失败。'], 500);
        }

        wp_send_json_success(['closed' => $closed]);
    }

    public static function handle_admin_update_crm_request(): void
    {
        self::verify_admin_workspace_request();

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_key(wp_unslash($_POST['conversation_id'])) : '';
        if ($conversation_id === '') {
            wp_send_json_error(['message' => '请选择会话。'], 400);
        }

        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        $tags = isset($_POST['tags']) ? self::normalize_customer_tags(wp_unslash($_POST['tags'])) : '';
        $follow_up_at = isset($_POST['follow_up_at']) ? self::sanitize_follow_up_at(wp_unslash($_POST['follow_up_at'])) : null;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $status = isset($_POST['status']) ? self::sanitize_customer_status(wp_unslash($_POST['status'])) : 'new';

        $updated = $wpdb->update(
            self::customers_table(),
            [
                'customer_name' => $customer_name,
                'contact' => $contact,
                'tags' => $tags,
                'follow_up_at' => $follow_up_at,
                'notes' => $notes,
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ],
            ['conversation_id' => $conversation_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%s']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => '客户跟进资料更新失败。'], 500);
        }

        wp_send_json_success([
            'customer_name' => $customer_name,
            'contact' => $contact,
            'tags' => $tags,
            'tag_list' => self::split_customer_tags($tags),
            'follow_up_at' => $follow_up_at ?: '',
            'status' => $status,
            'status_label' => self::customer_status_label($status),
        ]);
    }

    private static function sanitize_priority(string $priority): string
    {
        $priority = sanitize_key($priority);
        return in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : 'normal';
    }

    private static function priority_label(string $priority): string
    {
        $labels = ['low' => '低', 'normal' => '普通', 'high' => '高', 'urgent' => '紧急'];
        return $labels[$priority] ?? $labels['normal'];
    }

    private static function normalize_customer_tags(string $tags): string
    {
        $parts = preg_split('/[,，\\n]+/', $tags);
        $clean = [];
        foreach ($parts ?: [] as $part) {
            $tag = trim(sanitize_text_field($part));
            if ($tag !== '' && !in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
            if (count($clean) >= 12) {
                break;
            }
        }

        return implode(', ', $clean);
    }

    private static function split_customer_tags(string $tags): array
    {
        if ($tags === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $tags))));
    }

    private static function sanitize_follow_up_at(string $value): ?string
    {
        $value = trim(sanitize_text_field($value));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    public static function handle_admin_canned_replies_request(): void
    {
        self::verify_admin_workspace_request();

        wp_send_json_success(['replies' => self::get_active_canned_replies()]);
    }

    private static function get_active_canned_replies(): array
    {
        global $wpdb;
        self::create_customer_tables();

        $rows = $wpdb->get_results("SELECT id, title, content, category FROM " . self::canned_replies_table() . " WHERE is_active = 1 ORDER BY sort_order ASC, title ASC LIMIT 200", ARRAY_A);
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'content' => (string) $row['content'],
                'category' => (string) $row['category'],
            ];
        }, $rows ?: []);
    }

    private static function verify_admin_workspace_request(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足。'], 403);
        }

        check_ajax_referer('linkai_admin_workspace', 'nonce');
    }

    private static function format_admin_conversation(array $customer): array
    {
        return [
            'id' => (int) ($customer['id'] ?? 0),
            'conversation_id' => (string) ($customer['conversation_id'] ?? ''),
            'customer_name' => (string) ($customer['customer_name'] ?? ''),
            'contact' => (string) ($customer['contact'] ?? ''),
            'ip_address' => (string) ($customer['ip_address'] ?? ''),
            'country' => (string) ($customer['country'] ?? ''),
            'device' => (string) ($customer['device'] ?? ''),
            'last_message' => (string) ($customer['last_message'] ?? ''),
            'last_reply' => (string) ($customer['last_reply'] ?? ''),
            'status' => (string) ($customer['status'] ?? 'new'),
            'status_label' => self::customer_status_label((string) ($customer['status'] ?? 'new')),
            'ai_paused' => !empty($customer['ai_paused']),
            'unread_count' => (int) ($customer['unread_count'] ?? 0),
            'last_customer_message_at' => (string) ($customer['last_customer_message_at'] ?? ''),
            'last_staff_reply_at' => (string) ($customer['last_staff_reply_at'] ?? ''),
            'assigned_user_id' => (int) ($customer['assigned_user_id'] ?? 0),
            'assigned_user_label' => self::get_user_display_name((int) ($customer['assigned_user_id'] ?? 0)),
            'assigned_at' => (string) ($customer['assigned_at'] ?? ''),
            'closed_at' => (string) ($customer['closed_at'] ?? ''),
            'priority' => self::sanitize_priority((string) ($customer['priority'] ?? 'normal')),
            'priority_label' => self::priority_label((string) ($customer['priority'] ?? 'normal')),
            'tags' => self::normalize_customer_tags((string) ($customer['tags'] ?? '')),
            'tag_list' => self::split_customer_tags((string) ($customer['tags'] ?? '')),
            'follow_up_at' => (string) ($customer['follow_up_at'] ?? ''),
            'notes' => (string) ($customer['notes'] ?? ''),
            'updated_at' => (string) ($customer['updated_at'] ?? ''),
        ];
    }

    private static function get_user_display_name(int $user_id): string
    {
        if ($user_id <= 0) {
            return '未分配';
        }
        $user = get_user_by('id', $user_id);
        return $user ? $user->display_name : '未知客服';
    }

    private static function get_workspace_agents(): array
    {
        $users = get_users(['capability' => 'manage_options', 'fields' => ['ID', 'display_name'], 'number' => 100]);
        return array_map(static function ($user): array {
            return ['id' => (int) $user->ID, 'name' => (string) $user->display_name];
        }, $users ?: []);
    }

    private static function format_admin_message(array $message): array
    {
        $role = (string) ($message['role'] ?? 'assistant');

        return [
            'id' => (int) ($message['id'] ?? 0),
            'role' => $role,
            'role_label' => self::message_role_label($role),
            'content' => (string) ($message['content'] ?? ''),
            'trace_id' => (string) ($message['trace_id'] ?? ''),
            'created_at' => (string) ($message['created_at'] ?? ''),
        ];
    }

    private static function message_role_label(string $role): string
    {
        if ($role === 'user') {
            return '客户';
        }
        if ($role === 'human') {
            return '人工客服';
        }

        return 'AI客服';
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

}
