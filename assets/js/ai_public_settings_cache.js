(function () {
  const TTL_MS = 5 * 60 * 1000;
  let pendingRequest = null;

  function cacheKey() {
    return 'wrcrm.aiPublicSettings.' + String(window.currentUserId || 'current');
  }

  function sanitize(source) {
    const ai = source || {};
    return {
      enabled: Number(ai.enabled || 0) ? 1 : 0,
      proactive_enabled: Number(ai.proactive_enabled || 0) ? 1 : 0,
      proactive_interval_minutes: Math.max(1, Number(ai.proactive_interval_minutes || 30)),
      chat_poll_interval_seconds: Math.max(10, Number(ai.chat_poll_interval_seconds || 30)),
      draggable_launcher_enabled: Number(ai.draggable_launcher_enabled || 0) ? 1 : 0
    };
  }

  function read() {
    try {
      const cached = JSON.parse(localStorage.getItem(cacheKey()) || 'null');
      if (!cached || Number(cached.expiresAt || 0) < Date.now()) return null;
      return sanitize(cached.ai);
    } catch (e) {
      return null;
    }
  }

  function set(ai) {
    const safe = sanitize(ai);
    try {
      localStorage.setItem(cacheKey(), JSON.stringify({
        ai: safe,
        expiresAt: Date.now() + TTL_MS
      }));
    } catch (e) {}
    return safe;
  }

  function clear() {
    try { localStorage.removeItem(cacheKey()); } catch (e) {}
    pendingRequest = null;
  }

  async function get(forceRefresh) {
    if (!forceRefresh) {
      const cached = read();
      if (cached) return cached;
    }
    if (pendingRequest) return pendingRequest;

    pendingRequest = fetch('api/get_ai_settings.php?status=1', { credentials: 'same-origin' })
      .then(response => {
        if (!response.ok) throw new Error('Falha ao consultar configurações públicas da IA.');
        return response.json();
      })
      .then(data => {
        if (!data || !data.success) throw new Error('Configurações públicas da IA indisponíveis.');
        return set(data.ai || {});
      })
      .finally(() => { pendingRequest = null; });

    return pendingRequest;
  }

  window.wrcrmAiPublicSettings = { get, set, clear };
})();
