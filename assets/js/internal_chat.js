(function(){
  const apiUrl = 'api/internal_chat.php';
  const pollMs = 12000;
  const state = {
    open: false,
    conversations: [],
    users: [],
    activeConversationId: null,
    activeUser: null,
    lastMessageId: 0,
    loadingMessages: false
  };

  function escapeHtml(value){
    return String(value || '').replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
  }

  function displayName(user){
    if (!user) return 'Usuario';
    if (String(user.type || '') === 'global') return 'Sala geral';
    return user.other_nome_completo || user.nome_completo || user.other_username || user.username || 'Usuario';
  }

  function initials(name){
    return String(name || 'U').trim().split(/\s+/).slice(0,2).map(part => part.charAt(0).toUpperCase()).join('') || 'U';
  }

  function avatarHtml(src, name){
    const cleanSrc = String(src || '').trim();
    if (cleanSrc) return `<img class="internal-chat-avatar" src="${escapeHtml(cleanSrc)}" alt="">`;
    return `<span class="internal-chat-avatar">${escapeHtml(initials(name))}</span>`;
  }

  function formatTime(value){
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
  }

  function request(action, options){
    const opts = options || {};
    const method = opts.method || 'GET';
    const body = opts.body || null;
    const url = method === 'GET' ? `${apiUrl}?action=${encodeURIComponent(action)}${opts.query || ''}` : `${apiUrl}?action=${encodeURIComponent(action)}`;
    return fetch(url, {
      method,
      headers: body ? {'Content-Type':'application/x-www-form-urlencoded'} : undefined,
      body
    }).then(res => res.json());
  }

  function buildWidget(){
    if (document.getElementById('internalChat')) return;
    const root = document.createElement('div');
    root.id = 'internalChat';
    root.className = 'internal-chat';
    root.innerHTML = `
      <button class="internal-chat-toggle" type="button" title="Chat interno" aria-label="Abrir chat interno">
        <i class="fa-regular fa-comments"></i>
        <span class="internal-chat-badge">0</span>
      </button>
      <section class="internal-chat-panel" aria-label="Chat interno">
        <header class="internal-chat-header">
          <div class="internal-chat-title">
            <strong>Chat interno</strong>
            <span id="internalChatSubtitle">Converse com a equipe</span>
          </div>
          <button class="internal-chat-icon-btn" type="button" data-chat-refresh title="Atualizar" aria-label="Atualizar"><i class="fa-solid fa-rotate"></i></button>
          <button class="internal-chat-icon-btn" type="button" data-chat-close title="Fechar" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="internal-chat-search">
          <input id="internalChatSearch" type="search" placeholder="Buscar usuario" autocomplete="off">
        </div>
        <div class="internal-chat-body">
          <aside class="internal-chat-list">
            <div id="internalChatPeople" class="internal-chat-people"></div>
          </aside>
          <main class="internal-chat-thread">
            <div id="internalChatMessages" class="internal-chat-messages">
              <div class="internal-chat-empty">Selecione alguem para iniciar.</div>
            </div>
            <form id="internalChatForm" class="internal-chat-form">
              <textarea id="internalChatInput" rows="1" placeholder="Mensagem" disabled></textarea>
              <button type="submit" title="Enviar" aria-label="Enviar" disabled><i class="fa-solid fa-paper-plane"></i></button>
            </form>
          </main>
        </div>
      </section>
    `;
    document.body.appendChild(root);

    root.querySelector('.internal-chat-toggle').addEventListener('click', () => setOpen(!state.open));
    root.querySelector('[data-chat-close]').addEventListener('click', () => setOpen(false));
    root.querySelector('[data-chat-refresh]').addEventListener('click', () => refreshAll());
    root.querySelector('#internalChatSearch').addEventListener('input', debounce(searchUsers, 220));
    root.querySelector('#internalChatForm').addEventListener('submit', sendActiveMessage);
    root.querySelector('#internalChatInput').addEventListener('keydown', function(event){
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendActiveMessage(event);
      }
    });
  }

  function setOpen(open){
    state.open = open;
    const root = document.getElementById('internalChat');
    if (!root) return;
    root.classList.toggle('open', open);
    if (open) refreshAll();
  }

  function debounce(fn, ms){
    let timer = null;
    return function(){
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, arguments), ms);
    };
  }

  function renderPeople(){
    const list = document.getElementById('internalChatPeople');
    if (!list) return;
    const search = document.getElementById('internalChatSearch');
    const searching = search && search.value.trim() !== '';
    const rows = searching ? state.users : state.conversations;
    const globalRow = !searching ? state.conversations.find(row => String(row.type || '') === 'global') : null;
    const privateRows = !searching ? state.conversations.filter(row => String(row.type || '') !== 'global') : rows;

    if (!searching && !rows.length) {
      list.innerHTML = `<div class="internal-chat-empty">${searching ? 'Nenhum usuario encontrado.' : 'Nenhuma conversa ainda.'}</div>`;
      return;
    }
    if (searching && !rows.length) {
      list.innerHTML = '<div class="internal-chat-empty">Nenhum usuario encontrado.</div>';
      return;
    }

    const globalHtml = globalRow ? `
      <button type="button" class="internal-chat-person internal-chat-person-global${Number(globalRow.id) === Number(state.activeConversationId) ? ' active' : ''}" data-chat-target="conv-${escapeHtml(globalRow.id)}">
        <span class="internal-chat-avatar internal-chat-avatar-global"><i class="fa-solid fa-bullhorn"></i></span>
        <span class="internal-chat-person-main">
          <strong>Sala geral</strong>
          <span>Mensagens para todos</span>
        </span>
        ${Number(globalRow.unread_count || 0) > 0 ? `<span class="internal-chat-unread-dot">${Number(globalRow.unread_count)}</span>` : ''}
      </button>
    ` : '';

    const privateHtml = (searching ? rows : privateRows).map(row => {
      const name = displayName(row);
      const subtitle = searching ? (row.email || row.username || '') : (String(row.type || '') === 'global' ? 'Mensagens para todos' : (row.last_message || 'Conversa iniciada'));
      const id = searching ? `user-${row.id}` : `conv-${row.id}`;
      const active = (!searching && Number(row.id) === Number(state.activeConversationId)) ? ' active' : '';
      const badge = !searching && Number(row.unread_count || 0) > 0 ? `<span class="internal-chat-unread-dot">${Number(row.unread_count)}</span>` : '';
      const avatar = searching ? avatarHtml(row.avatar, name) : avatarHtml(row.other_avatar, name);
      return `
        <button type="button" class="internal-chat-person${active}" data-chat-target="${escapeHtml(id)}">
          ${avatar}
          <span class="internal-chat-person-main">
            <strong>${escapeHtml(name)}</strong>
            <span>${escapeHtml(subtitle)}</span>
          </span>
          ${badge}
        </button>
      `;
    }).join('');

    list.innerHTML = searching ? privateHtml : `${globalHtml}${privateHtml}`;

    list.querySelectorAll('[data-chat-target]').forEach(btn => {
      btn.addEventListener('click', () => {
        const target = btn.getAttribute('data-chat-target') || '';
        if (target.startsWith('user-')) startConversation(Number(target.slice(5)));
        if (target.startsWith('conv-')) openConversation(Number(target.slice(5)));
      });
    });
  }

  function renderUnread(total){
    const root = document.getElementById('internalChat');
    if (!root) return;
    const badge = root.querySelector('.internal-chat-badge');
    const count = Number(total || 0);
    root.classList.toggle('has-unread', count > 0);
    if (badge) badge.textContent = count > 99 ? '99+' : String(count);
  }

  function loadSummary(){
    return request('summary').then(json => {
      if (!json || !json.success) return;
      state.conversations = json.conversations || [];
      renderUnread(json.unread_total || 0);
      renderPeople();
      if (!state.activeConversationId) {
        const globalConv = state.conversations.find(row => String(row.type || '') === 'global');
        if (globalConv) openConversation(Number(globalConv.id));
      }
    }).catch(() => {});
  }

  function searchUsers(){
    const input = document.getElementById('internalChatSearch');
    const q = input ? input.value.trim() : '';
    if (!q) {
      state.users = [];
      renderPeople();
      return;
    }
    request('users', {query: '&q=' + encodeURIComponent(q)}).then(json => {
      state.users = json && json.success ? (json.users || []) : [];
      renderPeople();
    }).catch(() => {});
  }

  function startConversation(userId){
    const body = new URLSearchParams();
    body.set('user_id', String(userId));
    request('start', {method:'POST', body: body.toString()}).then(json => {
      if (!json || !json.success) return;
      const search = document.getElementById('internalChatSearch');
      if (search) search.value = '';
      state.users = [];
      return loadSummary().then(() => openConversation(Number(json.conversation_id)));
    }).catch(() => {});
  }

  function openConversation(conversationId){
    state.activeConversationId = conversationId;
    state.lastMessageId = 0;
    const conv = state.conversations.find(row => Number(row.id) === Number(conversationId));
    state.activeUser = conv || null;
    const subtitle = document.getElementById('internalChatSubtitle');
    if (subtitle) subtitle.textContent = conv ? displayName(conv) : 'Conversa';
    setComposerEnabled(true);
    renderPeople();
    loadMessages(false);
  }

  function setComposerEnabled(enabled){
    const input = document.getElementById('internalChatInput');
    const btn = document.querySelector('#internalChatForm button');
    if (input) input.disabled = !enabled;
    if (btn) btn.disabled = !enabled;
  }

  function loadMessages(append){
    if (!state.activeConversationId || state.loadingMessages) return Promise.resolve();
    state.loadingMessages = true;
    const query = '&conversation_id=' + encodeURIComponent(state.activeConversationId) + (append && state.lastMessageId ? '&after_id=' + encodeURIComponent(state.lastMessageId) : '');
    return request('messages', {query}).then(json => {
      if (!json || !json.success) return;
      renderMessages(json.messages || [], append);
      loadSummary();
    }).finally(() => {
      state.loadingMessages = false;
    }).catch(() => {});
  }

  function renderMessages(messages, append){
    const box = document.getElementById('internalChatMessages');
    if (!box) return;
    if (!append) box.innerHTML = '';
    if (!messages.length && !append) {
      box.innerHTML = '<div class="internal-chat-empty">Envie a primeira mensagem.</div>';
      return;
    }
    if (box.querySelector('.internal-chat-empty')) box.innerHTML = '';
    messages.forEach(message => {
      state.lastMessageId = Math.max(state.lastMessageId, Number(message.id));
      const mine = Number(message.sender_id) === Number(window.currentUserId || 0);
      const senderName = String(message.nome_completo || '').trim() || String(message.username || '').trim();
      const isGlobal = state.activeUser && String(state.activeUser.type || '') === 'global';
      const item = document.createElement('div');
      item.className = 'internal-chat-message' + (mine ? ' mine' : '');
      item.innerHTML = `
        ${senderName && (isGlobal || !mine) ? `<div class="internal-chat-sender">${escapeHtml(mine && isGlobal ? 'Voce' : senderName)}</div>` : ''}
        <div class="internal-chat-bubble">${escapeHtml(message.body)}</div>
        <div class="internal-chat-time">${escapeHtml(formatTime(message.created_at))}</div>
      `;
      box.appendChild(item);
    });
    box.scrollTop = box.scrollHeight;
  }

  function sendActiveMessage(event){
    if (event) event.preventDefault();
    if (!state.activeConversationId) return;
    const input = document.getElementById('internalChatInput');
    const bodyText = input ? input.value.trim() : '';
    if (!bodyText) return;
    const body = new URLSearchParams();
    body.set('conversation_id', String(state.activeConversationId));
    body.set('body', bodyText);
    if (input) input.value = '';
    request('send', {method:'POST', body: body.toString()}).then(json => {
      if (!json || !json.success) return;
      loadMessages(false);
    }).catch(() => {
      if (input) input.value = bodyText;
    });
  }

  function refreshAll(){
    loadSummary().then(() => {
      const search = document.getElementById('internalChatSearch');
      if (search && search.value.trim()) searchUsers();
      if (state.activeConversationId) loadMessages(true);
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    if (!window.currentUserId) return;
    buildWidget();
    loadSummary();
    setInterval(refreshAll, pollMs);
  });
})();
