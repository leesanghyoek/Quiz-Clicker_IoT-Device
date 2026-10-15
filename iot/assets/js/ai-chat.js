/*
 * AI assistant window (includes/ai_chat_widget.php). The conversation lives in
 * this tab's sessionStorage, so it follows the teacher from page to page; each
 * question sends the recent turns to api/ai_chat.php and the answer streams in.
 */
(function () {
    var panel = document.getElementById('ai-chat-panel');
    var launcher = document.getElementById('ai-chat-launcher');

    if (!panel || !launcher) {
        return;
    }

    var STORE = 'stem-ai-chat';
    var log = document.getElementById('ai-chat-log');
    var body = document.getElementById('ai-chat-body');
    var welcome = document.getElementById('ai-chat-welcome');
    var form = document.getElementById('ai-chat-form');
    var input = document.getElementById('ai-chat-input');
    var send = document.getElementById('ai-chat-send');
    var jump = document.getElementById('ai-chat-jump');
    var wide = document.getElementById('ai-chat-wide');
    var state = load();
    var running = null; // AbortController of the answer being written
    var followBottom = true;

    function load() {
        try {
            var saved = JSON.parse(sessionStorage.getItem(STORE));
            return saved && Array.isArray(saved.messages) ? saved : { open: false, wide: false, messages: [] };
        } catch (error) {
            return { open: false, wide: false, messages: [] };
        }
    }

    function save() {
        try {
            sessionStorage.setItem(STORE, JSON.stringify(state));
        } catch (error) {
            // Private mode: the chat simply starts fresh on the next page.
        }
    }

    /* ------------------------------------------------------------
     * Turning the answer text into safe HTML
     * ---------------------------------------------------------- */
    function escapeHtml(text) {
        return text.replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Formulas sometimes still arrive as LaTeX ($ I = \frac{U}{R} $); show them as plain text.
    function plainMath(text) {
        return text
            // The small local model now and then slips into Chinese mid-sentence; drop those characters.
            .replace(/[\u3000-\u303f\u3400-\u4dbf\u4e00-\u9fff\uff00-\uffef]+/g, '')
            .replace(/\\frac\{([^{}]*)\}\{([^{}]*)\}/g, '$1/$2')
            .replace(/\\(cdot|times)\b/g, function (m, op) { return op === 'cdot' ? '·' : '×'; })
            .replace(/\\(Omega|rightarrow|to)\b/g, function (m, w) { return w === 'Omega' ? 'Ω' : '→'; })
            .replace(/\\(text|mathrm)\{([^{}]*)\}/g, '$2')
            .replace(/\$\$?\s*([^$\n]+?)\s*\$\$?/g, '$1');
    }

    function inline(text) {
        return text
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>');
    }

    /*
     * Paragraphs, lists and headings, plus the quiz shapes this assistant
     * writes all the time: "A. option" lines become answer chips in the
     * keypad colours and "Đáp án đúng: B" marks the right one.
     */
    function format(text) {
        var html = '';
        var list = null; // 'ul' | 'ol' | 'opts'
        var lines = escapeHtml(plainMath(text)).split('\n');

        function close() {
            if (list) {
                html += list === 'ul' ? '</ul>' : list === 'ol' ? '</ol>' : '</div>';
                list = null;
            }
        }

        function open(kind, tag) {
            if (list !== kind) {
                close();
                html += tag;
                list = kind;
            }
        }

        lines.forEach(function (raw) {
            var line = raw.trim();
            var match;

            if (line === '') {
                return;
            }
            if ((match = /^#{1,4}\s+(.*)$/.exec(line))) {
                close();
                html += '<h4>' + inline(match[1]) + '</h4>';
            } else if ((match = /^(?:\*\*)?([A-D])[.)]\s*(?:\*\*)?\s*(.+)$/.exec(line))) {
                open('opts', '<div class="ai-chat-options">');
                html += '<div class="ai-chat-option ai-chat-opt-' + match[1] + '"><b>' + match[1] + '</b><span>'
                    + inline(match[2].replace(/\*\*$/, '')) + '</span></div>';
            } else if ((match = /^[\s*_(→•-]*(?:đáp án(?: đúng)?|answer|correct(?: answer)?)\s*(?:là)?\s*[:：]?\s*[*_]*\s*([A-D])\b[*_)]*(.*)$/i.exec(line))) {
                close();
                html += '<p class="ai-chat-correct"><i class="ti ti-circle-check" aria-hidden="true"></i> Đáp án đúng: <b>'
                    + match[1].toUpperCase() + '</b>' + inline(match[2]) + '</p>';
            } else if ((match = /^[-*•]\s+(.*)$/.exec(line))) {
                open('ul', '<ul>');
                html += '<li>' + inline(match[1]) + '</li>';
            } else if ((match = /^(\d+)[.)]\s+(.*)$/.exec(line))) {
                close();
                html += '<p class="ai-chat-q"><span class="ai-chat-q-num">' + match[1] + '</span>' + inline(match[2]) + '</p>';
            } else {
                close();
                html += '<p>' + inline(line) + '</p>';
            }
        });
        close();

        return html;
    }

    /* ------------------------------------------------------------
     * Messages
     * ---------------------------------------------------------- */
    function avatar() {
        var mark = document.createElement('span');

        mark.className = 'ai-chat-mark ai-chat-mark-sm';
        mark.setAttribute('aria-hidden', 'true');
        mark.innerHTML = '<i class="ti ti-atom-2"></i>';
        return mark;
    }

    function addMessage(role, text) {
        var item = document.createElement('li');
        var content = document.createElement('div');

        item.className = 'ai-chat-msg ai-chat-msg-' + role;
        content.className = 'ai-chat-content';
        if (role === 'assistant') {
            item.appendChild(avatar());
            content.innerHTML = format(text);
            item.appendChild(content);
            item.appendChild(tools(item));
        } else {
            content.textContent = text;
            item.appendChild(content);
        }
        log.appendChild(item);
        return item;
    }

    function tools(item) {
        var bar = document.createElement('div');
        var copy = document.createElement('button');

        bar.className = 'ai-chat-tools';
        copy.type = 'button';
        copy.innerHTML = '<i class="ti ti-copy" aria-hidden="true"></i> Chép';
        copy.addEventListener('click', function () {
            var text = item.dataset.raw || item.querySelector('.ai-chat-content').innerText;
            var done = function () {
                copy.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i> Đã chép';
                setTimeout(function () { copy.innerHTML = '<i class="ti ti-copy" aria-hidden="true"></i> Chép'; }, 1600);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
                done();
            }
        });
        bar.appendChild(copy);
        return bar;
    }

    function render() {
        log.innerHTML = '';
        state.messages.forEach(function (message) {
            var item = addMessage(message.role, message.content);
            item.dataset.raw = message.content;
        });
        welcome.hidden = state.messages.length > 0;
        if (state.messages.length) {
            scrollDown(true);
        } else {
            body.scrollTop = 0; // the greeting reads from the top
        }
    }

    function nearBottom() {
        return body.scrollHeight - body.scrollTop - body.clientHeight < 60;
    }

    function scrollDown(force) {
        if (force || followBottom) {
            body.scrollTop = body.scrollHeight;
            jump.hidden = true;
        } else {
            jump.hidden = false;
        }
    }

    body.addEventListener('scroll', function () {
        followBottom = nearBottom();
        if (followBottom) {
            jump.hidden = true;
        }
    });
    jump.addEventListener('click', function () {
        followBottom = true;
        scrollDown(true);
    });

    /* ------------------------------------------------------------
     * Window
     * ---------------------------------------------------------- */
    function setOpen(open) {
        state.open = open;
        panel.hidden = !open;
        launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        launcher.classList.toggle('is-open', open);
        save();
        if (open) {
            if (state.messages.length) {
                scrollDown(true);
            }
            input.focus({ preventScroll: true });
        }
    }

    function setWide(on) {
        state.wide = on;
        panel.classList.toggle('is-wide', on);
        wide.setAttribute('aria-pressed', on ? 'true' : 'false');
        wide.title = on ? 'Thu nhỏ' : 'Phóng to';
        wide.querySelector('i').className = 'ti ' + (on ? 'ti-arrows-diagonal-minimize-2' : 'ti-arrows-diagonal');
        save();
    }

    function setBusy(busy) {
        send.classList.toggle('is-stop', busy);
        send.innerHTML = busy
            ? '<i class="ti ti-player-stop-filled" aria-hidden="true"></i>'
            : '<i class="ti ti-arrow-up" aria-hidden="true"></i>';
        send.setAttribute('aria-label', busy ? 'Dừng' : 'Gửi');
        send.title = busy ? 'Dừng' : 'Gửi (Enter)';
    }

    function grow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    }

    /* ------------------------------------------------------------
     * Asking
     * ---------------------------------------------------------- */
    function ask(text) {
        state.messages.push({ role: 'user', content: text });
        save();
        welcome.hidden = true;
        addMessage('user', text);
        followBottom = true;

        var answer = '';
        var item = addMessage('assistant', '');
        var content = item.querySelector('.ai-chat-content');
        var controller = new AbortController();
        var failed = null;
        var stopped = false;

        item.classList.add('is-writing');
        content.innerHTML = '<span class="ai-chat-dots" aria-label="Đang trả lời"><i></i><i></i><i></i></span>';
        running = controller;
        setBusy(true);
        scrollDown(true);

        fetch('api/ai_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: panel.dataset.csrf,
                page: document.title + ' (' + location.pathname.split('/').pop() + ')',
                messages: state.messages.slice(-20)
            }),
            signal: controller.signal
        }).then(function (response) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var pending = '';

            function pump() {
                return reader.read().then(function (chunk) {
                    if (chunk.done) {
                        return;
                    }
                    pending += decoder.decode(chunk.value, { stream: true });

                    var events = pending.split('\n\n');
                    pending = events.pop();
                    events.forEach(function (event) {
                        if (event.indexOf('data: ') !== 0) {
                            return;
                        }
                        var data = JSON.parse(event.slice(6));

                        if (data.text) {
                            answer += data.text;
                            content.innerHTML = format(answer);
                            scrollDown(false);
                        } else if (data.error) {
                            failed = data.error;
                        }
                    });
                    return pump();
                });
            }

            return pump();
        }).catch(function (error) {
            if (error.name === 'AbortError') {
                stopped = true;
            } else {
                failed = 'Không kết nối được máy chủ.';
            }
        }).then(function () {
            running = null;
            setBusy(false);
            item.classList.remove('is-writing');

            if (failed || !answer) {
                // Take the question back so it can be asked again.
                state.messages.pop();
                save();
                showError(item, failed || 'Đã dừng.', text);
            } else {
                if (stopped) {
                    answer += '\n\n(đã dừng)';
                }
                item.dataset.raw = answer;
                state.messages.push({ role: 'assistant', content: answer });
                save();
            }
            input.focus();
        });
    }

    function showError(item, message, question) {
        var content = item.querySelector('.ai-chat-content');
        var retry = document.createElement('button');

        item.classList.add('is-error');
        item.querySelector('.ai-chat-tools').remove();
        content.innerHTML = '<p><i class="ti ti-alert-circle" aria-hidden="true"></i> ' + escapeHtml(message) + '</p>';
        retry.type = 'button';
        retry.className = 'ai-chat-retry';
        retry.innerHTML = '<i class="ti ti-refresh" aria-hidden="true"></i> Thử lại';
        retry.addEventListener('click', function () {
            if (running) {
                return;
            }
            item.previousElementSibling && item.previousElementSibling.remove();
            item.remove();
            ask(question);
        });
        content.appendChild(retry);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (running) {
            running.abort();
            return;
        }
        var text = input.value.trim();

        if (text) {
            input.value = '';
            grow();
            ask(text);
        }
    });

    input.addEventListener('input', grow);
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    welcome.addEventListener('click', function (event) {
        var chip = event.target.closest('button[data-prompt]');

        if (chip && !running) {
            ask(chip.dataset.prompt);
        }
    });

    launcher.addEventListener('click', function () {
        setOpen(panel.hidden);
    });
    wide.addEventListener('click', function () {
        setWide(!state.wide);
    });
    document.getElementById('ai-chat-close').addEventListener('click', function () {
        setOpen(false);
        launcher.focus();
    });
    document.getElementById('ai-chat-clear').addEventListener('click', function () {
        if (running) {
            running.abort();
        }
        state.messages = [];
        save();
        render();
        input.focus();
    });
    panel.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setOpen(false);
            launcher.focus();
        }
    });

    render();
    setWide(!!state.wide);
    if (state.open) {
        setOpen(true);
    }
})();
