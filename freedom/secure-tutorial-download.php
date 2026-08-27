<?php
/**
 * Plugin Name: freedom
 * Description: freedom 后台管理插件：提供隐藏教程页面、密码验证、付款核验和安全下载。
 * Version: 1.6.8
 * Author: freedom
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
    private const PAYMENT_IMAGE_TTL = 180;
    private const PAYMENT_IMAGE_QUERY_VAR = 'freedom_payment_image';
    private const RECEIPT_REGISTRY_OPTION = 'freedom_payment_receipt_registry';
    private const MIGRATION_OPTION = 'freedom_secure_tutorial_migration_version';
    private const VERSION = '1.6.8';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'maybe_upgrade_defaults'], 1);
        add_action('init', [__CLASS__, 'register_rewrite_rule']);
        add_filter('query_vars', [__CLASS__, 'register_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_frontend_requests'], 0);

        add_action('wp_ajax_jsd_verify_download_password', [__CLASS__, 'ajax_verify_download_password']);
        add_action('wp_ajax_nopriv_jsd_verify_download_password', [__CLASS__, 'ajax_verify_download_password']);
        add_action('wp_ajax_jsd_verify_payment_screenshot', [__CLASS__, 'ajax_verify_payment_screenshot']);
        add_action('wp_ajax_nopriv_jsd_verify_payment_screenshot', [__CLASS__, 'ajax_verify_payment_screenshot']);

        // 后台大文件分块上传：每块远小于 PHP 单次上传限制，适合 100MB+ 软件包频繁替换。
        add_action('wp_ajax_freedom_chunk_upload', [__CLASS__, 'ajax_chunk_upload']);
        add_action('wp_ajax_freedom_payment_image_selftest', [__CLASS__, 'ajax_payment_image_selftest']);

        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'ensure_physical_tutorial_router']);
        add_action('admin_post_jsd_save_settings', [__CLASS__, 'handle_save_settings']);

        // 当请求来自本教程页时，只替换 LinkAI 的系统提示词，不改动原 AI 客服插件。
        add_filter('http_request_args', [__CLASS__, 'inject_tutorial_ai_prompt'], 20, 2);
    }

    public static function activate(): void
    {
        self::ensure_private_directory();
        self::ensure_seeded_resources();
        self::ensure_physical_tutorial_router();
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
            'download_name' => 'freedom-desktop-package.rar',
            'android_file' => '',
            'android_name' => 'freedom-android.rar',
            'ios_config_file' => '',
            'ios_config_name' => 'freedom-shadowrocket-nodes.txt',
            'android_subscription_link_1' => 'https://v1.xdollar.top/link/kioGpPqJha5wzG8kW1gKpwqgOAcUeJwHx45P?sub=3',
            'android_subscription_link_2' => 'https://v1.xdollar.top/link/H4mm9yDL3Gtx5egm8JuDmIvTPD7yf2ABefPH?sub=3',
            'archive_extract_password' => '111',
            'payment_enabled' => '1',
            'payment_amount' => '200.00',
            'payment_currency' => 'CNY',
            'payment_payee_keywords' => 'DonnaChen',
            'payment_qr_file' => '',
            'payment_app_code' => '',
            'payment_auto_approve' => '1',
        ];
    }

    private static function get_options(): array
    {
        $saved = get_option(self::OPTION_NAME, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function maybe_upgrade_defaults(): void
    {
        $saved = get_option(self::OPTION_NAME, []);
        $saved = is_array($saved) ? $saved : [];
        $changed = false;

        $defaults = [
            'payment_amount' => '200.00',
            'payment_currency' => 'CNY',
            'payment_payee_keywords' => 'DonnaChen',
            'payment_enabled' => '1',
            'payment_auto_approve' => '1',
            'archive_extract_password' => '111',
            'android_subscription_link_1' => 'https://v1.xdollar.top/link/kioGpPqJha5wzG8kW1gKpwqgOAcUeJwHx45P?sub=3',
            'android_subscription_link_2' => 'https://v1.xdollar.top/link/H4mm9yDL3Gtx5egm8JuDmIvTPD7yf2ABefPH?sub=3',
        ];
        foreach ($defaults as $key => $value) {
            if (!isset($saved[$key]) || $saved[$key] === '') {
                $saved[$key] = $value;
                $changed = true;
            }
        }
        if ($changed) {
            update_option(self::OPTION_NAME, $saved, false);
        }

        // 旧版会在每个 init 都检查/读取大号 seed 文件。现在只在版本迁移时做一次，
        // 避免后台请求因为 10MB+ 文件操作变慢或触发资源限制。
        $migration = (string) get_option(self::MIGRATION_OPTION, '');
        if ($migration !== self::VERSION) {
            self::ensure_seeded_resources();
            update_option(self::MIGRATION_OPTION, self::VERSION, false);
        }
    }

    private static function seed_payload_offset(string $seed_path)
    {
        if (!is_file($seed_path) || !is_readable($seed_path)) return false;
        $handle = @fopen($seed_path, 'rb');
        if (!$handle) return false;
        $prefix = @fread($handle, 4096);
        fclose($handle);
        if (!is_string($prefix)) return false;
        $marker = "__halt_compiler();\n";
        $at = strpos($prefix, $marker);
        return $at === false ? false : $at + strlen($marker);
    }

    private static function extract_seed_payload(string $seed_path, string $destination): bool
    {
        $offset = self::seed_payload_offset($seed_path);
        if ($offset === false) return false;
        $in = @fopen($seed_path, 'rb');
        $out = @fopen($destination, 'wb');
        if (!$in || !$out) {
            if (is_resource($in)) fclose($in);
            if (is_resource($out)) fclose($out);
            return false;
        }
        if (@fseek($in, (int) $offset) !== 0) {
            fclose($in); fclose($out); @unlink($destination); return false;
        }
        $copied = stream_copy_to_stream($in, $out);
        fclose($in); fclose($out);
        if ($copied === false || $copied <= 0) {
            @unlink($destination);
            return false;
        }
        return true;
    }

    private static function ensure_seeded_resources(): void
    {
        $options = self::get_options();
        $dir = self::ensure_private_directory();
        if (!is_dir($dir) || !is_writable($dir)) return;

        $changed = false;
        $seeds = [
            'android' => ['option'=>'android_file','name_option'=>'android_name','default_name'=>'freedom-android.rar','seed'=>'seeds/android-package.php','ext'=>'rar'],
            'ios' => ['option'=>'ios_config_file','name_option'=>'ios_config_name','default_name'=>'freedom-shadowrocket-nodes.txt','seed'=>'seeds/shadowrocket-config.php','ext'=>'txt'],
        ];
        foreach ($seeds as $key=>$seed) {
            $current = sanitize_file_name((string)($options[$seed['option']] ?? ''));
            if ($current !== '' && is_file(trailingslashit($dir).$current)) continue;
            $seed_path = trailingslashit(plugin_dir_path(__FILE__)) . $seed['seed'];
            if (!is_file($seed_path) || self::seed_payload_offset($seed_path) === false) continue;
            $seed_hash = @hash_file('sha256', $seed_path);
            if (!is_string($seed_hash) || $seed_hash === '') continue;
            $stored = 'seed-'.$key.'-'.substr($seed_hash,0,16).'.'.$seed['ext'];
            $dest = trailingslashit($dir).$stored;
            if (!is_file($dest) && !self::extract_seed_payload($seed_path, $dest)) continue;
            @chmod($dest, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
            $options[$seed['option']] = $stored;
            if (empty($options[$seed['name_option']])) $options[$seed['name_option']] = $seed['default_name'];
            $changed = true;
        }
        if ($changed) update_option(self::OPTION_NAME,$options,false);
    }


    private static function router_marker(): string
    {
        return 'JINSHANJIAO_SECURE_TUTORIAL_ROUTER';
    }

    private static function tutorial_router_directory(?string $path = null): string
    {
        if ($path === null) {
            $options = self::get_options();
            $path = (string) ($options['tutorial_path'] ?? '');
        }

        $path = trim((string) $path, '/');
        $path = preg_replace('~[^A-Za-z0-9/_-]+~', '-', $path);
        $path = preg_replace('~/+~', '/', (string) $path);

        if ($path === '' || strpos($path, '..') !== false) {
            return '';
        }

        return trailingslashit(ABSPATH) . $path;
    }

    private static function router_file_content(): string
    {
        $marker = self::router_marker();
        return "<?php\n"
            . "// {$marker}\n"
            . "// Generated by Jinshanjiao Secure Tutorial. Do not edit manually.\n"
            . "\$_GET['" . self::PAGE_QUERY_VAR . "'] = '1';\n"
            . "\$_REQUEST['" . self::PAGE_QUERY_VAR . "'] = '1';\n"
            . "\$dir = __DIR__;\n"
            . "\$wpRoot = '';\n"
            . "for (\$i = 0; \$i < 16; \$i++) {\n"
            . "    if (is_file(\$dir . '/wp-blog-header.php')) { \$wpRoot = \$dir; break; }\n"
            . "    \$parent = dirname(\$dir);\n"
            . "    if (\$parent === \$dir) { break; }\n"
            . "    \$dir = \$parent;\n"
            . "}\n"
            . "if (\$wpRoot === '') { http_response_code(500); exit('WordPress bootstrap not found.'); }\n"
            . "define('WP_USE_THEMES', true);\n"
            . "require \$wpRoot . '/wp-blog-header.php';\n";
    }

    private static function remove_physical_tutorial_router(string $path): void
    {
        $dir = self::tutorial_router_directory($path);
        if ($dir === '') {
            return;
        }

        $index = trailingslashit($dir) . 'index.php';
        if (!is_file($index)) {
            return;
        }

        $contents = @file_get_contents($index);
        if (is_string($contents) && strpos($contents, self::router_marker()) !== false) {
            @unlink($index);
        }
    }

    public static function ensure_physical_tutorial_router(): bool
    {
        $dir = self::tutorial_router_directory();
        if ($dir === '') {
            return false;
        }

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        $index = trailingslashit($dir) . 'index.php';
        if (is_file($index)) {
            $existing = @file_get_contents($index);
            if (!is_string($existing) || strpos($existing, self::router_marker()) === false) {
                // Never overwrite an unrelated real file.
                return false;
            }
        }

        $written = @file_put_contents($index, self::router_file_content(), LOCK_EX);
        if ($written === false) {
            return false;
        }

        @chmod($index, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
        return true;
    }

    private static function physical_tutorial_router_status(): array
    {
        $dir = self::tutorial_router_directory();
        $index = $dir !== '' ? trailingslashit($dir) . 'index.php' : '';
        $ready = false;

        if ($index !== '' && is_file($index)) {
            $contents = @file_get_contents($index);
            $ready = is_string($contents) && strpos($contents, self::router_marker()) !== false;
        }

        return [
            'ready' => $ready,
            'path' => $index,
            'writable' => $dir !== '' && (is_writable($dir) || is_writable(dirname($dir))),
        ];
    }

    public static function register_rewrite_rule(): void
    {
        $options = self::get_options();
        $path = trim((string) $options['tutorial_path'], '/');
        if ($path === '') {
            return;
        }

        add_rewrite_rule(
            '^' . preg_quote($path, '/') . '/?$',
            'index.php?' . self::PAGE_QUERY_VAR . '=1',
            'top'
        );
    }

    public static function register_query_vars(array $vars): array
    {
        $vars[] = self::PAGE_QUERY_VAR;
        $vars[] = self::DOWNLOAD_QUERY_VAR;
        return $vars;
    }

    private static function request_matches_tutorial_path(): bool
    {
        $options = self::get_options();
        $tutorial_path = trim((string) ($options['tutorial_path'] ?? ''), '/');
        if ($tutorial_path === '') {
            return false;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $request_path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/');

        return $request_path !== '' && hash_equals($tutorial_path, $request_path);
    }

    public static function handle_frontend_requests(): void
    {
        if (!empty($_GET[self::PAYMENT_IMAGE_QUERY_VAR])) {
            self::serve_temporary_payment_image(sanitize_text_field(wp_unslash($_GET[self::PAYMENT_IMAGE_QUERY_VAR])));
        }

        if (!empty($_GET[self::DOWNLOAD_QUERY_VAR])) {
            self::serve_download(sanitize_text_field(wp_unslash($_GET[self::DOWNLOAD_QUERY_VAR])));
        }

        // Primary route: normal WordPress rewrite query var.
        // Fallback route: match REQUEST_URI directly. This prevents a stale
        // WordPress rewrite cache from turning the hidden tutorial into a 404.
        $is_tutorial = (string) get_query_var(self::PAGE_QUERY_VAR) === '1'
            || self::request_matches_tutorial_path();

        if (!$is_tutorial) {
            return;
        }

        global $wp_query;
        if (is_object($wp_query)) {
            $wp_query->is_404 = false;
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
                @file_put_contents(
                    $htaccess,
                    "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                );
            }
            $index = trailingslashit($dir) . 'index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, "<?php\nhttp_response_code(403);\nexit;\n");
            }
        }

        return $dir;
    }

    private static function format_bytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
        if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / 1024 / 1024, 1) . ' MB';
        return number_format($bytes / 1024 / 1024 / 1024, 2) . ' GB';
    }

    private static function resource_status(array $options, string $resource): array
    {
        $resource = self::normalize_resource_key($resource);
        $file = self::resolve_resource_file($options, $resource);
        $map = self::resource_map($options);
        return [
            'ready' => (bool) $file,
            'path' => $file ?: '',
            'size' => $file && is_file($file) ? (int) filesize($file) : 0,
            'name' => self::resource_download_name($options, $resource),
            'label' => $map[$resource]['label'],
        ];
    }

    private static function admin_chunk_size(): int
    {
        $max = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 2 * 1024 * 1024;
        if ($max <= 0) $max = 2 * 1024 * 1024;
        // 留出 multipart/header 余量；服务器允许时每块最高 16MB。
        // 例如服务器上限 512MB 时，103MB 文件约 7 个分块即可完成。
        return max(256 * 1024, min(16 * 1024 * 1024, (int) floor($max * 0.25)));
    }

    private static function chunk_temp_paths(string $upload_id): array
    {
        $dir = self::ensure_private_directory();
        return [
            'part' => trailingslashit($dir) . '.freedom-upload-' . $upload_id . '.part',
            'meta' => trailingslashit($dir) . '.freedom-upload-' . $upload_id . '.json',
        ];
    }

    private static function cleanup_stale_chunk_uploads(): void
    {
        $dir = self::ensure_private_directory();
        if (!is_dir($dir)) return;
        foreach (glob(trailingslashit($dir) . '.freedom-upload-*.*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - DAY_IN_SECONDS) {
                @unlink($file);
            }
        }
    }

    private static function allowed_resource_extensions(string $resource): array
    {
        $resource = self::normalize_resource_key($resource);
        return $resource === 'ios'
            ? ['txt']
            : ['rar','zip','7z','exe','msi','dmg','deb','rpm','apk'];
    }

    public static function ajax_chunk_upload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限。'], 403);
        }
        check_ajax_referer('freedom_chunk_upload', 'nonce');

        $resource = isset($_POST['resource']) ? self::normalize_resource_key((string) wp_unslash($_POST['resource'])) : 'desktop';
        $upload_id = isset($_POST['upload_id']) ? sanitize_key((string) wp_unslash($_POST['upload_id'])) : '';
        $index = isset($_POST['chunk_index']) ? max(0, (int) $_POST['chunk_index']) : -1;
        $total = isset($_POST['total_chunks']) ? max(1, (int) $_POST['total_chunks']) : 0;
        $total_size = isset($_POST['total_size']) ? max(0, (int) $_POST['total_size']) : 0;
        $original = isset($_POST['filename']) ? sanitize_file_name((string) wp_unslash($_POST['filename'])) : '';

        if (!preg_match('/^[a-z0-9]{20,64}$/', $upload_id) || $index < 0 || $total < 1 || $index >= $total) {
            wp_send_json_error(['message' => '上传会话参数无效。'], 400);
        }
        if ($total_size <= 0 || $total_size > 1024 * 1024 * 1024) {
            wp_send_json_error(['message' => '单个文件最大支持 1GB。'], 400);
        }
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($original === '' || !in_array($ext, self::allowed_resource_extensions($resource), true)) {
            wp_send_json_error(['message' => '该文件类型不支持。'], 400);
        }
        if (empty($_FILES['chunk']) || (int) ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => '当前分块上传失败。'], 400);
        }
        $chunk = $_FILES['chunk'];
        $tmp = (string) ($chunk['tmp_name'] ?? '');
        $chunk_size = (int) ($chunk['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || $chunk_size <= 0 || $chunk_size > 20 * 1024 * 1024) {
            wp_send_json_error(['message' => '上传分块无效。'], 400);
        }

        $dir = self::ensure_private_directory();
        if (!is_dir($dir) || !is_writable($dir)) {
            wp_send_json_error(['message' => '私有下载目录不可写。'], 500);
        }
        $free = @disk_free_space($dir);
        if ($free !== false && $index === 0 && $free < $total_size + 50 * 1024 * 1024) {
            wp_send_json_error(['message' => '服务器磁盘空间不足。'], 507);
        }

        $paths = self::chunk_temp_paths($upload_id);
        if ($index === 0) {
            @unlink($paths['part']);
            @unlink($paths['meta']);
            $meta = [
                'resource' => $resource,
                'filename' => $original,
                'extension' => $ext,
                'total_chunks' => $total,
                'total_size' => $total_size,
                'next_index' => 0,
                'started_at' => time(),
            ];
            if (@file_put_contents($paths['meta'], wp_json_encode($meta), LOCK_EX) === false) {
                wp_send_json_error(['message' => '无法创建上传会话。'], 500);
            }
        }

        $meta = json_decode((string) @file_get_contents($paths['meta']), true);
        if (!is_array($meta)
            || ($meta['resource'] ?? '') !== $resource
            || ($meta['filename'] ?? '') !== $original
            || (int) ($meta['total_chunks'] ?? 0) !== $total
            || (int) ($meta['total_size'] ?? 0) !== $total_size) {
            wp_send_json_error(['message' => '上传会话不匹配，请重新选择文件上传。'], 409);
        }

        $next = (int) ($meta['next_index'] ?? 0);
        if ($index < $next) {
            wp_send_json_success(['message' => '该分块已接收。', 'progress' => min(100, (int) round($next / $total * 100))]);
        }
        if ($index !== $next) {
            wp_send_json_error(['message' => '分块顺序错误，请重新上传。'], 409);
        }

        $in = @fopen($tmp, 'rb');
        $out = @fopen($paths['part'], $index === 0 ? 'wb' : 'ab');
        if (!$in || !$out) {
            if (is_resource($in)) fclose($in);
            if (is_resource($out)) fclose($out);
            wp_send_json_error(['message' => '服务器无法写入上传文件。'], 500);
        }
        if (function_exists('flock')) @flock($out, LOCK_EX);
        $copied = stream_copy_to_stream($in, $out);
        if (function_exists('flock')) @flock($out, LOCK_UN);
        fclose($in); fclose($out);
        if ($copied === false || (int) $copied !== $chunk_size) {
            wp_send_json_error(['message' => '分块写入不完整，请重试。'], 500);
        }

        $meta['next_index'] = $index + 1;
        $meta['updated_at'] = time();
        @file_put_contents($paths['meta'], wp_json_encode($meta), LOCK_EX);

        if ($index + 1 < $total) {
            wp_send_json_success([
                'message' => '上传中…',
                'progress' => min(99, (int) round(($index + 1) / $total * 100)),
            ]);
        }

        clearstatcache(true, $paths['part']);
        $actual_size = is_file($paths['part']) ? (int) filesize($paths['part']) : 0;
        if ($actual_size !== $total_size) {
            wp_send_json_error(['message' => '文件大小校验失败，请重新上传。'], 500);
        }

        $options = self::get_options();
        $map = self::resource_map($options);
        $old = self::resolve_resource_file($options, $resource);
        $stored = $resource . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(10, false, false) . '.' . $ext;
        $dest = trailingslashit($dir) . $stored;
        if (!@rename($paths['part'], $dest)) {
            wp_send_json_error(['message' => '上传完成，但服务器无法完成最终替换。'], 500);
        }
        @chmod($dest, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
        @unlink($paths['meta']);

        $options[$map[$resource]['file_option']] = $stored;
        // 如果显示名为空，才采用原始文件名；否则保留后台已设置的 freedom 名称。
        if (empty($options[$map[$resource]['name_option']])) {
            $options[$map[$resource]['name_option']] = $original;
        }
        update_option(self::OPTION_NAME, $options, false);

        if ($old && is_file($old) && realpath($old) !== realpath($dest)) {
            @unlink($old);
        }

        wp_send_json_success([
            'message' => '上传完成，已安全替换旧文件。',
            'progress' => 100,
            'size' => self::format_bytes($actual_size),
            'download_name' => self::resource_download_name($options, $resource),
        ]);
    }

    public static function ajax_payment_image_selftest(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限。'], 403);
        }
        check_ajax_referer('freedom_payment_selftest', 'nonce');
        $options = self::get_options();
        $image_url = self::payment_qr_url($options);
        if ($image_url === '') {
            wp_send_json_error(['message' => '请先配置收款码。'], 400);
        }
        $result = self::verify_payment_image_with_linkai($image_url, $options);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => '识图接口未通过：' . $result->get_error_message()], 502);
        }
        wp_send_json_success([
            'message' => 'LinkAI 图片输入与 JSON 返回均正常。正式付款截图仍会继续校验金额、收款方、支付成功状态和重复使用。',
        ]);
    }

    public static function add_admin_menu(): void
    {
        add_menu_page(
            'freedom',
            'freedom',
            'manage_options',
            'jinshanjiao-secure-tutorial',
            [__CLASS__, 'render_settings_page'],
            'dashicons-lock',
            59
        );
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) return;
        self::cleanup_stale_chunk_uploads();
        $options = self::get_options();
        $tutorial_url = home_url('/' . trim($options['tutorial_path'], '/') . '/');
        $private_dir = self::ensure_private_directory();
        $router = self::physical_tutorial_router_status();
        $saved = isset($_GET['updated']) && $_GET['updated'] === '1';
        $error = isset($_GET['jsd_error']) ? sanitize_text_field(wp_unslash($_GET['jsd_error'])) : '';
        $statuses = [
            'desktop' => self::resource_status($options, 'desktop'),
            'android' => self::resource_status($options, 'android'),
            'ios' => self::resource_status($options, 'ios'),
        ];
        $linkai = self::linkai_options();
        $chunk_size = self::admin_chunk_size();
        $server_upload_max = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
        $chunk_nonce = wp_create_nonce('freedom_chunk_upload');
        $payment_test_nonce = wp_create_nonce('freedom_payment_selftest');
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <div class="wrap">
            <h1>freedom</h1>
            <?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>设置已保存。</p></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <p><strong>v1.5.2：</strong>大文件改为分块上传，103MB、几百 MB 的软件可直接在这里替换；不需要为了大文件把 PHP 全站上传限制调到很高。</p>
            <p><strong>稳定性修复：</strong>大号 Android seed 不再在每个后台/前台请求重复检查，只在版本迁移时处理一次。</p>

            <table class="widefat striped" style="max-width:1050px;margin:16px 0 24px"><tbody>
                <tr><th style="width:220px">教程地址</th><td><a href="<?php echo esc_url($tutorial_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($tutorial_url); ?></a></td></tr>
                <tr><th>私有目录</th><td><code><?php echo esc_html($private_dir); ?></code> <?php echo is_writable($private_dir) ? '<strong style="color:#198754">可写</strong>' : '<strong style="color:#b32d2e">不可写</strong>'; ?></td></tr>
                <?php foreach ($statuses as $key => $status): ?>
                <tr><th><?php echo esc_html($status['label']); ?></th><td><?php if ($status['ready']): ?><strong style="color:#198754">✅ 已就绪</strong> · <?php echo esc_html($status['name']); ?> · <?php echo esc_html(self::format_bytes($status['size'])); ?><?php else: ?><strong style="color:#b32d2e">❌ 尚未上传</strong><?php endif; ?></td></tr>
                <?php endforeach; ?>
                <tr><th>解压密码</th><td><code><?php echo esc_html($options['archive_extract_password']); ?></code></td></tr>
                <tr><th>教程截图</th><td><?php echo self::tutorial_assets_ready() ? '<strong style="color:#198754">✅ 电脑 8 + Android 16 + iPhone 2 张已内置</strong>' : '<strong style="color:#b32d2e">❌ 图片不完整</strong>'; ?></td></tr>
                <tr><th>付款配置</th><td><?php echo (!empty($options['payment_enabled']) && (float)$options['payment_amount'] > 0 && self::payment_qr_url($options) !== '') ? '<strong style="color:#198754">✅ 已配置</strong>' : '<strong style="color:#b32d2e">❌ 未完整配置</strong>'; ?> · <?php echo esc_html(number_format((float)$options['payment_amount'], 2) . ' ' . $options['payment_currency']); ?></td></tr>
                <tr><th>LinkAI API Key</th><td><?php echo !empty($linkai['api_key']) ? '<strong style="color:#198754">✅ 已检测到</strong>' : '<strong style="color:#b32d2e">❌ 未配置</strong>'; ?></td></tr>
                <tr><th>物理路由</th><td><?php echo $router['ready'] ? '<strong style="color:#198754">✅ 已就绪</strong>' : '<strong style="color:#b32d2e">❌ 未创建</strong>'; ?></td></tr>
                <tr><th>服务器单次上传上限</th><td><?php echo $server_upload_max > 0 ? esc_html(self::format_bytes($server_upload_max)) : '未知'; ?>；freedom 分块大小：<?php echo esc_html(self::format_bytes($chunk_size)); ?></td></tr>
            </tbody></table>

            <h2>大文件上传 / 替换</h2>
            <p>这里上传后<strong>立即生效</strong>，不需要再点页面底部“保存设置”。旧文件会等新文件完整上传并校验成功后才删除，上传中断不会破坏当前可下载版本。</p>
            <table class="form-table" style="max-width:1050px">
                <?php
                $accepts = [
                    'desktop' => '.rar,.zip,.7z,.exe,.msi,.dmg,.deb,.rpm,.apk',
                    'android' => '.rar,.zip,.7z,.apk',
                    'ios' => '.txt,text/plain',
                ];
                foreach ($statuses as $key => $status): ?>
                <tr>
                    <th><?php echo esc_html($status['label']); ?></th>
                    <td>
                        <div style="margin-bottom:8px" id="freedom-status-<?php echo esc_attr($key); ?>"><?php echo $status['ready'] ? '<strong style="color:#198754">当前：已就绪 · ' . esc_html(self::format_bytes($status['size'])) . '</strong>' : '<strong style="color:#b32d2e">当前：尚未上传</strong>'; ?></div>
                        <input type="file" id="freedom-file-<?php echo esc_attr($key); ?>" accept="<?php echo esc_attr($accepts[$key]); ?>">
                        <button type="button" class="button button-primary freedom-chunk-upload" data-resource="<?php echo esc_attr($key); ?>">上传 / 替换</button>
                        <div style="max-width:620px;margin-top:8px"><progress id="freedom-progress-<?php echo esc_attr($key); ?>" value="0" max="100" style="width:100%;display:none"></progress><span id="freedom-upload-msg-<?php echo esc_attr($key); ?>" style="margin-left:8px"></span></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <h2>付款截图功能自检</h2>
            <p>代码链路会检查：有效图片 → LinkAI 识图 → 已付款状态 → 金额 → 币种 → 收款方关键词 → 截图哈希/交易号防重复 → 60 秒一次性下载链接。实际能否识图还取决于你当前 LinkAI 应用是否已开启“图像识别”。</p>
            <p><button type="button" class="button" id="freedom-payment-selftest">测试 LinkAI 识图连接</button> <span id="freedom-payment-selftest-msg"></span></p>

            <hr>
            <h2>教程与销售设置</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="max-width:1000px">
                <input type="hidden" name="action" value="jsd_save_settings"><?php wp_nonce_field('jsd_save_settings'); ?>
                <table class="form-table">
                    <tr><th>隐藏教程路径</th><td><input name="tutorial_path" class="regular-text" style="width:100%;max-width:680px" value="<?php echo esc_attr($options['tutorial_path']); ?>"></td></tr>
                    <tr><th>下载密码</th><td><input name="download_password" type="password" class="regular-text" autocomplete="new-password"><p class="description">留空保留旧密码；三个资源共用。</p></td></tr>
                    <tr><th>压缩包解压密码</th><td><input name="archive_extract_password" class="regular-text" value="<?php echo esc_attr($options['archive_extract_password']); ?>"><p class="description">电脑和 Android 当前统一为 111；TXT 不需要解压。</p></td></tr>
                    <tr><th>电脑下载显示名</th><td><input name="download_name" class="regular-text" value="<?php echo esc_attr($options['download_name']); ?>"></td></tr>
                    <tr><th>Android 下载显示名</th><td><input name="android_name" class="regular-text" value="<?php echo esc_attr($options['android_name']); ?>"></td></tr>
                    <tr><th>Android 订阅链接 1</th><td><input name="android_subscription_link_1" type="url" class="regular-text" style="width:100%;max-width:760px" value="" autocomplete="off" placeholder="粘贴新的订阅链接 1"><p class="description">当前链接不在后台页面显示。填写新链接并保存后会覆盖原链接；留空则保留原链接。</p></td></tr>
                    <tr><th>Android 订阅链接 2</th><td><input name="android_subscription_link_2" type="url" class="regular-text" style="width:100%;max-width:760px" value="" autocomplete="off" placeholder="粘贴新的订阅链接 2"><p class="description">填写新链接并保存后覆盖原链接；留空则保留原链接。</p></td></tr>
                    <tr><th>Shadowrocket 私有内容文件名</th><td><input name="ios_config_name" class="regular-text" value="<?php echo esc_attr($options['ios_config_name']); ?>"><p class="description">仅作为服务器私有内容源，不再提供给客户下载。</p></td></tr>
                    <tr><th>Apple / Shadowrocket 订阅内容</th><td><textarea name="ios_subscription_content" rows="12" class="large-text code" style="max-width:900px" spellcheck="false" autocomplete="off" placeholder="粘贴新的 Apple / Shadowrocket 节点内容；建议每个节点一行"></textarea><p class="description">当前节点内容不在后台页面显示。粘贴新的完整内容并保存后会整体覆盖原内容；留空则保留原内容。该内容仅保存在服务器私有文件中。</p></td></tr>
                    <tr><th>付款下载</th><td><label><input type="checkbox" name="payment_enabled" value="1" <?php checked($options['payment_enabled'], '1'); ?>> 启用</label></td></tr>
                    <tr><th>售价</th><td><input name="payment_amount" type="number" min="0" step="0.01" class="small-text" value="<?php echo esc_attr($options['payment_amount']); ?>"> <select name="payment_currency"><?php foreach(['CNY'=>'CNY 人民币','USD'=>'USD 美元','EUR'=>'EUR 欧元'] as $c=>$l): ?><option value="<?php echo esc_attr($c); ?>" <?php selected($options['payment_currency'], $c); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>收款码</th><td><?php if (self::payment_qr_url($options)): ?><p><img src="<?php echo esc_url(self::payment_qr_url($options)); ?>" style="max-width:180px"></p><?php endif; ?><input name="payment_qr" type="file" accept=".jpg,.jpeg,.png,.webp"></td></tr>
                    <tr><th>收款方关键词</th><td><input name="payment_payee_keywords" class="regular-text" value="<?php echo esc_attr($options['payment_payee_keywords']); ?>"></td></tr>
                    <tr><th>LinkAI 识图 App Code</th><td><input name="payment_app_code" class="regular-text" value="<?php echo esc_attr($options['payment_app_code']); ?>"><p class="description">留空则使用现有在线客服的 App Code。对应应用必须开启“图像识别”。</p></td></tr>
                    <tr><th>识别通过后</th><td><label><input type="checkbox" name="payment_auto_approve" value="1" <?php checked($options['payment_auto_approve'], '1'); ?>> 自动生成一次性下载链接</label></td></tr>
                </table>
                <?php submit_button('保存设置'); ?>
            </form>
        </div>
        <script>
        (function(){
            const ajaxUrl=<?php echo wp_json_encode($ajax_url); ?>;
            const nonce=<?php echo wp_json_encode($chunk_nonce); ?>;
            const selftestNonce=<?php echo wp_json_encode($payment_test_nonce); ?>;
            const chunkSize=<?php echo (int) $chunk_size; ?>;

            function id(){
                if(window.crypto&&crypto.getRandomValues){const a=new Uint8Array(16);crypto.getRandomValues(a);return Array.from(a).map(v=>v.toString(16).padStart(2,'0')).join('');}
                return (Date.now().toString(36)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)).replace(/[^a-z0-9]/g,'').padEnd(24,'0').slice(0,32);
            }
            async function upload(resource,button){
                const input=document.getElementById('freedom-file-'+resource), file=input.files[0];
                const msg=document.getElementById('freedom-upload-msg-'+resource), progress=document.getElementById('freedom-progress-'+resource);
                if(!file){msg.textContent='请先选择文件';return;}
                const total=Math.ceil(file.size/chunkSize), uploadId=id();
                button.disabled=true; progress.style.display='inline-block'; progress.value=0; msg.textContent='准备上传…';
                try{
                    for(let i=0;i<total;i++){
                        const blob=file.slice(i*chunkSize,Math.min(file.size,(i+1)*chunkSize));
                        let json=null,lastError=null;
                        for(let attempt=1;attempt<=3;attempt++){
                            try{
                                const fd=new FormData();
                                fd.append('action','freedom_chunk_upload'); fd.append('nonce',nonce); fd.append('resource',resource); fd.append('upload_id',uploadId);
                                fd.append('chunk_index',String(i)); fd.append('total_chunks',String(total)); fd.append('total_size',String(file.size)); fd.append('filename',file.name);
                                fd.append('chunk',blob,'chunk.bin');
                                const res=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd});
                                json=await res.json();
                                if(!json.success) throw new Error(json.data&&json.data.message?json.data.message:'上传失败');
                                lastError=null; break;
                            }catch(err){
                                lastError=err;
                                if(attempt<3){
                                    msg.textContent='第 '+(i+1)+'/'+total+' 块上传重试 '+attempt+'/2…';
                                    await new Promise(resolve=>setTimeout(resolve,800*attempt));
                                }
                            }
                        }
                        if(lastError) throw lastError;
                        progress.value=json.data.progress||Math.round((i+1)/total*100);
                        msg.textContent=(json.data.message||'上传中…')+' '+progress.value+'%';
                    }
                    msg.textContent='✅ 上传完成';
                    document.getElementById('freedom-status-'+resource).innerHTML='<strong style="color:#198754">当前：✅ 已就绪 · '+file.name+' · '+(file.size/1024/1024).toFixed(1)+' MB</strong>';
                    input.value='';
                }catch(e){msg.textContent='❌ '+(e.message||'上传失败');}
                finally{button.disabled=false;}
            }
            document.querySelectorAll('.freedom-chunk-upload').forEach(b=>b.addEventListener('click',()=>upload(b.dataset.resource,b)));

            const test=document.getElementById('freedom-payment-selftest'), testMsg=document.getElementById('freedom-payment-selftest-msg');
            test.addEventListener('click',async function(){
                test.disabled=true; testMsg.textContent='正在测试…';
                const fd=new FormData(); fd.append('action','freedom_payment_image_selftest'); fd.append('nonce',selftestNonce);
                try{const r=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd});const j=await r.json();if(!j.success)throw new Error(j.data&&j.data.message?j.data.message:'测试失败');testMsg.textContent='✅ '+j.data.message;}
                catch(e){testMsg.textContent='❌ '+(e.message||'测试失败');}
                finally{test.disabled=false;}
            });
        })();
        </script>
        <?php
    }

    public static function handle_save_settings(): void
    {
        if (!current_user_can('manage_options')) wp_die('无权限。');
        check_admin_referer('jsd_save_settings');
        $options=self::get_options(); $old_path=$options['tutorial_path'];

        $path=isset($_POST['tutorial_path'])?trim(sanitize_text_field(wp_unslash($_POST['tutorial_path'])),'/'):'';
        $path=preg_replace('~[^A-Za-z0-9/_-]+~','-',$path); $path=preg_replace('~/+~','/',(string)$path);
        if($path==='')$path=self::defaults()['tutorial_path']; $options['tutorial_path']=$path;

        $password=isset($_POST['download_password'])?(string)wp_unslash($_POST['download_password']):'';
        if($password!==''){if(strlen($password)<6)self::redirect_settings_error('下载密码至少需要 6 个字符。');$options['password_hash']=wp_hash_password($password);}

        foreach(['download_name','android_name','ios_config_name'] as $k){$v=isset($_POST[$k])?sanitize_file_name(wp_unslash($_POST[$k])):'';if($v!=='')$options[$k]=$v;}
        foreach(['android_subscription_link_1','android_subscription_link_2'] as $k){
            $v=isset($_POST[$k])?trim((string)wp_unslash($_POST[$k])):'';
            // 后台不回显旧值，所以留空必须表示“保留当前值”，不能清空。
            if($v===''){ continue; }
            $clean=esc_url_raw($v,['http','https']);
            if($clean==='' || !filter_var($clean,FILTER_VALIDATE_URL)) self::redirect_settings_error('Android 订阅链接格式不正确。');
            $options[$k]=$clean;
        }
        $ep=isset($_POST['archive_extract_password'])?sanitize_text_field(wp_unslash($_POST['archive_extract_password'])):''; $options['archive_extract_password']=$ep!==''?$ep:'111';

        if(!empty($_FILES['download_file']['name']))$options=self::handle_resource_upload($_FILES['download_file'],$options,'desktop');
        if(!empty($_FILES['android_file']['name']))$options=self::handle_resource_upload($_FILES['android_file'],$options,'android');
        if(!empty($_FILES['ios_config_file']['name']))$options=self::handle_resource_upload($_FILES['ios_config_file'],$options,'ios');
        if(isset($_POST['ios_subscription_content'])){
            $ios_content=(string)wp_unslash($_POST['ios_subscription_content']);
            if(trim($ios_content)!=='') $options=self::save_ios_subscription_content($options,$ios_content);
        }

        $options['payment_enabled']=!empty($_POST['payment_enabled'])?'1':'0';
        $amount=isset($_POST['payment_amount'])?trim((string)wp_unslash($_POST['payment_amount'])):'';
        $options['payment_amount']=$amount!==''?number_format(max(0,(float)$amount),2,'.',''):'';
        $cur=isset($_POST['payment_currency'])?strtoupper(sanitize_text_field(wp_unslash($_POST['payment_currency']))):'CNY';
        $options['payment_currency']=in_array($cur,['CNY','USD','EUR'],true)?$cur:'CNY';
        $options['payment_payee_keywords']=isset($_POST['payment_payee_keywords'])?sanitize_text_field(wp_unslash($_POST['payment_payee_keywords'])):'';
        $options['payment_app_code']=isset($_POST['payment_app_code'])?sanitize_text_field(wp_unslash($_POST['payment_app_code'])):'';
        $options['payment_auto_approve']=!empty($_POST['payment_auto_approve'])?'1':'0';
        if(!empty($_FILES['payment_qr']['name']))$options['payment_qr_file']=self::handle_payment_qr_upload($_FILES['payment_qr'],(string)($options['payment_qr_file']??''));

        update_option(self::OPTION_NAME,$options,false);
        if($old_path!==$options['tutorial_path']){self::remove_physical_tutorial_router($old_path);self::register_rewrite_rule();flush_rewrite_rules(false);}
        if(!self::ensure_physical_tutorial_router())self::redirect_settings_error('教程物理路由无法创建。');
        wp_safe_redirect(add_query_arg(['page'=>'jinshanjiao-secure-tutorial','updated'=>'1'],admin_url('admin.php')));exit;
    }

    private static function redirect_settings_error(string $message): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => 'jinshanjiao-secure-tutorial',
            'jsd_error' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    private static function payment_qr_directory(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'freedom-payment';
    }

    private static function payment_qr_base_url(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['baseurl']) . 'freedom-payment';
    }

    private static function payment_qr_url(array $options): string
    {
        $file = sanitize_file_name((string) ($options['payment_qr_file'] ?? ''));
        if ($file !== '') {
            $path = trailingslashit(self::payment_qr_directory()) . $file;
            if (is_file($path)) {
                return trailingslashit(self::payment_qr_base_url()) . rawurlencode($file);
            }
        }

        // freedom 默认收款码已随插件内置；后台重新上传后会自动覆盖默认显示。
        $bundled = trailingslashit(plugin_dir_path(__FILE__)) . 'assets/payment/freedom-wechat-qr.png';
        if (is_file($bundled)) {
            return plugin_dir_url(__FILE__) . 'assets/payment/freedom-wechat-qr.png';
        }

        return '';
    }

    private static function handle_payment_qr_upload(array $file, string $old_file = ''): string
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::redirect_settings_error('收款码上传失败，错误代码：' . (int) ($file['error'] ?? -1));
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || $tmp === '' || !is_uploaded_file($tmp)) {
            self::redirect_settings_error('收款码必须是 JPG、PNG 或 WebP 图片。');
        }
        $image_info = @getimagesize($tmp);
        if (!$image_info) {
            self::redirect_settings_error('收款码不是有效图片。');
        }
        $dir = self::payment_qr_directory();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            self::redirect_settings_error('无法创建收款码目录。');
        }
        $stored = 'qr-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '.' . $ext;
        $dest = trailingslashit($dir) . $stored;
        if (!@move_uploaded_file($tmp, $dest)) {
            self::redirect_settings_error('无法保存收款码。');
        }
        @chmod($dest, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
        @file_put_contents(trailingslashit($dir) . 'index.php', "<?php\nhttp_response_code(403);\nexit;\n");
        $old_file = sanitize_file_name($old_file);
        if ($old_file !== '' && $old_file !== $stored) {
            $old_path = trailingslashit($dir) . $old_file;
            if (is_file($old_path)) {
                @unlink($old_path);
            }
        }
        return $stored;
    }

    private static function payment_review_directory(): string
    {
        $dir = trailingslashit(self::ensure_private_directory()) . 'payment-review';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }

    private static function linkai_options(): array
    {
        $options = get_option('linkai_ai_customer_service_options', []);
        return is_array($options) ? $options : [];
    }

    private static function resource_map(array $options): array
    {
        return [
            'desktop'=>['label'=>'电脑软件包','file_option'=>'download_file','name_option'=>'download_name','name'=>(string)($options['download_name'] ?? 'freedom-desktop-package.rar')],
            'android'=>['label'=>'Android 软件包','file_option'=>'android_file','name_option'=>'android_name','name'=>(string)($options['android_name'] ?? 'freedom-android.rar')],
            'ios'=>['label'=>'Shadowrocket 节点 TXT','file_option'=>'ios_config_file','name_option'=>'ios_config_name','name'=>(string)($options['ios_config_name'] ?? 'freedom-shadowrocket-nodes.txt')],
        ];
    }

    private static function normalize_resource_key(string $resource): string
    {
        $resource=sanitize_key($resource);
        return in_array($resource,['desktop','android','ios'],true) ? $resource : 'desktop';
    }

    private static function resolve_resource_file(array $options,string $resource)
    {
        $resource=self::normalize_resource_key($resource);
        $map=self::resource_map($options);
        $stored=sanitize_file_name((string)($options[$map[$resource]['file_option']] ?? ''));
        if ($stored==='') return false;
        $path=trailingslashit(self::ensure_private_directory()).$stored;
        return is_file($path) ? $path : false;
    }

    private static function resource_download_name(array $options,string $resource): string
    {
        $resource=self::normalize_resource_key($resource);
        $map=self::resource_map($options);
        $name=sanitize_file_name((string)$map[$resource]['name']);
        return $name!=='' ? $name : 'download.bin';
    }

    /**
     * Android 只展示订阅链接；两个链接轮换使用。
     * Apple / Shadowrocket 继续使用私有 TXT 中的订阅/节点内容。
     */
    private static function android_subscription_links(array $options): array
    {
        $links = [];
        foreach (['android_subscription_link_1', 'android_subscription_link_2'] as $key) {
            $url = isset($options[$key]) ? trim((string) $options[$key]) : '';
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $links[] = $url;
            }
        }
        return array_values(array_unique($links));
    }

    private static function current_android_subscription_link(array $options): string
    {
        $links = self::android_subscription_links($options);
        if (!$links) {
            return '';
        }
        if (count($links) === 1) {
            return $links[0];
        }
        // 每 30 秒轮换一次，页面初始展示和验证后的复制内容使用同一规则。
        $index = (int) floor(time() / 30) % count($links);
        return $links[$index];
    }

    private static function ios_subscription_content(array $options): string
    {
        $file = self::resolve_resource_file($options, 'ios');
        if (!$file || !is_readable($file)) {
            return '';
        }

        $size = @filesize($file);
        if ($size === false || $size < 1 || $size > 512 * 1024) {
            return '';
        }

        $content = @file_get_contents($file);
        if (!is_string($content)) {
            return '';
        }

        $content = str_replace("\0", '', $content);
        $content = wp_check_invalid_utf8($content, true);
        return trim($content);
    }

    /**
     * SECURITY: This method is called only after password/payment verification.
     * Never call it while rendering the public tutorial page.
     */
    private static function mobile_copy_content(array $options, string $resource): string
    {
        $resource = self::normalize_resource_key($resource);
        return $resource === 'android'
            ? self::current_android_subscription_link($options)
            : ($resource === 'ios' ? self::ios_subscription_content($options) : '');
    }

    private static function save_ios_subscription_content(array $options, string $content): array
    {
        $content = str_replace("\0", '', $content);
        $content = wp_check_invalid_utf8($content, true);
        $content = trim($content);

        // 留空表示保留原文件，防止误清空。
        if ($content === '') {
            return $options;
        }
        if (strlen($content) > 512 * 1024) {
            self::redirect_settings_error('Apple / Shadowrocket 订阅内容不能超过 512KB。');
        }

        $dir = self::ensure_private_directory();
        if (!is_dir($dir) || !is_writable($dir)) {
            self::redirect_settings_error('私有下载目录不可写，无法保存 Apple 订阅内容。');
        }

        $old = self::resolve_resource_file($options, 'ios');
        $stored = 'ios-manual-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '.txt';
        $dest = trailingslashit($dir) . $stored;

        if (@file_put_contents($dest, $content . "\n", LOCK_EX) === false) {
            self::redirect_settings_error('Apple / Shadowrocket 订阅内容保存失败。');
        }
        @chmod($dest, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);

        $options['ios_config_file'] = $stored;
        if (empty($options['ios_config_name'])) {
            $options['ios_config_name'] = 'freedom-shadowrocket-nodes.txt';
        }

        if ($old && is_file($old) && realpath($old) !== realpath($dest)) {
            @unlink($old);
        }

        return $options;
    }

    private static function handle_resource_upload(array $file,array $options,string $resource): array
    {
        $resource=self::normalize_resource_key($resource);
        $map=self::resource_map($options);
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) self::redirect_settings_error($map[$resource]['label'].'上传失败。');
        $original=sanitize_file_name((string)($file['name'] ?? ''));
        $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        $allowed=$resource==='ios' ? ['txt'] : ['rar','zip','7z','exe','msi','dmg','deb','rpm','apk'];
        if (!in_array($ext,$allowed,true)) self::redirect_settings_error($map[$resource]['label'].'文件类型不支持。');
        $tmp=(string)($file['tmp_name'] ?? '');
        if ($tmp==='' || !is_uploaded_file($tmp)) self::redirect_settings_error('上传临时文件无效。');
        $dir=self::ensure_private_directory();
        $stored=$resource.'-'.gmdate('Ymd-His').'-'.wp_generate_password(8,false,false).'.'.$ext;
        $dest=trailingslashit($dir).$stored;
        if (!@move_uploaded_file($tmp,$dest)) self::redirect_settings_error('服务器无法保存'.$map[$resource]['label'].'。');
        @chmod($dest, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
        $old=self::resolve_resource_file($options,$resource);
        $options[$map[$resource]['file_option']]=$stored;
        if (empty($options[$map[$resource]['name_option']])) $options[$map[$resource]['name_option']]=$original;
        if ($old && is_file($old) && realpath($old)!==realpath($dest)) @unlink($old);
        return $options;
    }

    private static function issue_download_token(array $options, string $source = 'password', string $resource = 'desktop'): array
    {
        $resource=self::normalize_resource_key($resource);
        $file=self::resolve_resource_file($options,$resource);
        if (!$file) return ['error'=>'所选下载资源尚未配置。'];
        try {$token=bin2hex(random_bytes(32));} catch (Exception $e) {$token=wp_generate_password(64,false,false);}
        $token_key='jsd_dl_'.hash('sha256',$token);
        set_transient($token_key,[
            'file'=>basename($file),
            'name'=>self::resource_download_name($options,$resource),
            'resource'=>$resource,
            'created'=>time(),
            'source'=>sanitize_key($source),
        ],self::TOKEN_TTL);
        return ['url'=>add_query_arg(self::DOWNLOAD_QUERY_VAR,rawurlencode($token),home_url('/')),'expires_in'=>self::TOKEN_TTL,'resource'=>$resource];
    }

    private static function temporary_payment_image_url(string $file): array
    {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = wp_generate_password(64, false, false);
        }
        set_transient('freedom_payimg_' . hash('sha256', $token), ['file' => basename($file)], self::PAYMENT_IMAGE_TTL);
        return [
            'token' => $token,
            'url' => add_query_arg(self::PAYMENT_IMAGE_QUERY_VAR, rawurlencode($token), home_url('/')),
        ];
    }

    private static function serve_temporary_payment_image(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9]{40,128}$/', $token)) {
            status_header(404);
            exit;
        }
        $key = 'freedom_payimg_' . hash('sha256', $token);
        $payload = get_transient($key);
        if (!is_array($payload) || empty($payload['file'])) {
            status_header(410);
            exit;
        }
        $dir = realpath(self::payment_review_directory());
        $file = realpath(trailingslashit(self::payment_review_directory()) . sanitize_file_name((string) $payload['file']));
        if (!$dir || !$file || !is_file($file) || strpos($file, $dir . DIRECTORY_SEPARATOR) !== 0) {
            status_header(404);
            exit;
        }
        $info = @getimagesize($file);
        $mime = is_array($info) && !empty($info['mime']) ? $info['mime'] : 'application/octet-stream';
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
    }

    private static function lower_text(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function normalize_currency(string $value): string
    {
        $value = strtoupper(trim($value));
        $map = [
            'RMB' => 'CNY',
            '人民币' => 'CNY',
            '¥' => 'CNY',
            '￥' => 'CNY',
            '美元' => 'USD',
            '$' => 'USD',
            '欧元' => 'EUR',
            '€' => 'EUR',
        ];
        return $map[$value] ?? $value;
    }

    private static function receipt_registry(): array
    {
        $registry = get_option(self::RECEIPT_REGISTRY_OPTION, []);
        return is_array($registry) ? $registry : [];
    }

    private static function receipt_already_used(string $image_hash, string $transaction_id): bool
    {
        foreach (self::receipt_registry() as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($image_hash !== '' && hash_equals((string) ($item['image_hash'] ?? ''), $image_hash)) {
                return true;
            }
            $saved_txn = trim((string) ($item['transaction_id'] ?? ''));
            if ($transaction_id !== '' && $saved_txn !== '' && hash_equals($saved_txn, $transaction_id)) {
                return true;
            }
        }
        return false;
    }

    private static function remember_receipt(string $image_hash, string $transaction_id, array $ai): void
    {
        $registry = self::receipt_registry();
        $registry[] = [
            'image_hash' => $image_hash,
            'transaction_id' => $transaction_id,
            'created_at' => current_time('mysql'),
            'amount' => $ai['amount'] ?? '',
            'currency' => $ai['currency'] ?? '',
        ];
        if (count($registry) > 500) {
            $registry = array_slice($registry, -500);
        }
        update_option(self::RECEIPT_REGISTRY_OPTION, $registry, false);
    }

    private static function parse_ai_json(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', (string) $content);
        $data = json_decode((string) $content, true);
        return is_array($data) ? $data : [];
    }

    private static function verify_payment_image_with_linkai(string $image_url, array $options)
    {
        $linkai = self::linkai_options();
        $api_key = trim((string) ($linkai['api_key'] ?? ''));
        if ($api_key === '') {
            return new WP_Error('no_linkai_key', 'LinkAI API Key 尚未配置。');
        }
        $app_code = trim((string) ($options['payment_app_code'] ?? ''));
        if ($app_code === '') {
            $app_code = trim((string) ($linkai['app_code'] ?? ''));
        }

        $expected_amount = number_format((float) ($options['payment_amount'] ?? 0), 2, '.', '');
        $currency = strtoupper((string) ($options['payment_currency'] ?? 'CNY'));
        $payee = trim((string) ($options['payment_payee_keywords'] ?? ''));

        $prompt = "你是付款截图核验器。只根据图片中清晰可见的信息判断，不要猜测被遮挡或不存在的信息。"
            . "目标订单金额={$expected_amount} {$currency}。"
            . ($payee !== '' ? "预期收款方关键词={$payee}。" : '')
            . "请只返回 JSON 对象，字段必须为："
            . "is_payment(boolean), paid(boolean), amount(number|null), currency(string), payee(string), transaction_id(string), confidence(number 0-1), reason(string)。"
            . "paid 只有在截图明确显示支付成功/交易成功/已付款时才为 true。";

        $body = [
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $image_url]],
                ],
            ]],
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
        ];
        if ($app_code !== '') {
            $body['app_code'] = $app_code;
        }
        if (!empty($linkai['model'])) {
            $body['model'] = sanitize_text_field((string) $linkai['model']);
        }

        $response = wp_remote_post('https://api.link-ai.tech/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($data) ? (string) ($data['error']['message'] ?? 'LinkAI 图像识别失败。') : 'LinkAI 图像识别失败。';
            return new WP_Error('linkai_payment_verify_failed', $message);
        }
        $content = is_array($data) ? (string) ($data['choices'][0]['message']['content'] ?? '') : '';
        $parsed = self::parse_ai_json($content);
        if (!$parsed) {
            return new WP_Error('invalid_ai_json', 'AI 没有返回可解析的付款识别结果。');
        }
        return $parsed;
    }

    private static function payment_rate_limit_key(): string
    {
        return 'freedom_payment_attempt_' . md5(self::client_ip());
    }

    private static function check_payment_rate_limit(): void
    {
        $key = self::payment_rate_limit_key();
        $attempts = (int) get_transient($key);
        if ($attempts >= 8) {
            wp_send_json_error(['message' => '付款截图核验次数过多，请 10 分钟后再试。'], 429);
        }
        set_transient($key, $attempts + 1, 10 * MINUTE_IN_SECONDS);
    }

    public static function ajax_verify_payment_screenshot(): void
    {
        check_ajax_referer('jsd_download_verify', 'nonce');
        self::check_payment_rate_limit();

        $options = self::get_options();
        $resource = isset($_POST['resource']) ? self::normalize_resource_key((string) wp_unslash($_POST['resource'])) : 'desktop';
        if (empty($options['payment_enabled'])) {
            wp_send_json_error(['message' => '付款下载暂未启用。'], 403);
        }
        if ((float) ($options['payment_amount'] ?? 0) <= 0 || self::payment_qr_url($options) === '') {
            wp_send_json_error(['message' => '管理员尚未配置完整的售价或收款码。'], 503);
        }
        if (!self::resolve_resource_file($options, $resource)) {
            wp_send_json_error(['message' => '所选下载资源尚未配置。'], 503);
        }
        if (empty($_FILES['payment_screenshot']['name'])) {
            wp_send_json_error(['message' => '请上传付款截图。'], 400);
        }

        $file = $_FILES['payment_screenshot'];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => '截图上传失败。'], 400);
        }
        if ((int) ($file['size'] ?? 0) > 6 * 1024 * 1024) {
            wp_send_json_error(['message' => '截图不能超过 6MB。'], 400);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($tmp === '' || !is_uploaded_file($tmp) || !in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !@getimagesize($tmp)) {
            wp_send_json_error(['message' => '只支持有效的 JPG、PNG 或 WebP 付款截图。'], 400);
        }

        $image_hash = hash_file('sha256', $tmp);
        if (self::receipt_already_used($image_hash, '')) {
            wp_send_json_error(['message' => '这张付款截图已经使用过，不能重复下载。'], 409);
        }

        $dir = self::payment_review_directory();
        $stored = 'receipt-' . gmdate('Ymd-His') . '-' . wp_generate_password(10, false, false) . '.' . $ext;
        $dest = trailingslashit($dir) . $stored;
        if (!@move_uploaded_file($tmp, $dest)) {
            wp_send_json_error(['message' => '服务器无法暂存付款截图。'], 500);
        }
        @chmod($dest, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);

        $temp = self::temporary_payment_image_url($dest);
        $ai = self::verify_payment_image_with_linkai($temp['url'], $options);
        delete_transient('freedom_payimg_' . hash('sha256', $temp['token']));
        @unlink($dest);

        if (is_wp_error($ai)) {
            wp_send_json_error([
                'message' => '付款截图暂时无法自动识别：' . $ai->get_error_message() . ' 请确认 LinkAI 应用已开启图像识别。',
            ], 502);
        }

        $recognized_amount = isset($ai['amount']) && is_numeric($ai['amount']) ? (float) $ai['amount'] : -1;
        $expected_amount = (float) $options['payment_amount'];
        $amount_ok = $recognized_amount >= 0 && abs($recognized_amount - $expected_amount) <= 0.01;
        $currency = self::normalize_currency((string) ($ai['currency'] ?? ''));
        $expected_currency = self::normalize_currency((string) $options['payment_currency']);
        $currency_ok = $currency === '' || $currency === $expected_currency;
        $confidence = isset($ai['confidence']) && is_numeric($ai['confidence']) ? (float) $ai['confidence'] : 0;
        $transaction_id = trim(sanitize_text_field((string) ($ai['transaction_id'] ?? '')));
        $payee_text = self::lower_text((string) ($ai['payee'] ?? ''));

        $payee_ok = true;
        $keywords = array_filter(array_map('trim', explode(',', (string) ($options['payment_payee_keywords'] ?? ''))));
        if ($keywords) {
            $payee_ok = false;
            foreach ($keywords as $keyword) {
                $needle = self::lower_text($keyword);
                $found = function_exists('mb_strpos') ? mb_strpos($payee_text, $needle, 0, 'UTF-8') : strpos($payee_text, $needle);
                if ($keyword !== '' && $found !== false) {
                    $payee_ok = true;
                    break;
                }
            }
        }

        if (self::receipt_already_used('', $transaction_id)) {
            wp_send_json_error(['message' => '这个交易号已经用于下载，不能重复使用。'], 409);
        }

        $passed = !empty($ai['is_payment'])
            && !empty($ai['paid'])
            && $amount_ok
            && $currency_ok
            && $payee_ok
            && $confidence >= 0.75;

        if (!$passed) {
            $reason = sanitize_text_field((string) ($ai['reason'] ?? '截图信息与订单要求不匹配。'));
            wp_send_json_error([
                'message' => '未通过自动付款核验。' . ($reason !== '' ? ' ' . $reason : ''),
                'result' => [
                    'amount_ok' => $amount_ok,
                    'currency_ok' => $currency_ok,
                    'payee_ok' => $payee_ok,
                    'confidence' => $confidence,
                ],
            ], 422);
        }

        if (($options['payment_auto_approve'] ?? '1') !== '1') {
            wp_send_json_error(['message' => '截图识别通过，但当前设置为人工确认后放行。'], 202);
        }

        delete_transient(self::payment_rate_limit_key());
        self::remember_receipt($image_hash, $transaction_id, $ai);

        // Apple / Shadowrocket：付款核验后只返回全部节点内容，不提供 TXT 下载。
        if ($resource==='ios') {
            $copy_content=self::mobile_copy_content($options,$resource);
            if ($copy_content==='') wp_send_json_error(['message'=>'Apple / Shadowrocket 节点内容尚未配置。'],503);
            wp_send_json_success([
                'url'=>'',
                'expires_in'=>0,
                'copy_content'=>$copy_content,
                'copy_title'=>'Apple / Shadowrocket 节点内容',
                'message'=>'付款截图已通过核验。全部节点内容已解锁，请点击“点击拷贝”。',
            ]);
        }

        $download = self::issue_download_token($options, 'payment', $resource);
        if (!empty($download['error'])) {
            wp_send_json_error(['message' => $download['error']], 503);
        }
        $response = [
            'url' => $download['url'],
            'expires_in' => $download['expires_in'],
            'message' => '付款截图已通过核验，下载即将开始。',
        ];

        if ($resource==='android') {
            $copy_content = self::mobile_copy_content($options, $resource);
            if ($copy_content !== '') {
                $response['copy_content'] = $copy_content;
                $response['copy_title'] = 'Android 订阅链接';
                $response['message'] = '付款截图已通过核验。服务器已解锁 Android 订阅链接，可一键复制；下载也将自动开始。';
            }
        }

        wp_send_json_success($response);
    }

    private static function resolve_download_file(array $options)
    {
        return self::resolve_resource_file($options,'desktop');
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
        check_ajax_referer('jsd_download_verify','nonce');
        $rate_key=self::rate_limit_key();
        $attempts=(int)get_transient($rate_key);
        if ($attempts>=8) wp_send_json_error(['message'=>'尝试次数过多，请 10 分钟后再试。'],429);

        $options=self::get_options();
        $resource=isset($_POST['resource']) ? self::normalize_resource_key((string)wp_unslash($_POST['resource'])) : 'desktop';
        if (!self::resolve_resource_file($options,$resource)) wp_send_json_error(['message'=>'所选下载资源尚未配置。'],503);
        if (empty($options['password_hash'])) wp_send_json_error(['message'=>'下载密码尚未配置。'],503);

        $password=isset($_POST['password']) ? (string)wp_unslash($_POST['password']) : '';
        if ($password==='' || !wp_check_password($password,$options['password_hash'])) {
            set_transient($rate_key,$attempts+1,10*MINUTE_IN_SECONDS);
            wp_send_json_error(['message'=>'密码不正确，请重新输入。'],403);
        }

        delete_transient($rate_key);

        // Apple / Shadowrocket：只解锁节点内容，不生成 TXT 下载链接。
        if ($resource==='ios') {
            $copy_content=self::mobile_copy_content($options,$resource);
            if ($copy_content==='') wp_send_json_error(['message'=>'Apple / Shadowrocket 节点内容尚未配置。'],503);
            wp_send_json_success([
                'url'=>'',
                'expires_in'=>0,
                'copy_content'=>$copy_content,
                'copy_title'=>'Apple / Shadowrocket 节点内容',
                'message'=>'验证成功。全部节点内容已解锁，请点击“点击拷贝”。',
            ]);
        }

        $download=self::issue_download_token($options,'password',$resource);
        if (!empty($download['error'])) wp_send_json_error(['message'=>$download['error']],503);

        $response=[
            'url'=>$download['url'],
            'expires_in'=>$download['expires_in'],
            'message'=>'验证成功，下载链接 60 秒内有效且只能使用一次。',
        ];

        if ($resource==='android') {
            $copy_content=self::mobile_copy_content($options,$resource);
            if ($copy_content!=='') {
                $response['copy_content']=$copy_content;
                $response['copy_title']='Android 订阅链接';
                $response['message']='验证成功。服务器已解锁 Android 订阅链接，可一键复制；下载也将自动开始。';
            }
        }

        wp_send_json_success($response);
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

        // 在开始输出前立即删除，确保令牌最多使用一次。
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

    private static function tutorial_assets_directory(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'jinshanjiao-tutorial-assets/v2rayn';
    }

    private static function tutorial_assets_base_url(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['baseurl']) . 'jinshanjiao-tutorial-assets/v2rayn';
    }

    private static function tutorial_assets_ready(): bool
    {
        $sets=[
            ['dir'=>trailingslashit(plugin_dir_path(__FILE__)).'assets/freedom-v7246','count'=>8],
            ['dir'=>trailingslashit(plugin_dir_path(__FILE__)).'assets/android','count'=>16],
            ['dir'=>trailingslashit(plugin_dir_path(__FILE__)).'assets/ios','count'=>2],
        ];
        foreach ($sets as $set) for($i=1;$i<=$set['count'];$i++) if(!is_file(trailingslashit($set['dir']).'image'.$i.'.webp')) return false;
        return true;
    }

    private static function handle_tutorial_assets_upload(array $file): void
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::redirect_settings_error('教程截图包上传失败，PHP 上传错误代码：' . (int) ($file['error'] ?? -1));
        }
        if (!class_exists('ZipArchive')) {
            self::redirect_settings_error('服务器没有启用 PHP ZipArchive，无法解压教程截图包。');
        }
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            self::redirect_settings_error('教程截图包必须是 ZIP 文件。');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            self::redirect_settings_error('教程截图包临时文件无效。');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            self::redirect_settings_error('教程截图 ZIP 无法打开。');
        }
        $dir = self::tutorial_assets_directory();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $zip->close();
            self::redirect_settings_error('无法创建教程截图目录。');
        }

        $found = 0;
        for ($i = 1; $i <= 10; $i++) {
            $wanted = 'image' . $i . '.webp';
            $entry_index = false;
            for ($j = 0; $j < $zip->numFiles; $j++) {
                $entry = $zip->getNameIndex($j);
                if ($entry !== false && basename($entry) === $wanted) {
                    $entry_index = $j;
                    break;
                }
            }
            if ($entry_index === false) {
                continue;
            }
            $info = $zip->statIndex($entry_index);
            if (!$info || ($info['size'] ?? 0) > 2 * 1024 * 1024) {
                continue;
            }
            $bytes = $zip->getFromIndex($entry_index);
            if ($bytes === false || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
                continue;
            }
            if (@file_put_contents(trailingslashit($dir) . $wanted, $bytes, LOCK_EX) !== false) {
                $found++;
            }
        }
        $zip->close();
        @file_put_contents(trailingslashit($dir) . 'index.php', "<?php\nhttp_response_code(403);\nexit;\n");

        if ($found !== 10) {
            self::redirect_settings_error('截图包只识别到 ' . $found . ' 张有效 WebP 图片，需要 image1.webp 到 image10.webp 共 10 张。');
        }
    }

    private static function image_url(string $filename): string
    {
        $filename = sanitize_file_name($filename);
        return plugin_dir_url(__FILE__) . 'assets/freedom-v7246/' . ltrim($filename, '/');
    }

    private static function platform_image_url(string $platform,string $filename): string
    {
        $platform=in_array($platform,['android','ios'],true) ? $platform : 'freedom-v7246';
        return plugin_dir_url(__FILE__).'assets/'.$platform.'/'.ltrim(sanitize_file_name($filename),'/');
    }


    private static function is_tutorial_ai_request(): bool
    {
        if (!isset($_POST['action']) || sanitize_key(wp_unslash($_POST['action'])) !== 'linkai_customer_chat') {
            return false;
        }
        $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        if ($referer === '') {
            return false;
        }
        $referer_path = trim((string) wp_parse_url($referer, PHP_URL_PATH), '/');
        $tutorial_path = trim((string) self::get_options()['tutorial_path'], '/');
        return $referer_path !== '' && hash_equals($tutorial_path, $referer_path);
    }

    private static function tutorial_ai_prompt(): string
    {
        return <<<'PROMPT'
你是本站 Freedom 多平台配置教程助手。先判断用户的平台：电脑 V2rayN、Android v2freeGo、或 iPhone/Apple TV Shadowrocket。
只依据教程回答；超出教程就明确说明未覆盖。订阅链接、登录密码、节点 URL 和节点 TXT 都是敏感凭据，不要要求用户发送完整内容。

电脑：V2rayN 7.24.6；完整解压后运行；Windows 可管理员启动；macOS dmg 拖入 Applications，损坏提示用 sudo xattr -cr /Applications/v2rayN.app；Linux 用 apt/dnf 安装。订阅分组填订阅链接和本站登录密码；更新当前订阅通常不通过代理；备用方法为节点 URL → 从剪贴板导入。分流 V3-绕过大陆，全球 V3-全局。Core 错误检查 v2Rayn\bin\Xray\xray.exe。
Android：v2freeGo；侧边栏 → 分组 → + → 类型订阅，填订阅链接和本站登录密码；分组界面更新；测速为三个点 → 连接测试 → URL测试；路由启用绕过中国域名和中国IP；设置启用绕过局域网/LAN；配置里选节点后点底部小飞机启动。备用导入为节点 URL 从剪贴板导入。Android 压缩包当前解压密码 111。
Shadowrocket：本站提供的 TXT 是节点地址列表，不是 default.conf。下载 TXT 后在 iPhone 文件中打开，复制节点 URL，回 Shadowrocket 按剪贴板提示确认导入；不要通过微信/QQ传播。底部配置选 default.conf；延迟测试方法改 CONNECT；首页连通性测试后选节点并打开开关。全局路由选“配置”为分流，选“代理”为全局。Apple TV 要与 iPhone 同 Wi-Fi、两端前台打开、开启本地网络权限并避免 AP 隔离。
PROMPT;
    }

    public static function inject_tutorial_ai_prompt(array $args, string $url): array
    {
        if (strpos($url, 'https://api.link-ai.tech/v1/chat/completions') !== 0 || !self::is_tutorial_ai_request()) {
            return $args;
        }
        if (empty($args['body']) || !is_string($args['body'])) {
            return $args;
        }
        $body = json_decode($args['body'], true);
        if (!is_array($body) || empty($body['messages']) || !is_array($body['messages'])) {
            return $args;
        }
        $messages = [];
        foreach ($body['messages'] as $message) {
            if (is_array($message) && ($message['role'] ?? '') === 'system') {
                continue;
            }
            $messages[] = $message;
        }
        array_unshift($messages, ['role' => 'system', 'content' => self::tutorial_ai_prompt()]);
        $body['messages'] = $messages;
        $args['body'] = wp_json_encode($body);
        return $args;
    }

    private static function render_resource_box(string $resource,string $label,bool $ready,bool $password_ready,bool $payment_base,string $qr,array $options,string $extract=''): void
    {
        $rid=sanitize_html_class($resource); $payready=$payment_base&&$ready; $copy_only=$resource==='ios'; ?>
        <div class="dl"><strong><?php echo esc_html($label);?></strong>
        <p><?php echo $copy_only ? '验证成功后显示全部节点内容，可一键复制到剪贴板。' : '每次下载都要重新验证，可使用密码或付款截图。';?></p>
        <div class="actions">
        <button class="btn" data-pw data-resource="<?php echo esc_attr($resource);?>" data-label="<?php echo esc_attr($label);?>" <?php disabled(!$ready||!$password_ready);?>><?php echo $copy_only?'🔐 密码验证':'🔐 密码下载';?></button>
        <button class="btn alt" data-paytoggle data-target="pay-<?php echo esc_attr($rid);?>" <?php disabled(!$payready);?>><?php echo $copy_only?'💳 付款验证':'💳 付款后下载';?></button></div>
        <?php if(!$ready):?><p style="color:#b42318">该资源尚未配置。</p><?php endif;?>
        <?php if($extract!==''):?><div class="extract">解压密码：<strong><?php echo esc_html($extract);?></strong></div><?php endif;?>
        <div class="pay" id="pay-<?php echo esc_attr($rid);?>"><?php if($payready):?><div class="paygrid"><div><img src="<?php echo esc_url($qr);?>" alt="付款收款码"></div><div><div class="price"><?php echo esc_html(number_format((float)$options['payment_amount'],2).' '.$options['payment_currency']);?></div><p><?php echo $copy_only?'付款成功后上传支付截图；核验通过后服务器返回全部 Apple / Shadowrocket 节点内容，只提供复制，不提供 TXT 下载。':'付款成功后上传支付截图，核验通过后自动生成 60 秒一次性下载链接。Android 的受保护内容不会写入页面源码；验证成功后服务器才返回当前内容并允许一键复制。';?></p><input id="payfile-<?php echo esc_attr($rid);?>" type="file" accept=".jpg,.jpeg,.png,.webp"><button class="btn" data-paysubmit data-resource="<?php echo esc_attr($resource);?>" data-file="payfile-<?php echo esc_attr($rid);?>" data-msg="paymsg-<?php echo esc_attr($rid);?>">上传截图并核验</button><div class="msg" id="paymsg-<?php echo esc_attr($rid);?>" role="status" aria-live="polite"></div><div class="copy-result" id="paycopy-<?php echo esc_attr($rid);?>" hidden><div class="copy-result-head"><strong data-copy-title>订阅 / 节点内容</strong><button type="button" class="freedom-copy-btn" data-copy-button data-copy-source="paycopytext-<?php echo esc_attr($rid);?>">点击拷贝</button></div><textarea class="copy-content" id="paycopytext-<?php echo esc_attr($rid);?>" rows="9" readonly spellcheck="false" aria-label="<?php echo esc_attr($label);?>订阅或节点内容"></textarea><div class="msg" data-copy-status role="status" aria-live="polite"></div></div></div></div><?php endif;?></div></div><?php
    }

    private static function render_tutorial_page(): void
    {
        status_header(200); nocache_headers(); header('X-Robots-Tag: noindex, nofollow, noarchive',true);
        add_filter('pre_get_document_title',static function(){return 'Freedom · 多平台配置使用教程';},999);
        $options=self::get_options();
        $ready=['desktop'=>(bool)self::resolve_resource_file($options,'desktop'),'android'=>(bool)self::resolve_resource_file($options,'android'),'ios'=>(bool)self::resolve_resource_file($options,'ios')];
        $password_ready=!empty($options['password_hash']);
        $payment_base=!empty($options['payment_enabled'])&&(float)$options['payment_amount']>0&&self::payment_qr_url($options)!=='';
        $qr=self::payment_qr_url($options); $ajax=admin_url('admin-ajax.php'); $nonce=wp_create_nonce('jsd_download_verify'); $ep=(string)($options['archive_extract_password']??'111');
        ?><!doctype html><html <?php language_attributes();?>><head><meta charset="<?php bloginfo('charset');?>"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head();?></head><body <?php body_class('freedom-tutorial-standalone');?>>
        <style>
        html,body{margin:0;background:#f6f8fb}.ft{--b:#1769e0;--d:#17324d;max-width:1480px;margin:auto;padding:28px 20px 80px;color:#263747;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif;line-height:1.75}.hero{background:linear-gradient(135deg,#14273f,#245db4);color:#fff;border-radius:20px;padding:36px 34px}.hero h1{color:#fff;margin:0}.nav{display:flex;gap:10px;flex-wrap:wrap;margin:20px 0}.nav a{background:#fff;border:1px solid #dfe7f0;padding:10px 15px;border-radius:10px;text-decoration:none;color:#27435f;font-weight:700}.tutorial-shell{display:grid;grid-template-columns:236px minmax(0,1fr);gap:24px;align-items:start}.side-toc{position:sticky;top:24px;max-height:calc(100vh - 48px);overflow:auto;background:#fff;border:1px solid #d9e3ee;border-radius:15px;padding:15px;box-shadow:0 8px 24px rgba(16,42,67,.07)}.side-toc h2{font-size:17px;margin:0 0 10px;color:var(--d)}.side-toc strong{display:block;margin:12px 0 5px;color:#53697d;font-size:12px;letter-spacing:.06em;text-transform:uppercase}.side-toc a{display:block;padding:6px 9px;border-radius:7px;color:#31516f;text-decoration:none;font-size:13px;line-height:1.35}.side-toc a:hover,.side-toc a[aria-current="true"]{background:#eaf2ff;color:#115dc8;font-weight:700}.tutorial-content{min-width:0}.platform{scroll-margin-top:24px;margin:0 0 28px}.platform+ .platform{margin-top:34px}.platform>h2{font-size:28px;color:var(--d)}.card{background:#fff;border:1px solid #dfe7f0;border-radius:14px;padding:24px 26px;margin:16px 0;box-shadow:0 6px 18px rgba(16,42,67,.04)}.card h3{margin-top:0;color:var(--d)}.shot{display:block;max-width:100%;height:auto;margin:15px auto;border:1px solid #e2e8f0;border-radius:9px}.warn,.note{padding:12px 14px;border-radius:10px;margin:12px 0}.warn{background:#fff5f3;border-left:4px solid #d93d2b}.note{background:#eef5ff;border-left:4px solid var(--b)}.dl{background:#f4f8ff;border:1px solid #cdddf4;border-radius:13px;padding:18px}.actions{display:flex;gap:9px;flex-wrap:wrap}.btn{border:0;background:var(--b);color:#fff!important;border-radius:9px;min-height:44px;padding:10px 16px;font-weight:700;cursor:pointer}.btn.alt{background:#fff;color:#31516f!important;border:1px solid #c9d7e8}.btn:disabled{opacity:.45}.extract{margin-top:10px;background:#fff8da;border:1px solid #e7d47c;border-radius:8px;padding:7px 10px;display:inline-block}.pay{display:none;margin-top:12px;border-top:1px dashed #cbd7e5;padding-top:12px}.pay.open{display:block}.paygrid{display:grid;grid-template-columns:180px 1fr;gap:18px}.pay img{width:170px;max-width:100%;border:1px solid #ddd;background:#fff;padding:6px;border-radius:9px}.price{font-size:24px;font-weight:800;color:var(--d)}.msg{font-size:14px;min-height:22px}.msg.error{color:#b42318}.msg.success{color:#157347}.copy-result{margin-top:14px;padding:14px;background:#fff;border:1px solid #b7cbe2;border-radius:10px}.copy-result[hidden]{display:none}.copy-result-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:9px}.copy-content{width:100%;box-sizing:border-box;resize:vertical;min-height:142px;border:1px solid #c8d5e3;border-radius:8px;padding:10px;font:13px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace;color:#21384d;background:#f8fbff;direction:ltr}@media(max-width:700px){}.freedom-copy-btn{display:inline-flex!important;visibility:visible!important;opacity:1!important;align-items:center!important;justify-content:center!important;flex:0 0 auto!important;min-width:110px!important;min-height:42px!important;padding:9px 16px!important;border:0!important;border-radius:8px!important;background:#7d8792!important;color:#fff!important;font-size:15px!important;font-weight:700!important;line-height:1!important;cursor:pointer!important;box-sizing:border-box!important}.freedom-copy-btn:hover{background:#606a75!important}.freedom-copy-btn:active{background:#4f5963!important}.modal{position:fixed;inset:0;background:rgba(10,25,42,.62);display:none;align-items:center;justify-content:center;z-index:999999;padding:20px}.modal.open{display:flex}.dialog{width:min(430px,100%);background:#fff;border-radius:16px;padding:24px}.field{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccd7e3;border-radius:8px;font-size:16px}.freedom-download-actions{display:flex!important;justify-content:flex-end!important;align-items:center!important;gap:12px!important;flex-wrap:wrap!important;margin-top:16px!important}.freedom-modal-btn{display:inline-flex!important;visibility:visible!important;opacity:1!important;align-items:center!important;justify-content:center!important;min-width:112px!important;height:44px!important;padding:0 18px!important;margin:0!important;border-radius:9px!important;font-size:16px!important;font-weight:700!important;line-height:1!important;cursor:pointer!important;box-sizing:border-box!important;text-decoration:none!important}.freedom-modal-btn-primary{background:#1769e0!important;color:#fff!important;border:1px solid #1769e0!important}.freedom-modal-btn-primary:hover{background:#1259c3!important;border-color:#1259c3!important}.freedom-modal-btn-secondary{background:#fff!important;color:#31516f!important;border:1px solid #c9d7e8!important}.freedom-modal-btn:disabled{opacity:.6!important;cursor:not-allowed!important}.freedom-tutorial-standalone .linkai-chat__toggle-icon{display:flex!important;align-items:center!important;justify-content:center!important;width:30px!important;height:30px!important;padding:0!important;margin:0!important;font-size:15px!important;font-weight:800!important;line-height:1!important;letter-spacing:0!important;text-align:center!important;text-indent:0!important;color:#fff!important}.mini-toc{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 18px}.mini-toc a{font-size:13px;background:#eef5ff;border:1px solid #cdddf4;border-radius:999px;padding:5px 10px;text-decoration:none;color:#24527e}.subsec{scroll-margin-top:24px}.subsec h3{font-size:21px;margin-bottom:10px}.steps{padding-left:22px}.steps li{margin:7px 0}.logbox{background:#111827;color:#e5eefc;border-radius:9px;padding:12px 14px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-line}.muted{color:#607387;font-size:14px}.kbd{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;background:#edf2f7;border:1px solid #d9e2ec;border-radius:5px;padding:1px 5px}@media(max-width:980px){.ft{max-width:900px}.tutorial-shell{display:block}.side-toc{display:none}.platform{margin-top:28px}}@media(max-width:700px){.paygrid{grid-template-columns:1fr}.hero{padding:26px 20px}.hero h1{font-size:28px}.card{padding:19px 17px}.copy-result-head{align-items:flex-start;flex-direction:column}.freedom-copy-btn{width:100%!important}}
        </style>
        <main class="ft"><div class="hero"><h1>Freedom · 多平台配置使用教程</h1><p>电脑、Android、iPhone / Shadowrocket。下载支持密码验证<?php if($payment_base):?>或 <?php echo esc_html(rtrim(rtrim(number_format((float)$options['payment_amount'],2,'.',''),'0'),'.').' '.$options['payment_currency']);?> 付款截图核验<?php endif;?>。</p></div>
        <nav class="nav"><a href="#pc">💻 电脑</a><a href="#android">🤖 Android</a><a href="#ios">📱 iPhone / 小火箭</a></nav>

        <div class="tutorial-shell">
        <aside class="side-toc" aria-label="教程快速目录">
            <h2>快速目录</h2>
            <strong>电脑</strong>
            <a href="#pc-download">应用下载</a><a href="#pc-sub">订阅链接</a><a href="#pc-add">添加订阅</a><a href="#pc-update">更新订阅</a><a href="#pc-backup">备用导入</a><a href="#pc-start">开始使用</a><a href="#pc-route">国内外分流</a><a href="#pc-global">全局代理</a><a href="#pc-proxy">代理选项</a><a href="#pc-core">Core 错误</a>
            <strong>Android</strong>
            <a href="#and-download">应用下载</a><a href="#and-sub">获取订阅</a><a href="#and-add">添加订阅</a><a href="#and-update">更新节点</a><a href="#and-test">节点测速</a><a href="#and-route">路由设置</a><a href="#and-lan">局域网设置</a><a href="#and-start">开始使用</a><a href="#and-global">全局模式</a><a href="#and-backup">备用导入</a>
            <strong>Apple / Shadowrocket</strong>
            <a href="#ios-app">应用说明</a><a href="#ios-txt">TXT 导入</a><a href="#ios-sub">订阅导入</a><a href="#ios-test">配置与测速</a><a href="#ios-update">更新节点</a><a href="#ios-route">分流</a><a href="#ios-global">全局模式</a><a href="#ios-start">开始使用</a><a href="#ios-tv">Apple TV</a>
        </aside>
        <div class="tutorial-content">

<section class="platform" id="pc">
<h2>电脑 · Windows / macOS / Linux</h2>
<p class="muted">以下内容按你上传的最新版 V2rayN 7.24.6 教程完整整理，不再只保留摘要。</p>
<div class="mini-toc">
<a href="#pc-download">应用下载</a><a href="#pc-sub">订阅链接</a><a href="#pc-add">添加订阅</a><a href="#pc-update">更新订阅</a><a href="#pc-backup">备用导入</a><a href="#pc-start">开始使用</a><a href="#pc-route">国内外分流</a><a href="#pc-global">全局代理</a><a href="#pc-proxy">三种代理选项</a><a href="#pc-core">Core 错误</a>
</div>

<div class="card subsec" id="pc-download">
<h3>1. 应用下载与安装</h3>
<p>这是 <strong>V2rayN 7.24.6</strong> 版教程。如果软件版本低于 7.24.6，请使用新版，并按本教程在订阅分组设置中输入你在本网站的登录密码。</p>
<div class="warn"><strong>安全软件提示：</strong>原教程特别提醒，部分杀毒/安全软件可能删除 V2RayN 或其中的 <code>xray.exe</code>，造成软件无法运行。如果遇到文件被删除、Core 缺失或程序莫名无法启动，请先检查安全软件隔离区。</div>
<?php self::render_resource_box('desktop','电脑软件包',$ready['desktop'],$password_ready,$payment_base,$qr,$options,$ep);?>
<p>建议把完整压缩包解压到桌面等当前用户拥有完整权限的位置。<strong>不要只把 v2rayn.exe 单独移动到桌面</strong>；需要快捷方式时，在完整 v2rayn 文件夹内右键 v2rayn.exe 创建桌面快捷方式。</p>

<h4>Windows 安装启动</h4>
<ol class="steps">
<li>把压缩包<strong>完整解压</strong>，不要在压缩包内直接运行。</li>
<li>进入 v2rayn 文件夹，右键 v2rayN.exe，选择“以管理员身份运行”。</li>
</ol>
<img class="shot" src="<?php echo esc_url(self::image_url('image1.webp'));?>" alt="Windows 以管理员身份运行 v2rayN">
<p>如果 SmartScreen 显示“Windows 已保护您的电脑”，先点“更多信息”，再选择“仍要运行”。</p>
<img class="shot" src="<?php echo esc_url(self::image_url('image2.webp'));?>" alt="Windows SmartScreen 更多信息">

<h4>macOS 安装启动</h4>
<p>Mac 下载的安装文件为 <code>.dmg</code>。双击 dmg 后，把 v2rayN 图标拖到 <strong>Applications</strong> 文件夹。</p>
<p>如果提示“v2rayN 已损坏，无法打开”，打开终端执行：</p>
<div class="logbox">sudo xattr -cr /Applications/v2rayN.app</div>
<p>按回车后输入 Mac 开机密码；输入密码时终端不会显示字符，输完直接回车，然后重新打开 v2rayN。</p>

<h4>Linux 安装启动</h4>
<p>在终端进入安装文件所在目录：</p>
<div class="logbox">(Debian 系) sudo apt install ./文件名.deb
(Redhat 系) sudo dnf install ./文件名.rpm</div>
</div>

<div class="card subsec" id="pc-sub">
<h3>2. 订阅链接</h3>
<p>订阅链接需要在你的网站用户中心、登录状态下获取。</p>
<div class="warn">订阅链接与账号绑定，作用接近账号密码。不要公开分享，也不要把完整订阅链接发给在线客服。</div>
</div>

<div class="card subsec" id="pc-add">
<h3>3. 订阅设置、添加订阅链接</h3>
<p>程序启动后，点击左上角 <strong>“+”</strong> 添加订阅分组：</p>
<ol class="steps">
<li>填写别名。</li>
<li>粘贴订阅链接。</li>
<li>输入 <strong>V2free 网站登录密码</strong>。</li>
<li>启用更新。</li>
<li>原教程示例自动更新间隔为 <strong>1688</strong>。</li>
<li>点击“确定”。</li>
</ol>
<img class="shot" src="<?php echo esc_url(self::image_url('image3.webp'));?>" alt="V2rayN 添加订阅分组">
</div>

<div class="card subsec" id="pc-update">
<h3>4. 更新订阅（更新节点）</h3>
<p>已有订阅后，点击 <strong>订阅分组 → 更新当前订阅（不通过代理）</strong>。</p>
<img class="shot" src="<?php echo esc_url(self::image_url('image4.webp'));?>" alt="更新当前订阅不通过代理">
<p>正常情况下本站订阅链接可直连，因此一般使用“不通过代理”；如果已经可以正常访问外网，也可以选择“通过代理”。</p>
<p>更新时请查看底部日志，原教程要求确认出现类似以下提示，才算真正更新完成：</p>
<div class="logbox">v2ree-&gt;获取订阅内容成功
v2ree-&gt;开始解析和处理订阅内容
v2ree-&gt;更新订阅结束</div>
<p>如果订阅更新失败，可以使用下一节的“节点导入备用方法”。</p>
</div>

<div class="card subsec" id="pc-backup">
<h3>5. 节点导入备用方法</h3>
<p>如果订阅链接无法更新节点，或使用的不是本站定制软件：</p>
<ol class="steps">
<li>在用户中心进入 <strong>节点 URL</strong>。</li>
<li>点击“拷贝全部节点 URL”。</li>
<li>回到 V2rayN，进入配置/服务器菜单。</li>
<li>选择 <strong>从剪贴板导入分享链接（Ctrl+V）</strong>。</li>
</ol>
<img class="shot" src="<?php echo esc_url(self::image_url('image5.webp'));?>" alt="从剪贴板导入分享链接">
<div class="note">如果界面提示导入成功，但节点实际上没有出现，通常是文件权限问题。退出 V2RayN 后，用管理员权限重新启动，再尝试导入。</div>
</div>

<div class="card subsec" id="pc-start">
<h3>6. 开始使用</h3>
<ol class="steps">
<li>点击 <strong>一键测试真连接测试</strong>。节点较多时需要等待几分钟。</li>
<li>选择有延迟数值的节点；延迟几百毫秒也可能属于正常范围。</li>
<li>点击 <strong>启动连接</strong>。</li>
</ol>
<img class="shot" src="<?php echo esc_url(self::image_url('image6.webp'));?>" alt="V2rayN 测试节点并启动连接">
<div class="warn"><strong>不用时先点“停止连接”，再退出软件。</strong>原教程明确提醒：不要在仍处于连接状态时直接退出，否则可能导致电脑之后无法联网。</div>
<p>如果命令行程序、微软商店 APP 或其他不遵循系统代理的软件也需要使用代理，可以启用 <strong>TUN 模式</strong>。启用 TUN 时需要清除系统代理。</p>
<p>如果 SS 节点真连接测试正常，而 VMess 节点普遍超时或报错，请先检查系统时区与时间。原教程要求尽量把系统时间误差控制在 <strong>30 秒以内</strong>。</p>
</div>

<div class="card subsec" id="pc-route">
<h3>7. 国内外分流</h3>
<p>路由选择 <strong>V3-绕过大陆（Whitelist）</strong>：国内网站直连，国外网站走节点。</p>
<p>如果有个别网址不希望走节点，可在设置/参数设置/系统代理相关设置中增加直连规则。</p>
</div>

<div class="card subsec" id="pc-global">
<h3>8. 全局代理</h3>
<p>路由选择 <strong>V3-全局（Global）</strong> 即为全局代理。原教程同时提醒，局域网、BT 下载等仍可能存在默认不走代理的例外情况。</p>
</div>

<div class="card subsec" id="pc-proxy">
<h3>9. 三种代理选项</h3>
<p><strong>清除系统代理：</strong>禁止使用 Windows 系统代理，不设置任何代理。</p>
<p><strong>自动配置系统代理：</strong>设置 Windows 使用 V2RayN 的代理。</p>
<p><strong>不改变系统代理：</strong>保持 Windows 原有代理设置，不做任何改变。</p>
</div>

<div class="card subsec" id="pc-core">
<h3>10. 找不到 Core / xray.exe 错误</h3>
<p>如果出现“操作失败”“找不到 Core”或类似错误，原教程指出常见原因是 <code>xray.exe</code> 被安全软件删除。</p>
<img class="shot" src="<?php echo esc_url(self::image_url('image7.webp'));?>" alt="V2rayN Core 操作失败">
<img class="shot" src="<?php echo esc_url(self::image_url('image8.webp'));?>" alt="V2rayN 缺少 xray Core">
<p>解决方法：从原压缩包重新解压 <code>xray.exe</code>，放到：</p>
<div class="logbox">v2Rayn\bin\Xray</div>
<p>该目录中必须存在 <code>xray.exe</code> 才能工作。</p>
<div class="note">你上传的最新版教程目录里还列有“共享热点给其它设备上网”，但该 9 页文档没有提供这一节的正文，所以这里没有自行补写。</div>
</div>
</section>

<section class="platform" id="android">
<h2>Android · v2freeGo</h2>
<p class="muted">以下按你上传的 Android 教程完整整理。</p>
<div class="mini-toc">
<a href="#and-download">应用下载</a><a href="#and-sub">获取订阅</a><a href="#and-add">添加订阅</a><a href="#and-update">更新节点</a><a href="#and-test">节点测速</a><a href="#and-route">路由设置</a><a href="#and-lan">局域网设置</a><a href="#and-start">开始使用</a><a href="#and-global">全局模式</a><a href="#and-backup">备用导入</a>
</div>

<div class="card subsec" id="and-download">
<h3>1. 应用下载</h3>
<?php self::render_resource_box('android','Android 软件包',$ready['android'],$password_ready,$payment_base,$qr,$options,$ep);?>
<p>教程使用的是 <strong>v2freeGo Android APP</strong>。如果 APP 不是从本站下载、界面与教程差异很大，可以重新安装本站版本，或者使用本页底部的备用节点导入方法。</p>
</div>

<div class="card subsec" id="and-sub">
<h3>2. 获取 Android 订阅链接</h3>
<p>为了保护订阅地址，未验证状态下本页<strong>不会输出任何 Android 订阅链接</strong>。</p>
<div class="note">请在上方 Android 软件包区域选择“密码下载”或“付款后下载”。验证成功后，服务器只返回当前轮换到的一个订阅链接，并显示“一键复制”。</div>

</div>

<div class="card subsec" id="and-add">
<h3>3. 添加订阅链接</h3>
<p>打开 v2freeGo，点击左上角菜单图标打开侧边栏，然后点击 <strong>分组</strong>。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image1.webp'));?>" alt="Android 打开侧边栏">
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image2.webp'));?>" alt="Android 进入分组">
<p>点击右上角“+”。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image3.webp'));?>" alt="Android 新建分组">
<p>分组名可填写 v2freeGo，分组类型选择 <strong>订阅</strong>。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image4.webp'));?>" alt="Android 分组类型订阅">
<p>填写订阅链接和你的网站登录密码，然后保存。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image5.webp'));?>" alt="Android 填写订阅和密码">
<p>看到成功提示后，节点会导入。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image6.webp'));?>" alt="Android 节点导入成功">
</div>

<div class="card subsec" id="and-update">
<h3>4. 更新订阅 / 更新节点</h3>
<p>在分组界面点击 <strong>更新</strong> 按钮即可手动更新。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image7.webp'));?>" alt="Android 更新订阅">
<p>教程提醒：节点信息可能不定时变化；如果出现大面积节点不可用，或账号套餐状态发生变化，需要重新更新订阅。</p>
<div class="note">如果已经开启 VPN，更新前确保当前节点可用；如果没有任何可用节点，先关闭 VPN 再更新。</div>
</div>

<div class="card subsec" id="and-test">
<h3>5. 节点测速</h3>
<p>点击右上角三个点 → <strong>连接测试</strong> → <strong>URL 测试</strong>。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image8.webp'));?>" alt="Android 连接测试">
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image9.webp'));?>" alt="Android URL 测试">
</div>

<div class="card subsec" id="and-route">
<h3>6. 路由设置和中国网站直连</h3>
<p>左上角菜单 → <strong>路由</strong>。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image10.webp'));?>" alt="Android 路由">
<p>向下找到规则并启用 <strong>绕过中国域名规则</strong> 和 <strong>中国 IP 规则</strong>，让国内网站直连。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image11.webp'));?>" alt="Android 中国域名和中国IP规则">
</div>

<div class="card subsec" id="and-lan">
<h3>7. 绕过局域网和分应用代理</h3>
<p>左上角菜单 → <strong>设置</strong>。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image12.webp'));?>" alt="Android 设置">
<p>向下找到对应项目，启用 <strong>绕过局域网地址</strong> 和 <strong>在核心中绕过 LAN</strong>。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image13.webp'));?>" alt="Android 绕过局域网">
</div>

<div class="card subsec" id="and-start">
<h3>8. 开始使用</h3>
<p>左上角菜单 → <strong>配置</strong>。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image14.webp'));?>" alt="Android 配置">
<p>选择一个节点，一般延迟数值越小速度越快，然后点击底部的小飞机按钮启动 VPN。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image15.webp'));?>" alt="Android 选择节点并启动">
<p>系统弹出 VPN 连接请求时，点击允许/确定，并按系统要求验证密码、指纹等。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('android','image16.webp'));?>" alt="Android VPN 请求">
<p>VPN 启动后，直接点选另一个节点即可切换；再次点小飞机按钮可以断开。</p>
</div>

<div class="card subsec" id="and-global">
<h3>9. 全局模式</h3>
<p>原教程对“全局模式”的定义是：让全部流量都走节点。需要关闭前面提到的中国网站直连、绕过局域网和分应用代理等绕过规则，并按教程启用需要代理的规则。</p>
</div>

<div class="card subsec" id="and-backup">
<h3>10. 节点导入备选方法</h3>
<p>该方法更新节点不如订阅方便，因此作为备用：</p>
<ol class="steps">
<li>在网站用户中心进入节点 URL。</li>
<li>拷贝全部 V2Ray 节点 URL（不是订阅链接）。</li>
<li>APP 主界面右上角“+” → <strong>从剪贴板导入</strong>。</li>
<li>如果账号还有 SS 节点，可再拷贝全部 SS 节点 URL，用相同方法导入。</li>
</ol>
</div>
</section>

<section class="platform" id="ios">
<h2>iPhone / iPad · Shadowrocket 小火箭</h2>
<div class="note">Apple / Shadowrocket 订阅内容需要先通过密码验证或付款验证。验证成功后会显示内容和“点击拷贝”按钮。</div>
<p class="muted">本站当前按你的要求，以 TXT 节点文件作为主要导入方式，同时保留原教程中的订阅方式说明。</p>
<div class="mini-toc">
<a href="#ios-app">应用说明</a><a href="#ios-txt">TXT 导入</a><a href="#ios-sub">订阅导入</a><a href="#ios-test">配置与测速</a><a href="#ios-update">更新节点</a><a href="#ios-route">分流</a><a href="#ios-global">全局模式</a><a href="#ios-start">开始使用</a><a href="#ios-tv">Apple TV</a>
</div>

<div class="card subsec" id="ios-app">
<h3>1. 应用说明</h3>
<p>Shadowrocket 是 iOS 平台的付费 APP。你上传的原教程说明，中国区 App Store 无法直接下载，需要使用可下载该 APP 的 Apple ID。</p>
<div class="warn">原教程特别提醒：仅在 App Store 中登录相关 Apple ID，不要在系统设置里的 iCloud 中登录第三方账号。</div>
</div>

<div class="card subsec" id="ios-txt">
<h3>2. 本站当前方式：一键复制节点内容</h3>
<?php self::render_resource_box('ios','Shadowrocket 节点内容',$ready['ios'],$password_ready,$payment_base,$qr,$options,'');?>
<div class="note">验证成功后，页面会一次显示<strong>全部节点内容</strong>，保持后台原有格式，每个节点一行。点击“点击拷贝”即可把全部节点一次复制到剪贴板。</div>
<ol class="steps">
<li>通过本站密码验证或付款验证。</li>
<li>验证成功后点击<strong>“点击拷贝”</strong>，一次复制全部节点。</li>
<li>切换回 Shadowrocket；允许剪贴板访问后，按应用弹出的导入提示确认。</li>
</ol>
<div class="warn">节点内容属于访问凭据，请像密码一样保管，不要通过公开渠道传播。</div>
</div>

<div class="card subsec" id="ios-sub">
<h3>3. 原教程方式：V2Ray 订阅链接导入</h3>
<p>原教程说明本站订阅默认加密，而 Shadowrocket 无法直接解析加密后的订阅，因此操作顺序是：</p>
<ol class="steps">
<li>用户中心 → 订阅链接 / Clash 配置 → 拷贝 V2Ray 订阅链接。</li>
<li>Shadowrocket 右上角“+” → 类型选择 <strong>Subscribe</strong> → 填写备注 → URL 粘贴订阅链接。</li>
<li>回网站用户中心 → 修改资料 → 订阅加密 → <strong>临时禁用订阅加密 30 秒</strong>。</li>
<li>立即回 Shadowrocket，点保存/完成。</li>
<li>出现节点列表后进行连通性测试并选择节点。</li>
</ol>
<p>30 秒后订阅会自动恢复加密。以后刷新订阅时，也需要先临时禁用订阅加密 30 秒，再点刷新。</p>
</div>

<div class="card subsec" id="ios-test">
<h3>4. 配置与测速</h3>
<p>获取节点后，底部 <strong>配置</strong> 选择 <strong>default.conf</strong>。</p>
<p>右下角 <strong>设置 → 延迟测试方法</strong>，改成：</p>
<div class="logbox">CONNECT</div>
<p>回到首页点击“连通性测试”，选择速度快、延迟低的节点。</p>
</div>

<div class="card subsec" id="ios-update">
<h3>5. 更新节点</h3>
<p>如果出现大面积节点不可用，或账号套餐状态改变，需要重新更新节点。</p>
<p><strong>订阅方式：</strong>先临时禁用订阅加密 30 秒，再回小火箭主界面点击订阅右侧刷新。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('ios','image1.webp'));?>" alt="Shadowrocket 刷新订阅">
<p><strong>TXT / 节点 URL 方式：</strong>重新执行“TXT 节点文件导入”步骤即可。原教程建议，在导入新节点之前不要急着删除旧节点，以免只是临时故障。</p>
<div class="note">如果 VPN 已开启，更新前确保当前节点可用；如果当前没有可用节点，先关闭 VPN 再更新。</div>
</div>

