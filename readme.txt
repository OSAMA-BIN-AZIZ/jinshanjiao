=== LinkAI 智能 AI 客服 ===
Contributors: jinshanjiao
Tags: ai, customer-service, chat, chatbot, crm
Requires at least: 5.8
Tested up to: 6.9.4
Requires PHP: 7.4
Stable tag: 1.3.44
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

为 WordPress 网站添加 LinkAI 智能客服聊天窗口，并在后台管理客户资料、跟进状态和聊天记录。

== Description ==

LinkAI 智能 AI 客服可以在网站右下角显示客服聊天窗口，通过 WordPress AJAX 在服务端调用 LinkAI 通用对话接口，避免把 API Key 暴露到浏览器。

主要功能：

* 全站右下角悬浮智能客服窗口，自动显示兼容 `wp_body_open`、`wp_footer` 和前端 JS 兜底渲染。
* 支持短代码 `[linkai_customer_service]` 嵌入指定页面。
* 后台配置 LinkAI API Key、应用 Code、模型、温度、欢迎语和系统提示词。
* 自动携带最近上下文，支持连续对话。
* 自动保存客户姓名、联系方式、咨询部门、IP、国家/地区、设备、咨询内容、AI 回复和完整聊天记录。
* 后台「LinkAI 客服 → 客户管理」可编辑客户资料、跟进状态和备注。
* 提供 GitHub 更新排查、缓存清除和插件目录权限修复入口。

== Installation ==

1. 上传插件目录到 `/wp-content/plugins/`，或在 WordPress 后台上传插件 ZIP。
2. 在 WordPress 后台「插件」中启用「LinkAI 智能 AI 客服」。
3. 进入「LinkAI 客服 → 设置」，填写 LinkAI API Key。
4. 保存后访问前台，右下角会显示「在线客服」。

== Frequently Asked Questions ==


= 登录后能看到，未登录看不到怎么办？ =

通常是缓存问题。登录用户一般绕过页面缓存，未登录访客会看到缓存的旧首页 HTML。请清理 WordPress 缓存插件、服务器缓存和 CDN 缓存，并确认首页 `/` 没有被单独缓存。

= 为什么后台检测不到更新？ =

WordPress 只有在远程插件文件头部的 `Version` 高于当前已安装版本时才会提示更新。如果刚刚手动上传的是最新版，本地和远程版本相同，就不会出现更新提示。

= 为什么提示“无法安装这个包”？ =

WordPress 更新会自动下载 ZIP 并解压。这个提示通常表示 ZIP 包结构不正确、ZIP 中没有插件主文件，或下载到的不是 ZIP 文件而是 GitHub/服务器错误页面。请确保 ZIP 解压后能找到 `linkai-ai-customer-service.php`，并且文件头部包含有效的 WordPress 插件信息。

= 为什么提示“更新失败：文件系统错误”？ =

通常是手动上传插件后目录所有者或权限不一致。可在「LinkAI 客服 → 设置 → 更新排查」中清除缓存或尝试修复权限；如果 PHP 用户不是目录所有者，仍需通过主机面板、FTP 或 SSH 修改所有者。

== Changelog ==

= 1.3.14 =
* 修复嵌套 jinshanjiao-main 源目录移动到父目录失败的问题。

= 1.3.13 =
* 增加登录可见、未登录不可见时的缓存排查说明。

= 1.3.12 =
* 增加前端 JS 兜底渲染，改善首页缓存或模板缺少挂载点时不显示的问题。

= 1.3.11 =
* 修复更新临时目录可能重复拼接 jinshanjiao-main 的问题。

= 1.3.10 =
* 更新失败时输出更具体的 ZIP 结构和文件系统错误原因。

= 1.3.9 =
* 改进前台自动显示逻辑，兼容更多主题模板。

= 1.3.8 =
* 插件页自动刷新远程版本，减少手动清除更新缓存的需要。

= 1.3.7 =
* 增加客户 IP、国家/地区、设备类型和 User Agent 记录。

= 1.3.6 =
* 固定使用 jinshanjiao-main 作为更新目录名。

= 1.3.5 =
* 增加更新包下载地址诊断，并改用 GitHub codeload ZIP 地址。

= 1.3.4 =
* 增加本地版本、GitHub 远程版本和更新判断诊断。
* 改进更新排查说明。

= 1.3.3 =
* 增加插件目录权限修复按钮。

= 1.3.2 =
* 将客户管理入口移动到后台左侧「LinkAI 客服 → 客户管理」。

= 1.3.1 =
* 增加更新缓存清除和文件系统诊断。

= 1.3.0 =
* 增加客户管理、跟进状态和备注。
* 改进 GitHub 更新目录处理。

== LinkAI tawk.to parity roadmap ==
Current builds include realtime workspace, online visitors, human takeover, business hours/offline messages, triggers, knowledge base, reports, basic ticket states, attachments and PWA manifest. See README.md and TODO.md for the current roadmap and remaining differences from a full tawk.to-like SaaS platform.
