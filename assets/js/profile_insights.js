(function () {
    'use strict';

    function setText(id, value) {
        const element = document.getElementById(id);
        if (element) element.textContent = String(value);
    }

    function emptyChart(id, message) {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        const note = document.createElement('div');
        note.className = 'profile-insight-empty';
        note.textContent = message;
        canvas.replaceWith(note);
    }

    function addAdvice(container, message) {
        const item = document.createElement('div');
        item.className = 'profile-advice-item';
        item.textContent = message;
        container.appendChild(item);
    }

    async function loadInsights() {
        const status = document.getElementById('profileInsightsStatus');
        if (!status) return;
        try {
            const response = await fetch('api/profile_insights.php', { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Falha ao carregar indicadores');

            const counts = (data.trend?.counts || []).map(Number);
            const labels = (data.trend?.days || []).map(day => day.slice(8, 10) + '/' + day.slice(5, 7));
            const leadTotal = counts.reduce((sum, value) => sum + value, 0);
            const stageRows = data.stages || [];
            const stageTotal = stageRows.reduce((sum, row) => sum + Number(row.total || 0), 0);
            const tasks = data.tasks || {};
            setText('profileLeads30Total', leadTotal);
            setText('profileStagesTotal', stageTotal);
            setText('profileTasksOverdue', Number(tasks.overdue || 0));

            const advice = document.getElementById('profileInsightsAdvice');
            if (advice) {
                advice.className = 'profile-advice-list';
                advice.replaceChildren();
                const overdue = Number(tasks.overdue || 0);
                if (overdue) addAdvice(advice, `${overdue} tarefa${overdue === 1 ? '' : 's'} vencida${overdue === 1 ? '' : 's'}: revise os prazos e priorize os próximos contatos.`);
                const recent = counts.slice(-7).reduce((sum, value) => sum + value, 0);
                addAdvice(advice, recent ? `${recent} novo${recent === 1 ? ' lead' : 's leads'} nos últimos 7 dias.` : 'Nenhum lead novo nos últimos 7 dias. Confira a entrada de oportunidades.');
                if (stageRows.length) addAdvice(advice, `Maior concentração da carteira: ${stageRows[0].label} (${Number(stageRows[0].total)}).`);
                else addAdvice(advice, 'A distribuição por etapa aparecerá quando houver leads na sua carteira.');
            }

            if (typeof window.Chart !== 'function') {
                status.textContent = 'Os dados foram carregados, mas os gráficos não estão disponíveis.';
                return;
            }

            const dark = document.body.classList.contains('theme-dark');
            const gridColor = dark ? 'rgba(226,232,240,.12)' : 'rgba(71,85,105,.1)';
            const textColor = dark ? '#cbd5e1' : '#475569';
            const common = { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } } };

            if (leadTotal === 0) emptyChart('profileLeadsTrendChart', 'Nenhum lead criado neste período.');
            else new Chart(document.getElementById('profileLeadsTrendChart'), {
                type:'line',
                data:{ labels, datasets:[{ label:'Leads', data:counts, borderColor:'#0b6ac1', backgroundColor:'rgba(11,106,193,.12)', fill:true, tension:.32, pointRadius:0, pointHoverRadius:4, borderWidth:2.5 }] },
                options:{ ...common, interaction:{ intersect:false, mode:'index' }, scales:{ x:{ grid:{display:false}, ticks:{color:textColor,maxTicksLimit:8} }, y:{ beginAtZero:true, ticks:{color:textColor,precision:0}, grid:{color:gridColor} } } }
            });

            if (!stageTotal) emptyChart('profileStagesChart', 'Nenhum lead na carteira para distribuir por status.');
            else {
                const top = stageRows.slice(0, 6);
                const rest = stageRows.slice(6).reduce((sum, row) => sum + Number(row.total || 0), 0);
                if (rest) top.push({label:'Outras etapas',total:rest});
                new Chart(document.getElementById('profileStagesChart'), {
                    type:'bar',
                    data:{ labels:top.map(row => row.label), datasets:[{ label:'Leads', data:top.map(row => Number(row.total)), backgroundColor:['#0b6ac1','#159895','#64a9ea','#f59e0b','#8675cf','#5f819f','#a6b7c8'], borderRadius:6, maxBarThickness:30 }] },
                    options:{ ...common, indexAxis:'y', scales:{ x:{beginAtZero:true,ticks:{color:textColor,precision:0},grid:{color:gridColor}}, y:{ticks:{color:textColor},grid:{display:false}} } }
                });
            }

            const taskValues = [Number(tasks.pending || 0), Number(tasks.progress || 0), Number(tasks.completed || 0)];
            if (taskValues.every(value => value === 0)) emptyChart('profileTasksChart', 'Nenhuma tarefa encontrada.');
            else new Chart(document.getElementById('profileTasksChart'), {
                type:'doughnut',
                data:{ labels:['Pendentes','Em andamento','Concluídas'], datasets:[{data:taskValues,backgroundColor:['#f59e0b','#0b6ac1','#159895'],borderWidth:0,hoverOffset:5}] },
                options:{ ...common, cutout:'68%', plugins:{legend:{display:true,position:'bottom',labels:{color:textColor,usePointStyle:true,padding:16}}} }
            });
            status.textContent = '';
        } catch (error) {
            status.textContent = 'Não foi possível carregar os indicadores agora.';
            const advice = document.getElementById('profileInsightsAdvice');
            if (advice) advice.textContent = 'Tente atualizar a página mais tarde.';
        }
    }

    function bindPasswordForm() {
        const form = document.getElementById('profilePasswordForm');
        if (!form) return;
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const button = document.getElementById('profilePasswordSave');
            const feedback = document.getElementById('profilePasswordFeedback');
            if (button.disabled) return;
            const values = new FormData(form);
            if (values.get('new_password') !== values.get('confirm_password')) {
                feedback.textContent = 'A confirmação não corresponde à nova senha.';
                feedback.className = 'profile-security-feedback text-danger';
                return;
            }
            button.disabled = true;
            feedback.textContent = 'Alterando senha...';
            feedback.className = 'profile-security-feedback text-muted';
            try {
                const response = await fetch('api/change_my_password.php', { method:'POST', body:values, credentials:'same-origin' });
                const data = await response.json();
                feedback.textContent = data.message || 'Não foi possível alterar a senha.';
                feedback.className = 'profile-security-feedback ' + (response.ok && data.success ? 'text-success' : 'text-danger');
                if (response.ok && data.success) {
                    form.reset();
                    form.querySelector('[name="csrf_token"]').value = data.csrf_token;
                }
            } catch (error) {
                feedback.textContent = 'Erro de conexão. Tente novamente.';
                feedback.className = 'profile-security-feedback text-danger';
            } finally {
                button.disabled = false;
            }
        });
    }

    function initializeProfilePage() {
        loadInsights();
        bindPasswordForm();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeProfilePage, { once: true });
    } else {
        initializeProfilePage();
    }
})();
