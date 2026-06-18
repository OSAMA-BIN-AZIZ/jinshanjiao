# jinshanjiao

A suitable car parts website for deployment on WordPress.

## LinkAI 智能 AI 客服插件

本仓库提供一个 WordPress 插件，可在网站右下角显示汽车配件行业智能客服，并通过 WordPress AJAX 在服务端调用 LinkAI 通用对话接口，避免把 API Key 暴露到浏览器。

### 功能

- 全站右下角悬浮智能客服窗口。
- 支持短代码 `[linkai_customer_service]` 嵌入指定页面。
- WordPress 后台可配置 LinkAI API Key，支持已保存密钥提示、留空保留、重新填写替换和清除密钥。
- 后台配置应用 Code、模型、温度、欢迎语、系统提示词，以及 GitHub 更新仓库和分支。
- 自动携带最近 8 条上下文，让客服具备连续对话能力。
- 默认提示词适配汽车配件咨询场景，会主动收集车型、年份、发动机型号、数量和联系方式。


### 效果预览

可以直接打开仓库根目录的 `demo.html` 查看静态演示，也可以查看 `docs/demo-preview.svg` 了解右下角智能客服的大致样式。

![LinkAI 智能客服演示](docs/demo-preview.svg)

### 安装

1. 将仓库文件上传到 WordPress 的 `wp-content/plugins/linkai-ai-customer-service/` 目录。
2. 在 WordPress 后台「插件」中启用 **LinkAI 智能 AI 客服**。
3. 进入「设置 → LinkAI 智能客服」，填写 LinkAI API Key；保存后输入框会隐藏密钥，后续留空保存不会覆盖原密钥。
4. 可选填写 LinkAI 应用、工作流或超级 AI 助理的 `app_code`；不填时将直接调用模型能力。
5. 保存后访问网站前台，右下角会自动出现「在线客服」。

### 后台一键更新

如果你把插件代码放在 GitHub 仓库中，可以在「设置 → LinkAI 智能客服」里填写：

- GitHub 更新仓库： `https://github.com/OSAMA-BIN-AZIZ/jinshanjiao`
- 更新分支：默认 `main`

之后每次在 GitHub 更新插件时，请同步提高 `linkai-ai-customer-service.php` 文件头部的 `Version` 版本号。WordPress 后台「插件」页面检测到远程版本高于本地版本后，会显示更新提示，管理员可以直接点击更新。

### LinkAI 接口说明

插件调用的接口为：

```http
POST https://api.link-ai.tech/v1/chat/completions
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json
```

请求体会传入 `messages`，并按配置附加 `app_code`、`model` 和 `temperature`。

### 安全建议

- 不要在前端 JavaScript 中硬编码 API Key；请统一在「设置 → LinkAI 智能客服」中保存。
- 建议在 LinkAI 后台为网站单独创建 API Key，并定期轮换。
- 如果业务需要严格报价或库存，请在 LinkAI 应用中绑定真实知识库或工作流。
