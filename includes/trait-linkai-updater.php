<?php
if (!defined('ABSPATH')) {
    exit;
}

trait LinkAI_Updater
{
    public static function handle_permission_fix(): void
    {
        if (!current_user_can('manage_options') || empty($_POST['linkai_fix_permissions'])) {
            return;
        }

        check_admin_referer('linkai_fix_permissions');
        $fixed = self::chmod_plugin_directory(dirname(self::PLUGIN_FILE));

        wp_safe_redirect(add_query_arg(['permission_fixed' => $fixed ? '1' : '0'], self::settings_page_url()));
        exit;
    }

    private static function chmod_plugin_directory(string $plugin_dir): bool
    {
        $dir_mode = defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : 0755;
        $file_mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
        $success = self::chmod_path($plugin_dir, $dir_mode);

        if (!is_dir($plugin_dir) || !is_readable($plugin_dir)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($plugin_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $mode = $item->isDir() ? $dir_mode : $file_mode;
            $success = self::chmod_path($path, $mode) && $success;
        }

        return $success && is_writable($plugin_dir);
    }

    private static function chmod_path(string $path, int $mode): bool
    {
        return file_exists($path) && @chmod($path, $mode);
    }

    public static function handle_update_cache_clear(): void
    {
        if (!current_user_can('manage_options') || empty($_POST['linkai_clear_update_cache'])) {
            return;
        }

        check_admin_referer('linkai_clear_update_cache');
        self::delete_update_cache();
        delete_site_transient('update_plugins');

        wp_safe_redirect(add_query_arg(['update_cache_cleared' => '1'], self::settings_page_url()));
        exit;
    }

    private static function delete_update_cache(): void
    {
        $options = self::get_options();
        $repo = self::parse_github_repo($options['update_repo_url']);
        if (!$repo) {
            return;
        }

        $branch = !empty($options['update_branch']) ? $options['update_branch'] : 'main';
        delete_site_transient(self::get_update_cache_key($repo, $branch));
    }

    private static function get_update_cache_key(array $repo, string $branch): string
    {
        return 'linkai_ai_customer_service_update_' . md5($repo['owner'] . '/' . $repo['name'] . '/' . $branch);
    }

    public static function check_for_plugin_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $plugin_basename = plugin_basename(self::PLUGIN_FILE);
        $update = self::get_github_update_data(self::should_force_update_check());
        if (!$update || !version_compare($update['version'], self::VERSION, '>')) {
            if (isset($transient->response[$plugin_basename])) {
                unset($transient->response[$plugin_basename]);
            }
            return $transient;
        }

        $transient->response[$plugin_basename] = (object) [
            'slug' => self::PLUGIN_DIRECTORY_NAME,
            'plugin' => $plugin_basename,
            'new_version' => $update['version'],
            'url' => $update['repo_url'],
            'package' => $update['zip_url'],
            'tested' => get_bloginfo('version'),
            'requires' => '5.8',
        ];

        return $transient;
    }

    public static function render_plugin_update_info($result, string $action, object $args)
    {
        $plugin_slug = self::PLUGIN_DIRECTORY_NAME;
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $plugin_slug) {
            return $result;
        }

        $update = self::get_github_update_data();
        if (!$update) {
            return $result;
        }

