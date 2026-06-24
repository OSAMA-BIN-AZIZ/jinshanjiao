<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Customer_Records_Admin
{
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
                        'tags' => isset($_POST['tags']) ? self::normalize_customer_tags(wp_unslash($_POST['tags'])) : '',
                        'follow_up_at' => isset($_POST['follow_up_at']) ? self::sanitize_follow_up_at(wp_unslash($_POST['follow_up_at'])) : null,
                        'notes' => isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '',
                        'updated_at' => current_time('mysql'),
                    ],
                    ['conversation_id' => $posted_conversation_id],
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
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
        $export_customers_url = wp_nonce_url(admin_url('admin-post.php?action=linkai_export_customers'), 'linkai_export_customers');
        $export_conversation_url = $selected ? wp_nonce_url(admin_url('admin-post.php?action=linkai_export_conversation&conversation_id=' . rawurlencode($selected->conversation_id)), 'linkai_export_conversation') : '';
        ?>
        <div class="wrap">
            <h1>LinkAI 客户管理</h1>
            <p>这里会保存访客在智能客服中留下的姓名、电话/微信，以及完整聊天记录；人工主动回复会自动暂停该会话的 AI 回复，避免客户同时收到 AI 与人工的重复答复。</p>
            <p>
                <a class="button" href="<?php echo esc_url($export_customers_url); ?>">导出客户 CSV</a>
                <?php if ($selected && $export_conversation_url !== '') : ?>
                    <a class="button" href="<?php echo esc_url($export_conversation_url); ?>">导出当前会话记录 CSV</a>
                <?php endif; ?>
            </p>
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
                            <p><strong>咨询部门：</strong><?php echo esc_html($selected->department ?? '未选择'); ?></p>
                            <p><label><strong>跟进状态：</strong><br><select name="status"><?php foreach (self::customer_statuses() as $status_key => $status_label) : ?><option value="<?php echo esc_attr($status_key); ?>" <?php selected($selected->status ?? 'new', $status_key); ?>><?php echo esc_html($status_label); ?></option><?php endforeach; ?></select></label></p>
                            <p><label><strong>客户标签：</strong><br><input type="text" class="regular-text" name="tags" value="<?php echo esc_attr($selected->tags ?? ''); ?>" placeholder="高意向, 配件咨询, 已报价"></label></p>
                            <p><label><strong>下次跟进时间：</strong><br><input type="datetime-local" name="follow_up_at" value="<?php echo esc_attr(!empty($selected->follow_up_at) ? date('Y-m-d\TH:i', strtotime($selected->follow_up_at)) : ''); ?>"></label></p>
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



}
