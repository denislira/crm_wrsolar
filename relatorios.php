<?php
// relatorios.php - Extended reports with multiple charts and a funnel
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit(); }
require_once 'includes/config.php';
$pageTitle = 'Relatórios';
include 'includes/header.php';

// Defensive server-side data collection
$userId = $_SESSION['user_id'];

$leadsTotal = 0;
$stages = [];
$stageCounts = [];
$monthsRows = [];
$monthsClosedRows = [];
$timeline = [];
$timelineUsers = [];
$timelineTypes = [];
$avgDaysToClose = null;
$avgTicket = null;
$sources = [];

try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE user_id = ?');
        $stmt->execute([$userId]);
        $leadsTotal = (int)$stmt->fetchColumn();
} catch (Exception $e) { $leadsTotal = 0; }

// funil_stages (name, color, position) - defensive column detection
try {
        $colsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funil_stages'");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
        $nameCol = in_array('name', $cols) ? 'name' : (in_array('stage_name', $cols) ? 'stage_name' : 'name');
        $positionCol = in_array('position', $cols) ? 'position' : (in_array('stage_order', $cols) ? 'stage_order' : 'id');
        $colorCol = in_array('color', $cols) ? 'color' : (in_array('stage_color', $cols) ? 'stage_color' : null);
        $selectCols = "id, {$nameCol} AS name";
        if ($colorCol) $selectCols .= ", {$colorCol} AS color";
        $q = $pdo->prepare("SELECT {$selectCols} FROM funil_stages WHERE user_id = ? ORDER BY COALESCE({$positionCol}, id) ASC");
        $q->execute([$userId]);
        $stages = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stages as $s) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE user_id = ? AND (stage_id = ? OR status = ?)');
                $c->execute([$userId, $s['id'], $s['name']]);
                $stageCounts[] = (int)$c->fetchColumn();
        }
} catch (Exception $e) { $stages = []; $stageCounts = []; }

// Last 12 months created
try {
        $m = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as cnt FROM leads WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym ASC");
        $m->execute([$userId]);
        $monthsRows = $m->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $monthsRows = []; }

// Last 12 months closed (if closed_at exists)
try {
        $leadColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'");
        $leadCols = $leadColsStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasClosedAt = in_array('closed_at', $leadCols);
        $valueCol = null;
        foreach (['value','amount','budget','estimated_value'] as $vc) if (in_array($vc, $leadCols)) { $valueCol = $vc; break; }
        $sourceCol = null;
        foreach (['source','origem','lead_source'] as $sc) if (in_array($sc, $leadCols)) { $sourceCol = $sc; break; }

        if ($hasClosedAt) {
                $mc = $pdo->prepare("SELECT DATE_FORMAT(closed_at, '%Y-%m') as ym, COUNT(*) as cnt FROM leads WHERE user_id = ? AND closed_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym ASC");
                $mc->execute([$userId]);
                $monthsClosedRows = $mc->fetchAll(PDO::FETCH_ASSOC);
        }

        // avg days to close
        if ($hasClosedAt) {
                $ad = $pdo->prepare("SELECT AVG(DATEDIFF(closed_at, created_at)) as avgd FROM leads WHERE user_id = ? AND closed_at IS NOT NULL");
                $ad->execute([$userId]);
                $avgDaysToClose = round((float)$ad->fetchColumn(),2);
        }

        // avg ticket
        if ($valueCol) {
                $at = $pdo->prepare("SELECT AVG(CASE WHEN {$valueCol} IS NULL OR {$valueCol} = '' THEN NULL ELSE {$valueCol} END) FROM leads WHERE user_id = ?");
                $at->execute([$userId]);
                $avgTicket = $at->fetchColumn();
                if ($avgTicket !== null) $avgTicket = round((float)$avgTicket,2);
        }

        // sources
        if ($sourceCol) {
                $sstmt = $pdo->prepare("SELECT {$sourceCol} AS source, COUNT(*) AS cnt FROM leads WHERE user_id = ? GROUP BY {$sourceCol} ORDER BY cnt DESC LIMIT 10");
                $sstmt->execute([$userId]);
                $sources = $sstmt->fetchAll(PDO::FETCH_ASSOC);
        }

} catch (Exception $e) { /* ignore and continue */ }