<div class="card subsec" id="ios-route">
<h3>6. 分流规则</h3>
<p>首页进入 <strong>全局路由</strong>，选择 <strong>配置</strong>；然后在底部“配置”里选 <strong>default.conf</strong>。</p>
<img class="shot" src="<?php echo esc_url(self::platform_image_url('ios','image2.webp'));?>" alt="Shadowrocket 全局路由与配置">
</div>

<div class="card subsec" id="ios-global">
<h3>7. 全局代理 / 全局模式</h3>
<p>在“全局路由”中选择 <strong>代理</strong>，就是全局代理/全局模式，此时全部流量走节点。</p>
</div>

<div class="card subsec" id="ios-start">
<h3>8. 开始使用</h3>
<ol class="steps">
<li>延迟测试方法设为 <strong>CONNECT</strong>。</li>
<li>首页做“连通性测试”。</li>
<li>选择速度快、延迟低的节点。</li>
<li>打开首页第一行开关。</li>
</ol>
<p>如果系统提示添加 VPN 配置，点击 Allow/允许，并按系统要求验证密码、Touch ID 或 Face ID。</p>
</div>

<div class="card subsec" id="ios-tv">
<h3>9. Apple TV 小火箭节点导入排查</h3>
<p>确保 iPhone 与 Apple TV 连接同一个 Wi‑Fi，并同时把两台设备上的 Shadowrocket 打开在前台。</p>
<p>如果“服务器节点”界面一直搜索不到 Apple TV，按原教程检查：</p>
<ol class="steps">
<li><strong>本地网络权限：</strong>iPhone 设置 → 隐私与安全性 → 本地网络，确认 Shadowrocket 已开启。</li>
<li><strong>电视端状态：</strong>Apple TV 上的 Shadowrocket 保持前台打开。</li>
<li><strong>网络隔离：</strong>检查路由器是否启用 AP 隔离，以及不同 Wi‑Fi 频段之间是否无法互通。</li>
</ol>
</div>
</section>