        return (object) [
            'name' => 'LinkAI 智能 AI 客服',
            'slug' => $plugin_slug,
            'version' => $update['version'],
            'author' => 'Jinshanjiao',
            'homepage' => $update['repo_url'],
            'download_link' => $update['zip_url'],
            'requires' => '5.8',
            'tested' => get_bloginfo('version'),
            'sections' => [
                'description' => '从 GitHub 仓库分支下载并更新 LinkAI 智能 AI 客服插件。',
                'changelog' => !empty($update['changelog']) ? nl2br(esc_html($update['changelog'])) : '请查看 GitHub 仓库提交记录。',
            ],
        ];
    }

    public static function rename_github_update_source($source, $remote_source, $upgrader, $hook_extra)
    {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(self::PLUGIN_FILE)) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return new WP_Error('linkai_update_filesystem_unavailable', 'LinkAI 更新失败：WordPress 文件系统不可用，无法检查更新包。');
        }
        if (!$wp_filesystem->exists($source)) {
            return new WP_Error('linkai_update_source_missing', sprintf('LinkAI 更新失败：解压后的更新目录不存在：%s', $source));
        }

        $plugin_file = basename(self::PLUGIN_FILE);
        $source = untrailingslashit($source);

        if (!$wp_filesystem->exists(trailingslashit($source) . $plugin_file)) {
            $found_source = self::find_update_source_with_plugin_file($source, $plugin_file);
            if ($found_source !== '') {
                $source = untrailingslashit($found_source);
            }
        }

        $target = self::get_update_target_path($source, $remote_source);

        if (!$wp_filesystem->exists(trailingslashit($source) . $plugin_file)) {
            return new WP_Error(
                'linkai_update_plugin_file_missing',
                sprintf(
                    'LinkAI 更新包无效：解压后没有找到插件主文件 %1$s。当前解压目录：%2$s。目录内容：%3$s。请确认 ZIP 解压后包含 %4$s/%1$s。',
                    $plugin_file,
                    $source,
                    self::describe_update_source($source),
                    self::PLUGIN_DIRECTORY_NAME
                )
            );
        }

        if ($source === $target || self::is_update_source_inside_target($source, $target)) {
            return $source;
        }

        if ($wp_filesystem->exists($target) && !$wp_filesystem->delete($target, true)) {
            return new WP_Error(
                'linkai_update_target_delete_failed',
                sprintf('LinkAI 更新失败：无法删除临时目标目录 %s。请检查 wp-content/upgrade 目录权限。', $target)
            );
        }

        if ($wp_filesystem->move($source, $target, true)) {
            return $target;
        }

        return new WP_Error(
            'linkai_update_source_move_failed',
            sprintf('LinkAI 更新失败：已找到插件主文件，但无法把 %1$s 移动为 %2$s。请检查 wp-content/upgrade 目录权限。', $source, $target)
        );
    }

    private static function get_update_target_path(string $source, string $remote_source): string
    {
        $source = untrailingslashit($source);
        $remote_source = untrailingslashit($remote_source);

        if (basename($source) === self::PLUGIN_DIRECTORY_NAME) {
            return $source;
        }

        if (basename($remote_source) === self::PLUGIN_DIRECTORY_NAME) {
            return $remote_source;
        }

        return untrailingslashit(trailingslashit($remote_source) . self::PLUGIN_DIRECTORY_NAME);
    }

    private static function is_update_source_inside_target(string $source, string $target): bool
    {
        $source = untrailingslashit($source);
        $target = untrailingslashit($target);

        return $source !== $target && strpos($source, trailingslashit($target)) === 0;
    }

    private static function describe_update_source(string $source): string
    {
        global $wp_filesystem;
        $entries = $wp_filesystem ? $wp_filesystem->dirlist($source) : [];
        if (!is_array($entries) || empty($entries)) {
            return '空目录或无法读取目录';
        }

        return implode(', ', array_slice(array_keys($entries), 0, 12));
    }

    private static function find_update_source_with_plugin_file(string $source, string $plugin_file): string
    {
        global $wp_filesystem;
        $entries = $wp_filesystem->dirlist($source);
        if (!is_array($entries)) {
            return '';
        }

        foreach ($entries as $name => $entry) {
            if (($entry['type'] ?? '') !== 'd') {
                continue;
            }

            $candidate = trailingslashit($source) . $name;
            if ($wp_filesystem->exists(trailingslashit($candidate) . $plugin_file)) {
                return $candidate;
            }
        }

        return '';
    }

    private static function should_force_update_check(): bool
    {
        global $pagenow;

        return is_admin() && in_array($pagenow, ['plugins.php', 'update-core.php', 'plugin-install.php'], true);
    }

    private static function get_github_update_data(bool $force_refresh = false): ?array
    {
        $options = self::get_options();
        $repo = self::parse_github_repo($options['update_repo_url']);
        $branch = !empty($options['update_branch']) ? $options['update_branch'] : 'main';
        if (!$repo) {
            return null;
        }

        $cache_key = self::get_update_cache_key($repo, $branch);
        $cached = get_site_transient($cache_key);
        if (!$force_refresh && is_array($cached)) {
            return $cached;
        }

        $plugin_file_url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/linkai-ai-customer-service.php',
            rawurlencode($repo['owner']),
            rawurlencode($repo['name']),
            rawurlencode($branch)
        );
        $response = wp_remote_get($plugin_file_url, ['timeout' => 15]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $remote_plugin = wp_remote_retrieve_body($response);
        if (!preg_match('/^[ \t\/*#@]*Version:\s*([0-9A-Za-z.\-_]+)/mi', $remote_plugin, $matches)) {
            return null;
        }

        $data = [
            'version' => $matches[1],
            'repo_url' => sprintf('https://github.com/%s/%s', $repo['owner'], $repo['name']),
            'zip_url' => self::github_branch_zip_url($repo, $branch),
            'changelog' => self::extract_remote_changelog($remote_plugin),
        ];
        set_site_transient($cache_key, $data, MINUTE_IN_SECONDS);

        return $data;
    }

    private static function github_branch_zip_url(array $repo, string $branch): string
    {
        return sprintf(
            'https://codeload.github.com/%s/%s/zip/refs/heads/%s',
            rawurlencode($repo['owner']),
            rawurlencode($repo['name']),
            str_replace('%2F', '/', rawurlencode($branch))
        );
    }

    private static function sanitize_update_branch(string $branch): string
    {
        $branch = preg_replace('/[^A-Za-z0-9._\/-]/', '', trim($branch));

        return $branch !== '' ? $branch : 'main';
    }

    private static function parse_github_repo(string $repo_url): ?array
    {
        if ($repo_url === '') {
            return null;
        }

        if (preg_match('#github\.com[:/]([^/]+)/([^/.]+)(?:\.git)?/?#i', $repo_url, $matches)) {
            return [
                'owner' => sanitize_key($matches[1]),
                'name' => sanitize_key($matches[2]),
            ];
        }

        return null;
    }

    private static function extract_remote_changelog(string $remote_plugin): string
    {
        if (preg_match('/^[ \t\/*#@]*Version:\s*([^\n]+)$/mi', $remote_plugin, $matches)) {
            return '远程版本：' . trim($matches[1]);
        }

        return '';
    }

}
