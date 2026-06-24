(function () {
    function createMessage(role, text, extraClass) {
        const message = document.createElement('div');
        message.className = `linkai-chat__message linkai-chat__message--${role}`;
        if (extraClass) {
            message.classList.add(extraClass);
        }
        message.textContent = text;
        return message;
    }

    function getConfig() {
        return window.LinkAICustomerService || {};
    }

    function readStorage(key) {
        try {
            return window.localStorage && key ? window.localStorage.getItem(key) || '' : '';
        } catch (error) {
            return '';
        }
    }

    function writeStorage(key, value) {
        try {
            if (window.localStorage && key) {
                window.localStorage.setItem(key, value);
            }
        } catch (error) {
            // Storage can be blocked by privacy settings; chat should still work without it.
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    function createId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return `linkai-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    function createWidgetFromConfig() {
        const config = getConfig();
        if (!config.autoRender || document.querySelector('.linkai-chat')) {
            return null;
        }

        const widget = document.createElement('div');
        const receptionState = config.receptionState || {};
        widget.className = 'linkai-chat' + (receptionState.is_online === false ? ' linkai-chat--offline' : '') + (config.widgetPosition === 'left' ? ' linkai-chat--left' : '');
        if (config.widgetPrimaryColor) {
            widget.style.setProperty('--linkai-primary', config.widgetPrimaryColor);
        }
        widget.dataset.welcome = config.welcomeMessage || receptionState.message || '您好，请问有什么可以帮您？';
        const departments = Array.isArray(config.departments) ? config.departments.filter(Boolean) : [];
        const departmentField = departments.length > 0 ? '<select class="linkai-chat__customer-input" name="department" aria-label="选择咨询部门">' + departments.map(function(department){ return '<option value="' + escapeHtml(department) + '">' + escapeHtml(department) + '</option>'; }).join('') + '</select>' : '';
        widget.innerHTML = `
            <button class="linkai-chat__toggle" type="button" aria-label="打开智能客服">
                <span class="linkai-chat__toggle-icon">AI</span>
                <span class="linkai-chat__toggle-text"></span>
            </button>
            <section class="linkai-chat__panel" aria-label="智能客服聊天窗口" hidden>
                <header class="linkai-chat__header">
                    <div>
                        <strong></strong>
                        <span></span>
                    </div>
                    <button class="linkai-chat__close" type="button" aria-label="关闭智能客服">×</button>
                </header>
                <div class="linkai-chat__messages" role="log" aria-live="polite"></div>
                <form class="linkai-chat__form">
                    <div class="linkai-chat__customer-fields">
                        <input class="linkai-chat__customer-input" name="customer_name" type="text" maxlength="50" placeholder="姓名（选填）" autocomplete="name">
                        <input class="linkai-chat__customer-input" name="contact" type="text" maxlength="80" placeholder="电话/微信（选填）" autocomplete="tel">
                        ${departmentField}
                    </div>
                    <div class="linkai-chat__composer">
                        <textarea class="linkai-chat__input" name="message" rows="1" placeholder="请输入您的问题，例如：你们有哪些汽车配件？" required></textarea>
                        <input class="linkai-chat__attachment" name="attachment" type="file" accept="image/*,.pdf" aria-label="上传附件">
                        <button class="linkai-chat__send" type="submit">发送</button>
                    </div>
                </form>
            </section>`;
        widget.querySelector('.linkai-chat__toggle-text').textContent = config.widgetLauncherText || '在线客服';
        widget.querySelector('.linkai-chat__header strong').textContent = config.assistantName || '智能客服';
        widget.querySelector('.linkai-chat__header span').textContent = receptionState.label || '在线接待中';
        document.body.appendChild(widget);
        return widget;
    }

    function initChat(widget) {
        const config = getConfig();
        if (!config.ajaxUrl || !config.nonce) {
            return;
        }

        const toggle = widget.querySelector('.linkai-chat__toggle');
        const panel = widget.querySelector('.linkai-chat__panel');
        const close = widget.querySelector('.linkai-chat__close');
        const messages = widget.querySelector('.linkai-chat__messages');
        const form = widget.querySelector('.linkai-chat__form');
        const input = widget.querySelector('.linkai-chat__input');
        const send = widget.querySelector('.linkai-chat__send');
        const attachment = widget.querySelector('.linkai-chat__attachment');
        const customerName = widget.querySelector('[name="customer_name"]');
        const contact = widget.querySelector('[name="contact"]');
        const department = widget.querySelector('[name="department"]');
        const history = [];
        const errorMessage = config.errorMessage || '抱歉，智能客服暂时无法连接，请稍后再试或留下联系方式。';
        const contactRequiredMessage = config.contactRequiredMessage || '请先留下电话或微信，方便客服继续跟进。';
        const storageKey = config.conversationStorageKey || 'linkai_customer_service_conversation_id';
        const visitorStorageKey = config.visitorStorageKey || 'linkai_customer_service_visitor_id';
        let conversationId = readStorage(storageKey);
        let visitorId = readStorage(visitorStorageKey) || createId();
        writeStorage(visitorStorageKey, visitorId);
        let lastMessageId = 0;
        let pollTimer = null;
        let aiPaused = false;
        let serviceOnline = !(config.receptionState && config.receptionState.is_online === false);

        if (config.requireContact && contact) {
            contact.required = true;
            contact.placeholder = '电话/微信（必填）';
        }
        setServiceOnline(serviceOnline, config.welcomeMessage || '', (config.receptionState && config.receptionState.label) || '在线接待中');

        function scrollToBottom() {
            messages.scrollTop = messages.scrollHeight;
        }

        function addMessage(role, text, extraClass) {
            const messageEl = createMessage(role, text, extraClass);
            messages.appendChild(messageEl);
            scrollToBottom();
            return messageEl;
        }

        function addSatisfactionPrompt() {
            if (!conversationId || messages.querySelector('.linkai-chat__satisfaction')) {
                return;
            }
            const prompt = document.createElement('div');
            prompt.className = 'linkai-chat__satisfaction';
            prompt.innerHTML = '<span>这次回复有帮助吗？</span><button type="button" data-score="5">有帮助</button><button type="button" data-score="1">没帮助</button>';
            messages.appendChild(prompt);
            scrollToBottom();
        }

        async function sendSatisfaction(score, container) {
            if (!conversationId) { return; }
            const formData = new FormData();
            formData.append('action', 'linkai_customer_satisfaction');
            formData.append('nonce', config.nonce || '');
            formData.append('conversation_id', conversationId);
            formData.append('score', String(score));
            try {
                const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData });
                const payload = await response.json();
                container.textContent = payload.success ? '感谢您的反馈。' : '评价暂时无法保存。';
            } catch (error) {
                container.textContent = '评价暂时无法保存。';
            }
        }

        function setAiPaused(paused) {
            aiPaused = paused;
            widget.classList.toggle('linkai-chat--human-takeover', paused);
            updateInputPlaceholder();
        }

        function setServiceOnline(online, message, label) {
            serviceOnline = online;
            widget.classList.toggle('linkai-chat--offline', !online);
            if (message) {
                widget.dataset.welcome = message;
            }
            const status = widget.querySelector('.linkai-chat__header span');
            if (status && label) {
                status.textContent = label;
            }
            updateInputPlaceholder();
        }

        function updateInputPlaceholder() {
            if (!serviceOnline) {
                input.placeholder = '当前离线，请留言并留下联系方式…';
                return;
            }
            input.placeholder = aiPaused ? '人工客服已接管，您可以继续留言…' : '请输入您的问题，例如：你们有哪些汽车配件？';
        }

        function rememberConversation(id) {
            if (!id) {
                return;
            }
            conversationId = id;
            writeStorage(storageKey, conversationId);
            startPolling();
        }

        async function sendPresence() {
            const formData = new FormData();
            formData.append('action', 'linkai_customer_presence');
            formData.append('nonce', config.nonce || '');
            formData.append('visitor_id', visitorId);
            formData.append('conversation_id', conversationId);
            formData.append('current_url', window.location.href);
            formData.append('page_title', document.title || '');
            formData.append('referrer', document.referrer || '');

            try {
                const response = await fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                });
                const payload = await response.json();
                if (payload.success && payload.data.visitor_id) {
                    visitorId = payload.data.visitor_id;
                    writeStorage(visitorStorageKey, visitorId);
                }
            } catch (error) {
                // Presence is best-effort and must never interrupt the chat widget.
            }
        }

        async function pollUpdates() {
            if (!conversationId || document.hidden) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'linkai_customer_updates');
            formData.append('nonce', config.nonce || '');
            formData.append('conversation_id', conversationId);
            formData.append('after_id', String(lastMessageId));

            try {
                const response = await fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                });
                const payload = await response.json();
                if (!payload.success) {
                    return;
                }
                setAiPaused(Boolean(payload.data.ai_paused));
                if (payload.data.reception_state) {
                    setServiceOnline(Boolean(payload.data.reception_state.is_online), payload.data.reception_state.message || '', payload.data.reception_state.label || '');
                }
                (payload.data.messages || []).forEach(function (message) {
                    if (message.id <= lastMessageId) {
                        return;
                    }
                    addMessage(message.role || 'assistant', message.content || '', message.source === 'human' ? 'linkai-chat__message--human' : '');
                    history.push({ role: 'assistant', content: message.content || '' });
                    lastMessageId = message.id;
                    addSatisfactionPrompt();
                });
            } catch (error) {
                // Polling should stay quiet so it does not interrupt the visitor while waiting for a human reply.
            }
        }

        function startPolling() {
            if (pollTimer || !conversationId) {
                return;
            }
            pollTimer = window.setInterval(pollUpdates, 8000);
        }

        function scheduleTriggers() {
            (config.triggers || []).forEach(function(trigger){
                const urlMatch = !trigger.url_contains || window.location.href.indexOf(trigger.url_contains) !== -1;
                if (!urlMatch || !trigger.message) { return; }
                const key = 'linkai_trigger_' + trigger.id;
                if (readStorage(key)) { return; }
                window.setTimeout(function(){
                    if (readStorage(key)) { return; }
                    addMessage('assistant', trigger.message);
                    writeStorage(key, '1');
                    if (conversationId) {
                        const formData = new FormData();
                        formData.append('action', 'linkai_customer_trigger');
                        formData.append('nonce', config.nonce || '');
                        formData.append('conversation_id', conversationId);
                        formData.append('trigger_id', String(trigger.id || ''));
                        formData.append('current_url', window.location.href);
                        fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData }).catch(function(){});
                    }
                }, Math.max(1, Number(trigger.delay_seconds || 8)) * 1000);
            });
        }

        function openPanel() {
            panel.hidden = false;
            toggle.hidden = true;
            if (!messages.dataset.initialized) {
                addMessage('assistant', widget.dataset.welcome || '您好，请问有什么可以帮您？');
                messages.dataset.initialized = 'true';
            }
            input.focus();
        }

        function closePanel() {
            panel.hidden = true;
            toggle.hidden = false;
        }

        toggle.addEventListener('click', openPanel);
        close.addEventListener('click', closePanel);
        startPolling();
        sendPresence();
        window.setInterval(sendPresence, 20000);
        scheduleTriggers();

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });

        messages.addEventListener('click', function (event) {
            const button = event.target.closest('.linkai-chat__satisfaction button');
            if (!button) { return; }
            const container = button.closest('.linkai-chat__satisfaction');
            sendSatisfaction(Number(button.dataset.score || 0), container);
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const question = input.value.trim();
            if (!question) {
                return;
            }
            if (config.requireContact && !contact.value.trim()) {
                addMessage('assistant', contactRequiredMessage);
                contact.focus();
                return;
            }

            addMessage('user', question);
            history.push({ role: 'user', content: question });
            input.value = '';
            input.disabled = true;
            send.disabled = true;
            customerName.disabled = true;
            contact.disabled = true;

            const loading = createMessage('assistant', '正在思考，请稍候…', 'linkai-chat__message--loading');
            messages.appendChild(loading);
            scrollToBottom();

            const formData = new FormData();
            formData.append('action', 'linkai_customer_chat');
            formData.append('nonce', config.nonce || '');
            formData.append('message', question);
            formData.append('history', JSON.stringify(history.slice(0, -1)));
            formData.append('conversation_id', conversationId);
            formData.append('customer_name', customerName.value.trim());
            formData.append('contact', contact.value.trim());
            formData.append('department', department ? department.value : '');
            if (attachment && attachment.files && attachment.files[0]) {
                formData.append('attachment', attachment.files[0]);
            }

            try {
                const response = await fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                });
                const payload = await response.json();
                loading.remove();

                if (!payload.success) {
                    throw new Error(payload.data && payload.data.message ? payload.data.message : errorMessage);
                }

                if (payload.data.conversation_id) {
                    rememberConversation(payload.data.conversation_id);
                }
                if (payload.data.message_id) {
                    lastMessageId = Math.max(lastMessageId, Number(payload.data.message_id));
                }
                setAiPaused(Boolean(payload.data.ai_paused));
                if (payload.data.reception_state) {
                    setServiceOnline(Boolean(payload.data.reception_state.is_online), payload.data.reception_state.message || '', payload.data.reception_state.label || '');
                }
                addMessage('assistant', payload.data.reply, payload.data.ai_paused || payload.data.offline ? 'linkai-chat__message--human' : '');
                history.push({ role: 'assistant', content: payload.data.reply });
                addSatisfactionPrompt();
            } catch (error) {
                loading.remove();
                addMessage('assistant', error.message || errorMessage);
            } finally {
                input.disabled = false;
                send.disabled = false;
                customerName.disabled = false;
                contact.disabled = false;
                if (attachment) { attachment.value = ''; }
                input.focus();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const fallbackWidget = createWidgetFromConfig();
        if (fallbackWidget) {
            initChat(fallbackWidget);
            return;
        }

        document.querySelectorAll('.linkai-chat').forEach(initChat);
    });
}());
