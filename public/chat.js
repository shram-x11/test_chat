const token = document.querySelector('meta[name="chat-token"]').content;
const tab = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte =>
    byte.toString(16).padStart(2, '0')
).join('');
const timeFormat = new Intl.DateTimeFormat('ru-RU', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
});

async function readResponse(response) {
    const data = await response.json();
    if (!response.ok) {
        throw Object.assign(new Error(data.error), { status: response.status });
    }
    return data;
}

function post(action, data = {}, options = {}) {
    return fetch(`api.php?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, tab, ...data }),
        ...options
    }).then(readResponse);
}

function poll(after) {
    const query = new URLSearchParams({ action: 'poll', tab, after });
    return fetch(`api.php?${query}`, {
        headers: { 'X-Chat-Token': token },
        signal: AbortSignal.timeout(10000)
    }).then(readResponse);
}

new Vue({
    ...window.chatTemplate,
    el: '#app',
    data: {
        name: '',
        draft: '',
        error: '',
        connectionError: '',
        joined: false,
        joining: false,
        leaving: false,
        sending: false,
        connected: false,
        cursor: '',
        messages: [],
        users: []
    },
    mounted() {
        window.addEventListener('pagehide', this.leave);
        window.addEventListener('pageshow', event => {
            if (event.persisted && this.leaving) {
                window.location.reload();
            }
        });
    },
    methods: {
        async join() {
            if (this.joining) {
                return;
            }
            this.joining = true;
            this.error = '';
            try {
                await post('join', { name: this.name }, { signal: AbortSignal.timeout(10000) });
                this.joined = true;
                this.$nextTick(() => document.getElementById('message').focus());
                this.receive();
            } catch (error) {
                this.error = error.status ? error.message : 'Не удалось подключиться к чату.';
            } finally {
                this.joining = false;
            }
        },
        async receive() {
            while (!this.leaving) {
                try {
                    const { messages, users } = await poll(this.cursor);
                    this.users = users;
                    this.append(messages);
                    this.connected = true;
                    this.connectionError = '';
                } catch (error) {
                    this.connected = false;
                    if (error.status === 403) {
                        this.connectionError = error.message;
                        return;
                    }
                    this.connectionError = 'Связь прервалась. Восстанавливаем подключение…';
                    if (error.status === 410 && !this.leaving) {
                        try {
                            await post('join', { name: this.name }, { signal: AbortSignal.timeout(10000) });
                            continue;
                        } catch (joinError) {
                            if (joinError.status === 403) {
                                this.connectionError = joinError.message;
                                return;
                            }
                        }
                    }
                    await new Promise(resolve => setTimeout(resolve, 1000));
                }
            }
        },
        append(messages) {
            if (!messages.length) {
                return;
            }
            const log = this.$refs.log;
            const atBottom = log.scrollHeight - log.clientHeight - log.scrollTop < 32;
            this.messages.push(...messages.map(message => {
                const date = new Date(Number(message.id.split('-')[0]));
                return { ...message, time: timeFormat.format(date), datetime: date.toISOString() };
            }));
            this.cursor = messages[messages.length - 1].id;
            if (atBottom) {
                this.$nextTick(() => { log.scrollTop = log.scrollHeight; });
            }
        },
        async send() {
            if (this.sending || !this.connected || !this.draft.trim()) {
                return;
            }
            const text = this.draft;
            this.error = '';
            this.sending = true;
            try {
                await post('send', { text }, { signal: AbortSignal.timeout(10000) });
                if (this.draft === text) {
                    this.draft = '';
                }
            } catch (error) {
                this.error = error.status ? error.message : 'Не удалось подтвердить отправку сообщения.';
            } finally {
                this.sending = false;
            }
        },
        leave() {
            if (!this.joined && !this.joining) {
                return;
            }
            this.leaving = true;
            const body = new Blob([JSON.stringify({ token, tab })], { type: 'application/json' });
            if (!navigator.sendBeacon('api.php?action=leave', body)) {
                post('leave', {}, { keepalive: true }).catch(() => {});
            }
        }
    }
});
