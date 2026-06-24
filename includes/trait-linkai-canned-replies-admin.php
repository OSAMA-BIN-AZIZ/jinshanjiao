<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Canned_Replies_Admin
{
    public static function render_canned_replies_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        self::create_customer_tables();
        $table = self::canned_replies_table();

        if (isset($_POST['linkai_canned_reply_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['linkai_canned_reply_nonce'])), 'linkai_save_canned_reply')) {
            $reply_id = isset($_POST['reply_id']) ? (int) $_POST['reply_id'] : 0;
            $data = [
                'title' => isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '',
                'content' => isset($_POST['content']) ? sanitize_textarea_field(wp_unslash($_POST['content'])) : '',
                'category' => isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '',
                'sort_order' => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0,
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                'updated_at' => current_time('mysql'),
            ];
            if ($data['title'] !== '' && $data['content'] !== '') {
                if ($reply_id > 0) {
                    $wpdb->update($table, $data, ['id' => $reply_id], ['%s', '%s', '%s', '%d', '%d', '%s'], ['%d']);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $wpdb->insert($table, $data, ['%s', '%s', '%s', '%d', '%d', '%s', '%s']);
                }
                wp_safe_redirect(add_query_arg(['saved' => '1'], admin_url('admin.php?page=linkai-canned-replies')));
                exit;
            }
        }

        if (isset($_POST['linkai_delete_canned_reply_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['linkai_delete_canned_reply_nonce'])), 'linkai_delete_canned_reply')) {
            $reply_id = isset($_POST['reply_id']) ? (int) $_POST['reply_id'] : 0;
            if ($reply_id > 0) {
                $wpdb->delete($table, ['id' => $reply_id], ['%d']);
                wp_safe_redirect(add_query_arg(['deleted' => '1'], admin_url('admin.php?page=linkai-canned-replies')));
                exit;
            }
        }

        $edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $editing = $edit_id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $edit_id), ARRAY_A) : null;
        $replies = $wpdb->get_results("SELECT * FROM {$table} ORDER BY sort_order ASC, title ASC LIMIT 200");
        ?>
        <div class="wrap">
            <h1>LinkAI 快捷回复</h1>
            <p>维护客服常用话术，实时工作台里可以一键插入。</p>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>快捷回复已保存。</p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p>快捷回复已删除。</p></div><?php endif; ?>
            <div style="display:grid;grid-template-columns: minmax(320px, 420px) 1fr;gap:24px;align-items:start;">
                <form method="post" class="postbox" style="padding:16px;">
                    <h2><?php echo $editing ? '编辑快捷回复' : '新增快捷回复'; ?></h2>
                    <?php wp_nonce_field('linkai_save_canned_reply', 'linkai_canned_reply_nonce'); ?>
                    <input type="hidden" name="reply_id" value="<?php echo esc_attr((string) ($editing['id'] ?? 0)); ?>">
                    <p><label><strong>标题</strong><br><input type="text" name="title" class="regular-text" value="<?php echo esc_attr($editing['title'] ?? ''); ?>" required></label></p>
                    <p><label><strong>分类</strong><br><input type="text" name="category" class="regular-text" value="<?php echo esc_attr($editing['category'] ?? ''); ?>" placeholder="例如：询价、售后、联系方式"></label></p>
                    <p><label><strong>内容</strong><br><textarea name="content" class="large-text" rows="6" required><?php echo esc_textarea($editing['content'] ?? ''); ?></textarea></label></p>
                    <p><label><strong>排序</strong><br><input type="number" name="sort_order" value="<?php echo esc_attr((string) ($editing['sort_order'] ?? 0)); ?>"></label></p>
                    <p><label><input type="checkbox" name="is_active" value="1" <?php checked((int) ($editing['is_active'] ?? 1), 1); ?>> 启用</label></p>
                    <?php submit_button($editing ? '保存修改' : '新增快捷回复'); ?>
                </form>
                <table class="widefat striped">
                    <thead><tr><th>标题</th><th>分类</th><th>内容</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php if (empty($replies)) : ?><tr><td colspan="6">暂无快捷回复。</td></tr><?php endif; ?>
                    <?php foreach ($replies as $reply) : ?>
                        <tr>
                            <td><?php echo esc_html($reply->title); ?></td>
                            <td><?php echo esc_html($reply->category ?: '-'); ?></td>
                            <td><?php echo esc_html(wp_trim_words($reply->content, 18)); ?></td>
                            <td><?php echo esc_html((string) $reply->sort_order); ?></td>
                            <td><?php echo !empty($reply->is_active) ? '启用' : '停用'; ?></td>
                            <td>
                                <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'linkai-canned-replies', 'edit' => (int) $reply->id], admin_url('admin.php'))); ?>">编辑</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('确定删除这个快捷回复吗？');">
                                    <?php wp_nonce_field('linkai_delete_canned_reply', 'linkai_delete_canned_reply_nonce'); ?>
                                    <input type="hidden" name="reply_id" value="<?php echo esc_attr((string) $reply->id); ?>">
                                    <?php submit_button('删除', 'delete small', 'submit', false); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

}
