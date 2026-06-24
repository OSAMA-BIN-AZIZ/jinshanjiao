<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Export
{
    public static function handle_export_customers_request(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足。');
        }
        check_admin_referer('linkai_export_customers');

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $customers = $wpdb->get_results("SELECT conversation_id, customer_name, contact, department, status, priority, tags, follow_up_at, assigned_user_id, ai_paused, unread_count, last_message, last_reply, ip_address, country, device, created_at, updated_at FROM " . self::customers_table() . " ORDER BY updated_at DESC LIMIT 5000", ARRAY_A);

        self::send_csv_headers('linkai-customers-' . gmdate('Ymd-His') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['会话ID', '姓名', '联系方式', '咨询部门', '跟进状态', '优先级', '标签', '下次跟进', '负责客服', 'AI暂停', '未读数', '最后咨询', '最后回复', 'IP', '国家', '设备', '创建时间', '更新时间']);
        foreach ($customers ?: [] as $customer) {
            fputcsv($output, [
                $customer['conversation_id'] ?? '',
                $customer['customer_name'] ?? '',
                $customer['contact'] ?? '',
                $customer['department'] ?? '',
                self::customer_status_label((string) ($customer['status'] ?? 'new')),
                self::priority_label((string) ($customer['priority'] ?? 'normal')),
                $customer['tags'] ?? '',
                $customer['follow_up_at'] ?? '',
                self::get_user_display_name((int) ($customer['assigned_user_id'] ?? 0)),
                !empty($customer['ai_paused']) ? '是' : '否',
                (int) ($customer['unread_count'] ?? 0),
                $customer['last_message'] ?? '',
                $customer['last_reply'] ?? '',
                $customer['ip_address'] ?? '',
                $customer['country'] ?? '',
                $customer['device'] ?? '',
                $customer['created_at'] ?? '',
                $customer['updated_at'] ?? '',
            ]);
        }
        fclose($output);
        exit;
    }

    public static function handle_export_conversation_request(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足。');
        }
        check_admin_referer('linkai_export_conversation');

        $conversation_id = isset($_GET['conversation_id']) ? sanitize_key(wp_unslash($_GET['conversation_id'])) : '';
        if ($conversation_id === '') {
            wp_die('请选择会话。');
        }

        global $wpdb;
        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();

        $messages = $wpdb->get_results(
            $wpdb->prepare("SELECT role, content, trace_id, created_at FROM " . self::messages_table() . " WHERE conversation_id = %s ORDER BY id ASC", $conversation_id),
            ARRAY_A
        );

        self::send_csv_headers('linkai-conversation-' . $conversation_id . '-' . gmdate('Ymd-His') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['会话ID', '角色', '内容', 'Trace ID', '时间']);
        foreach ($messages ?: [] as $message) {
            fputcsv($output, [
                $conversation_id,
                self::message_role_label((string) ($message['role'] ?? 'assistant')),
                $message['content'] ?? '',
                $message['trace_id'] ?? '',
                $message['created_at'] ?? '',
            ]);
        }
        fclose($output);
        exit;
    }

    private static function send_csv_headers(string $filename): void
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        echo "\xEF\xBB\xBF";
    }

}
