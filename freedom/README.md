# freedom

当前同步版本：**v1.6.8**

来源：`analyze-v1.6.8-hidden-admin-values.zip`

本目录保存 freedom 教程插件当前核心源码备份。同步到 GitHub 时仅把可见品牌名称统一为 **freedom**，内部 class、slug、option 名保持不变，避免影响现有 WordPress 配置。

## v1.6.8 重点

- Android / Apple 的“点击拷贝”使用移动端兼容复制逻辑。
- 后台不回显现有 Android 订阅链接。
- 后台不回显 Apple / Shadowrocket 节点内容。
- Android：输入新链接并保存后覆盖对应旧链接；留空保留原链接。
- Apple：粘贴新的完整节点内容并保存后整体覆盖旧内容；留空保留原内容。
- 未验证访客不能从前台页面源码直接读取受保护订阅内容。
- 插件可见名称：`freedom`
- 版本：`1.6.8`

`secure-tutorial-download.php.gz` 是当前核心源码的 gzip 备份；`secure-tutorial-download.php` 是可直接查看的展开源码。
