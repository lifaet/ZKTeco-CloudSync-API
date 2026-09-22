<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Door Monitor — Attendance Dashboard</title>
<link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
@include('partials.styles')
<style>
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,0.4); } 70% { box-shadow: 0 0 0 10px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
.live-dot { width:10px; height:10px; background:#22c55e; border-radius:50%; display:inline-block; animation: pulse 2s infinite; }
.door-card { background: rgba(255,255,255,0.85); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.18); border-radius: 12px; }
</style>
</head>
<body>

<nav class="navbar-header">
    <button class="sidebar-toggle" id="sidebarToggle" title="Menu"><i class="bi bi-list"></i></button>
    <a href="/" style="font: inherit; color: inherit; text-decoration: none;">
    <div class="logo"><i class="bi bi-clock-history"></i>Attendance Dashboard</div>
    </a>
    <div class="date-time" id="currentDateTime"></div>
</nav>

<div class="sidebar" id="sidebar">
    <a href="/"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="/"><i class="bi bi-calendar-day"></i> Daily</a>
    <a href="/"><i class="bi bi-calendar-month"></i> Monthly</a>
    <a href="/"><i class="bi bi-person-circle"></i> User-wise</a>
    <a href="/"><i class="bi bi-people"></i> User Directory</a>
    <a href="/attendance2" class="active"><i class="bi bi-shield-lock"></i> Door Monitor <span id="doorLiveBadge2" class="badge bg-success ms-auto">0</span></a>
    <hr>
    <a href="#" id="logoutBtn" class="logout-btn"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<div class="content">
    <div class="content-wrapper">
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <h4 class="mb-0"><i class="bi bi-shield-lock"></i> Door Monitor <span class="live-dot ms-2"></span> <small class="text-muted" style="font-size:0.7rem;">Live</small></h4>
            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="badge bg-success" id="doorTotal">0 today</span>
                <a href="/" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Dashboard</a>
            </div>
        </div>

        <!-- Tier 1: Door Monitor Live Pulse — last 5 + total poll 5s -->
        <div id="doorPulse" class="row g-2 mb-3">
            <div class="col-12 col-md-4">
                <div class="card door-card h-100">
                    <div class="card-body py-3 text-center">
                        <div class="small text-muted">Total Today</div>
                        <div class="display-6 fw-bold" id="doorCount" style="color:#0284c7;">0</div>
                        <div class="small text-muted" id="doorPulseTime">—</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-8">
                <div class="card door-card h-100">
                    <div class="card-body py-2">
                        <div class="small fw-bold mb-1"><i class="bi bi-activity"></i> Last 5 Unlocks</div>
                        <div id="doorLastFive" class="d-flex flex-column gap-1">
                            <div class="text-muted small">Loading…</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <table id="attendanceTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Timestamp</th>
                    <th>Punch</th>
                    <th>VerifyID</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<footer>
    <div class="copyright">&copy; {{ date("Y") }} Attendance Management System</div>
    <div class="maintenance" style="margin-left: auto;">Maintenance by <a href="https://lifaet.github.io">ZIM</a></div>
</footer>

<form id="logoutForm" method="POST" action="/logout" style="display:none;">@csrf</form>
<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
let table;
let lastCheckId = 0;
const CHECK_LATEST_INTERVAL_MS = 4000;
$(document).ready(function(){
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });
    // clock + sidebar
    function tick(){ const n=new Date(); $('#currentDateTime').text(n.toLocaleString('en-GB',{weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'})); }
    tick(); setInterval(tick,1000);
    $('#sidebarToggle').click(function(){ $('#sidebar').toggleClass('show'); });
    $(document).click(function(e){ if(!$(e.target).closest('#sidebar, #sidebarToggle').length) $('#sidebar').removeClass('show'); });
    $('#logoutBtn').click(function(e){ e.preventDefault(); $('#logoutForm').submit(); });

    loadUserFromServer();
    initPulse();
});

function loadUserFromServer(){
    $.ajax({ url: '/api/users', method: 'GET', dataType: 'json', cache: false }).done(function(res){
        window.userDirectory = Array.isArray(res) ? res : [];
        initializeDataTable();
    }).fail(function(){ window.userDirectory=[]; initializeDataTable(); });
}
function userLabel(id){
    try{ const sid=parseInt(id,10); const s=(window.userDirectory||[]).find(x=>parseInt(x.id,10)===sid); if(s){ return `<div><strong>${s.name} (${id})</strong><br><small class="text-muted">${s.title||''}${s.department?', '+s.department:''}</small></div>`;} }catch(e){}
    return id;
}
function initializeDataTable(){
    table = $('#attendanceTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: { url: '/api/attendance2-summary', xhrFields: { withCredentials: true } },
        columns: [
            { data: 'user_id', title: 'User', render: function(d,type,row){ if(row.name) return `<div><strong>${row.name} (${d})</strong><br><small class="text-muted">${row.title||''}${row.department?', '+row.department:''}</small></div>`; return userLabel(d); } },
            { data: 'timestamp', title: 'Timestamp' },
            { data: 'punch', render: function(d){ if(d==255||d==='255') return '<span class="badge bg-info text-white">Auto</span>'; if(d) return '<span class="badge bg-warning text-dark">Manual</span>'; return ''; } },
            { data: 'status', title: 'VerifyID', render: function(d){ if(d==1||d==='1') return 'FINID'; if(d==4||d==='4') return 'RFID'; return 'N/A'; } }
        ],
        order: [[1,'desc']],
        lengthMenu: [[50,100,200,1000000],[50,100,200,"All"]],
        pageLength: 50
    });
    $.ajax({ url: '/api/attendance2-latest', method:'GET', dataType:'json', cache:false }).done(function(res){
        lastCheckId = res && res.id ? (parseInt(res.id,10)||0) : 0;
        setInterval(checkNewPunch, CHECK_LATEST_INTERVAL_MS);
    }).fail(function(){ setInterval(checkNewPunch, CHECK_LATEST_INTERVAL_MS); });
}
function initPulse(){
    function refresh(){
        $.getJSON('/api/door-pulse').done(function(res){
            const c = parseInt(res.count||res.recordsTotal||0,10)||0;
            $('#doorCount').text(c);
            $('#doorTotal').text(c+' today');
            $('#doorLiveBadge2').text(c);
            $('#doorPulseTime').text(new Date().toLocaleTimeString());
            const last = res.last_five||[];
            if(!last.length){ $('#doorLastFive').html('<div class="text-muted small">No punches today</div>'); return; }
            let h='';
            last.forEach(function(r){
                const name = r.name ? r.name+' ('+r.user_id+')' : r.user_id;
                h += `<div class="d-flex justify-content-between align-items-center border rounded px-2 py-1" style="background:rgba(2,132,199,0.06);"><span><span class="live-dot" style="width:8px;height:8px;"></span> <strong>${name}</strong></span><span class="small text-muted">${r.time||r.timestamp}</span></div>`;
            });
            $('#doorLastFive').html(h);
        }).fail(function(){
            // fallback via attendance2-summary count
            $.getJSON('/api/attendance2-summary', {start:0,length:1,draw:1}).done(function(res){
                const c = parseInt(res.recordsTotal||0,10)||0;
                $('#doorCount').text(c); $('#doorTotal').text(c+' today'); $('#doorLiveBadge2').text(c);
            });
        });
    }
    refresh();
    setInterval(refresh, 5000);
}
function checkNewPunch(){
    $.ajax({ url:'/api/attendance2-latest', method:'GET', dataType:'json', cache:false, data:{ last_id: lastCheckId } }).done(function(res){
        function extractId(r){ if(!r) return 0; if(r.id) return parseInt(r.id,10)||0; if(r.latest&&r.latest.id) return parseInt(r.latest.id,10)||0; if(r.data&&r.data.id) return parseInt(r.data.id,10)||0; if(Array.isArray(r)&&r.length&&r[0].id) return parseInt(r[0].id,10)||0; return 0; }
        const id = extractId(res);
        if(!lastCheckId||lastCheckId===0){ lastCheckId=id; return; }
        if(id && id>lastCheckId){
            lastCheckId=id;
            const user=res.user_id||res.user||'Unknown';
            const time=res.time||res.timestamp||'';
            const date=res.date||'';
            let msg=`User ${user}`+(time?` recorded at ${time}`:'')+(date?` on ${date}`:''); 
            showToast('New Punch', msg);
            if(table && table.ajax) table.ajax.reload(null,false);
        }
    });
}
function showToast(title, message){
    const toastId='toast-'+Date.now();
    const toastHTML=`<div id="${toastId}" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true"><div class="d-flex"><div class="toast-body"><strong>${title}</strong><br>${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`;
    if($('.toast-container').length===0) $('body').append('<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>');
    $('.toast-container').append(toastHTML);
    const toastElement=new bootstrap.Toast(document.getElementById(toastId));
    toastElement.show();
    setTimeout(()=>{ toastElement.dispose(); $('#'+toastId).remove(); },5000);
}
</script>
</body>
</html>
