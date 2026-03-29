(function () {
    function createSessionId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return 'agent-' + Math.random().toString(36).slice(2);
    }

    function AgentAssistant(root, config) {
        this.root = root;
        this.config = config || {};
        this.panel = root.querySelector('.aivesese-agent__panel');
        this.messages = root.querySelector('[data-agent-messages]');
        this.form = root.querySelector('[data-agent-form]');
        this.input = root.querySelector('[data-agent-input]');
        this.openButton = root.querySelector('[data-agent-open]');
        this.closeButton = root.querySelector('[data-agent-close]');
        this.sessionId = createSessionId();
        this.started = false;
        this.bind();
    }

    AgentAssistant.prototype.bind = function () {
        if (this.openButton) {
            this.openButton.addEventListener('click', this.open.bind(this));
        }

        if (this.closeButton) {
            this.closeButton.addEventListener('click', this.close.bind(this));
        }

        if (this.form) {
            this.form.addEventListener('submit', this.submit.bind(this));
        }

        if (this.messages) {
            this.messages.addEventListener('click', this.trackAddToCart.bind(this));
        }
    };

    AgentAssistant.prototype.track = function (payload) {
        var body = new URLSearchParams();
        body.append('action', 'aivesese_agent_track');
        body.append('nonce', this.config.nonce || '');
        body.append('session_id', this.sessionId);

        Object.keys(payload || {}).forEach(function (key) {
            if (payload[key] !== undefined && payload[key] !== null) {
                body.append(key, String(payload[key]));
            }
        });

        window.fetch(this.config.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).catch(function () {});
    };

    AgentAssistant.prototype.open = function () {
        if (!this.panel) {
            return;
        }

        this.panel.hidden = false;
        this.root.classList.add('is-open');

        if (!this.started) {
            this.started = true;
            this.track({ event_type: 'session_start' });
            this.renderMessage('assistant', (this.config.strings && this.config.strings.intro) || 'I am an AI Agent for this store.');
            if (this.config.disclaimer) {
                this.renderMessage('assistant', this.config.disclaimer);
            }
        }
    };

    AgentAssistant.prototype.close = function () {
        if (!this.panel) {
            return;
        }

        this.panel.hidden = true;
        this.root.classList.remove('is-open');
    };

    AgentAssistant.prototype.submit = function (event) {
        var _this = this;
        event.preventDefault();

        var message = (this.input && this.input.value ? this.input.value : '').trim();
        if (!message) {
            return;
        }

        this.renderMessage('user', message);
        this.input.value = '';
        this.renderMessage('assistant', (this.config.strings && this.config.strings.thinking) || 'Thinking…', true);

        var body = new URLSearchParams();
        body.append('action', 'aivesese_agent_chat');
        body.append('nonce', this.config.nonce || '');
        body.append('session_id', this.sessionId);
        body.append('message', message);

        window.fetch(this.config.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                _this.removePending();

                if (!payload || !payload.success) {
                    _this.renderMessage('assistant', (payload && payload.data && payload.data.message) || (payload && payload.message) || (_this.config.strings && _this.config.strings.error) || 'Request failed.');
                    return;
                }

                _this.renderAgentResponse(payload.data || {});
            })
            .catch(function () {
                _this.removePending();
                _this.renderMessage('assistant', (_this.config.strings && _this.config.strings.error) || 'Request failed.');
            });
    };

    AgentAssistant.prototype.renderAgentResponse = function (data) {
        var wrapper = document.createElement('div');
        wrapper.className = 'aivesese-agent__message aivesese-agent__message--assistant';

        var text = document.createElement('div');
        text.className = 'aivesese-agent__bubble';
        text.textContent = data.message || 'No response available.';
        wrapper.appendChild(text);

        if (Array.isArray(data.products) && data.products.length) {
            var list = document.createElement('div');
            list.className = 'aivesese-agent__products';

            data.products.forEach(function (product) {
                var card = document.createElement('article');
                card.className = 'aivesese-agent__product';

                var link = document.createElement('a');
                link.className = 'aivesese-agent__product-link';
                var productUrl = String(product.product_url || '');
                link.href = /^https?:\/\//i.test(productUrl) ? productUrl : '#';

                if (product.image_url) {
                    var img = document.createElement('img');
                    img.src = product.image_url;
                    img.alt = product.name || '';
                    link.appendChild(img);
                }

                var strong = document.createElement('strong');
                strong.textContent = product.name || 'Product';
                link.appendChild(strong);
                card.appendChild(link);

                var priceDiv = document.createElement('div');
                priceDiv.className = 'aivesese-agent__product-price';
                priceDiv.innerHTML = product.price_html || '';
                card.appendChild(priceDiv);

                if (product.can_add_to_cart && product.add_to_cart_url) {
                    var button = document.createElement('a');
                    button.className = 'button button-primary aivesese-agent__cart';
                    button.href = product.add_to_cart_url;
                    button.textContent = product.add_to_cart_text || 'Add to cart';
                    button.dataset.productId = product.woocommerce_id || '';
                    button.dataset.intent = data.intent || '';
                    card.appendChild(button);
                }

                list.appendChild(card);
            });

            wrapper.appendChild(list);
        }

        this.messages.appendChild(wrapper);
        this.messages.scrollTop = this.messages.scrollHeight;
    };

    AgentAssistant.prototype.renderMessage = function (role, message, pending) {
        if (!this.messages) {
            return;
        }

        var row = document.createElement('div');
        row.className = 'aivesese-agent__message aivesese-agent__message--' + role + (pending ? ' is-pending' : '');

        var bubble = document.createElement('div');
        bubble.className = 'aivesese-agent__bubble';
        bubble.textContent = message;
        row.appendChild(bubble);

        this.messages.appendChild(row);
        this.messages.scrollTop = this.messages.scrollHeight;
    };

    AgentAssistant.prototype.removePending = function () {
        var pending = this.messages.querySelector('.is-pending');
        if (pending) {
            pending.remove();
        }
    };

    AgentAssistant.prototype.trackAddToCart = function (event) {
        var button = event.target.closest('.aivesese-agent__cart');
        if (!button) {
            return;
        }

        this.track({
            event_type: 'add_to_cart_click',
            product_id: button.dataset.productId || '',
            intent: button.dataset.intent || ''
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (!window.aivesese_agent || window.aivesese_agent.enabled !== '1') {
            return;
        }

        document.querySelectorAll('[data-aivesese-agent]').forEach(function (root) {
            new AgentAssistant(root, window.aivesese_agent);
        });
    });
}());
