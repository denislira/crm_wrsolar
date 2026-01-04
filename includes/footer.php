  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <script>
    // small helper to toggle active nav based on current path or hash
    (function(){
      const links = document.querySelectorAll('.app-sidebar a.nav-link');
      links.forEach(a=>{
        try{ if(a.getAttribute('href') === window.location.pathname || a.getAttribute('href') === window.location.hash) a.classList.add('active'); }catch(e){}
      });
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
</body>
</html>
