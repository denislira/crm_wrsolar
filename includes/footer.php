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
          // Don't show for logout or external links or same page
          if (href && href !== '#' && !href.startsWith('javascript:') && href !== window.location.pathname) {
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
        try{ localStorage.setItem('theme.mode', mode); }catch(e){}
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
      let stored = null; try{ stored = localStorage.getItem('theme.mode'); }catch(e){}
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
    // Sidebar collapse toggle
    (function(){
      const sidebar = document.querySelector('.app-sidebar');
      const btn = document.getElementById('sidebarToggle');
      if(!sidebar || !btn) return;
      function setCollapsed(v){
        if(v) sidebar.classList.add('collapsed'); else sidebar.classList.remove('collapsed');
        // persist user choice
        localStorage.setItem('sidebar.collapsed', v ? '1':'0');
        // toggle a body class so CSS can adapt content width smoothly
        if(v) document.body.classList.add('sidebar-collapsed'); else document.body.classList.remove('sidebar-collapsed');
        // accessibility hints
        btn.setAttribute('aria-expanded', (!v).toString());
        // hide label text from screen readers when collapsed
        document.querySelectorAll('.app-sidebar .nav-link .label').forEach(el=>{
          if(v) { el.setAttribute('aria-hidden','true'); } else { el.removeAttribute('aria-hidden'); }
        });
      }
      // initialize
      btn.setAttribute('aria-controls','appSidebar');
      btn.setAttribute('aria-expanded','true');
      sidebar.setAttribute('role','navigation');
      sidebar.setAttribute('id','appSidebar');
      const stored = localStorage.getItem('sidebar.collapsed');
      // Respect the user's last choice if present. If not present, default to collapsed on small screens and expanded on desktop.
      if (stored !== null) {
        setCollapsed(stored === '1');
      } else {
        setCollapsed(window.innerWidth < 992);
      }
      btn.addEventListener('click', ()=>{
        setCollapsed(!sidebar.classList.contains('collapsed'));
      });
    })();
  </script>

  <script src="assets/js/notifications.js"></script>
</body>
</html>
