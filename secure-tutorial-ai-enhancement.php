<?php
/**
 * Plugin Name: Jinshanjiao 教程 AI 增强
 * Description: 为安全教程页自动切换 LinkAI 为 V2rayN 技术支持模式，并支持上传教程截图 ZIP 到安全教程插件的 assets/v2rayn 目录。
 * Version: 1.0.0
 * Author: Jinshanjiao
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Jinshanjiao_Tutorial_AI_Enhancement
{
    private const SECURE_OPTIONS = 'jinshanjiao_secure_tutorial_options';
    private const LINKAI_ENDPOINT = 'https://api.link-ai.tech/v1/chat/completions';

    public static function init(): void
    {
        add_filter('http_request_args', [__CLASS__, 'inject_tutorial_prompt'], 20, 2);
        add_action('wp_footer', [__CLASS__, 'render_tutorial_ai_ui_patch'], 1000);
        add_action('admin_menu', [__CLASS__, 'add_admin_page'], 100);
        add_action('admin_post_jsd_ai_upload_assets', [__CLASS__, 'handle_assets_upload']);
    }

    private static function secure_options(): array
    {
        $options = get_option(self::SECURE_OPTIONS, []);
        return is_array($options) ? $options : [];
    }

    private static function tutorial_path(): string
    {
        $options = self::secure_options();
        return trim((string) ($options['tutorial_path'] ?? 'help/resources/windows/setup/v2rayn/7f3a9c2e4b6d'), '/');
    }

    private static function request_is_tutorial_page(): bool
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/');
        $tutorial = self::tutorial_path();
        return $path !== '' && $tutorial !== '' && hash_equals($tutorial, $path);
    }

    private static function ajax_came_from_tutorial(): bool
    {
        if (!isset($_POST['action']) || sanitize_key(wp_unslash($_POST['action'])) !== 'linkai_customer_chat') {
            return false;
        }
        $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        if ($referer === '') {
            return false;
        }
        $path = trim((string) wp_parse_url($referer, PHP_URL_PATH), '/');
        $tutorial = self::tutorial_path();
        return $path !== '' && $tutorial !== '' && hash_equals($tutorial, $path);
    }

    private static function tutorial_prompt(): string
    {
        return <<<'PROMPT'
你是本站“V2rayN 配置使用教程”的技术支持助手。当前访客正在阅读 Windows / V2rayN 教程页。

回答规则：
1. 优先且严格依据下面的本站教程内容回答，不要把汽车配件客服的知识、报价、产品信息带入本对话。
2. 如果问题超出这份教程，明确说“这份教程没有覆盖这个问题”，再建议联系人工客服；不要凭空编造设置步骤。
3. 订阅链接属于敏感凭据。提醒用户像密码一样保管，不要要求用户发送完整订阅链接、账号密码或 API Key。
4. 回答尽量简短、分步骤，并沿用教程中的菜单名称。

本站教程要点：
- V2rayN 是 Windows 平台客户端，教程写明支持 VMess 协议；需要 Microsoft .NET Framework 4.8 或更高版本。
- 下载后必须先解压到硬盘文件夹再运行，不要直接在压缩包里运行。建议放在桌面等当前用户有完整权限的位置。
- 如果提示“获取订阅内容成功”或导入成功，但节点没有真正导入/更新，或无法切换节点，教程判断常见原因是文件权限问题；退出 V2rayN 后以管理员权限启动 v2rayn.exe。
- 订阅链接：仪表盘 → 一键订阅 → 复制订阅地址。订阅链接与账号绑定，相当于账号密码，应妥善保管。
- 添加订阅：启动 v2rayn.exe → 左上角“订阅” → “订阅设置” → “添加” → 粘贴订阅链接 → 勾选“启用” → “确定”。
- 更新节点：订阅 → 更新订阅（不通过代理）。教程说明本站订阅链接正常可直连，因此一般使用“不通过代理”；如果已经能正常访问外网，也可以使用“通过代理”。
- 订阅链接不能更新时，可从用户中心复制全部 V2ray 节点 URL（不是订阅链接），在主界面右键 → 从剪贴板导入批量 URL（Ctrl+V）；SS 节点同理，教程注明付费才有 SS 节点。
- 开始使用：右键任务栏托盘区 V2rayN 图标 → 系统代理 → 自动配置系统代理。教程说明 IE、Edge、Chrome 等使用系统代理的浏览器随后可使用。
- 国内外分流：教程版本已配置国内外分流，国内网站直连。设置 → 路由设置。可添加 geosite:cn、baidu.com、163.com 等直连域名，每行一个，按教程示例使用英文逗号。
- 全局代理：在路由设置里把直连的 Domain 和 IP 删除（先备份），留空即变成教程所述的全局代理模式；填回后恢复分流。
- 三种代理选项：“清除系统代理”=禁止使用 Windows 系统代理；“自动配置系统代理”=设置使用 V2rayN 的代理；“不改变系统代理”=保持 Windows 原设置不变。
- Core 错误：教程原文认为可能是安全软件删除了 V2rayN 文件夹里的 xray.exe。教程给出的处理办法是从压缩包重新解压 xray.exe 放回 V2rayN 文件夹；程序包中必须有 xray.exe 才能工作。
PROMPT;
    }

    public static function inject_tutorial_prompt(array $args, string $url): array
    {
        if (strpos($url, self::LINKAI_ENDPOINT) !== 0 || !self::ajax_came_from_tutorial()) {
            return $args;
        }
        if (empty($args['body']) || !is_string($args['body'])) {
            return $args;
        }
        $body = json_decode($args['body'], true);
        if (!is_array($body) || !isset($body['messages']) || !is_array($body['messages'])) {
            return $args;
        }
        $messages = [];
        foreach ($body['messages'] as $message) {
            if (is_array($message) && ($message['role'] ?? '') === 'system') {
                continue;
            }
            $messages[] = $message;
        }
        array_unshift($messages, ['role' => 'system', 'content' => self::tutorial_prompt()]);
        $body['messages'] = $messages;
        $args['body'] = wp_json_encode($body);
        return $args;
    }

    public static function render_tutorial_ai_ui_patch(): void
    {
        if (!self::request_is_tutorial_page()) {
            return;
        }
        ?>
        <script>
        window.addEventListener('load', function () {
            var widget = document.querySelector('.linkai-chat');
            if (!widget) return;
            widget.dataset.welcome = '您好，我是 V2rayN 教程技术助手。您可以直接告诉我卡在哪一步。';
            var title = widget.querySelector('.linkai-chat__header strong');
            if (title) title.textContent = 'V2rayN 教程助手';
            var toggle = widget.querySelector('.linkai-chat__toggle-text');
            if (toggle) toggle.textContent = '教程助手';
            var input = widget.querySelector('.linkai-chat__input');
            if (input) input.placeholder = '请输入 V2rayN 配置问题…';
            var messages = widget.querySelector('.linkai-chat__messages');
            var first = messages ? messages.querySelector('.linkai-chat__message--assistant') : null;
            if (first && messages.children.length === 1) first.textContent = widget.dataset.welcome;
        });
        </script>
        <?php
    }

    private static function secure_plugin_dir(): string
    {
        $candidates = [
            WP_PLUGIN_DIR . '/jinshanjiao-main',
            WP_PLUGIN_DIR . '/jinshanjiao-secure-tutorial',
            dirname(__FILE__),
        ];
        foreach ($candidates as $dir) {
            if (is_file(trailingslashit($dir) . 'secure-tutorial-download.php')) {
                return untrailingslashit($dir);
            }
        }
        return dirname(__FILE__);
    }

    private static function assets_dir(): string
    {
        return trailingslashit(self::secure_plugin_dir()) . 'assets/v2rayn';
    }

    private static function assets_ready(): bool
    {
        $dir = self::assets_dir();
        for ($i = 1; $i <= 10; $i++) {
            if (!is_file(trailingslashit($dir) . 'image' . $i . '.webp')) {
                return false;
            }
        }
        return true;
    }

    public static function add_admin_page(): void
    {
        add_submenu_page(
            'jinshanjiao-secure-tutorial',
            '教程 AI 与截图',
            'AI 与截图',
            'manage_options',
            'jinshanjiao-tutorial-ai-assets',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) return;
        $updated = isset($_GET['updated']) && $_GET['updated'] === '1';
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        ?>
        <div class="wrap">
            <h1>教程 AI 与截图</h1>
            <?php if ($updated) : ?><div class="notice notice-success"><p>10 张教程截图已安装。</p></div><?php endif; ?>
            <?php if ($error !== '') : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:900px;margin:18px 0 24px"><tbody>
                <tr><th style="width:220px">LinkAI 客服</th><td><?php echo class_exists('LinkAI_AI_Customer_Service') ? '<strong style="color:#198754">已检测到</strong>' : '<strong style="color:#b32d2e">未检测到</strong>'; ?></td></tr>
                <tr><th>教程专用 AI</th><td><strong style="color:#198754">已启用</strong>（仅在隐藏教程路径生效）</td></tr>
                <tr><th>教程截图</th><td><?php echo self::assets_ready() ? '<strong style="color:#198754">10 张已就绪</strong>' : '<strong style="color:#b32d2e">尚未安装</strong>'; ?></td></tr>
                <tr><th>截图目录</th><td><code><?php echo esc_html(self::assets_dir()); ?></code></td></tr>
            </tbody></table>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="jsd_ai_upload_assets">
                <?php wp_nonce_field('jsd_ai_upload_assets'); ?>
                <p><input type="file" name="assets_zip" accept=".zip,application/zip" required></p>
                <p class="description">请选择 v2rayn-tutorial-images.zip。只会读取 image1.webp 到 image10.webp。</p>
                <?php submit_button('安装教程截图'); ?>
            </form>
        </div>
        <?php
    }

    private static function redirect_admin(string $error = ''): void
    {
        $args = ['page' => 'jinshanjiao-tutorial-ai-assets'];
        if ($error !== '') $args['error'] = $error; else $args['updated'] = '1';
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function handle_assets_upload(): void
    {
        if (!current_user_can('manage_options')) wp_die('无权限。');
        check_admin_referer('jsd_ai_upload_assets');
        if (empty($_FILES['assets_zip']['name'])) self::redirect_admin('请选择截图 ZIP。');
        if (!class_exists('ZipArchive')) self::redirect_admin('服务器没有启用 PHP ZipArchive。');
        $file = $_FILES['assets_zip'];
        if ((int) $file['error'] !== UPLOAD_ERR_OK) self::redirect_admin('上传失败，错误代码：' . (int) $file['error']);
        if (strtolower(pathinfo(sanitize_file_name((string) $file['name']), PATHINFO_EXTENSION)) !== 'zip') self::redirect_admin('必须上传 ZIP 文件。');
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) self::redirect_admin('上传临时文件无效。');

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) self::redirect_admin('ZIP 文件无法打开。');
        $dir = self::assets_dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $zip->close();
            self::redirect_admin('无法创建截图目录，请检查插件目录写入权限。');
        }
        $count = 0;
        for ($i = 1; $i <= 10; $i++) {
            $wanted = 'image' . $i . '.webp';
            $index = false;
            for ($j = 0; $j < $zip->numFiles; $j++) {
                $entry = $zip->getNameIndex($j);
                if ($entry !== false && basename($entry) === $wanted) {
                    $index = $j;
                    break;
                }
            }
            if ($index === false) continue;
            $stat = $zip->statIndex($index);
            if (!$stat || ($stat['size'] ?? 0) > 2 * 1024 * 1024) continue;
            $bytes = $zip->getFromIndex($index);
            if ($bytes === false || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') continue;
            if (@file_put_contents(trailingslashit($dir) . $wanted, $bytes, LOCK_EX) !== false) $count++;
        }
        $zip->close();
        if ($count !== 10) self::redirect_admin('只识别到 ' . $count . ' 张有效图片，需要 image1.webp 到 image10.webp 共 10 张。');
        self::redirect_admin();
    }
}

Jinshanjiao_Tutorial_AI_Enhancement::init();
