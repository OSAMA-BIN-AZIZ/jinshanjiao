# freedom

当前同步版本：**v1.6.4 安全版**

来源：`analyze-v1.6.4-secure-subscriptions.zip`

本目录保存 freedom 教程插件当前核心源码备份。

安全规则：
- Android 两个订阅链接不写入公开前台源码；仅在密码/付款验证成功后由服务器返回当前一个链接。
- Apple / Shadowrocket 订阅内容不写入公开前台源码；仅在验证成功后由服务器返回。
- 后台仍可修改 Android 两个订阅链接和 Apple / Shadowrocket 订阅内容。
- 插件可见名称：`freedom`
- 版本：`1.6.4`

`secure-tutorial-download.php.gz` 是当前 v1.6.4 核心 PHP 源码的 gzip 备份。仓库工作流会自动解压生成可直接查看的 `secure-tutorial-download.php`。