// Timeline
try {
        $actColsStmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log'");
        $actCols = $actColsStmt->fetchAll(PDO::FETCH_COLUMN);
        $select = ['a.message','a.created_at'];
        $joins = '';
        if (in_array('user_id', $actCols)) { $select[] = 'a.user_id'; $select[] = 'COALESCE(u.username, "(desconhecido)") AS username'; $joins .= ' LEFT JOIN users u ON u.id = a.user_id '; }
        if (in_array('event_type', $actCols)) { $select[] = 'a.event_type'; }
        $selectSql = implode(', ', $select);
        $timelineSql = "SELECT {$selectSql} FROM activity_log a {$joins} WHERE a.user_id = ? OR ? IS NULL ORDER BY a.created_at DESC LIMIT 500";
        $tStmt = $pdo->prepare($timelineSql);
        $tStmt->execute([$userId, null]);
        $timeline = $tStmt->fetchAll(PDO::FETCH_ASSOC);
        // derive users and types
        $usersMap = [];
        $typesMap = [];
        foreach ($timeline as $r) {
                if (isset($r['user_id']) && isset($r['username'])) $usersMap[$r['user_id']] = ['id'=>$r['user_id'],'username'=>$r['username']];
                if (isset($r['event_type']) && $r['event_type'] !== '') $typesMap[$r['event_type']] = true;
        }
        $timelineUsers = array_values($usersMap);
        $timelineTypes = array_keys($typesMap);
} catch (Exception $e) { $timeline = []; $timelineUsers = []; $timelineTypes = []; }

// Final stage and conversion
$finalStageCount = 0; $conversionRate = 0.0;
if (count($stages) > 0) {
        $final = end($stages);
        try {
                $fstmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE user_id = ? AND (stage_id = ? OR status = ?)');
                $fstmt->execute([$userId, $final['id'], $final['name']]);
                $finalStageCount = (int)$fstmt->fetchColumn();
                $conversionRate = $leadsTotal > 0 ? round(($finalStageCount / $leadsTotal) * 100, 2) : 0;
        } catch (Exception $e) { }
}

?>

<div class="d-flex">
    <?php include 'includes/sidebar.php'; ?>
    <main class="flex-grow-1 main-content-scroll">
        <div class="container-fluid">
            <div class="row mb-3">
                <div class="col-12 d-flex justify-content-between align-items-center">
                    <h1 class="h3 mb-0">Relatórios</h1>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <div id="reportsWrap">
                        <div id="reportKpis" class="mb-3"></div>

                        <div class="row g-3 mb-3">
                            <div class="col-lg-6">
                                <canvas id="chartLeadsByStage" height="180"></canvas>
                            </div>
                            <div class="col-lg-6">
                                <canvas id="chartLeadsMonthly" height="180"></canvas>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <canvas id="chartConversionDonut" height="160"></canvas>
                            </div>
                            <div class="col-md-4">
                                <canvas id="chartSourcesPie" height="160"></canvas>
                            </div>
                            <div class="col-md-4">
                                <canvas id="chartCreatedClosed" height="160"></canvas>
                            </div>
                        </div>

                        <div id="chartFunnel" class="mb-3"></div>
                        <div id="timeline" class="mb-3"></div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Server data
