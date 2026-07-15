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
        document.body.classList.remove('theme-dark','theme-light');
        if(mode === 'dark') document.body.classList.add('theme-dark');
        else document.body.classList.add('theme-light');
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

  <script src="assets/js/notifications.js"></script>
  <?php if (empty($noNavbar) && !empty($_SESSION['user_id'])): ?>
  <script src="assets/js/internal_chat.js"></script>
  <?php endif; ?>
</body>
</html>
