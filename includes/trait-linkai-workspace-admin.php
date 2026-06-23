<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Workspace_Admin
{
    public static function render_realtime_workspace_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        self::create_customer_tables();
        self::maybe_upgrade_customer_tables();
        $nonce = wp_create_nonce('linkai_admin_workspace');
        $agents = self::get_workspace_agents();
        ?>
        <div class="wrap linkai-workspace" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-agents="<?php echo esc_attr(wp_json_encode($agents)); ?>">
            <h1>LinkAI 实时工作台</h1>
            <p>左侧选择会话，中间实时查看并发送人工回复，右侧管理客户资料与 AI 接管状态。</p>
            <div class="linkai-workspace__grid">
                <aside class="linkai-workspace__sidebar">
                    <div class="linkai-workspace__toolbar">
                        <strong>会话</strong>
                        <button type="button" class="button" data-linkai-refresh>刷新</button>
                    </div>
                    <div class="linkai-workspace__filters">
                        <input type="search" class="widefat" data-linkai-filter-search placeholder="搜索姓名、联系方式、消息、标签">
                        <select data-linkai-filter-status><option value="">全部状态</option><option value="new">新客户</option><option value="contacted">已联系</option><option value="qualified">有意向</option><option value="closed">已成交/关闭</option></select>
                        <select data-linkai-filter-assignee><option value="">全部客服</option><option value="0">未分配</option></select>
                        <select data-linkai-filter-priority><option value="">全部优先级</option><option value="urgent">紧急</option><option value="high">高</option><option value="normal">普通</option><option value="low">低</option></select>
                        <label><input type="checkbox" data-linkai-filter-unread> 只看未读</label>
                    </div>
                    <div class="linkai-workspace__conversations" data-linkai-conversations>正在加载会话…</div>
                </aside>
                <main class="linkai-workspace__chat">
                    <div class="linkai-workspace__chat-header" data-linkai-chat-header>请选择左侧会话</div>
                    <div class="linkai-workspace__messages" data-linkai-messages></div>
                    <form class="linkai-workspace__reply" data-linkai-reply-form>
                        <select data-linkai-canned-replies><option value="">快捷回复…</option></select>
                        <textarea data-linkai-reply rows="3" placeholder="输入人工回复，发送后会自动暂停 AI"></textarea>
                        <button type="submit" class="button button-primary">发送人工回复</button>
                    </form>
                </main>
                <aside class="linkai-workspace__profile" data-linkai-profile>
                    <h2>客户资料</h2>
                    <p>选择会话后显示客户资料。</p>
                </aside>
            </div>
        </div>
        <?php
    }

}