const REPORT_STAGES = <?php echo json_encode($stages, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_STAGE_COUNTS = <?php echo json_encode($stageCounts, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_MONTHS = <?php echo json_encode($monthsRows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_MONTHS_CLOSED = <?php echo json_encode($monthsClosedRows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_TIMELINE = <?php echo json_encode($timeline, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_TIMELINE_USERS = <?php echo json_encode($timelineUsers, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_TIMELINE_TYPES = <?php echo json_encode($timelineTypes, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_LEADS_TOTAL = <?php echo json_encode($leadsTotal, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_FINAL_STAGE_COUNT = <?php echo json_encode($finalStageCount, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_CONVERSION_RATE = <?php echo json_encode($conversionRate, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_AVG_DAYS_TO_CLOSE = <?php echo json_encode($avgDaysToClose, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_AVG_TICKET = <?php echo json_encode($avgTicket, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
const REPORT_SOURCES = <?php echo json_encode($sources, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

function defaultPalette(i) { const pal = ['#0b6ac1','#3bb273','#ffd24a','#f97316','#7c3aed','#ef4444','#06b6d4','#8b5cf6']; return pal[i%pal.length]; }

function buildLast12Months() {
    const res = []; const now = new Date(); for (let i=11;i>=0;i--){ const d=new Date(now.getFullYear(), now.getMonth()-i,1); res.push(d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')); } return res;
}

function escapeHtml(s){ if(!s) return ''; return String(s).replace(/[&<>"']/g, function(t){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[t]||t; }); }

function renderPyramid(containerId, pairs){
    const parent = document.getElementById(containerId);
    if(!parent) return;
    parent.innerHTML = '';
    // Build an accessible SVG pyramid
    const svgNS = 'http://www.w3.org/2000/svg';
    const width = 700; const rowH = 36; const gap = 8; const maxVal = pairs.length ? Math.max(...pairs.map(p=>p.value||0)) : 1;
    const height = pairs.length * (rowH + gap) + 20;
    const svg = document.createElementNS(svgNS,'svg');
    svg.setAttribute('width', '100%');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Funil de vendas');
    svg.classList.add('pyramid-svg');
    // draw each row
    pairs.forEach((p, idx) => {
        const val = p.value || 0;
        const w = maxVal > 0 ? Math.max(40, Math.round((val / maxVal) * (width - 160))) : 40;
        const x = Math.round((width - w) / 2);
        const y = idx * (rowH + gap) + 10;
        const rect = document.createElementNS(svgNS,'rect');
        rect.setAttribute('x', x);
        rect.setAttribute('y', y);
        rect.setAttribute('width', w);
        rect.setAttribute('height', rowH);
        rect.setAttribute('rx', 6);
        rect.setAttribute('fill', p.color || defaultPalette(idx));
        rect.setAttribute('opacity', 0.95);
        svg.appendChild(rect);
        const txt = document.createElementNS(svgNS,'text');
        txt.setAttribute('x', x + 12);
        txt.setAttribute('y', y + rowH/2 + 6);
        txt.setAttribute('fill', '#fff');
        txt.setAttribute('font-size', '13');
        txt.setAttribute('font-family', 'Inter, Arial, sans-serif');
        txt.textContent = `${p.label} — ${p.value}`;
        svg.appendChild(txt);
    });
    parent.appendChild(svg);
}

function renderReports(){
    const kpiWrap = document.getElementById('reportKpis'); if(!kpiWrap) return;
    // KPIs
    kpiWrap.innerHTML = `
        <div class="row g-3">
            <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Total de Leads</div><div class="h4">${REPORT_LEADS_TOTAL}</div></div></div>
            <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Conversões</div><div class="h4">${REPORT_FINAL_STAGE_COUNT} <small class="text-muted">(${REPORT_CONVERSION_RATE}%)</small></div></div></div>
            <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Tempo médio p/ fechar (dias)</div><div class="h5">${REPORT_AVG_DAYS_TO_CLOSE !== null ? REPORT_AVG_DAYS_TO_CLOSE : '—'}</div></div></div>
            <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Ticket Médio</div><div class="h5">${REPORT_AVG_TICKET !== null ? REPORT_AVG_TICKET : '—'}</div></div></div>
        </div>
    `;

    // stage bar
    const labels = REPORT_STAGES.map(s=>s.name||'Sem nome');
    const colors = REPORT_STAGES.map((s,i)=>(s.color && s.color!=='')?s.color:defaultPalette(i));
    const counts = REPORT_STAGE_COUNTS.map(c=>Number(c)||0);
    const ctx1 = document.getElementById('chartLeadsByStage').getContext('2d');
    if (window._chartLeadsByStage) window._chartLeadsByStage.destroy();
    window._chartLeadsByStage = new Chart(ctx1, { type:'bar', data:{ labels, datasets:[{ label:'Leads', data:counts, backgroundColor:colors }]}, options:{ responsive:true, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true } } } });

    // monthly created/closed line
    const monthsMap = {}; REPORT_MONTHS.forEach(r=>monthsMap[r.ym]=Number(r.cnt));
    const monthsClosedMap = {}; REPORT_MONTHS_CLOSED.forEach(r=>monthsClosedMap[r.ym]=Number(r.cnt));
    const last12 = buildLast12Months();
    const createdData = last12.map(m=>monthsMap[m]||0);
    const closedData = last12.map(m=>monthsClosedMap[m]||0);
    const ctx2 = document.getElementById('chartLeadsMonthly').getContext('2d');
    if (window._chartLeadsMonthly) window._chartLeadsMonthly.destroy();
    window._chartLeadsMonthly = new Chart(ctx2, { type:'line', data:{ labels:last12, datasets:[{ label:'Criados', data:createdData, borderColor:'rgba(11,106,193,0.9)', backgroundColor:'rgba(11,106,193,0.08)', fill:true }, { label:'Fechados', data:closedData, borderColor:'rgba(59,178,115,0.9)', backgroundColor:'rgba(59,178,115,0.08)', fill:true }]}, options:{ responsive:true, plugins:{ legend:{ position:'top' } }, scales:{ y:{ beginAtZero:true } } } });

    // conversion donut
    const ctx3 = document.getElementById('chartConversionDonut').getContext('2d');
    if (window._chartConversionDonut) window._chartConversionDonut.destroy();
    window._chartConversionDonut = new Chart(ctx3, { type:'doughnut', data:{ labels:['Convertidos','Outros'], datasets:[{ data:[REPORT_FINAL_STAGE_COUNT, Math.max(0, REPORT_LEADS_TOTAL - REPORT_FINAL_STAGE_COUNT)], backgroundColor:[ '#3bb273', '#e5e7eb' ] }] }, options:{ responsive:true, plugins:{ legend:{ position:'bottom' } } } });

    // sources pie
    const srcLabels = REPORT_SOURCES.map(s=>s.source||'Sem origem');
    const srcData = REPORT_SOURCES.map(s=>Number(s.cnt)||0);
    const ctx4 = document.getElementById('chartSourcesPie').getContext('2d');
    if (window._chartSourcesPie) window._chartSourcesPie.destroy();
    window._chartSourcesPie = new Chart(ctx4,{ type:'pie', data:{ labels:srcLabels, datasets:[{ data:srcData, backgroundColor: srcLabels.map((_,i)=>defaultPalette(i)) }] }, options:{ responsive:true, plugins:{ legend:{ position:'bottom' } } } });

    // created vs closed (area) - reuse created/closed
    const ctx5 = document.getElementById('chartCreatedClosed').getContext('2d');
    if (window._chartCreatedClosed) window._chartCreatedClosed.destroy();
    window._chartCreatedClosed = new Chart(ctx5, { type:'bar', data:{ labels:last12, datasets:[{ label:'Criados', data:createdData, backgroundColor:'rgba(11,106,193,0.6)' }, { label:'Fechados', data:closedData, backgroundColor:'rgba(59,178,115,0.6)' }] }, options:{ responsive:true, plugins:{ legend:{ position:'top' } }, scales:{ y:{ beginAtZero:true } } } });

    // Funnel pyramid
    const pairs = REPORT_STAGES.map((s,i)=>({ label:s.name||'Sem nome', value:counts[i]||0, color:(s.color&&s.color!=='')?s.color:defaultPalette(i)}));
    pairs.sort((a,b)=>b.value-a.value);
    renderPyramid('chartFunnel', pairs);

    // Timeline with basic filters
    const tl = document.getElementById('timeline'); if(!tl) return; tl.innerHTML = '';
    const filters = document.createElement('div'); filters.className='d-flex gap-2 mb-2 flex-wrap';
    const userSelect = document.createElement('select'); userSelect.className='form-select form-select-sm w-auto'; userSelect.id='tlUserFilter'; const optAll=document.createElement('option'); optAll.value=''; optAll.text='Todos usuários'; userSelect.appendChild(optAll);
    REPORT_TIMELINE_USERS.forEach(u=>{ const o=document.createElement('option'); o.value=u.id; o.text=u.username; userSelect.appendChild(o); }); filters.appendChild(userSelect);
    const typeSelect = document.createElement('select'); typeSelect.className='form-select form-select-sm w-auto'; typeSelect.id='tlTypeFilter'; const oAllT=document.createElement('option'); oAllT.value=''; oAllT.text='Todos tipos'; typeSelect.appendChild(oAllT); REPORT_TIMELINE_TYPES.forEach(t=>{ const o=document.createElement('option'); o.value=t; o.text=t; typeSelect.appendChild(o); }); filters.appendChild(typeSelect);
    const search = document.createElement('input'); search.type='search'; search.className='form-control form-control-sm w-50'; search.placeholder='Buscar no histórico...'; search.id='tlSearch'; filters.appendChild(search);
    tl.appendChild(filters);
    const results = document.createElement('div'); results.id='tlResults'; tl.appendChild(results);

    function drawTimeline(rows){ results.innerHTML=''; if(rows.length===0){ results.innerHTML='<div class="text-muted">Sem atividades.</div>'; return; }
        const groups = {}; rows.forEach(it=>{ const d=it.created_at?it.created_at.substr(0,10):'unknown'; (groups[d]=groups[d]||[]).push(it); }); Object.keys(groups).sort((a,b)=>b.localeCompare(a)).forEach(day=>{ const h=document.createElement('div'); h.className='fw-semibold mt-2 mb-1'; h.textContent = (new Date(day)).toLocaleDateString(); results.appendChild(h); groups[day].forEach(it=>{ const n=document.createElement('div'); n.className='mb-2 p-2 border rounded bg-white'; const who = it.username?`<strong>${escapeHtml(it.username)}</strong>`:''; const type = it.event_type?` <span class="badge rounded-pill bg-secondary ms-2">${escapeHtml(it.event_type)}</span>`:''; n.innerHTML = `<div class="small text-muted">${new Date(it.created_at).toLocaleTimeString()}</div><div>${who}${type} <span class="ms-1">${escapeHtml(it.message)}</span></div>`; results.appendChild(n); }); }); }

    function filterAndRender(){ const uid=document.getElementById('tlUserFilter').value; const ttype=document.getElementById('tlTypeFilter').value; const q=document.getElementById('tlSearch').value.trim().toLowerCase(); const filtered = REPORT_TIMELINE.filter(item=>{ if(uid && String(item.user_id||'')!==String(uid)) return false; if(ttype && String(item.event_type||'')!==String(ttype)) return false; if(q){ const hay = ((item.message||'') + ' ' + (item.username||'') + ' ' + (item.event_type||'')).toLowerCase(); return hay.indexOf(q) !== -1; } return true; }); drawTimeline(filtered); }

    userSelect.addEventListener('change', filterAndRender); typeSelect.addEventListener('change', filterAndRender); search.addEventListener('input', debounce(filterAndRender, 300)); filterAndRender();
}

document.addEventListener('DOMContentLoaded', function(){ try{ renderReports(); }catch(e){ console.error('Render reports failed', e); } });

// debounce
function debounce(fn, wait){ let t=null; return function(){ const args = arguments; clearTimeout(t); t = setTimeout(()=>fn.apply(null,args), wait); } }
</script>

<?php include 'includes/footer.php'; ?>
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });
        // group by date
        const groups = {};
        rows.forEach(item => { const dateKey = item.created_at ? item.created_at.substr(0,10) : 'unknown'; groups[dateKey] = groups[dateKey] || []; groups[dateKey].push(item); });
        const sortedDates = Object.keys(groups).sort((a,b) => b.localeCompare(a));
        results.innerHTML = '';
        if (sortedDates.length === 0) { results.innerHTML = '<div class="text-muted">Sem atividades encontradas.</div>'; return; }
        sortedDates.forEach(dateKey => {
            const header = document.createElement('div'); header.className = 'fw-semibold mt-2 mb-1'; header.textContent = (new Date(dateKey)).toLocaleDateString(); results.appendChild(header);
            groups[dateKey].forEach(item => {
                const d = new Date(item.created_at);
                const node = document.createElement('div'); node.className = 'mb-2 p-2 border rounded bg-white';
                const who = item.username ? `<strong>${escapeHtml(item.username)}</strong>` : '';
                const typeBadge = item.event_type ? `<span class="badge rounded-pill bg-secondary ms-2">${escapeHtml(item.event_type)}</span>` : '';
                node.innerHTML = `<div class="small text-muted">${d.toLocaleTimeString()}</div><div>${who} ${typeBadge} <span class="ms-1">${escapeHtml(item.message)}</span></div>`;
                results.appendChild(node);
            });
        });
    }

    // wire events
    userSelect.addEventListener('change', filterAndRender);
    typeSelect.addEventListener('change', filterAndRender);
    search.addEventListener('input', debounce(filterAndRender, 250));
    // initial render
    filterAndRender();
}
</script>

<?php include 'includes/footer.php'; ?>