# LinkAI 第七批 tawk.to 差异补强任务：安全加固与触发器校验

对比 tawk.to 的触发器、文件传输、工单/团队协作能力后，本批先补齐安全优先级最高的细节：公开前端接口不能让访客伪造系统/触发器消息，同时限制输入长度，降低滥用和资源消耗风险。

- [x] 审计公开 AJAX：chat / updates / presence / satisfaction / trigger
- [x] 修复 trigger 接口可提交任意 message 的漏洞
- [x] trigger 事件改为提交 trigger_id，由服务端读取已启用触发器文案
- [x] trigger 事件校验 URL 匹配并防止同一会话重复写入
- [x] chat / satisfaction / trigger 增加输入长度限制
- [x] 更新前端触发器上报参数
- [x] 更新 README/发布说明
- [x] 更新插件 Version 和内部 VERSION
- [x] 运行 PHP/JS 检查
