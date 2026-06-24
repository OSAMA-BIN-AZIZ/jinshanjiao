<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Database
{
    private static function create_customer_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $customers_table = self::customers_table();
        $messages_table = self::messages_table();
        $visitors_table = self::visitors_table();
        $pageviews_table = self::visitor_pageviews_table();
        $canned_replies_table = self::canned_replies_table();

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
            unread_count int(11) NOT NULL DEFAULT 0,
            last_customer_message_at datetime NULL,
            last_staff_reply_at datetime NULL,
            assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            assigned_at datetime NULL,
            closed_at datetime NULL,
            priority varchar(20) NOT NULL DEFAULT 'normal',
            tags varchar(255) NOT NULL DEFAULT '',
            follow_up_at datetime NULL,
            status varchar(30) NOT NULL DEFAULT 'new',
            notes text NULL,
            satisfaction_score tinyint(1) NOT NULL DEFAULT 0,
            satisfaction_comment varchar(255) NOT NULL DEFAULT '',
            satisfaction_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY conversation_id (conversation_id),
            KEY updated_at (updated_at),
            KEY status (status),
            KEY ai_paused (ai_paused),
            KEY unread_count (unread_count),
            KEY last_customer_message_at (last_customer_message_at),
            KEY assigned_user_id (assigned_user_id),
            KEY closed_at (closed_at),
            KEY priority (priority),
            KEY follow_up_at (follow_up_at),
            KEY ip_address (ip_address),
            KEY contact (contact),
            KEY satisfaction_score (satisfaction_score)
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

        dbDelta("CREATE TABLE {$visitors_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL,
            conversation_id varchar(64) NOT NULL DEFAULT '',
            current_url text NULL,
            page_title varchar(255) NOT NULL DEFAULT '',
            referrer text NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            country varchar(80) NOT NULL DEFAULT '',
            device varchar(30) NOT NULL DEFAULT '',
            browser varchar(80) NOT NULL DEFAULT '',
            user_agent text NULL,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY visitor_id (visitor_id),
            KEY conversation_id (conversation_id),
            KEY last_seen_at (last_seen_at),
            KEY ip_address (ip_address)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$pageviews_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL,
            conversation_id varchar(64) NOT NULL DEFAULT '',
            page_url text NULL,
            page_title varchar(255) NOT NULL DEFAULT '',
            referrer text NULL,
            visited_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY visitor_id (visitor_id),
            KEY conversation_id (conversation_id),
            KEY visited_at (visited_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$canned_replies_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(120) NOT NULL,
            content text NOT NULL,
            category varchar(80) NOT NULL DEFAULT '',
            sort_order int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY is_active (is_active),
            KEY sort_order (sort_order),
            KEY category (category)
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

        $upgrade_columns = [
            'unread_count' => 'ALTER TABLE ' . $customers_table . ' ADD unread_count int(11) NOT NULL DEFAULT 0 AFTER ai_paused',
            'last_customer_message_at' => 'ALTER TABLE ' . $customers_table . ' ADD last_customer_message_at datetime NULL AFTER unread_count',
            'last_staff_reply_at' => 'ALTER TABLE ' . $customers_table . ' ADD last_staff_reply_at datetime NULL AFTER last_customer_message_at',
            'assigned_user_id' => 'ALTER TABLE ' . $customers_table . ' ADD assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER last_staff_reply_at',
            'assigned_at' => 'ALTER TABLE ' . $customers_table . ' ADD assigned_at datetime NULL AFTER assigned_user_id',
            'closed_at' => 'ALTER TABLE ' . $customers_table . ' ADD closed_at datetime NULL AFTER assigned_at',
            'priority' => "ALTER TABLE " . $customers_table . " ADD priority varchar(20) NOT NULL DEFAULT 'normal' AFTER closed_at",
            'tags' => "ALTER TABLE " . $customers_table . " ADD tags varchar(255) NOT NULL DEFAULT '' AFTER priority",
            'follow_up_at' => 'ALTER TABLE ' . $customers_table . ' ADD follow_up_at datetime NULL AFTER tags',
            'satisfaction_score' => 'ALTER TABLE ' . $customers_table . ' ADD satisfaction_score tinyint(1) NOT NULL DEFAULT 0 AFTER notes',
            'satisfaction_comment' => "ALTER TABLE " . $customers_table . " ADD satisfaction_comment varchar(255) NOT NULL DEFAULT '' AFTER satisfaction_score",
            'satisfaction_at' => 'ALTER TABLE ' . $customers_table . ' ADD satisfaction_at datetime NULL AFTER satisfaction_comment',
        ];
        foreach ($upgrade_columns as $column_name => $sql) {
            $column = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $customers_table . ' LIKE %s', $column_name));
            if ($column === null) {
                $wpdb->query($sql);
            }
        }

        $upgrade_indexes = [
            'unread_count' => 'ALTER TABLE ' . $customers_table . ' ADD INDEX unread_count (unread_count)',
            'last_customer_message_at' => 'ALTER TABLE ' . $customers_table . ' ADD INDEX last_customer_message_at (last_customer_message_at)',
            'assigned_user_id' => 'ALTER TABLE ' . $customers_table . ' ADD INDEX assigned_user_id (assigned_user_id)',
            'closed_at' => 'ALTER TABLE ' . $customers_table . ' ADD INDEX closed_at (closed_at)',
            'priority' => 'ALTER TABLE ' . $customers_table . ' ADD INDEX priority (priority)',
            'follow_up_at' => 'ALTER TABLE ' . $customers_table . ' ADD INDEX follow_up_at (follow_up_at)',
            'satisfaction_score' => 'ALTER TABLE ' . $customers_table . ' ADD INDEX satisfaction_score (satisfaction_score)',
        ];
        foreach ($upgrade_indexes as $index_name => $sql) {
            $index = $wpdb->get_var($wpdb->prepare('SHOW INDEX FROM ' . $customers_table . ' WHERE Key_name = %s', $index_name));
            if ($index === null) {
                $wpdb->query($sql);
            }
        }
    }

    private static function canned_replies_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'linkai_canned_replies';
    }

    private static function visitors_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'linkai_online_visitors';
    }

    private static function visitor_pageviews_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'linkai_visitor_pageviews';
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

}
