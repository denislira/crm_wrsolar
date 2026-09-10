(function () {
  function el(tag, attrs, text) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([key, value]) => {
      if (key === 'class') node.className = value;
      else node.setAttribute(key, value);
    });
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function addMessage(list, role, text) {
    const home = document.getElementById('aiAssistantHome');
    if (home) home.classList.add('chat-active');
    list.hidden = false;
    const msg = el('div', { class: 'ai-assistant-message ' + role });
    const content = el('div', { class: 'ai-message-content' });
    if (role === 'assistant') {
      content.innerHTML = renderAssistantMessage(String(text || ''));
    } else {
      content.appendChild(el('span', { class: 'ai-message-text' }, String(text || '')));
    }
    msg.appendChild(content);
    list.appendChild(msg);
    if (home) home.scrollTop = home.scrollHeight;
    return msg;
  }

  function updateAssistantMessage(msg, text) {
    const content = msg.querySelector('.ai-message-content');
    if (content) {
      content.innerHTML = renderAssistantMessage(String(text || ''));
    }
  }

  function renderAssistantMessage(text) {
    const normalized = String(text || '').trim();
    if (!normalized) return '<div class="ai-msg-empty">Sem resposta.</div>';

    const lines = normalized.split(/\r?\n/).map(line => line.trim());
    const blocks = [];
    let listItems = [];

    function flushList() {
      if (!listItems.length) return;
      blocks.push('<ul class="ai-msg-list">' + listItems.map(item => '<li>' + escapeHtml(item) + '</li>').join('') + '</ul>');
      listItems = [];
    }

    for (const line of lines) {
      if (!line) {
        flushList();
        continue;
      }

      const headingMatch = line.match(/^#{1,3}\s*(.+)$/);
      if (headingMatch) {
        flushList();
        blocks.push('<div class="ai-msg-heading">' + escapeHtml(headingMatch[1]) + '</div>');
        continue;
      }

      const bulletMatch = line.match(/^[-*•]\s+(.+)$/);
      if (bulletMatch) {
        listItems.push(bulletMatch[1]);
        continue;
      }

      const numberedMatch = line.match(/^\d+[\).\:-]\s+(.+)$/);
      if (numberedMatch) {
        listItems.push(numberedMatch[1]);
        continue;
      }

      const emphasisMatch = line.match(/^(Resumo|Conclusão|Conclusao|Sugestão|Sugestao|Alerta|Prioridade|Atenção|Atencao)\s*:\s*(.+)$/i);
      if (emphasisMatch) {
        flushList();
        blocks.push(
          '<div class="ai-msg-callout">' +
            '<span class="ai-msg-label">' + escapeHtml(emphasisMatch[1]) + '</span>' +
            '<span class="ai-msg-value">' + escapeHtml(emphasisMatch[2]) + '</span>' +
          '</div>'
        );
        continue;
      }

      flushList();
      blocks.push('<p>' + escapeHtml(line) + '</p>');
    }

    flushList();
    return (
      '<section class="ai-assistant-insights ai-response-card">' +
        '<h3><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Resposta da IA</h3>' +
        '<div class="ai-response-content">' + blocks.join('') + '</div>' +
      '</section>'
    );
  }

  function buildAssistantHome(onPrompt) {
    const home = el('div', { id: 'aiAssistantHome', class: 'ai-assistant-home' });
    const intro = el('section', { class: 'ai-assistant-welcome' });
    intro.appendChild(el('h2', {}, 'Olá!'));
    intro.appendChild(el('p', {}, 'Sou sua IA e estou aqui para te ajudar a vender mais e com mais inteligência.'));
    home.appendChild(intro);

    const quickPrompts = [
      'Quais leads têm maior chance de conversão?',
      'Mostre os leads sem contato >24h',
      'Resumo do desempenho da equipe',
      'Sugira ações para melhorar meus resultados'
    ];
    const quickList = el('div', { class: 'ai-assistant-quick-list', 'aria-label': 'Perguntas rapidas' });
    renderQuickPrompts(quickList, quickPrompts, onPrompt);
    home.appendChild(quickList);

    const insightSection = el('section', { class: 'ai-assistant-insights' });
    insightSection.appendChild(el('h3', {}, 'Insights para você'));
    const insightList = el('div', { class: 'ai-assistant-insights-list' });
    insightSection.appendChild(insightList);
    const insights = [
      { icon: 'fa-user-clock', tone: 'danger', title: 'Leads estão sem contato há mais de 24h.', text: 'Atender agora pode aumentar a conversão em até 30%.', action: 'Mostre os leads sem contato >24h', label: 'Ver lista de leads' },
      { icon: 'fa-hourglass-half', tone: 'warning', title: 'Você tem leads parados há mais de 7 dias.', text: 'Que tal criar uma ação para reengajá-los?', action: 'Quais leads estão parados há mais de 7 dias?', label: 'Ver leads parados' },
      { icon: 'fa-circle-check', tone: 'success', title: 'Seu speed-to-lead está acima do ideal.', text: 'O ideal é responder em até 5 minutos.', action: 'Qual é o speed-to-lead atual?', label: 'Ver dicas' }
    ];
    insights.forEach(insight => {
      const row = el('div', { class: 'ai-assistant-insight' });
      const icon = el('span', { class: 'ai-assistant-insight-icon ' + insight.tone });
      icon.innerHTML = '<i class="fa-solid ' + insight.icon + '" aria-hidden="true"></i>';
      const content = el('div', { class: 'ai-assistant-insight-content' });
      content.appendChild(el('strong', {}, insight.title));
      content.appendChild(el('p', {}, insight.text));
      const action = el('button', { class: 'ai-assistant-insight-action', type: 'button' }, insight.label);
      action.addEventListener('click', () => onPrompt(insight.action));
      content.appendChild(action);
      row.appendChild(icon);
      row.appendChild(content);
      insightList.appendChild(row);
    });
    home.appendChild(insightSection);
    loadAssistantHomeInsights(quickList, insightList, onPrompt);
    return home;
  }

  function renderQuickPrompts(container, prompts, onPrompt) {
    container.innerHTML = '';
    prompts.forEach(prompt => {
      const button = el('button', { class: 'ai-assistant-quick-prompt', type: 'button' });
      button.appendChild(el('span', {}, prompt));
      button.innerHTML += '<i class="fa-solid fa-arrow-right" aria-hidden="true"></i>';
      button.addEventListener('click', () => onPrompt(prompt));
      container.appendChild(button);
    });
  }

  function renderHomeInsights(container, insights, onPrompt) {
    container.innerHTML = '';
    insights.forEach(insight => {
      const row = el('div', { class: 'ai-assistant-insight' });
      const icon = el('span', { class: 'ai-assistant-insight-icon ' + (insight.tone || 'success') });
      icon.innerHTML = '<i class="fa-solid ' + (insight.icon || 'fa-lightbulb') + '" aria-hidden="true"></i>';
      const content = el('div', { class: 'ai-assistant-insight-content' });
      content.appendChild(el('strong', {}, insight.title || 'Insight do CRM'));
      content.appendChild(el('p', {}, insight.text || 'Abra a IA para analisar os dados comerciais.'));
      if (insight.action && insight.label) {
        const action = el('button', { class: 'ai-assistant-insight-action', type: 'button' }, insight.label);
        action.addEventListener('click', () => onPrompt(insight.action));
        content.appendChild(action);
      }
      row.appendChild(icon);
      row.appendChild(content);
      container.appendChild(row);
    });
  }

  async function loadAssistantHomeInsights(quickList, insightList, onPrompt) {
    try {
      const res = await fetch('api/ai_home_insights.php');
      const data = await res.json();
      if (!data || !data.success) return;
      if (Array.isArray(data.quick_prompts) && data.quick_prompts.length) {
        renderQuickPrompts(quickList, data.quick_prompts, onPrompt);
      }
      if (Array.isArray(data.insights) && data.insights.length) {
        renderHomeInsights(insightList, data.insights, onPrompt);
      }
    } catch (e) {}
  }

  function buildAssistant() {
    if (document.getElementById('aiAssistantPanel')) return;

    const defaultLauncherPos = {
      right: Number(localStorage.getItem('aiAssistant.launcherRight') || 4),
      bottom: Number(localStorage.getItem('aiAssistant.launcherBottom') || 88)
    };

    let dragEnabled = false;
    window.__setAiAssistantDragEnabled = function(enabled) {
      setDragEnabled(enabled);
    };


    const launcher = el('button', {
      id: 'aiAssistantLauncher',
      class: 'ai-assistant-launcher',
      type: 'button',
      title: 'IA do CRM',
      'aria-label': 'Abrir IA do CRM'
    });
    launcher.innerHTML = '<img class="ai-assistant-robot-icon" src="assets/img/robot1.png" alt="">';
    const dragBadge = el('span', { class: 'ai-assistant-drag-badge', 'aria-hidden': 'true' }, '+');
    launcher.appendChild(dragBadge);
    const panel = el('section', {
      id: 'aiAssistantPanel',
      class: 'ai-assistant-panel',
      'aria-label': 'IA do CRM'
    });

    const header = el('div', { class: 'ai-assistant-header' });
    const titleWrap = el('div', {});
    const title = el('div', { class: 'ai-assistant-title' });
    title.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><span>IA Assistente</span><small>BETA</small>';
    const status = el('div', { id: 'aiAssistantStatus', class: 'ai-assistant-status' }, '');
    titleWrap.appendChild(title);
    titleWrap.appendChild(status);

    const actions = el('div', { class: 'ai-assistant-actions' });


    const clearBtn = el('button', { class: 'ai-assistant-icon-btn', type: 'button', title: 'Limpar histórico', 'aria-label': 'Limpar histórico' });
    clearBtn.innerHTML = '<i class="fa-solid fa-eraser"></i>';
    const closeBtn = el('button', { class: 'ai-assistant-icon-btn', type: 'button', title: 'Fechar', 'aria-label': 'Fechar' });
    closeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';


    actions.appendChild(clearBtn);
    actions.appendChild(closeBtn);
    header.appendChild(titleWrap);
    header.appendChild(actions);

    const messages = el('div', { id: 'aiAssistantMessages', class: 'ai-assistant-messages' });
    const contextBox = el('div', { id: 'aiAssistantContext', class: 'ai-assistant-context', hidden: 'hidden' });
    const suggestions = el('details', { class: 'ai-assistant-suggestions' });
    const suggestionsSummary = el('summary', { class: 'ai-assistant-suggestions-summary' }, 'Perguntas prontas');
    const suggestionsSelect = el('select', { class: 'ai-assistant-suggestions-select', 'aria-label': 'Perguntas prontas' });
    suggestionsSelect.appendChild(el('option', { value: '' }, 'Escolha uma pergunta...'));
    [
      'Quantos leads entraram hoje?',
      'Gargalos do funil nos últimos 30 dias',
      'Fontes com mais leads este mês',
      'Quais consultores tiveram mais atividades?',
      'Quais leads estão parados há mais de 7 dias?',
      'Quantos projetos entraram este mês?',
      'Resumo de pós-venda da semana'
    ].forEach(text => {
      suggestionsSelect.appendChild(el('option', { value: text }, text));
    });
    suggestionsSelect.addEventListener('change', () => {
      if (!suggestionsSelect.value) return;
      input.value = suggestionsSelect.value;
      suggestionsSelect.value = '';
      suggestions.open = false;
      form.requestSubmit();
    });
    suggestions.appendChild(suggestionsSummary);
    suggestions.appendChild(suggestionsSelect);

    const form = el('form', { id: 'aiAssistantForm', class: 'ai-assistant-form' });
    const input = el('textarea', {
      id: 'aiAssistantInput',
      class: 'ai-assistant-input',
      rows: '1',
      placeholder: 'Pergunte sobre leads, funil, atividades, projetos...'
    });
    const send = el('button', { class: 'ai-assistant-send', type: 'submit', title: 'Enviar', 'aria-label': 'Enviar' });
    send.innerHTML = '<i class="fa-solid fa-paper-plane"></i>';
    form.appendChild(input);
    form.appendChild(send);

    const home = buildAssistantHome(text => {
      input.value = text;
      form.requestSubmit();
    });
    home.appendChild(messages);

    panel.appendChild(header);
    panel.appendChild(home);
    panel.appendChild(contextBox);
    panel.appendChild(suggestions);
    panel.appendChild(form);
    document.body.appendChild(panel);
    document.body.appendChild(launcher);

    function setPanelOpen(open) {
      panel.classList.toggle('open', !!open);
      document.body.classList.toggle('ai-assistant-open', !!open);
      setAssistantSpeaking(!!open);
      if (open) {
        document.querySelectorAll('.ai-assistant-nudge.show, .ai-assistant-proactive.show').forEach(node => {
          node.classList.remove('show');
        });
        panel.dispatchEvent(new CustomEvent('ai-assistant:opened'));
        input.focus();
        loadHistory(messages);
      }
    }

    const avatarState = {
      mode: 'chatbot3',
      speechTimer: null
    };

    function applyLauncherAvatar(mode, speaking) {
      const normalized = ['none', 'head', 'body', 'chatbot1', 'chatbot3'].includes(mode) ? mode : (mode === 'speech' ? 'body' : 'chatbot3');
      const previousMode = avatarState.mode;
      avatarState.mode = normalized;
      window.__aiAssistantBotMode = normalized;
      launcher.hidden = normalized === 'none';
      launcher.classList.toggle('bot-mode-body', normalized === 'body');
      if (normalized === 'none') {
        panel.classList.remove('open');
        document.body.classList.remove('ai-assistant-open');
        return;
      }
      let src = normalized === 'chatbot3' ? 'assets/img/chatbot3.png' : (normalized === 'chatbot1' ? 'assets/img/chatbot1.png' : 'assets/img/robot1.png');
      if (normalized === 'body') {
        src = speaking ? 'assets/img/chatbot2.png' : 'assets/img/chatbot.png';
      }
      const launcherImg = launcher.querySelector('.ai-assistant-robot-icon');
      const titleImg = title.querySelector('.ai-assistant-title-icon');
      if (launcherImg) launcherImg.src = src;
      if (titleImg) titleImg.src = src;
      if (normalized === 'body' && previousMode !== 'body' && !localStorage.getItem('aiAssistant.launcherBottom')) {
        syncLauncherPosition(defaultLauncherPos.right, 130);
      }
    }

    window.__setAiAssistantAvatarMode = function(mode) {
      applyLauncherAvatar(mode, false);
    };

    function setAssistantSpeaking(isSpeaking) {
      window.clearTimeout(avatarState.speechTimer);
      if (avatarState.mode === 'body') {
        applyLauncherAvatar('body', !!isSpeaking);
        if (!isSpeaking && !panel.classList.contains('open')) {
          avatarState.speechTimer = window.setTimeout(() => applyLauncherAvatar('body', false), 150);
        }
        return;
      }
      applyLauncherAvatar(avatarState.mode, false);
    }

    window.__setAiAssistantSpeaking = function(isSpeaking) {
      setAssistantSpeaking(!!isSpeaking);
    };

    let dragState = {
      active: false,
      armed: false,
      pointerId: null,
      offsetX: 0,
      offsetY: 0,
      moved: false,
      suppressClick: false
    };

    function clamp(value, min, max) {
      return Math.min(max, Math.max(min, value));
    }

    function syncLauncherPosition(rightPx, bottomPx) {
      const iconW = launcher.offsetWidth || 64;
      const iconH = launcher.offsetHeight || 64;
      const minRight = 4;
      const minBottom = 24;
      const maxRight = Math.max(minRight, window.innerWidth - iconW - 4);
      const maxBottom = Math.max(minBottom, window.innerHeight - iconH - 4);
      const right = clamp(rightPx, minRight, maxRight);
      const bottom = clamp(bottomPx, minBottom, maxBottom);
      launcher.style.right = right + 'px';
      launcher.style.bottom = bottom + 'px';
      panel.style.right = right + 'px';
      panel.style.bottom = (bottom + iconH + 26) + 'px';
      document.documentElement.style.setProperty('--ai-assistant-launcher-right', right + 'px');
      document.documentElement.style.setProperty('--ai-assistant-launcher-bottom', bottom + 'px');
      document.documentElement.style.setProperty('--ai-assistant-panel-right', right + 'px');
      document.documentElement.style.setProperty('--ai-assistant-panel-bottom', (bottom + iconH + 26) + 'px');
      document.documentElement.style.setProperty('--ai-assistant-nudge-right', (right + iconW + 8) + 'px');
      document.documentElement.style.setProperty('--ai-assistant-nudge-bottom', (bottom + 8) + 'px');
      document.documentElement.style.setProperty('--ai-assistant-proactive-right', (right + iconW + 4) + 'px');
      document.documentElement.style.setProperty('--ai-assistant-proactive-bottom', (bottom + 40) + 'px');
      localStorage.setItem('aiAssistant.launcherRight', String(right));
      localStorage.setItem('aiAssistant.launcherBottom', String(bottom));
    }

    syncLauncherPosition(defaultLauncherPos.right, defaultLauncherPos.bottom);

    function setDragEnabled(enabled) {
      dragEnabled = !!enabled;

      launcher.classList.toggle('drag-enabled', enabled);
      panel.classList.toggle('drag-enabled', enabled);
      launcher.classList.toggle('drag-mode', enabled);
      dragBadge.style.pointerEvents = enabled ? 'auto' : 'none';
    }

    setDragEnabled(dragEnabled);

    function beginDrag(event) {
      if (!launcher.classList.contains('drag-enabled')) return;
      if (event.button !== 0) return;
      dragState.pointerId = event.pointerId;
      dragState.armed = true;
      dragState.active = true;
      dragState.moved = false;
      dragState.suppressClick = false;
      dragState.offsetX = event.clientX - launcher.getBoundingClientRect().left;
      dragState.offsetY = event.clientY - launcher.getBoundingClientRect().top;
      launcher.setPointerCapture(event.pointerId);
      event.preventDefault();
      event.stopPropagation();
    }

    dragBadge.addEventListener('pointerdown', event => {
      if (!launcher.classList.contains('drag-enabled')) return;
      if (event.button !== 0) return;
      beginDrag(event);
    });

    launcher.addEventListener('pointermove', event => {
      if (dragState.pointerId !== event.pointerId) return;
      const iconW = launcher.offsetWidth || 64;
      const iconH = launcher.offsetHeight || 64;
      const nextLeft = event.clientX - dragState.offsetX;
      const nextTop = event.clientY - dragState.offsetY;
      const nextRight = window.innerWidth - (nextLeft + iconW);
      const nextBottom = window.innerHeight - (nextTop + iconH);
      dragState.moved = true;
      dragState.suppressClick = true;
      syncLauncherPosition(nextRight, nextBottom);
    });

    function stopDrag(event) {
      if (dragState.pointerId !== event.pointerId) return;
      dragState.active = false;
      dragState.armed = false;
      dragState.pointerId = null;
      try { launcher.releasePointerCapture(event.pointerId); } catch (e) {}
    }

    launcher.addEventListener('pointerup', stopDrag);
    launcher.addEventListener('pointercancel', stopDrag);
    launcher.addEventListener('pointerleave', stopDrag);

    launcher.addEventListener('mouseenter', () => {
      launcher.classList.toggle('show-drag-badge', dragEnabled);
    });
    launcher.addEventListener('mouseleave', () => {
      launcher.classList.remove('show-drag-badge');
    });



    window.addEventListener('resize', () => {
      const right = Number(localStorage.getItem('aiAssistant.launcherRight') || defaultLauncherPos.right);
      const bottom = Number(localStorage.getItem('aiAssistant.launcherBottom') || defaultLauncherPos.bottom);
      syncLauncherPosition(right, bottom);
    });

    launcher.addEventListener('click', () => {
      if (dragState.suppressClick) {
        dragState.suppressClick = false;
        dragState.moved = false;
        return;
      }
      setPanelOpen(!panel.classList.contains('open'));
    });
    closeBtn.addEventListener('click', () => {
      setPanelOpen(false);
    });

    clearBtn.addEventListener('click', async () => {
      try {
        await fetch('api/ai_chat.php?action=clear', { method: 'POST' });
      } catch (e) {}
      messages.innerHTML = '';
      messages.dataset.loaded = '1';
      home.classList.remove('chat-active');
      messages.hidden = false;
    });

    input.addEventListener('keydown', event => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        form.requestSubmit();
      }
    });

    form.addEventListener('submit', async event => {
      event.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      addMessage(messages, 'user', text);
      const loading = addMessage(messages, 'assistant', 'Consultando o CRM e pensando...');
      setAssistantSpeaking(true);
      send.disabled = true;
      status.textContent = 'Consultando banco de dados...';
      try {
        const fd = new FormData();
        fd.append('message', text);
        const res = await fetch('api/ai_chat.php', { method: 'POST', body: fd });
        const data = await res.json();
        const answer = data.success ? data.answer : (data.message || 'Não consegui responder agora.');
        updateAssistantMessage(loading, answer);
        status.textContent = data.success && data.checked_at ? 'Banco consultado às ' + data.checked_at : 'Consulta finalizada';
      } catch (e) {
        updateAssistantMessage(loading, 'Erro ao consultar a IA. Confira a configuração em Integrações.');
        status.textContent = 'Erro na consulta';
      }
      send.disabled = false;
      setAssistantSpeaking(false);
      home.scrollTop = home.scrollHeight;
    });

    setupContextualAssistant({ panel, launcher, messages, input, form, contextBox, status });
  }

  async function loadHistory(messages) {
    if (messages.dataset.loaded === '1') return;
    messages.dataset.loaded = '1';
    try {
      const res = await fetch('api/ai_chat.php?action=history');
      const data = await res.json();
      if (data.success && Array.isArray(data.messages) && data.messages.length) {
        data.messages.forEach(row => addMessage(messages, row.role, row.message));
        return;
      }
    } catch (e) {}
    messages.hidden = false;
  }

  async function initAssistant() {
    try {
      const settingsRes = await fetch('api/get_ai_settings.php?status=1');
      const settingsData = await settingsRes.json();
      const ai = settingsData && settingsData.success ? (settingsData.ai || {}) : {};
      if (!Number(ai.enabled || 0)) return;
      buildAssistant();
      await loadAssistantPreferences(ai);
      setupProactiveAi(ai);
    } catch (e) {
      // Sem confirmação de que a IA está ativa, o launcher permanece oculto.
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAssistant);
  } else {
    initAssistant();
  }

  async function loadAssistantPreferences(aiSettings) {
    try {
      const profileRes = await fetch('api/get_my_profile.php');
      const profileData = await profileRes.json();
      const userMode = profileData && profileData.success && profileData.user ? profileData.user.ai_bot_mode : 'chatbot3';
      if (typeof window.__setAiAssistantAvatarMode === 'function') {
        window.__setAiAssistantAvatarMode(userMode || 'chatbot3');
      }
      const ai = aiSettings || {};
      if (typeof window.__setAiAssistantDragEnabled === 'function') {
        window.__setAiAssistantDragEnabled(!!Number(ai.draggable_launcher_enabled || 0));
      }
    } catch (e) {}
  }

  function setupContextualAssistant(parts) {
    const path = window.location.pathname.toLowerCase();
    if (!path.includes('leads_gestao.php')) return;

    const { panel, launcher, messages, input, form, contextBox, status } = parts;
    let lastSignature = '';
    let nudge = null;

    function readValue(selectors) {
      for (const selector of selectors) {
        const node = document.querySelector(selector);
        if (node && 'value' in node) return String(node.value || '').trim();
      }
      return '';
    }

    function leadModalContext() {
      const modal = document.getElementById('leadModal');
      const isOpen = !!(modal && modal.classList.contains('show'));
      if (!isOpen) return null;
      const ctx = {
        id: readValue(['#lead-id', '#leadId']),
        name: readValue(['#lead-name', '#leadName']),
        phone: readValue(['#lead-phone', '#leadPhone']),
        email: readValue(['#lead-email', '#leadEmail']),
        city: readValue(['#lead-city', '#leadCity']),
        source: readValue(['#lead-source', '#leadSource']),
        status: readValue(['#lead-status', '#leadStatus']),
        notes: readValue(['#lead-notes', '#leadNotes']),
        consumo: readValue(['#lead-consumo', '#leadConsumo']),
        estimativa: readValue(['#lead-estimativa-kwh', '#leadEstimativa'])
      };
      ctx.missing = [];
      if (!ctx.phone) ctx.missing.push('telefone');
      if (!ctx.email) ctx.missing.push('email');
      if (!ctx.city) ctx.missing.push('cidade');
      if (!ctx.notes || ctx.notes.length < 25) ctx.missing.push('observações completas');
      if (!ctx.consumo && !ctx.estimativa) ctx.missing.push('consumo/estimativa');
      return ctx;
    }

    function pageContext() {
      return {
        active: document.getElementById('kpiActive')?.textContent?.trim() || '',
        hot: document.getElementById('kpiHot')?.textContent?.trim() || '',
        value: document.getElementById('kpiValue')?.textContent?.trim() || '',
        stalledOn: document.getElementById('stalledToggle')?.classList.contains('active') || false
      };
    }

    function submitPrompt(text) {
      input.value = text;
      setPanelOpen(true);
      form.requestSubmit();
    }

    function renderContext() {
      const lead = leadModalContext();
      const page = pageContext();
      const signature = JSON.stringify({ lead, page, path });
      if (signature === lastSignature) return;
      lastSignature = signature;

      const actions = [];
      let title = 'Gestão de Leads';
      let body = '';

      if (lead) {
        title = lead.name ? `Lead: ${lead.name}` : 'Lead aberto';
        body = lead.missing.length
          ? `Campos para revisar: ${lead.missing.join(', ')}.`
          : 'Cadastro bem preenchido. Posso ajudar no próximo passo.';
        actions.push(['Sugerir abordagem', `Crie uma mensagem curta de WhatsApp para o lead ${lead.name || lead.id || ''}, considerando origem ${lead.source || 'não informada'}, status ${lead.status || 'não informado'} e observações: ${lead.notes || 'sem observações'}.`]);
        actions.push(['Próxima ação', `Qual a melhor próxima ação para este lead? Dados salvos: nome=${lead.name || '-'}, cidade=${lead.city || '-'}, fonte=${lead.source || '-'}, status=${lead.status || '-'}, telefone=${lead.phone ? 'informado' : 'faltando'}, email=${lead.email ? 'informado' : 'faltando'}, notas=${lead.notes || '-'}.`]);
        if (lead.missing.length) {
          actions.push(['Checklist', `Monte um checklist objetivo para completar o cadastro deste lead. Campos faltando: ${lead.missing.join(', ')}.`]);
        }
      } else {
        body = `Leads ativos: ${page.active || '0'}; quentes: ${page.hot || '0'}; pipeline: ${page.value || 'R$ 0,00'}.`;
        actions.push(['Resumo da tela', 'Analise a Gestão de Leads agora: quantidade de leads, funil, fontes, atividades e gargalos dos últimos 30 dias.']);
        actions.push(['Leads parados', 'Quais leads estão parados há mais de 7 dias e o que devo fazer com eles?']);
        actions.push(['Prioridades', 'Quais prioridades comerciais devo atacar hoje na Gestão de Leads?']);
      }

      contextBox.hidden = false;
      contextBox.innerHTML = `
        <div class="ai-assistant-context-title">${escapeHtml(title)}</div>
        <div class="ai-assistant-context-body">${escapeHtml(body)}</div>
        <div class="ai-assistant-context-actions">
          ${actions.map(([label, prompt]) => `<button type="button" data-ai-context-prompt="${escapeHtml(prompt)}">${escapeHtml(label)}</button>`).join('')}
        </div>
      `;
      contextBox.querySelectorAll('[data-ai-context-prompt]').forEach(btn => {
        btn.addEventListener('click', () => submitPrompt(btn.getAttribute('data-ai-context-prompt') || ''));
      });

      if (!panel.classList.contains('open')) showNudge(lead ? 'Posso sugerir o próximo passo desse lead.' : 'Posso analisar essa tela de leads.');
      status.textContent = lead ? 'Observando lead aberto' : 'Contexto: Gestão de Leads';
    }

    function showNudge(text) {
      if (!nudge) {
        nudge = el('button', { class: 'ai-assistant-nudge', type: 'button' });
        nudge.addEventListener('click', () => {
          setPanelOpen(true);
          nudge.classList.remove('show');
        });
        document.body.appendChild(nudge);
      }
      nudge.textContent = text;
      nudge.style.right = 'var(--ai-assistant-nudge-right, 70px)';
      nudge.style.bottom = 'var(--ai-assistant-nudge-bottom, 96px)';
      nudge.classList.add('show');
      window.clearTimeout(showNudge.timer);
      showNudge.timer = window.setTimeout(() => nudge.classList.remove('show'), 9000);
    }

    panel.addEventListener('ai-assistant:opened', renderContext);
    document.addEventListener('shown.bs.modal', renderContext);
    document.addEventListener('hidden.bs.modal', renderContext);
    document.addEventListener('input', event => {
      if (event.target && event.target.closest && event.target.closest('#leadModal')) {
        window.clearTimeout(renderContext.timer);
        renderContext.timer = window.setTimeout(renderContext, 350);
      }
    });
    window.setTimeout(renderContext, 1200);
  }

  async function setupProactiveAi(aiSettings) {
    if (window.__aiAssistantBotMode === 'none') return;
    const ai = aiSettings || {};
    if (!Number(ai.enabled || 0) || !Number(ai.proactive_enabled || 0)) return;

    const intervalMinutes = Math.max(1, Math.min(1440, Number(ai.proactive_interval_minutes || 30)));
    const intervalMs = intervalMinutes * 60 * 1000;
    const key = 'ai.proactive.lastRun';
    const indexKey = 'ai.proactive.index';

    async function run() {
      const last = Number(localStorage.getItem(key) || 0);
      if (Date.now() - last < intervalMs) return;
      localStorage.setItem(key, String(Date.now()));
      const idx = Number(localStorage.getItem(indexKey) || 0);
      try {
        const res = await fetch('api/ai_proactive.php?i=' + encodeURIComponent(idx));
        const data = await res.json();
        if (!data.success || !data.message) return;
        localStorage.setItem(indexKey, String(data.next_index || 0));
        showProactiveBubble(data.message);
      } catch (e) {}
    }

    window.setTimeout(run, 6000);
    window.setInterval(run, Math.min(intervalMs, 10 * 60 * 1000));
  }

  function showProactiveBubble(message) {
    if (window.__aiAssistantBotMode === 'none') return;
    let bubble = document.getElementById('aiProactiveBubble');
    if (!bubble) {
      bubble = el('button', { id: 'aiProactiveBubble', class: 'ai-assistant-proactive', type: 'button' });
      bubble.addEventListener('click', event => {
        if (event.target && event.target.closest && event.target.closest('.ai-proactive-close')) return;
        const launcher = document.getElementById('aiAssistantLauncher');
        if (launcher) launcher.click();
        bubble.classList.remove('show');
      });
      document.body.appendChild(bubble);
    }
    bubble.innerHTML = renderProactiveBubble(message);
    const close = bubble.querySelector('.ai-proactive-close');
    if (close) {
      close.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        bubble.classList.remove('show');
        if (typeof window.__setAiAssistantSpeaking === 'function') {
          window.__setAiAssistantSpeaking(false);
        }
      });
    }
    bubble.style.right = 'var(--ai-assistant-proactive-right, 62px)';
    bubble.style.bottom = 'var(--ai-assistant-proactive-bottom, 128px)';
    bubble.classList.add('show');
    if (typeof window.__setAiAssistantSpeaking === 'function') {
      window.__setAiAssistantSpeaking(true);
    }
    window.clearTimeout(showProactiveBubble.timer);
    showProactiveBubble.timer = window.setTimeout(() => {
      bubble.classList.remove('show');
      if (typeof window.__setAiAssistantSpeaking === 'function') {
        window.__setAiAssistantSpeaking(false);
      }
    }, 15000);
  }

  function renderProactiveBubble(message) {
    let text = String(message || '').trim();
    if (!text) return '<div class="ai-proactive-card"><button class="ai-proactive-close" type="button" aria-label="Fechar sugestão" title="Fechar sugestão"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="ai-proactive-kicker">IA proativa</div><div class="ai-proactive-title">Sem retorno no momento</div></div>';

    const parts = text
      .replace(/\s*([.!?])\s+(?=[A-ZÁÀÂÃÉÊÍÓÔÕÚÇ])/g, '$1\n')
      .replace(/\s*;\s*/g, ';\n')
      .split(/\r?\n/)
      .map(line => line.trim())
      .filter(Boolean)
      .slice(0, 6);

    const [lead, ...rest] = parts;
    const bullets = rest
      .map(line => line.replace(/^[-*•]\s*/, ''))
      .filter(Boolean);

    return (
      '<div class="ai-proactive-card">' +
        '<button class="ai-proactive-close" type="button" aria-label="Fechar sugestão" title="Fechar sugestão"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>' +
        '<div class="ai-proactive-kicker">IA proativa</div>' +
        '<div class="ai-proactive-title">' + escapeHtml(lead || text) + '</div>' +
        (bullets.length ? '<div class="ai-proactive-list">' + bullets.map(item => '<div class="ai-proactive-item">' + escapeHtml(item) + '</div>').join('') + '</div>' : '') +
      '</div>'
    );
  }

  function escapeHtml(s) {
    return s == null ? '' : String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
})();


