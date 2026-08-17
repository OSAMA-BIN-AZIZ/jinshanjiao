<?php
/**
 * Plugin Name: Jinshanjiao 安全教程与下载
 * Description: 在主域名下提供隐藏教程页面、每次下载密码验证、60 秒一次性下载链接，并与现有 LinkAI 客服共存。
 * Version: 1.0.0
 * Author: Jinshanjiao
 * License: GPL-2.0-or-later
 * Text Domain: jinshanjiao-secure-tutorial
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Jinshanjiao_Secure_Tutorial_Download
{
    private const OPTION_NAME = 'jinshanjiao_secure_tutorial_options';
    private const PAGE_QUERY_VAR = 'jsd_tutorial';
    private const DOWNLOAD_QUERY_VAR = 'jsd_download';
    private const TOKEN_TTL = 60;

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'register_rewrite_rule']);
        add_filter('query_vars', [__CLASS__, 'register_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_frontend_requests'], 0);
        add_action('wp_ajax_jsd_verify_download_password', [__CLASS__, 'ajax_verify_download_password']);
        add_action('wp_ajax_nopriv_jsd_verify_download_password', [__CLASS__, 'ajax_verify_download_password']);
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_post_jsd_save_settings', [__CLASS__, 'handle_save_settings']);
    }

    public static function activate(): void
    {
        self::ensure_private_directory();
        self::register_rewrite_rule();
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules(false);
    }

    private static function defaults(): array
    {
        return [
            'tutorial_path' => 'help/resources/windows/setup/v2rayn/7f3a9c2e4b6d',
            'password_hash' => '',
            'download_file' => '',
            'download_name' => 'v2rayn.rar',
        ];
    }

    private static function get_options(): array
    {
        $saved = get_option(self::OPTION_NAME, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function register_rewrite_rule(): void
    {
        $options = self::get_options();
        $path = trim((string) $options['tutorial_path'], '/');
        if ($path === '') {
            return;
        }
        add_rewrite_rule('^' . preg_quote($path, '/') . '/?$', 'index.php?' . self::PAGE_QUERY_VAR . '=1', 'top');
    }

    public static function register_query_vars(array $vars): array
    {
        $vars[] = self::PAGE_QUERY_VAR;
        $vars[] = self::DOWNLOAD_QUERY_VAR;
        return $vars;
    }

    public static function handle_frontend_requests(): void
    {
        if (!empty($_GET[self::DOWNLOAD_QUERY_VAR])) {
            self::serve_download(sanitize_text_field(wp_unslash($_GET[self::DOWNLOAD_QUERY_VAR])));
        }
        if ((string) get_query_var(self::PAGE_QUERY_VAR) !== '1') {
            return;
        }
        self::render_tutorial_page();
        exit;
    }

    private static function private_directory(): string
    {
        $outside_webroot = trailingslashit(dirname(ABSPATH)) . 'jinshanjiao-private-downloads';
        if (is_dir($outside_webroot) || @wp_mkdir_p($outside_webroot)) {
            if (is_writable($outside_webroot)) {
                return $outside_webroot;
            }
        }
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'jinshanjiao-private-downloads';
    }

    private static function ensure_private_directory(): string
    {
        $dir = self::private_directory();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (is_dir($dir)) {
            $htaccess = trailingslashit($dir) . '.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            }
            $index = trailingslashit($dir) . 'index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, "<?php\nhttp_response_code(403);\nexit;\n");
            }
        }
        return $dir;
    }

    public static function add_admin_menu(): void
    {
        add_menu_page('安全教程与下载', '教程下载', 'manage_options', 'jinshanjiao-secure-tutorial', [__CLASS__, 'render_settings_page'], 'dashicons-lock', 59);
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $options = self::get_options();
        $tutorial_url = home_url('/' . trim($options['tutorial_path'], '/') . '/');
        $private_dir = self::ensure_private_directory();
        $current_file = self::resolve_download_file($options);
        $saved = isset($_GET['updated']) && $_GET['updated'] === '1';
        $error = isset($_GET['jsd_error']) ? sanitize_text_field(wp_unslash($_GET['jsd_error'])) : '';
        ?>
        <div class="wrap">
            <h1>Jinshanjiao 安全教程与下载</h1>
            <?php if ($saved) : ?><div class="notice notice-success is-dismissible"><p>设置已保存。</p></div><?php endif; ?>
            <?php if ($error !== '') : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <p>教程页面使用隐藏路径直接访问；下载文件每次都要验证密码，验证后只生成一个 60 秒有效、仅可使用一次的下载链接。</p>
            <table class="widefat striped" style="max-width:1000px;margin:16px 0 24px;"><tbody>
                <tr><th style="width:220px;">当前教程地址</th><td><a href="<?php echo esc_url($tutorial_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($tutorial_url); ?></a></td></tr>
                <tr><th>私有文件目录</th><td><code><?php echo esc_html($private_dir); ?></code></td></tr>
                <tr><th>当前下载文件</th><td><?php echo $current_file ? '<code>' . esc_html(basename($current_file)) . '</code>' : '<strong>尚未上传</strong>'; ?></td></tr>
                <tr><th>下载密码</th><td><?php echo $options['password_hash'] ? '<strong style="color:#198754;">已设置</strong>' : '<strong style="color:#b32d2e;">尚未设置</strong>'; ?></td></tr>
            </tbody></table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="max-width:1000px;">
                <input type="hidden" name="action" value="jsd_save_settings"><?php wp_nonce_field('jsd_save_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="jsd_tutorial_path">隐藏教程路径</label></th><td><input id="jsd_tutorial_path" name="tutorial_path" type="text" class="regular-text" style="width:100%;max-width:680px;" value="<?php echo esc_attr($options['tutorial_path']); ?>"><p class="description">只填写域名后面的路径，不要填写 https:// 或域名。建议保持长且随机。</p></td></tr>
                    <tr><th scope="row"><label for="jsd_password">下载密码</label></th><td><input id="jsd_password" name="download_password" type="password" class="regular-text" autocomplete="new-password"><p class="description">留空表示保留旧密码。密码只保存哈希，不保存明文。</p></td></tr>
                    <tr><th scope="row"><label for="jsd_download_name">下载时显示的文件名</label></th><td><input id="jsd_download_name" name="download_name" type="text" class="regular-text" value="<?php echo esc_attr($options['download_name']); ?>"></td></tr>
                    <tr><th scope="row"><label for="jsd_download_file">上传受保护文件</label></th><td><input id="jsd_download_file" name="download_file" type="file" accept=".rar,.zip,.7z,.exe,.msi,.pdf,.doc,.docx"><p class="description">支持 RAR、ZIP、7Z、EXE、MSI、PDF、DOC、DOCX。留空表示保留当前文件。上传的新文件不会进入 WordPress 媒体库。</p></td></tr>
                </table>
                <?php submit_button('保存设置'); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('无权限。');
        }
        check_admin_referer('jsd_save_settings');
        $options = self::get_options();
        $old_path = $options['tutorial_path'];
        $path = isset($_POST['tutorial_path']) ? trim(sanitize_text_field(wp_unslash($_POST['tutorial_path'])), '/') : '';
        $path = preg_replace('~[^A-Za-z0-9/_-]+~', '-', $path);
        $path = preg_replace('~/+~', '/', (string) $path);
        if ($path === '') {
            $path = self::defaults()['tutorial_path'];
        }
        $options['tutorial_path'] = $path;
        $download_name = isset($_POST['download_name']) ? sanitize_file_name(wp_unslash($_POST['download_name'])) : '';
        if ($download_name !== '') {
            $options['download_name'] = $download_name;
        }
        $password = isset($_POST['download_password']) ? (string) wp_unslash($_POST['download_password']) : '';
        if ($password !== '') {
            if (strlen($password) < 6) {
                self::redirect_settings_error('下载密码至少需要 6 个字符。');
            }
            $options['password_hash'] = wp_hash_password($password);
        }
        if (!empty($_FILES['download_file']['name'])) {
            $upload_error = (int) $_FILES['download_file']['error'];
            if ($upload_error !== UPLOAD_ERR_OK) {
                self::redirect_settings_error('文件上传失败，PHP 上传错误代码：' . $upload_error);
            }
            $original_name = sanitize_file_name((string) $_FILES['download_file']['name']);
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $allowed = ['rar', 'zip', '7z', 'exe', 'msi', 'pdf', 'doc', 'docx'];
            if (!in_array($ext, $allowed, true)) {
                self::redirect_settings_error('不支持这个文件类型。');
            }
            $dir = self::ensure_private_directory();
            if (!is_dir($dir) || !is_writable($dir)) {
                self::redirect_settings_error('私有下载目录不可写，请检查服务器目录权限。');
            }
            $stored_name = 'download-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '.' . $ext;
            $destination = trailingslashit($dir) . $stored_name;
            $tmp_name = (string) $_FILES['download_file']['tmp_name'];
            if (!is_uploaded_file($tmp_name) || !@move_uploaded_file($tmp_name, $destination)) {
                self::redirect_settings_error('服务器无法把上传文件移动到私有目录。');
            }
            @chmod($destination, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
            $old_file = self::resolve_download_file($options);
            $options['download_file'] = $stored_name;
            if (empty($_POST['download_name'])) {
                $options['download_name'] = $original_name;
            }
            if ($old_file && is_file($old_file) && realpath($old_file) !== realpath($destination)) {
                @unlink($old_file);
            }
        }
        update_option(self::OPTION_NAME, $options, false);
        if ($old_path !== $options['tutorial_path']) {
            self::register_rewrite_rule();
            flush_rewrite_rules(false);
        }
        wp_safe_redirect(add_query_arg(['page' => 'jinshanjiao-secure-tutorial', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    private static function redirect_settings_error(string $message): void
    {
        wp_safe_redirect(add_query_arg(['page' => 'jinshanjiao-secure-tutorial', 'jsd_error' => $message], admin_url('admin.php')));
        exit;
    }

    private static function resolve_download_file(array $options)
    {
        $stored = sanitize_file_name((string) ($options['download_file'] ?? ''));
        if ($stored === '') {
            return false;
        }
        $path = trailingslashit(self::ensure_private_directory()) . $stored;
        return is_file($path) ? $path : false;
    }

    private static function client_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return substr($ip, 0, 45);
    }

    private static function rate_limit_key(): string
    {
        return 'jsd_attempt_' . md5(self::client_ip());
    }

    public static function ajax_verify_download_password(): void
    {
        check_ajax_referer('jsd_download_verify', 'nonce');
        $rate_key = self::rate_limit_key();
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 8) {
            wp_send_json_error(['message' => '尝试次数过多，请 10 分钟后再试。'], 429);
        }
        $options = self::get_options();
        $file = self::resolve_download_file($options);
        if (!$file) {
            wp_send_json_error(['message' => '下载文件尚未配置，请联系管理员。'], 503);
        }
        if (empty($options['password_hash'])) {
            wp_send_json_error(['message' => '下载密码尚未配置，请联系管理员。'], 503);
        }
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ($password === '' || !wp_check_password($password, $options['password_hash'])) {
            set_transient($rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);
            wp_send_json_error(['message' => '密码不正确，请重新输入。'], 403);
        }
        delete_transient($rate_key);
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = wp_generate_password(64, false, false);
        }
        $token_key = 'jsd_dl_' . hash('sha256', $token);
        set_transient($token_key, ['file' => basename($file), 'name' => sanitize_file_name((string) $options['download_name']), 'created' => time()], self::TOKEN_TTL);
        $download_url = add_query_arg(self::DOWNLOAD_QUERY_VAR, $token, home_url('/'));
        wp_send_json_success(['url' => $download_url, 'expires_in' => self::TOKEN_TTL, 'message' => '验证成功，下载链接 60 秒内有效且只能使用一次。']);
    }

    private static function serve_download(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9]{40,128}$/', $token)) {
            status_header(404);
            exit;
        }
        $token_key = 'jsd_dl_' . hash('sha256', $token);
        $payload = get_transient($token_key);
        if (!is_array($payload) || empty($payload['file'])) {
            status_header(410);
            wp_die('下载链接已失效，请返回教程页面重新验证密码。', '下载链接已失效', ['response' => 410]);
        }
        delete_transient($token_key);
        $dir = realpath(self::ensure_private_directory());
        $file = realpath(trailingslashit(self::ensure_private_directory()) . sanitize_file_name((string) $payload['file']));
        if (!$dir || !$file || !is_file($file) || strpos($file, $dir . DIRECTORY_SEPARATOR) !== 0) {
            status_header(404);
            exit;
        }
        $download_name = sanitize_file_name((string) ($payload['name'] ?? 'download.bin'));
        if ($download_name === '') {
            $download_name = basename($file);
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $download_name) . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
        header('Content-Length: ' . (string) filesize($file));
        header('X-Content-Type-Options: nosniff');
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            status_header(500);
            exit;
        }
        while (!feof($handle)) {
            echo fread($handle, 1024 * 1024);
            flush();
        }
        fclose($handle);
        exit;
    }

    private static function image_url(string $filename): string
    {
        return plugin_dir_url(__FILE__) . 'assets/v2rayn/' . ltrim($filename, '/');
    }

    private static function render_tutorial_page(): void
    {
        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        add_filter('pre_get_document_title', static function () { return 'V2rayN 配置使用教程（电脑）'; }, 999);
        $options = self::get_options();
        $file_ready = (bool) self::resolve_download_file($options);
        $password_ready = !empty($options['password_hash']);
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('jsd_download_verify');
        get_header();
        ?>
        <style>
        .jsd-wrap{--b:#1769e0;--d:#17324d;--bd:#e1e7ef;max-width:1180px;margin:0 auto;padding:34px 20px 80px;color:#263747;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif;line-height:1.75}.jsd-hero{background:linear-gradient(135deg,#172b4d,#2356a8);color:#fff;border-radius:20px;padding:42px 38px;box-shadow:0 16px 38px rgba(15,42,75,.16);margin:18px 0 28px}.jsd-hero h1{color:#fff;margin:0 0 12px;font-size:34px;line-height:1.25}.jsd-hero p{margin:0;color:rgba(255,255,255,.88)}.jsd-badges{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}.jsd-badge{padding:6px 11px;border-radius:999px;background:rgba(255,255,255,.13);font-size:13px}.jsd-grid{display:grid;grid-template-columns:260px minmax(0,1fr);gap:28px;align-items:start}.jsd-toc{position:sticky;top:90px;background:#fff;border:1px solid var(--bd);border-radius:16px;padding:18px;box-shadow:0 10px 26px rgba(16,42,67,.06)}.jsd-toc strong{display:block;margin-bottom:8px;color:var(--d)}.jsd-toc a{display:block;padding:7px 8px;color:#526575;text-decoration:none;border-radius:8px;font-size:14px}.jsd-toc a:hover{background:#f0f5ff;color:var(--b)}.jsd-content section{scroll-margin-top:100px;background:#fff;border:1px solid var(--bd);border-radius:16px;padding:28px 30px;margin-bottom:22px;box-shadow:0 8px 24px rgba(16,42,67,.05)}.jsd-content h2{margin:0 0 14px;color:var(--d);font-size:25px}.jsd-content p{margin:10px 0}.jsd-content ul{padding-left:22px}.jsd-shot{display:block;max-width:100%;height:auto;margin:18px auto 8px;border-radius:10px;border:1px solid #e5e9ef}.jsd-note,.jsd-warning{padding:14px 16px;border-radius:12px;margin:16px 0}.jsd-note{background:#eef5ff;border-left:4px solid var(--b)}.jsd-warning{background:#fff5f3;border-left:4px solid #dd4b39}.jsd-download{background:#f4f8ff;border:1px solid #cfdef6;border-radius:14px;padding:22px;margin:18px 0}.jsd-download-title{font-weight:700;color:var(--d);font-size:18px}.jsd-btn{appearance:none;border:0;background:var(--b);color:#fff!important;border-radius:10px;padding:12px 20px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;margin-top:12px}.jsd-btn:disabled{cursor:not-allowed;opacity:.48}.jsd-ai{display:flex;gap:12px;align-items:flex-start;background:#f7fbff;border:1px solid #dbeaf8;border-radius:14px;padding:16px}.jsd-ai-icon{font-size:24px}.jsd-modal{position:fixed;inset:0;z-index:999999;background:rgba(12,28,45,.58);display:none;align-items:center;justify-content:center;padding:20px}.jsd-modal.is-open{display:flex}.jsd-dialog{width:min(440px,100%);background:#fff;border-radius:18px;padding:26px;box-shadow:0 24px 80px rgba(0,0,0,.28)}.jsd-dialog h3{margin:0 0 8px;color:var(--d)}.jsd-field{width:100%;box-sizing:border-box;padding:12px 13px;border:1px solid #cfd8e3;border-radius:9px;font-size:16px;margin:10px 0}.jsd-dialog-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:12px}.jsd-btn-secondary{background:#e9eef5;color:#334a60!important}.jsd-msg{min-height:24px;font-size:14px;margin-top:8px}.jsd-msg.error{color:#b42318}.jsd-msg.success{color:#157347}@media(max-width:860px){.jsd-grid{grid-template-columns:1fr}.jsd-toc{position:static}.jsd-hero{padding:30px 24px}.jsd-hero h1{font-size:28px}.jsd-content section{padding:22px 20px}}
        </style>
        <main class="jsd-wrap">
            <div class="jsd-hero"><h1>V2rayN 配置使用教程 · 电脑</h1><p>Windows 客户端下载、订阅设置、节点更新、系统代理、国内外分流与 Core 错误排查。</p><div class="jsd-badges"><span class="jsd-badge">Windows</span><span class="jsd-badge">受保护下载</span><span class="jsd-badge">AI 在线协助</span><span class="jsd-badge">Noindex 隐藏页面</span></div></div>
            <div class="jsd-grid">
                <nav class="jsd-toc" aria-label="教程目录"><strong>教程目录</strong><a href="#overview">应用概述</a><a href="#download">应用下载</a><a href="#subscription">订阅链接</a><a href="#add-subscription">订阅设置、添加订阅</a><a href="#update">更新订阅（更新节点）</a><a href="#start">开始使用</a><a href="#routing">国内外分流</a><a href="#global">全局代理</a><a href="#proxy-options">三种代理选项</a><a href="#core-error">找不到 Core 错误</a></nav>
                <div class="jsd-content">
                    <section id="overview"><h2>应用概述</h2><p>V2rayN 是在 WIN 平台上的客户端软件，支持 VMess 协议。</p><p>V2rayN 要求系统安装有 Microsoft .NET Framework 4.8 或更高版本。如果程序启动不了，请先安装 Microsoft .NET Framework。</p></section>
                    <section id="download"><h2>应用下载</h2><div class="jsd-download"><div class="jsd-download-title">V2rayN Windows 客户端</div><p>下载文件受保护。每次点击下载都必须重新输入密码；验证成功后生成 60 秒有效、只能使用一次的下载链接。</p><button class="jsd-btn" id="jsd-open-download" type="button" <?php disabled(!$file_ready || !$password_ready); ?>>🔒 安全下载</button><?php if (!$file_ready || !$password_ready) : ?><p style="color:#b42318;margin-bottom:0;">管理员尚未完成下载文件或密码配置。</p><?php endif; ?></div><p>建议将程序下载后解压到桌面等当前用户有完整权限的位置，否则有可能遇到文件权限问题。具体表现包括：提示“获取订阅内容成功”却没有导入或更新节点、节点 URL 导入提示成功却没有实际导入，以及无法切换节点等现象。</p><p>如果遇到此类现象，也可以尝试用管理员权限启动。</p><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image1.webp')); ?>" alt="以管理员权限运行 V2rayN 示例"></section>
                    <section id="subscription"><h2>订阅链接</h2><p>点击仪表盘 → 一键订阅 → 复制订阅地址。</p><div class="jsd-warning"><strong>注意：</strong>订阅链接相当于你的账号密码，跟你的账号是绑定的，应当像密码一样妥善保管。</div><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image2.webp')); ?>" alt="复制订阅地址示例"></section>
                    <section id="add-subscription"><h2>订阅设置、添加订阅链接</h2><p>解压 v2rayn.zip 到硬盘。不要在压缩包里直接运行；解压到文件夹，然后进入文件夹里再运行。</p><p>启动 v2rayn.exe，点击左上角菜单“订阅”，随后点击“订阅设置”。</p><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image3.webp')); ?>" alt="启动 V2rayN 并打开订阅设置"><p>点击“添加”，粘贴订阅链接，勾选“启用”，点击“确定”。</p><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image4.webp')); ?>" alt="添加订阅链接"></section>
                    <section id="update"><h2>更新订阅（更新节点）</h2><p>点击：订阅 → 更新订阅（不通过代理）。</p><p>正常情况本站的订阅链接可以直连，所以一般点“更新订阅（不通过代理）”。如果你已经可以正常访问外网，也可以点“更新订阅（通过代理）”。</p><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image5.webp')); ?>" alt="更新订阅菜单"><p>如果订阅链接无法更新节点，也可以从用户中心拷贝全部 V2ray 节点 URL（不是订阅链接），然后在 V2RayN 主界面点右键，再点“从剪贴板导入批量 URL（Ctrl+V）”；然后拷贝全部 SS 节点 URL，也使用“从剪贴板导入批量 URL（Ctrl+V）”。付费才有 SS 节点。</p><div class="jsd-note">如果底部日志窗口提示更新成功，或“从剪贴板导入批量 URL”提示成功，但没有真正导入节点，一般是文件权限问题。请退出 V2rayN，然后以管理员权限重新启动 v2rayn.exe。</div></section>
                    <section id="start"><h2>开始使用</h2><p>右键点击 V2rayN 托盘区（桌面右下角任务栏小图标区域）图标，点击“系统代理 → 自动配置系统代理”。这样 IE、Edge、Chrome 等使用系统代理的浏览器即可使用。</p><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image6.webp')); ?>" alt="开启系统代理"></section>
                    <section id="routing"><h2>国内外分流</h2><p>从本站下载的 V2rayN 软件已经配置好国内外分流，国内网站会直连，不会走代理。可在“设置 → 路由设置”中查看。</p><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image7.webp')); ?>" alt="V2rayN 路由设置"><p>如果还有个别网站想直连，可以在这里添加域名，例如：</p><pre style="white-space:pre-wrap;background:#f7f9fc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;">geosite:cn,
baidu.com,
163.com</pre><p>每行一个域名，以英文逗号结尾，最后一行不用逗号。</p></section>
                    <section id="global"><h2>全局代理</h2><p>在路由设置中，把直连的 Domain 和 IP 全部删除（记得先备份），留空后就变成全局代理模式。把原来的内容重新填回去，就恢复为国内外分流模式。</p></section>
                    <section id="proxy-options"><h2>三种代理选项</h2><ul><li><strong>清除系统代理：</strong>禁止使用 Windows 系统代理，不设置任何代理。</li><li><strong>自动配置系统代理：</strong>设置使用 V2rayN 的代理。</li><li><strong>不改变系统代理：</strong>保持 Windows 原有代理设置，不做任何改变。</li></ul></section>
                    <section id="core-error"><h2>找不到 Core 错误</h2><p>如果出现 Core 相关错误，教程原文说明可能是安全软件删除了 V2rayN 文件夹里的 xray.exe，导致软件无法运行。</p><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image8.webp')); ?>" alt="V2rayN 操作失败示例"><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image9.webp')); ?>" alt="Core 文件缺失错误信息"><p>教程给出的处理方法是：从压缩包中重新把 xray.exe 解压出来，放回 V2rayN 文件夹。程序包中必须有 xray.exe 才能工作。</p><img class="jsd-shot" src="<?php echo esc_url(self::image_url('image10.webp')); ?>" alt="V2rayN 和 xray.exe 文件示例"></section>
                    <section id="ai-help"><h2>AI 在线协助</h2><div class="jsd-ai"><div class="jsd-ai-icon">🤖</div><div><strong>遇到问题可以直接询问右下角 AI 客服。</strong><br>例如：“更新订阅成功但为什么没有节点？”、“Core 错误怎么处理？”、“系统代理应该选哪个？”</div></div></section>
                </div>
            </div>
        </main>
        <div class="jsd-modal" id="jsd-download-modal" role="dialog" aria-modal="true" aria-labelledby="jsd-dialog-title"><div class="jsd-dialog"><h3 id="jsd-dialog-title">安全下载验证</h3><p>每次下载都需要输入密码。验证成功后链接仅 60 秒有效，并且只能使用一次。</p><input class="jsd-field" id="jsd-download-password" type="password" autocomplete="current-password" placeholder="请输入下载密码"><div class="jsd-msg" id="jsd-download-message"></div><div class="jsd-dialog-actions"><button class="jsd-btn jsd-btn-secondary" id="jsd-close-download" type="button">取消</button><button class="jsd-btn" id="jsd-verify-download" type="button">验证并下载</button></div></div></div>
        <script>
        (function(){var openBtn=document.getElementById('jsd-open-download'),modal=document.getElementById('jsd-download-modal'),closeBtn=document.getElementById('jsd-close-download'),verifyBtn=document.getElementById('jsd-verify-download'),input=document.getElementById('jsd-download-password'),msg=document.getElementById('jsd-download-message');if(!openBtn||!modal||!verifyBtn||!input)return;function openModal(){modal.classList.add('is-open');msg.textContent='';msg.className='jsd-msg';input.value='';setTimeout(function(){input.focus()},50)}function closeModal(){modal.classList.remove('is-open');input.value='';msg.textContent=''}openBtn.addEventListener('click',openModal);closeBtn.addEventListener('click',closeModal);modal.addEventListener('click',function(e){if(e.target===modal)closeModal()});document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal.classList.contains('is-open'))closeModal()});input.addEventListener('keydown',function(e){if(e.key==='Enter')verifyBtn.click()});verifyBtn.addEventListener('click',function(){var password=input.value;if(!password){msg.textContent='请输入下载密码。';msg.className='jsd-msg error';return}verifyBtn.disabled=true;msg.textContent='正在验证…';msg.className='jsd-msg';var body=new URLSearchParams();body.set('action','jsd_verify_download_password');body.set('nonce',<?php echo wp_json_encode($nonce); ?>);body.set('password',password);fetch(<?php echo wp_json_encode($ajax_url); ?>,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(function(r){return r.json().then(function(j){return{ok:r.ok,json:j}})}).then(function(res){if(!res.json||!res.json.success)throw new Error(res.json&&res.json.data&&res.json.data.message?res.json.data.message:'验证失败，请重试。');msg.textContent=res.json.data.message||'验证成功，开始下载。';msg.className='jsd-msg success';window.location.href=res.json.data.url;setTimeout(closeModal,1200)}).catch(function(err){msg.textContent=err.message||'验证失败，请重试。';msg.className='jsd-msg error'}).finally(function(){verifyBtn.disabled=false})})})();
        </script>
        <?php
        get_footer();
    }
}

Jinshanjiao_Secure_Tutorial_Download::init();
register_activation_hook(__FILE__, [Jinshanjiao_Secure_Tutorial_Download::class, 'activate']);
register_deactivation_hook(__FILE__, [Jinshanjiao_Secure_Tutorial_Download::class, 'deactivate']);
