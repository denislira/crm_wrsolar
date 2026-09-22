  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <script>
    // Page loading overlay on navigation
    (function(){
      const overlay = document.getElementById('pageLoadingOverlay');
      if (!overlay) return;
      
      // Show loading on sidebar link clicks
      document.querySelectorAll('.app-sidebar a.nav-link').forEach(link => {
        link.addEventListener('click', function(e) {
          const href = this.getAttribute('href');
          if (!href || href === '#' || href.startsWith('javascript:')) return;
          try {
            const targetPath = new URL(href, window.location.href).pathname;
            if (targetPath !== window.location.pathname) {
              overlay.classList.add('active');
            }
          } catch (err) {
            overlay.classList.add('active');
          }
        });
      });
      
      // Hide loading when page fully loaded
      window.addEventListener('load', function() {
        overlay.classList.remove('active');
      });
      
      // Fallback: hide after 5 seconds in case something goes wrong
      setTimeout(function() {
        overlay.classList.remove('active');
      }, 5000);
    })();
    
    // small helper to toggle active nav based on current path or hash
    (function(){
      const links = document.querySelectorAll('.app-sidebar a.nav-link');
      links.forEach(a=>{
        try{ if(a.getAttribute('href') === window.location.pathname || a.getAttribute('href') === window.location.hash) a.classList.add('active'); }catch(e){}
      });
    })();
    
    // Theme toggle (Light/Dark) - persist choice in localStorage
    (function(){
      const toggle = document.getElementById('themeToggle');
      const apply = (mode)=>{
        const preloadStyle = document.getElementById('theme-preload-dark');
        if (preloadStyle) preloadStyle.remove();
        document.body.classList.remove('theme-dark','theme-light','dark-mode');
        if(mode === 'dark') document.body.classList.add('theme-dark');
        else document.body.classList.add('theme-light');
        document.body.classList.toggle('dark-mode', mode === 'dark');
        document.documentElement.setAttribute('data-theme', mode);
        try{
          localStorage.setItem('theme.mode', mode);
          localStorage.setItem('darkMode', mode === 'dark' ? '1' : '0');
        }catch(e){}
        // update reminder bell dropdown to match theme
        try{ updateReminderDropdownTheme(); }catch(e){}
      };
      function updateReminderDropdownTheme(){
        const menu = document.getElementById('reminderBellMenu');
        const count = document.getElementById('reminderBellCount');
        if (!menu) return;
        if (document.body.classList.contains('theme-dark')){
          menu.classList.add('dropdown-menu-dark');
          // adjust badge to muted in dark theme for contrast
          if (count) { count.classList.remove('bg-danger'); count.classList.add('bg-secondary'); }
        } else {
          menu.classList.remove('dropdown-menu-dark');
          if (count) { count.classList.remove('bg-secondary'); count.classList.add('bg-danger'); }
        }
      }
      // initialize from storage or prefers-color-scheme
      let stored = null; try{ stored = localStorage.getItem('theme.mode') || (localStorage.getItem('darkMode') === '1' ? 'dark' : null); }catch(e){}
      if(stored) apply(stored);
      else {
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        apply(prefersDark ? 'dark' : 'light');
      }
      if(!toggle) return;
      toggle.addEventListener('click', ()=>{
        const isDark = document.body.classList.contains('theme-dark');
        apply(isDark ? 'light' : 'dark');
        // change toggle icon (simple) to reflect mode
        toggle.textContent = document.body.classList.contains('theme-dark') ? '🌙' : '☀️';
      });
      // set initial icon
      if(toggle) toggle.textContent = document.body.classList.contains('theme-dark') ? '🌙' : '☀️';
    })();
  </script>

  <!-- pageExplanation removed -->
  <script>
    // Compact long button labels on phones while keeping full labels on desktop.
    (function(){
      const longLabelLimit = 7;
      const iconMap = [
        [/novo|nova|adicionar|criar|cadastrar|incluir|\+/i, 'fa-plus'],
        [/sem status/i, 'fa-tag'],
        [/parado|parados/i, 'fa-pause'],
        [/an.{0,4}ncio|anuncios/i, 'fa-bullhorn'],
        [/massa|lote|selecionad/i, 'fa-layer-group'],
        [/funil|est[a\u00e1]gio/i, 'fa-sliders'],
        [/lead|leads/i, 'fa-user-group'],
        [/salvar|gravar|confirmar|aplicar/i, 'fa-check'],
        [/cancelar|fechar|limpar/i, 'fa-xmark'],
        [/excluir|deletar|remover|apagar/i, 'fa-trash'],
        [/editar|alterar/i, 'fa-pen'],
        [/config|gerenciar|campo|coluna|permiss/i, 'fa-gear'],
        [/filtro|filtrar|pesquisar|buscar/i, 'fa-filter'],
        [/exportar|relat[oó]rio|pdf|excel|csv/i, 'fa-file-export'],
        [/importar|upload|enviar arquivo/i, 'fa-file-import'],
        [/baixar|download/i, 'fa-download'],
        [/enviar|whatsapp|email/i, 'fa-paper-plane'],
        [/voltar|anterior/i, 'fa-arrow-left'],
        [/pr[oó]ximo|avan[cç]ar/i, 'fa-arrow-right'],
        [/abrir|visualizar|ver|detalhe/i, 'fa-eye'],
        [/kanban|quadro/i, 'fa-table-columns'],
        [/tabela|lista/i, 'fa-table-list'],
        [/compact/i, 'fa-compress'],
        [/expand/i, 'fa-expand'],
        [/indica/i, 'fa-share-nodes'],
        [/atualizar|recarregar|reload/i, 'fa-rotate'],
        [/sair|logout/i, 'fa-right-from-bracket']
      ];

      function normalizeLabel(text){
        return (text || '').replace(/\s+/g, ' ').trim();
      }

      function pickIcon(label){
        const found = iconMap.find(([pattern]) => pattern.test(label));
        return found ? found[1] : '';
      }

      function hasVisualIcon(btn){
        return !!btn.querySelector('i[class*="fa-"], svg, .lucide, [data-lucide]');
      }

      function wrapTextNodes(btn){
        if (btn.querySelector(':scope > .mobile-btn-label')) return;
        const nodes = Array.from(btn.childNodes).filter(node => node.nodeType === Node.TEXT_NODE && normalizeLabel(node.textContent));
        if (!nodes.length) {
          Array.from(btn.children).forEach(child => {
            if (child.matches('i[class*="fa-"], svg, .lucide, [data-lucide]')) return;
            if (normalizeLabel(child.textContent)) child.classList.add('mobile-btn-label');
          });
          return;
        }
        const span = document.createElement('span');
        span.className = 'mobile-btn-label';
        nodes[0].parentNode.insertBefore(span, nodes[0]);
        nodes.forEach(node => span.appendChild(node));
      }

      function compactButton(btn){
        if (!btn || btn.dataset.mobileCompactReady === '1') return;
        if (btn.closest('.app-sidebar') || btn.closest('.navbar')) return;
        const label = normalizeLabel(btn.textContent);
        if (label.length <= longLabelLimit) return;

        const needsGeneratedIcon = !hasVisualIcon(btn);
        const iconClass = needsGeneratedIcon ? pickIcon(label) : '';
        if (needsGeneratedIcon && !iconClass) return;

        btn.dataset.mobileCompactReady = '1';
        btn.classList.add('mobile-icon-btn');
        if (!btn.getAttribute('title')) btn.setAttribute('title', label);
        if (!btn.getAttribute('aria-label')) btn.setAttribute('aria-label', label);

        if (needsGeneratedIcon) {
          const icon = document.createElement('i');
          icon.className = 'fa-solid mobile-auto-icon ' + iconClass;
          icon.setAttribute('aria-hidden', 'true');
          btn.insertBefore(icon, btn.firstChild);
        }
        wrapTextNodes(btn);
      }

      function compactAll(root){
        (root || document).querySelectorAll('button.btn, a.btn').forEach(compactButton);
      }

      document.addEventListener('DOMContentLoaded', function(){
        compactAll(document);
        const observer = new MutationObserver(mutations => {
          mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
              if (node.nodeType !== Node.ELEMENT_NODE) return;
              if (node.matches && node.matches('button.btn, a.btn')) compactButton(node);
              compactAll(node);
            });
          });
        });
        observer.observe(document.body, { childList: true, subtree: true });
      });
    })();
  </script>

  <script>
    // Sidebar behavior: collapse on desktop, floating drawer on mobile
    (function(){
      const sidebar = document.querySelector('.app-sidebar');
      const btn = document.getElementById('sidebarToggle');
      const brandBtn = document.getElementById('sidebarBrandButton');
      const mobileBtn = document.getElementById('mobileSidebarToggle');
      const backdrop = document.getElementById('mobileSidebarBackdrop');
      if(!sidebar) return;
      const mobileQuery = window.matchMedia('(max-width: 767.98px)');

      function isMobile(){
        return mobileQuery.matches;
      }

      function setMobileOpen(open){
        document.body.classList.toggle('sidebar-mobile-open', open);
        [btn, mobileBtn].forEach(toggle => {
          if (toggle) toggle.setAttribute('aria-expanded', open.toString());
        });
        sidebar.setAttribute('aria-hidden', (!open).toString());
      }

      function setCollapsed(v){
        if(v) sidebar.classList.add('collapsed'); else sidebar.classList.remove('collapsed');
        if (!isMobile()) {
          localStorage.setItem('sidebar.collapsed', v ? '1':'0');
        }
        if(v) document.body.classList.add('sidebar-collapsed'); else document.body.classList.remove('sidebar-collapsed');
        if (btn) btn.setAttribute('aria-expanded', (!v).toString());
        if (brandBtn) {
          brandBtn.setAttribute('aria-disabled', (!v).toString());
          brandBtn.setAttribute('aria-label', v ? 'Expandir menu' : 'Logomarca do sistema');
          brandBtn.title = v ? 'Expandir menu' : '';
          brandBtn.tabIndex = v ? 0 : -1;
        }
        document.querySelectorAll('.app-sidebar .nav-link .label').forEach(el=>{
          if(v) { el.setAttribute('aria-hidden','true'); } else { el.removeAttribute('aria-hidden'); }
        });
      }

      function syncMode(){
        if (isMobile()) {
          setMobileOpen(false);
          sidebar.classList.remove('collapsed');
          document.body.classList.remove('sidebar-collapsed');
          document.querySelectorAll('.app-sidebar .nav-link .label').forEach(el=>el.removeAttribute('aria-hidden'));
        } else {
          setMobileOpen(false);
          const stored = localStorage.getItem('sidebar.collapsed');
          setCollapsed(stored !== null ? stored === '1' : false);
          sidebar.removeAttribute('aria-hidden');
        }
      }

      [btn, mobileBtn].forEach(toggle => {
        if (!toggle) return;
        toggle.setAttribute('aria-controls','appSidebar');
        toggle.setAttribute('aria-expanded','false');
      });
      sidebar.setAttribute('role','navigation');
      sidebar.setAttribute('id','appSidebar');

      syncMode();

      if (mobileQuery.addEventListener) {
        mobileQuery.addEventListener('change', syncMode);
      } else if (mobileQuery.addListener) {
        mobileQuery.addListener(syncMode);
      }

      if (btn) btn.addEventListener('click', ()=>{
        if (isMobile()) {
          setMobileOpen(!document.body.classList.contains('sidebar-mobile-open'));
          return;
        }
        setCollapsed(!sidebar.classList.contains('collapsed'));
      });

      if (brandBtn) brandBtn.addEventListener('click', ()=>{
        if (!isMobile() && sidebar.classList.contains('collapsed')) setCollapsed(false);
      });

      if (mobileBtn) mobileBtn.addEventListener('click', ()=>{
        setMobileOpen(!document.body.classList.contains('sidebar-mobile-open'));
      });

      if (backdrop) backdrop.addEventListener('click', ()=>setMobileOpen(false));

      document.addEventListener('keydown', (event)=>{
        if (event.key === 'Escape' && isMobile()) setMobileOpen(false);
      });

      sidebar.querySelectorAll('a.nav-link').forEach(link=>{
        link.addEventListener('click', ()=>{
          if (isMobile()) setMobileOpen(false);
        });
      });
    })();
  </script>

  <script>
    // Ícones e títulos principais compartilhados entre as telas internas.
    (function(){
      const page = (location.pathname.split('/').pop() || 'index.php').toLowerCase();
      const headings = {
        'index.php':               ['main h1', 'fa-gauge-high'],
        'dashboard.php':           ['main h1', 'fa-gauge-high'],
        'configuracoes.php':       ['main h1.settings-title', 'fa-gear'],
        'emails.php':              ['main h1', 'fa-envelope'],
        'leads_gestao.php':        ['main h1', 'fa-users'],
        'projetos.php':            ['main h1', 'fa-folder-open'],
        'integracao-equipes.php':  ['main h1', 'fa-people-group'],
        'funil.php':               ['main h1', 'fa-filter-circle-dollar'],
        'funil_config.php':        ['main h1', 'fa-sliders'],
        'projeto_config.php':      ['main h1', 'fa-diagram-project'],
        'pos-venda.php':           ['main h1', 'fa-arrows-rotate'],
        'meu_perfil.php':          ['main .profile-header-gradient h2', 'fa-user'],
        'customers.php':           ['main h1', 'fa-address-book'],
        'movimentos_leads.php':    ['main h1', 'fa-clock-rotate-left'],
        'fila_demandas.php':       ['main h1', 'fa-inbox'],
        'consultoria_externa.php': ['main h1.ce-page-title', 'fa-user-tie'],
        'import_leads.php':        ['main h1', 'fa-file-import'],
        'add_customer.php':        ['main h1', 'fa-user-plus']
      };
      const config = headings[page];
      if (!config) return;
      const title = document.querySelector(config[0]);
      if (!title || title.classList.contains('wr-page-title')) return;

      const oldIcon = title.querySelector(':scope > i');
      if (oldIcon) oldIcon.remove();
      const text = document.createElement('span');
      text.className = 'wr-page-title-text';
      while (title.firstChild) text.appendChild(title.firstChild);
      const icon = document.createElement('span');
      icon.className = 'wr-page-heading-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.innerHTML = '<i class="fa-solid ' + config[1] + '"></i>';
      title.append(icon, text);
      title.classList.add('wr-page-title');
    })();
  </script>

  <script src="assets/js/notifications.js"></script>
  <?php if (!empty($_SESSION['pending_login_notifications'])): ?>
  <script>
    window.addEventListener('load', function(){
      fetch('api/process_login_notifications.php', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true
      }).catch(function(){});
    });
  </script>
  <?php endif; ?>
  <?php if (empty($noNavbar) && !empty($_SESSION['user_id'])): ?>
  <script src="assets/js/ai_public_settings_cache.js"></script>
  <script src="assets/js/internal_chat.js"></script>
  <script src="assets/js/ai_assistant.js"></script>
  <?php endif; ?>
</body>
</html>
