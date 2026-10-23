document.querySelectorAll('[data-recipient-picker]').forEach(picker => {
    const form = picker.closest('form');
    const profile = form.querySelector('[name="profile_id"]');
    const search = picker.querySelector('[data-recipient-search]');
    const rows = [...picker.querySelectorAll('[data-recipient-row]')];
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLocaleLowerCase('vi');
    const update = () => {
        const query = normalize(search.value.trim());
        rows.forEach(row => {
            const checkbox = row.querySelector('input');
            checkbox.disabled = row.dataset.profile !== profile.value;
            if (checkbox.disabled) checkbox.checked = false;
            const visible = !checkbox.disabled && normalize(row.textContent).includes(query);
            row.hidden = !visible;
            row.classList.toggle('d-flex', visible);
        });
        const count = rows.filter(row => row.querySelector('input').checked).length;
        picker.querySelector('[data-recipient-count]').textContent = `Đã chọn ${count}`;
        picker.querySelector('[data-recipient-empty]').hidden = rows.some(row => !row.hidden);
        search.setCustomValidity(count ? '' : 'Hãy chọn ít nhất một người nhận hoặc nhóm.');
    };
    search.addEventListener('input', update);
    profile.addEventListener('change', update);
    picker.addEventListener('change', update);
    picker.querySelector('[data-recipient-select]').addEventListener('click', () => {
        rows.filter(row => !row.hidden).forEach(row => { row.querySelector('input').checked = true; });
        update();
    });
    picker.querySelector('[data-recipient-clear]').addEventListener('click', () => {
        rows.forEach(row => { row.querySelector('input').checked = false; });
        update();
    });
    update();
});

const inbox = document.querySelector('[data-inbox-live]');
if (inbox) {
    let stopped = false;
    const initialScroll = inbox.querySelector('[data-chat-scroll]');
    if (initialScroll && inbox.dataset.chatOlder !== 'true') initialScroll.scrollTop = initialScroll.scrollHeight;
    const refreshInbox = async () => {
        if (stopped) return;
        try {
            if (!document.hidden) {
                const response = await fetch(inbox.dataset.inboxLive, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
                if ([401, 403].includes(response.status) || response.redirected) {
                    stopped = true;
                    inbox.querySelector('[data-inbox-status]').textContent = 'Phiên đăng nhập đã hết. Tải lại trang để tiếp tục.';
                    return;
                }
                if (!response.ok) throw new Error('refresh');
                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                const next = doc.querySelector('[data-inbox-panels]');
                if (!next) throw new Error('content');
                if (!inbox.querySelector('[data-chat-scroll]') && next.querySelector('[data-chat-scroll]')) {
                    window.location.reload();
                    return;
                }
                // Never replace the reply form or interrupt a draft while messages refresh.
                if (!inbox.contains(document.activeElement)) {
                    const scroll = inbox.querySelector('[data-chat-scroll]');
                    const atBottom = scroll && scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 80;
                    const position = scroll?.scrollTop || 0;
                    const side = inbox.querySelector('.list-group');
                    const sidePosition = side?.scrollTop || 0;
                    inbox.querySelector('[data-inbox-panels]').replaceWith(next);
                    const updated = next.querySelector('[data-chat-scroll]');
                    if (updated) updated.scrollTop = atBottom && inbox.dataset.chatOlder !== 'true' ? updated.scrollHeight : position;
                    const updatedSide = next.querySelector('.list-group');
                    if (updatedSide) updatedSide.scrollTop = sidePosition;
                }
                inbox.querySelector('[data-inbox-status]').textContent = 'Đã cập nhật lúc ' + new Date().toLocaleTimeString('vi-VN');
            }
        } catch {
            inbox.querySelector('[data-inbox-status]').textContent = 'Chưa cập nhật được tin nhắn. Đang thử kết nối lại…';
        }
        if (!stopped) window.setTimeout(refreshInbox, 5000);
    };
    window.setTimeout(refreshInbox, 5000);
    window.addEventListener('pagehide', () => { stopped = true; });
}

const poll = document.querySelector('[data-platform-poll]');
if (poll) {
    let stopped = false;
    const refresh = async () => {
        if (stopped) return;
        try {
            if (!document.hidden) {
                const response = await fetch(poll.dataset.platformPoll, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
                if ([401, 403].includes(response.status)) { stopped = true; return; }
                if (response.ok) {
                    const { data } = await response.json();
                    data.forEach(profile => {
                        const card = [...poll.querySelectorAll('[data-live-profile]')].find(el => el.dataset.liveProfile === profile.id);
                        if (!card) return;
                        card.querySelector('[data-profile-status]').textContent = profile.status;
                        card.querySelectorAll('input[name="expected_version"]').forEach(input => { input.value = String(profile.version); });
                        const image = card.querySelector('[data-profile-qr]');
                        const hint = card.querySelector('[data-profile-qr-hint]');
                        const valid = profile.qr && Date.parse(profile.qr.expires_at) > Date.now();
                        image.hidden = !valid;
                        if (valid) {
                            image.src = `data:image/png;base64,${profile.qr.image_base64}`;
                            hint.textContent = `Quét QR trước ${new Date(profile.qr.expires_at).toLocaleTimeString('vi-VN')}`;
                        } else {
                            image.removeAttribute('src');
                            hint.textContent = profile.hint || 'Bấm Lấy mã QR để kết nối tài khoản.';
                        }
                    });
                }
            }
        } catch { /* Keep current data visible while the connection recovers. */ }
        if (!stopped) window.setTimeout(refresh, 5000);
    };
    window.setTimeout(refresh, 1500);
    window.addEventListener('pagehide', () => { stopped = true; });
}