<div class="card"><h3>Freedom 客服</h3><p>右下角 Freedom 客服会按电脑、Android、小火箭三个教程回答。不要把完整订阅链接、节点 URL、TXT 内容或账号密码发送给客服。</p></div>
        </div>
        </div>
        </main>

        <div class="modal" id="pwmodal"><div class="dialog"><h3>安全下载验证</h3><p id="pwlabel"></p><input class="field" id="pwinput" type="password" placeholder="请输入下载密码" autocomplete="current-password"><div class="msg" id="pwmsg" role="status" aria-live="polite"></div><div class="copy-result" id="pwcopy" hidden><div class="copy-result-head"><strong data-copy-title>订阅 / 节点内容</strong><button type="button" class="freedom-copy-btn" data-copy-button data-copy-source="pwcopytext">点击拷贝</button></div><textarea class="copy-content" id="pwcopytext" rows="7" readonly spellcheck="false" aria-label="订阅或节点内容"></textarea><div class="msg" data-copy-status role="status" aria-live="polite"></div></div><div class="freedom-download-actions"><button type="button" class="freedom-modal-btn freedom-modal-btn-secondary" id="pwcancel">取消</button><button type="button" class="freedom-modal-btn freedom-modal-btn-primary" id="pwsubmit">确认并下载</button></div></div></div>
        <script>
        (function(){
        var ajax=<?php echo wp_json_encode($ajax);?>,nonce=<?php echo wp_json_encode($nonce);?>,resource='desktop',modal=document.getElementById('pwmodal'),inp=document.getElementById('pwinput'),msg=document.getElementById('pwmsg'),lab=document.getElementById('pwlabel'),pwsubmit=document.getElementById('pwsubmit'),pwcopy=document.getElementById('pwcopy'),pwcopytext=document.getElementById('pwcopytext'),verifyController=null,verifyTimer=0,verifyRequestId=0;

        function resetPasswordState(abortRequest){
            if(abortRequest&&verifyController){verifyController.abort();}
            verifyController=null;
            window.clearTimeout(verifyTimer);
            verifyTimer=0;
            pwsubmit.disabled=false;
            pwsubmit.textContent='确认并下载';
            inp.disabled=false;
        }

        function clearPasswordCopy(){
            if(pwcopy){pwcopy.hidden=true;}
            if(pwcopytext){pwcopytext.value='';}
        }

        document.querySelectorAll('[data-pw]').forEach(function(b){b.onclick=function(){
            verifyRequestId++;
            resetPasswordState(true);
            clearPasswordCopy();
            resource=b.dataset.resource;
            lab.textContent='资源：'+b.dataset.label;
            inp.value='';
            msg.textContent='';
            msg.className='msg';
            modal.classList.add('open');
            inp.focus();
        };});

        document.getElementById('pwcancel').onclick=function(){
            verifyRequestId++;
            resetPasswordState(true);
            clearPasswordCopy();
            modal.classList.remove('open');
        };

        function verifyFreedomPassword(){
            if(!inp.value){msg.textContent='请输入密码';msg.className='msg error';inp.focus();return;}
            if(pwsubmit.disabled){return;}

            var currentRequest=++verifyRequestId;
            clearPasswordCopy();
            verifyController=new AbortController();
            pwsubmit.disabled=true;
            pwsubmit.textContent='正在验证…';
            inp.disabled=true;
            msg.textContent='正在验证密码，请稍候…';
            msg.className='msg';

            verifyTimer=window.setTimeout(function(){
                if(verifyController){verifyController.abort();}
            },20000);

            var body=new URLSearchParams();
            body.set('action','jsd_verify_download_password');
            body.set('nonce',nonce);
            body.set('password',inp.value);
            body.set('resource',resource);

            fetch(ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString(),signal:verifyController.signal})
            .then(function(r){return r.json();})
            .then(function(j){
                if(currentRequest!==verifyRequestId){return;}
                if(!j.success){throw new Error(j.data&&j.data.message?j.data.message:'验证失败');}
                msg.textContent=j.data.message||'验证成功，下载正在开始。60 秒只限制链接开始使用，不会中断已经开始的下载。';
                msg.className='msg success';
                showPasswordCopyResult(j.data);
                if(j.data.url){window.setTimeout(function(){window.location.assign(j.data.url);},j.data.copy_content?350:0);}
            })
            .catch(function(e){
                if(currentRequest!==verifyRequestId){return;}
                msg.textContent=e&&e.name==='AbortError'?'验证超时，请重新输入密码再试。':(e.message||'验证失败，请重试。');
                msg.className='msg error';
            })
            .finally(function(){
                if(currentRequest!==verifyRequestId){return;}
                resetPasswordState(false);
                if(msg.classList.contains('error')){inp.focus();inp.select();}
            });
        }

        pwsubmit.onclick=verifyFreedomPassword;
        inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();verifyFreedomPassword();}});

        document.querySelectorAll('[data-paytoggle]').forEach(function(b){b.onclick=function(){document.getElementById(b.dataset.target).classList.toggle('open');};});

        function showCopyResult(resourceKey,data){
            var box=document.getElementById('paycopy-'+resourceKey),field=document.getElementById('paycopytext-'+resourceKey);
            if(!box||!field||!data.copy_content){return;}
            var title=box.querySelector('[data-copy-title]');
            var copyBtn=box.querySelector('[data-copy-button]');
            if(title){title.textContent=data.copy_title||'订阅 / 节点内容';}
            field.value=data.copy_content;
            box.hidden=false;
            if(copyBtn){
                copyBtn.hidden=false;
                copyBtn.style.setProperty('display','inline-flex','important');
                copyBtn.textContent='点击拷贝';
            }
        }

        function showPasswordCopyResult(data){
            if(!pwcopy||!pwcopytext){return;}
            if(!data.copy_content){pwcopy.hidden=true;pwcopytext.value='';return;}
            var title=pwcopy.querySelector('[data-copy-title]');
            var copyBtn=pwcopy.querySelector('[data-copy-button]');
            if(title){title.textContent=data.copy_title||'订阅 / 节点内容';}
            pwcopytext.value=data.copy_content;
            pwcopy.hidden=false;
            if(copyBtn){
                copyBtn.hidden=false;
                copyBtn.style.setProperty('display','inline-flex','important');
                copyBtn.textContent='点击拷贝';
            }
        }

        function copyText(field,status){
            // Android Chrome / WebView 与 iPhone Safari / Chrome 共用这一套兼容复制逻辑。
            var value=field.value||'';
            if(!value){status.textContent='暂无可复制内容';status.className='msg error';return;}

            function legacyCopy(){
                var ta=document.createElement('textarea');
                ta.value=value;
                ta.setAttribute('readonly','readonly');
                ta.style.position='fixed';
                ta.style.left='0';
                ta.style.top='0';
                ta.style.width='2px';
                ta.style.height='2px';
                ta.style.padding='0';
                ta.style.border='0';
                ta.style.opacity='0.01';
                ta.style.fontSize='16px';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                if(ta.setSelectionRange){ta.setSelectionRange(0,ta.value.length);}
                var ok=false;
                try{ok=document.execCommand('copy');}catch(e){ok=false;}
                document.body.removeChild(ta);
                return ok;
            }

            // iPhone 上 Chrome 同样使用 WebKit。先在点击事件的同步用户手势中尝试 legacy copy，
            // 避免异步 Clipboard Promise 失败后再 fallback 时丢失 user gesture。
            if(legacyCopy()){
                status.textContent='复制成功';
                status.className='msg success';
                return;
            }

            if(navigator.clipboard&&window.isSecureContext){
                navigator.clipboard.writeText(value).then(function(){
                    status.textContent='复制成功';
                    status.className='msg success';
                }).catch(function(){
                    field.focus();
                    field.select();
                    if(field.setSelectionRange){field.setSelectionRange(0,field.value.length);}
                    status.textContent='浏览器未允许自动复制，节点已全选，请长按选择“拷贝”。';
                    status.className='msg error';
                });
            }else{
                field.focus();
                field.select();
                if(field.setSelectionRange){field.setSelectionRange(0,field.value.length);}
                status.textContent='浏览器未允许自动复制，节点已全选，请长按选择“拷贝”。';
                status.className='msg error';
            }
        }

        document.querySelectorAll('[data-copy-button]').forEach(function(b){b.onclick=function(){var field=document.getElementById(b.dataset.copySource),status=b.closest('.copy-result').querySelector('[data-copy-status]');copyText(field,status);};});

        

        document.querySelectorAll('[data-paysubmit]').forEach(function(b){b.onclick=function(){
            var fi=document.getElementById(b.dataset.file),out=document.getElementById(b.dataset.msg),originalText=b.textContent;
            if(!fi.files[0]){out.textContent='请先选择付款截图';out.className='msg error';return;}
            if(b.disabled){return;}
            b.disabled=true;
            b.textContent='正在核验…';
            out.textContent='正在核验付款截图，请稍候…';
            out.className='msg';
            var fd=new FormData();
            fd.append('action','jsd_verify_payment_screenshot');
            fd.append('nonce',nonce);
            fd.append('resource',b.dataset.resource);
            fd.append('payment_screenshot',fi.files[0]);
            fetch(ajax,{method:'POST',credentials:'same-origin',body:fd})
            .then(function(r){return r.json();})
            .then(function(j){
                if(!j.success){throw new Error(j.data&&j.data.message?j.data.message:'核验失败');}
                out.textContent=j.data.message||'付款截图已通过核验。';
                out.className='msg success';
                showCopyResult(b.dataset.resource,j.data);
                if(j.data.url){window.setTimeout(function(){window.location.assign(j.data.url);},250);}
            })
            .catch(function(e){out.textContent=e.message||'核验失败，请重试。';out.className='msg error';})
            .finally(function(){b.disabled=false;b.textContent=originalText;});
        };});

        var tocLinks=Array.prototype.slice.call(document.querySelectorAll('.side-toc a'));
        if('IntersectionObserver' in window&&tocLinks.length){
            var tocObserver=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(!entry.isIntersecting){return;}tocLinks.forEach(function(a){a.removeAttribute('aria-current');});var active=document.querySelector('.side-toc a[href="#'+entry.target.id+'"]');if(active){active.setAttribute('aria-current','true');}});},{rootMargin:'-15% 0px -70% 0px',threshold:0});
            document.querySelectorAll('.platform[id],.subsec[id]').forEach(function(section){tocObserver.observe(section);});
        }
        })();
        </script>
        <?php wp_footer();?>
        <script>
        (function(){
            /* 仅 freedom 教程页执行，而且在 wp_footer() 之后执行，确保覆盖主客服/旧教程增强插件的默认文案。 */
            var widget=document.querySelector('.linkai-chat');
            if(!widget){return;}

            var icon=widget.querySelector('.linkai-chat__toggle-icon');
            if(icon){
                icon.textContent='客';
                icon.setAttribute('aria-hidden','true');
            }

            var title=widget.querySelector('.linkai-chat__header strong');
            if(title){title.textContent='Freedom 客服';}

            var contact=widget.querySelector('.linkai-chat__customer-input[name="contact"]');
            if(contact){contact.placeholder='电话/微信/邮箱（选填）';}

            var message=widget.querySelector('.linkai-chat__input[name="message"]');
            if(message){message.placeholder='请输入您要咨询的问题，例如：下载后怎么配置？';}
        })();
        </script>
        </body></html><?php
    }
}

Jinshanjiao_Secure_Tutorial_Download::init();
register_activation_hook(__FILE__, [Jinshanjiao_Secure_Tutorial_Download::class, 'activate']);
register_deactivation_hook(__FILE__, [Jinshanjiao_Secure_Tutorial_Download::class, 'deactivate']);
