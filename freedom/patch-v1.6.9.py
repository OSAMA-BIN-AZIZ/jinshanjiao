from pathlib import Path

p = Path('freedom/secure-tutorial-download.php')
s = p.read_text(encoding='utf-8')

s = s.replace('* Version: 1.6.8', '* Version: 1.6.9')
s = s.replace("private const VERSION = '1.6.8';", "private const VERSION = '1.6.9';")

s = s.replace(
    '<p><strong>v1.5.2：</strong>大文件改为分块上传，103MB、几百 MB 的软件可直接在这里替换；不需要为了大文件把 PHP 全站上传限制调到很高。</p>',
    '<p><strong>v<?php echo esc_html(self::VERSION); ?>：</strong>支持大文件分块上传，103MB、几百MB的软件都可以直接在这里替换，无需提高整个 PHP 网站上传限制。</p>'
)

s = s.replace(
    '<tr><th>售价</th><td><input name="payment_amount" type="number" min="0" step="0.01" class="small-text" value="<?php echo esc_attr($options[\'payment_amount\']); ?>"> <select name="payment_currency">',
    '<tr><th>售价</th><td><input name="payment_amount" type="number" min="0" step="0.01" value="<?php echo esc_attr($options[\'payment_amount\']); ?>" style="width:160px;min-width:160px;font-size:16px;padding:6px 10px;"> <select name="payment_currency" style="min-width:145px;font-size:15px;">'
)

s = s.replace(
    '<input class="field" id="pwinput" type="password" placeholder="请输入下载密码" autocomplete="current-password">',
    '<input class="field" id="pwinput" type="text" placeholder="请输入下载密码（输入内容可见）" autocomplete="off" autocapitalize="off" spellcheck="false">'
)

s = s.replace(
    "<?php self::render_resource_box('desktop','电脑软件包',$ready['desktop'],$password_ready,$payment_base,$qr,$options,$ep);?>\n<p>建议把完整压缩包解压到桌面等当前用户拥有完整权限的位置。",
    "<?php self::render_resource_box('desktop','电脑软件包',$ready['desktop'],$password_ready,$payment_base,$qr,$options,$ep);?>\n<div class=\"desktop-ready-note\"><strong>✅ 电脑软件已经配置完成，下载后无需重新设置：</strong><br>1. 下载完成后把压缩包<strong>完整解压</strong>；<br>2. 先<strong>退出原来正在运行的 V2rayN</strong>；<br>3. 打开刚下载并解压的新文件夹，运行里面的 V2rayN，即可直接使用。</div>\n<p>建议把完整压缩包解压到桌面等当前用户拥有完整权限的位置。"
)

s = s.replace(
    '.warn,.note{padding:12px 14px;border-radius:10px;margin:12px 0}.warn{background:#fff5f3;border-left:4px solid #d93d2b}.note{background:#eef5ff;border-left:4px solid var(--b)}',
    '.warn,.note{padding:12px 14px;border-radius:10px;margin:12px 0}.warn{background:#fff5f3;border-left:4px solid #d93d2b}.note{background:#eef5ff;border-left:4px solid var(--b)}.desktop-ready-note{margin:14px 0 16px;padding:16px 18px;border:2px solid #1f9d55;border-left-width:6px;border-radius:12px;background:#ecfdf3;color:#173b2a;font-size:16px;line-height:1.85;box-shadow:0 4px 12px rgba(31,157,85,.08)}.desktop-ready-note strong{color:#087443;font-size:17px}'
)

p.write_text(s, encoding='utf-8')
