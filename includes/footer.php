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

  <!-- Explicação dinâmica da tela -->
  <div class="container-fluid mt-5">
    <div id="pageExplanation" class="alert alert-info text-center small" style="max-width:800px;margin:auto;">
    </div>
  </div>
  <script>
    // Explicação por página (definido de forma idempotente no objeto global para evitar redeclaração)
    window.__pageExplanations = window.__pageExplanations || {
      'leads.php': 'Gestão de Leads: Esta tela permite registrar, editar e acompanhar todos os potenciais clientes do seu negócio. É essencial para organizar contatos, entender o perfil de consumo e monitorar o avanço de cada lead no funil de vendas. O uso eficiente desta tela aumenta as chances de conversão, reduz perdas e traz clareza sobre oportunidades reais, beneficiando diretamente o crescimento comercial.',
      'funil.php': 'Funil de Vendas: Aqui você personaliza os estágios do processo comercial, define cores e organiza o fluxo dos leads. O funil é fundamental para visualizar pontos críticos, identificar pontos de melhoria e garantir que cada oportunidade seja tratada conforme sua maturidade. Utilizar bem esta tela traz mais controle, previsibilidade e eficiência para a equipe de vendas.',
      'projetos.php': 'Projetos: Gerencie todas as informações dos clientes, histórico de interações, contratos e andamento dos projetos. Esta tela é importante para garantir que cada etapa do projeto seja acompanhada, evitando atrasos e melhorando a comunicação com o cliente. O benefício é a entrega de projetos mais organizados, com maior satisfação e fidelização.',
      'integracao-equipes.php': 'Integração de Equipes: Centraliza tarefas, atividades e comunicação entre os times de marketing, vendas, técnica e financeiro. Facilita o alinhamento, reduz falhas de comunicação e aumenta a produtividade coletiva. O uso desta tela promove colaboração, agilidade e resultados mais consistentes para toda a empresa.',
      'relatorios.php': 'Relatórios: Visualize KPIs, gráficos avançados, funil pirâmide, timeline e métricas essenciais para a tomada de decisão. Esta tela é crucial para analisar resultados, identificar tendências e embasar estratégias. O benefício é a gestão orientada por dados, com mais segurança e assertividade nas decisões.',
      'pos-venda.php': 'Pós-venda: Acompanhe instalações, manutenções, garantias e o relacionamento após a conclusão dos projetos. Esta tela é importante para garantir a satisfação do cliente, prevenir problemas futuros e fortalecer a reputação da empresa. O uso contínuo traz mais fidelização e oportunidades de novos negócios.',
      'customers.php': 'Clientes: Lista e detalhes dos clientes cadastrados, facilitando o acesso rápido às informações e histórico. É essencial para manter o relacionamento ativo, personalizar abordagens e garantir que nenhum cliente seja esquecido. O benefício é uma base sólida para vendas recorrentes e indicações.',
    };
    const path = window.location.pathname.split('/').pop();
    const el = document.getElementById('pageExplanation');
    if (el && window.__pageExplanations[path]) el.textContent = window.__pageExplanations[path];
  </script>
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
