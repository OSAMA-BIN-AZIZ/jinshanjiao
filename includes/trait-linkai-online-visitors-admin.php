<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Online_Visitors_Admin
{
    public static function render_online_visitors_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        self::create_customer_tables();
        $nonce = wp_create_nonce('linkai_admin_workspace');
        ?>
        <div class="wrap linkai-visitors" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <h1>LinkAI 在线访客</h1>
            <p>展示最近 90 秒内仍在网站活动的访客，访客页面会每 20 秒上报一次在线状态；点击“轨迹”可查看该访客最近浏览页面。</p>
            <p><button type="button" class="button" data-linkai-refresh-visitors>刷新</button></p>
            <table class="widefat striped">
                <thead><tr><th>访客</th><th>当前页面</th><th>来源</th><th>IP/国家</th><th>设备/浏览器</th><th>首次访问</th><th>最后活动</th><th>轨迹</th></tr></thead>
                <tbody data-linkai-visitors><tr><td colspan="8">正在加载在线访客…</td></tr></tbody>
            </table>
            <div class="postbox" style="margin-top:16px;padding:16px;" data-linkai-visitor-path>选择访客后显示最近浏览轨迹。</div>
        </div>
        <?php
    }

}
