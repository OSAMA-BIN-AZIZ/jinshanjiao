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

    function createWidgetFromConfig() {
        const config = getConfig();
        if (!config.autoRender || document.querySelector('.linkai-chat')) {
            return null;
        }

        const widget = document.createElement('div');
        widget.className = 'linkai-chat';
        widget.dataset.welcome = config.welcomeMessage || '您好，请问有什么可以帮您？';
        widget.innerHTML = `
            <button class="linkai-chat__toggle" type="button" aria-label="打开智能客服">
                <span class="linkai-chat__toggle-icon">AI</span>
                <span class="linkai-chat__toggle-text">在线客服</span>
            </button>
            <section class="linkai-chat__panel" aria-label="智能客服聊天窗口" hidden>
                <header class="linkai-chat__header">
                    <div>
                        <strong></strong>
                        <span>通常几秒内回复</span>
                    </div>
                    <button class="linkai-chat__close" type="button" aria-label="关闭智能客服">×</button>
                </header>
                <div class="linkai-chat__messages" role="log" aria-live="polite"></div>
                <form class="linkai-chat__form">
                    <div class="linkai-chat__customer-fields">
                        <input class="linkai-chat__customer-input" name="customer_name" type="text" maxlength="50" placeholder="姓名（选填）" autocomplete="name">
                        <input class="linkai-chat__customer-input" name="contact" type="text" maxlength="80" placeholder="电话/微信（选填）" autocomplete="tel">
                    </div>
                    <div class="linkai-chat__composer">
                        <textarea class="linkai-chat__input" name="message" rows="1" placeholder="请输入您的问题，例如：你们有哪些汽车配件？" required></textarea>
                        <button class="linkai-chat__send" type="submit">发送</button>
                    </div>
                </form>
            </section>`;
        widget.querySelector('.linkai-chat__header strong').textContent = config.assistantName || '智能客服';
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
        const customerName = widget.querySelector('[name="customer_name"]');
        const contact = widget.querySelector('[name="contact"]');
        const history = [];
        const errorMessage = config.errorMessage || '抱歉，智能客服暂时无法连接，请稍后再试或留下联系方式。';
        const storageKey = config.conversationStorageKey || 'linkai_customer_service_conversation_id';
        let conversationId = readStorage(storageKey);
        let lastMessageId = 0;
        let pollTimer = null;
        let aiPaused = false;

        function scrollToBottom() {
            messages.scrollTop = messages.scrollHeight;
        }

        function addMessage(role, text, extraClass) {
            messages.appendChild(createMessage(role, text, extraClass));
            scrollToBottom();
        }

        function setAiPaused(paused) {
            aiPaused = paused;
            widget.classList.toggle('linkai-chat--human-takeover', paused);
            input.placeholder = paused ? '人工客服已接管，您可以继续留言…' : '请输入您的问题，例如：你们有哪些汽车配件？';
        }

        function rememberConversation(id) {
            if (!id) {
                return;
            }
            conversationId = id;
            writeStorage(storageKey, conversationId);
            startPolling();
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
                (payload.data.messages || []).forEach(function (message) {
                    if (message.id <= lastMessageId) {
                        return;
                    }
                    addMessage(message.role || 'assistant', message.content || '', message.source === 'human' ? 'linkai-chat__message--human' : '');
                    history.push({ role: 'assistant', content: message.content || '' });
                    lastMessageId = message.id;
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

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const question = input.value.trim();
            if (!question) {
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
                addMessage('assistant', payload.data.reply, payload.data.ai_paused ? 'linkai-chat__message--human' : '');
                history.push({ role: 'assistant', content: payload.data.reply });
            } catch (error) {
                loading.remove();
                addMessage('assistant', error.message || errorMessage);
            } finally {
                input.disabled = false;
                send.disabled = false;
                customerName.disabled = false;
                contact.disabled = false;
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
