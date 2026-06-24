(function(){
    const root = document.querySelector('.linkai-visitors');
    if (!root) { return; }
    const tbody = root.querySelector('[data-linkai-visitors]');
    const pathBox = root.querySelector('[data-linkai-visitor-path]');
    function escapeHtml(value) {
        return String(value || '').replace(/[&<>'"]/g, function(char){ return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]; });
    }
    function loadVisitors() {
        const formData = new FormData();
        formData.append('action', 'linkai_admin_online_visitors');
        formData.append('nonce', root.dataset.nonce);
        fetch(root.dataset.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
            .then(function(response){ return response.json(); })
            .then(function(payload){
                if (!payload.success) { throw new Error(payload.data && payload.data.message ? payload.data.message : '在线访客加载失败'); }
                const visitors = payload.data.visitors || [];
                if (!visitors.length) {
                    tbody.innerHTML = '<tr><td colspan="8">暂无在线访客。</td></tr>';
                    return;
                }
                tbody.innerHTML = visitors.map(function(visitor){
                    const title = visitor.page_title || visitor.current_url || '未知页面';
                    const conversation = visitor.conversation_id ? '<br><code>' + escapeHtml(visitor.conversation_id) + '</code>' : '';
                    return '<tr><td><code>' + escapeHtml(visitor.visitor_id) + '</code>' + conversation + '</td><td><a href="' + escapeHtml(visitor.current_url) + '" target="_blank" rel="noreferrer">' + escapeHtml(title) + '</a></td><td>' + escapeHtml(visitor.referrer || '直接访问') + '</td><td>' + escapeHtml([visitor.ip_address, visitor.country].filter(Boolean).join(' ') || '未知') + '</td><td>' + escapeHtml([visitor.device, visitor.browser].filter(Boolean).join(' / ') || '未知') + '</td><td>' + escapeHtml(visitor.first_seen_at) + '</td><td>' + escapeHtml(visitor.last_seen_at) + '</td><td><button type="button" class="button" data-visitor-path="' + escapeHtml(visitor.visitor_id) + '">查看</button></td></tr>';
                }).join('');
            })
            .catch(function(error){ tbody.innerHTML = '<tr><td colspan="8">' + escapeHtml(error.message) + '</td></tr>'; });
    }
    function loadVisitorPath(visitorId) {
        const formData = new FormData();
        formData.append('action', 'linkai_admin_visitor_path');
        formData.append('nonce', root.dataset.nonce);
        formData.append('visitor_id', visitorId);
        pathBox.textContent = '正在加载浏览轨迹…';
        fetch(root.dataset.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
            .then(function(response){ return response.json(); })
            .then(function(payload){
                if (!payload.success) { throw new Error(payload.data && payload.data.message ? payload.data.message : '浏览轨迹加载失败'); }
                const pageviews = payload.data.pageviews || [];
                if (!pageviews.length) {
                    pathBox.textContent = '该访客暂无浏览轨迹。';
                    return;
                }
                pathBox.innerHTML = '<h2>最近浏览轨迹</h2><ol>' + pageviews.map(function(pageview){
                    const title = pageview.page_title || pageview.page_url || '未知页面';
                    const referrer = pageview.referrer ? '<br><small>来源：' + escapeHtml(pageview.referrer) + '</small>' : '';
                    return '<li><a href="' + escapeHtml(pageview.page_url) + '" target="_blank" rel="noreferrer">' + escapeHtml(title) + '</a><br><small>' + escapeHtml(pageview.visited_at) + '</small>' + referrer + '</li>';
                }).join('') + '</ol>';
            })
            .catch(function(error){ pathBox.textContent = error.message; });
    }
    tbody.addEventListener('click', function(event){
        const button = event.target.closest('[data-visitor-path]');
        if (!button) { return; }
        loadVisitorPath(button.dataset.visitorPath);
    });
    root.querySelector('[data-linkai-refresh-visitors]').addEventListener('click', loadVisitors);
    loadVisitors();
    setInterval(loadVisitors, 10000);
}());
