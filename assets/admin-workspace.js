(function(){
    const root = document.querySelector('.linkai-workspace');
    if (!root) { return; }
    const ajaxUrl = root.dataset.ajaxUrl;
    const nonce = root.dataset.nonce;
    let agents = [];
    try { agents = JSON.parse(root.dataset.agents || '[]'); } catch (error) { agents = []; }
    const browserNotificationsEnabled = root.dataset.browserNotifications === '1';
    const conversationsEl = root.querySelector('[data-linkai-conversations]');
    const messagesEl = root.querySelector('[data-linkai-messages]');
    const headerEl = root.querySelector('[data-linkai-chat-header]');
    const profileEl = root.querySelector('[data-linkai-profile]');
    const receptionStatusEl = root.querySelector('[data-linkai-reception-status]');
    const replyForm = root.querySelector('[data-linkai-reply-form]');
    const replyInput = root.querySelector('[data-linkai-reply]');
    const cannedRepliesEl = root.querySelector('[data-linkai-canned-replies]');
    const filterSearchEl = root.querySelector('[data-linkai-filter-search]');
    const filterStatusEl = root.querySelector('[data-linkai-filter-status]');
    const filterAssigneeEl = root.querySelector('[data-linkai-filter-assignee]');
    const filterPriorityEl = root.querySelector('[data-linkai-filter-priority]');
    const filterUnreadEl = root.querySelector('[data-linkai-filter-unread]');
    let selectedConversation = '';
    let conversations = [];
    let messagesTimer = null;
    let lastUnreadTotal = 0;
    let hasLoadedConversations = false;
    populateAssigneeFilter();

    function post(action, data) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', nonce);
        Object.keys(data || {}).forEach(function(key){ formData.append(key, data[key]); });
        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData }).then(function(response){ return response.json(); });
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>'"]/g, function(char){
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char];
        });
    }

    function playNewMessageSound() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) { return; }
            const context = new AudioContext();
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.value = 0.05;
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start();
            window.setTimeout(function(){ oscillator.stop(); context.close(); }, 180);
        } catch (error) {}
    }

    function notifyNewMessages(count) {
        if (count <= 0 || !browserNotificationsEnabled) { return; }
        playNewMessageSound();
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('LinkAI 新消息', { body: count + ' 条客户消息待处理' });
        }
    }

    function populateAssigneeFilter() {
        if (!filterAssigneeEl) { return; }
        filterAssigneeEl.innerHTML = '<option value="">全部客服</option><option value="0">未分配</option>' + agents.map(function(agent){
            return '<option value="' + agent.id + '">' + escapeHtml(agent.name) + '</option>';
        }).join('');
    }

    function getFilteredConversations() {
        const keyword = filterSearchEl ? filterSearchEl.value.trim().toLowerCase() : '';
        const status = filterStatusEl ? filterStatusEl.value : '';
        const assignee = filterAssigneeEl ? filterAssigneeEl.value : '';
        const priority = filterPriorityEl ? filterPriorityEl.value : '';
        const unreadOnly = filterUnreadEl ? filterUnreadEl.checked : false;

        return conversations.filter(function(item){
            if (status && item.status !== status) { return false; }
            if (assignee !== '' && String(item.assigned_user_id || 0) !== assignee) { return false; }
            if (priority && item.priority !== priority) { return false; }
            if (unreadOnly && Number(item.unread_count || 0) <= 0) { return false; }
            if (!keyword) { return true; }
            const haystack = [
                item.customer_name,
                item.contact,
                item.last_message,
                item.last_reply,
                item.assigned_user_label,
                item.priority_label,
                item.status_label,
                item.tags,
                item.follow_up_at,
                item.conversation_id
            ].join(' ').toLowerCase();
            return haystack.indexOf(keyword) !== -1;
        });
    }

    function renderConversations() {
        const visibleConversations = getFilteredConversations();
        if (!visibleConversations.length) {
            conversationsEl.textContent = conversations.length ? '没有符合筛选条件的会话。' : '暂无会话。';
            return;
        }
        conversationsEl.innerHTML = visibleConversations.map(function(item){
            const active = item.conversation_id === selectedConversation ? ' is-active' : '';
            const paused = item.ai_paused ? '<span class="linkai-workspace__badge">人工</span>' : '';
            const unread = Number(item.unread_count || 0) > 0 ? '<span class="linkai-workspace__badge">' + Number(item.unread_count || 0) + '</span>' : '';
            const follow = item.follow_up_at ? ' · 跟进 ' + item.follow_up_at : '';
            const tags = item.tags ? ' · ' + escapeHtml(item.tags) : '';
            return '<button type="button" class="linkai-workspace__conversation' + active + '" data-conversation="' + escapeHtml(item.conversation_id) + '"><strong>' + escapeHtml(item.customer_name || item.contact || '未留姓名') + paused + unread + '</strong><small>' + escapeHtml(item.last_message || '暂无消息') + '</small><small>' + escapeHtml(item.assigned_user_label || '未分配') + ' · ' + escapeHtml(item.priority_label || '普通') + tags + (item.closed_at ? ' · 已关闭' : '') + '</small><small>' + escapeHtml(item.updated_at || '') + escapeHtml(follow) + '</small></button>';
        }).join('');
    }

    function loadConversations() {
        return post('linkai_admin_conversations', {}).then(function(payload){
            if (!payload.success) { throw new Error(payload.data && payload.data.message ? payload.data.message : '会话加载失败'); }
            conversations = payload.data.conversations || [];
            if (receptionStatusEl && payload.data.reception_state) {
                receptionStatusEl.textContent = (payload.data.reception_state.label || '') + '：' + (payload.data.reception_state.message || '');
            }
            const unreadTotal = conversations.reduce(function(total, item){ return total + Number(item.unread_count || 0); }, 0);
            if (hasLoadedConversations && unreadTotal > lastUnreadTotal) {
                notifyNewMessages(unreadTotal - lastUnreadTotal);
            }
            lastUnreadTotal = unreadTotal;
            hasLoadedConversations = true;
            document.title = unreadTotal > 0 ? '(' + unreadTotal + ') LinkAI 实时工作台' : 'LinkAI 实时工作台';
            if (!selectedConversation && conversations.length) {
                selectedConversation = conversations[0].conversation_id;
                loadMessages();
            }
            renderConversations();
        }).catch(function(error){ conversationsEl.textContent = error.message; });
    }

    function renderMessages(messages) {
        messagesEl.innerHTML = (messages || []).map(function(message){
            return '<div class="linkai-workspace__message linkai-workspace__message--' + escapeHtml(message.role) + '"><span class="linkai-workspace__message-meta">' + escapeHtml(message.role_label) + ' · ' + escapeHtml(message.created_at) + '</span>' + escapeHtml(message.content).replace(/\n/g, '<br>') + '</div>';
        }).join('');
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function renderProfile(conversation) {
        if (!conversation) { return; }
        headerEl.innerHTML = '<strong>' + escapeHtml(conversation.customer_name || conversation.contact || '未留姓名') + '</strong><span>' + (conversation.ai_paused ? '人工接管中' : 'AI 自动回复中') + '</span>';
        const agentOptions = '<option value="0">未分配</option>' + agents.map(function(agent){ return '<option value="' + agent.id + '"' + (Number(conversation.assigned_user_id || 0) === agent.id ? ' selected' : '') + '>' + escapeHtml(agent.name) + '</option>'; }).join('');
        const priorityOptions = ['low:低','normal:普通','high:高','urgent:紧急'].map(function(item){ const parts = item.split(':'); return '<option value="' + parts[0] + '"' + (conversation.priority === parts[0] ? ' selected' : '') + '>' + parts[1] + '</option>'; }).join('');
        const statusOptions = ['open:处理中','pending:待客户回复','solved:已解决','closed:已关闭','new:新客户','contacted:已联系','qualified:有意向'].map(function(item){ const parts = item.split(':'); return '<option value="' + parts[0] + '"' + (conversation.status === parts[0] ? ' selected' : '') + '>' + parts[1] + '</option>'; }).join('');
        const tagBadges = (conversation.tag_list || []).map(function(tag){ return '<span class="linkai-workspace__tag">' + escapeHtml(tag) + '</span>'; }).join('') || '<span class="description">暂无标签</span>';
        const followValue = conversation.follow_up_at ? String(conversation.follow_up_at).replace(' ', 'T').slice(0, 16) : '';
        profileEl.innerHTML = '<h2>客户资料</h2><dl><dt>姓名</dt><dd>' + escapeHtml(conversation.customer_name || '未留') + '</dd><dt>联系方式</dt><dd>' + escapeHtml(conversation.contact || '未留') + '</dd><dt>状态</dt><dd>' + escapeHtml(conversation.status_label) + '</dd><dt>客服</dt><dd>' + escapeHtml(conversation.assigned_user_label || '未分配') + '</dd><dt>优先级</dt><dd>' + escapeHtml(conversation.priority_label || '普通') + '</dd><dt>标签</dt><dd>' + tagBadges + '</dd><dt>下次跟进</dt><dd>' + escapeHtml(conversation.follow_up_at || '未设置') + '</dd><dt>IP/国家</dt><dd>' + escapeHtml([conversation.ip_address, conversation.country].filter(Boolean).join(' ') || '未知') + '</dd><dt>设备</dt><dd>' + escapeHtml(conversation.device || '未知') + '</dd><dt>更新时间</dt><dd>' + escapeHtml(conversation.updated_at || '') + '</dd></dl><p><label>分配客服<br><select data-linkai-agent>' + agentOptions + '</select></label></p><p><label>优先级<br><select data-linkai-priority>' + priorityOptions + '</select></label></p><p><button type="button" class="button" data-linkai-save-assignment>保存分配</button> <button type="button" class="button" data-linkai-close value="' + (conversation.closed_at ? '0' : '1') + '">' + (conversation.closed_at ? '重新打开会话' : '关闭会话') + '</button></p><hr><p><label>姓名<br><input type="text" class="widefat" data-linkai-customer-name value="' + escapeHtml(conversation.customer_name || '') + '" placeholder="客户姓名"></label></p><p><label>联系方式<br><input type="text" class="widefat" data-linkai-contact value="' + escapeHtml(conversation.contact || '') + '" placeholder="电话/微信"></label></p><p><label>跟进状态<br><select data-linkai-status>' + statusOptions + '</select></label></p><p><label>客户标签（逗号分隔）<br><input type="text" class="widefat" data-linkai-tags value="' + escapeHtml(conversation.tags || '') + '" placeholder="高意向, 已报价"></label></p><p><label>下次跟进时间<br><input type="datetime-local" class="widefat" data-linkai-follow-up value="' + escapeHtml(followValue) + '"></label></p><p><label>内部备注<br><textarea class="widefat" rows="4" data-linkai-notes>' + escapeHtml(conversation.notes || '') + '</textarea></label></p><p><button type="button" class="button button-primary" data-linkai-save-crm>保存跟进资料</button></p><p><button type="button" class="button" data-linkai-toggle-ai value="' + (conversation.ai_paused ? '0' : '1') + '">' + (conversation.ai_paused ? '恢复 AI 自动回复' : '暂停 AI，人工接管') + '</button></p>';
    }

    function loadMessages() {
        if (!selectedConversation) { return Promise.resolve(); }
        return post('linkai_admin_messages', { conversation_id: selectedConversation }).then(function(payload){
            if (!payload.success) { throw new Error(payload.data && payload.data.message ? payload.data.message : '消息加载失败'); }
            renderMessages(payload.data.messages || []);
            renderProfile(payload.data.conversation);
        }).catch(function(error){ messagesEl.textContent = error.message; });
    }

    function loadCannedReplies() {
        if (!cannedRepliesEl) { return; }
        post('linkai_admin_canned_replies', {}).then(function(payload){
            if (!payload.success) { return; }
            const replies = payload.data.replies || [];
            cannedRepliesEl.innerHTML = '<option value="">快捷回复…</option>' + replies.map(function(reply){
                const label = reply.category ? '[' + reply.category + '] ' + reply.title : reply.title;
                return '<option value="' + escapeHtml(reply.content) + '">' + escapeHtml(label) + '</option>';
            }).join('');
        });
    }

    if (cannedRepliesEl) {
        cannedRepliesEl.addEventListener('change', function(){
            if (!cannedRepliesEl.value) { return; }
            replyInput.value = replyInput.value ? replyInput.value + '\n' + cannedRepliesEl.value : cannedRepliesEl.value;
            cannedRepliesEl.value = '';
            replyInput.focus();
        });
    }

    conversationsEl.addEventListener('click', function(event){
        const button = event.target.closest('[data-conversation]');
        if (!button) { return; }
        selectedConversation = button.dataset.conversation;
        renderConversations();
        loadMessages();
        if (messagesTimer) { clearInterval(messagesTimer); }
        messagesTimer = setInterval(loadMessages, 3000);
    });

    [filterSearchEl, filterStatusEl, filterAssigneeEl, filterPriorityEl, filterUnreadEl].forEach(function(control){
        if (!control) { return; }
        control.addEventListener('input', renderConversations);
        control.addEventListener('change', renderConversations);
    });

    root.querySelector('[data-linkai-refresh]').addEventListener('click', function(){
        if (browserNotificationsEnabled && 'Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
        loadConversations().then(loadMessages);
    });

    profileEl.addEventListener('click', function(event){
        const assignButton = event.target.closest('[data-linkai-save-assignment]');
        if (assignButton && selectedConversation) {
            const agentSelect = profileEl.querySelector('[data-linkai-agent]');
            const prioritySelect = profileEl.querySelector('[data-linkai-priority]');
            post('linkai_admin_assign_conversation', { conversation_id: selectedConversation, assigned_user_id: agentSelect ? agentSelect.value : '0', priority: prioritySelect ? prioritySelect.value : 'normal' }).then(function(payload){
                if (!payload.success) { throw new Error(payload.data && payload.data.message ? payload.data.message : '分配失败'); }
                return loadConversations().then(loadMessages);
            }).catch(function(error){ alert(error.message); });
            return;
        }
        const closeButton = event.target.closest('[data-linkai-close]');
        if (closeButton && selectedConversation) {
            post('linkai_admin_close_conversation', { conversation_id: selectedConversation, closed: closeButton.value }).then(function(payload){
                if (!payload.success) { throw new Error(payload.data && payload.data.message ? payload.data.message : '会话状态更新失败'); }
                return loadConversations().then(loadMessages);
            }).catch(function(error){ alert(error.message); });
            return;
        }
        const crmButton = event.target.closest('[data-linkai-save-crm]');
        if (crmButton && selectedConversation) {
            const nameInput = profileEl.querySelector('[data-linkai-customer-name]');
            const contactInput = profileEl.querySelector('[data-linkai-contact]');
            const statusSelect = profileEl.querySelector('[data-linkai-status]');
            const tagsInput = profileEl.querySelector('[data-linkai-tags]');
            const followUpInput = profileEl.querySelector('[data-linkai-follow-up]');
            const notesInput = profileEl.querySelector('[data-linkai-notes]');
            post('linkai_admin_update_crm', {
                conversation_id: selectedConversation,
                customer_name: nameInput ? nameInput.value : '',
                contact: contactInput ? contactInput.value : '',
                status: statusSelect ? statusSelect.value : 'new',
                tags: tagsInput ? tagsInput.value : '',
                follow_up_at: followUpInput ? followUpInput.value : '',
                notes: notesInput ? notesInput.value : ''
            }).then(function(payload){
                if (!payload.success) { throw new Error(payload.data && payload.data.message ? payload.data.message : '跟进资料保存失败'); }
                return loadConversations().then(loadMessages);
            }).catch(function(error){ alert(error.message); });
            return;
        }
        const button = event.target.closest('[data-linkai-toggle-ai]');
        if (!button || !selectedConversation) { return; }
        post('linkai_admin_toggle_ai', { conversation_id: selectedConversation, ai_paused: button.value }).then(function(payload){
            if (!payload.success) { throw new Error(payload.data && payload.data.message ? payload.data.message : '更新失败'); }
            return loadConversations().then(loadMessages);
        }).catch(function(error){ alert(error.message); });
    });

    replyForm.addEventListener('submit', function(event){
        event.preventDefault();
        const message = replyInput.value.trim();
        if (!selectedConversation || !message) { return; }
        replyInput.disabled = true;
        post('linkai_admin_send_reply', { conversation_id: selectedConversation, message: message }).then(function(payload){
            if (!payload.success) { throw new Error(payload.data && payload.data.message ? payload.data.message : '发送失败'); }
            replyInput.value = '';
            return loadConversations().then(loadMessages);
        }).catch(function(error){ alert(error.message); }).finally(function(){ replyInput.disabled = false; replyInput.focus(); });
    });

    loadCannedReplies();
    loadConversations().then(function(){ messagesTimer = setInterval(loadMessages, 3000); setInterval(loadConversations, 8000); });
}());
