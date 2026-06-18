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

    function initChat(widget) {
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
        let conversationId = window.localStorage ? window.localStorage.getItem(LinkAICustomerService.conversationStorageKey) || '' : '';

        function scrollToBottom() {
            messages.scrollTop = messages.scrollHeight;
        }

        function addMessage(role, text, extraClass) {
            messages.appendChild(createMessage(role, text, extraClass));
            scrollToBottom();
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
            formData.append('nonce', LinkAICustomerService.nonce);
            formData.append('message', question);
            formData.append('history', JSON.stringify(history.slice(0, -1)));
            formData.append('conversation_id', conversationId);
            formData.append('customer_name', customerName.value.trim());
            formData.append('contact', contact.value.trim());

            try {
                const response = await fetch(LinkAICustomerService.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                });
                const payload = await response.json();
                loading.remove();

                if (!payload.success) {
                    throw new Error(payload.data && payload.data.message ? payload.data.message : LinkAICustomerService.errorMessage);
                }

                if (payload.data.conversation_id) {
                    conversationId = payload.data.conversation_id;
                    if (window.localStorage) {
                        window.localStorage.setItem(LinkAICustomerService.conversationStorageKey, conversationId);
                    }
                }
                addMessage('assistant', payload.data.reply);
                history.push({ role: 'assistant', content: payload.data.reply });
            } catch (error) {
                loading.remove();
                addMessage('assistant', error.message || LinkAICustomerService.errorMessage);
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
        document.querySelectorAll('.linkai-chat').forEach(initChat);
    });
}());
