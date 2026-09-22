<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Analytics — Attendance Dashboard</title>
<link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
@include('partials.styles')
<style>
.chart-card { background: rgba(255,255,255,0.85); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.18); border-radius: 12px; }
.chart-card .card-header { background: rgba(255,255,255,0.6); border-bottom: 1px solid rgba(100,116,139,0.08); font-weight: 600; }
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
    <a href="/" data-type="monthly"><i class="bi bi-calendar-month"></i> Monthly</a>
    <a href="/" data-type="user"><i class="bi bi-person-circle"></i> User-wise</a>
    <a href="/" data-type="directory"><i class="bi bi-people"></i> User Directory</a>
    @if(env('ATTENDANCE2_ENABLED'))
    <a href="/attendance2" id="attendance2Link"><i class="bi bi-shield-lock"></i> Door Monitor</a>
    @endif
    <hr>
    <a href="#" id="logoutBtn" class="logout-btn"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<div class="content">
    <div class="content-wrapper">
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <h4 class="mb-0"><i class="bi bi-graph-up"></i> Analytics Dashboard</h4>
            <div class="ms-auto d-flex align-items-center gap-2">
                <input type="month" id="ana-month" class="form-control form-control-sm" value="{{ date('Y-m') }}" style="width:150px;">
                <button id="ana-apply" class="btn btn-primary btn-sm">Apply</button>
                <a href="/" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12">
                <div class="card chart-card">
                    <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-bar-chart"></i> Daily Attendance % (this month) <span class="ms-auto small text-muted" id="ana-office"></span></div>
                    <div class="card-body" style="height:280px;"><canvas id="chartPresent"></canvas></div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card chart-card">
                    <div class="card-header"><i class="bi bi-people"></i> Department Avg Work Hours (this month)</div>
                    <div class="card-body" style="height:280px;"><canvas id="chartDept"></canvas></div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card chart-card">
                    <div class="card-header"><i class="bi bi-clock-history"></i> Late Arrivals — Last 30 Days</div>
                    <div class="card-body" style="height:280px;"><canvas id="chartLate"></canvas></div>
                </div>
            </div>
        </div>
    </div>
</div>

<footer>
    <div class="copyright">&copy; {{ date("Y") }} Attendance Management System</div>
    <div class="maintenance" style="margin-left: auto;">Maintenance by <a href="https://lifaet.github.io">ZIM</a></div>
</footer>

<form id="logoutForm" method="POST" action="/logout" style="display:none;">@csrf</form>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
$(function(){
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });
    // clock
    function tick(){ const n=new Date(); $('#currentDateTime').text(n.toLocaleString('en-GB',{weekday:'short',year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'})); }
    tick(); setInterval(tick,1000);
    $('#sidebarToggle').click(function(){ $('#sidebar').toggleClass('show'); });
    $(document).click(function(e){ if(!$(e.target).closest('#sidebar, #sidebarToggle').length) $('#sidebar').removeClass('show'); });
    $('#logoutBtn').click(function(e){ e.preventDefault(); $('#logoutForm').submit(); });

    let c1=null,c2=null,c3=null;
    function load(){
        const month = $('#ana-month').val();
        $.getJSON('/api/analytics', { month }, function(res){
            $('#ana-office').text('Office: ' + (res.office_start||'09:30') + ' – ' + (res.office_end||'17:00'));
            // chart 1: daily present % - fixed: future nulls, no spanGaps, capped 100, maintainAspectRatio false
            const labels1 = res.daily_present.map(x => x.date.slice(8));
            const data1 = res.daily_present.map(x => x.pct);
            if(c1) c1.destroy();
            c1 = new Chart(document.getElementById('chartPresent'), {
                type: 'line',
                data: { labels: labels1, datasets: [{ label: 'Present %', data: data1, borderColor: '#0284c7', backgroundColor: 'rgba(2,132,199,0.12)', fill: true, tension: 0.35, spanGaps: false, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2, spanGaps: false }] },
                options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, scales: { x: { grid: { display: true, color: 'rgba(100,116,139,0.08)' } }, y: { min:0, max:100, ticks: { stepSize: 10, callback: v => v+'%' }, grid: { color: 'rgba(100,116,139,0.08)' } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ctx.parsed.y==null ? 'No data' : ctx.parsed.y+'% (' + (res.daily_present[ctx.dataIndex].present||0) + '/' + (res.daily_present[ctx.dataIndex].total||0) + ')' } } } }
            });
            // chart 2: dept avg hours - fixed maintainAspectRatio
            const deptLabels = res.dept_avg.map(x => x.dept);
            const deptHours = res.dept_avg.map(x => (x.avg_sec/3600).toFixed(2));
            if(c2) c2.destroy();
            c2 = new Chart(document.getElementById('chartDept'), {
                type: 'bar',
                data: { labels: deptLabels, datasets: [{ label: 'Avg Hours', data: deptHours, backgroundColor: 'rgba(2,132,199,0.65)', borderColor: '#0284c7', borderWidth: 1, borderRadius: 4 }]},
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { min:0, max:12, title: { display:true, text:'Hours' }, grid: { color: 'rgba(100,116,139,0.08)' } }, y: { grid: { display: false } } }, plugins: { legend: { display:false }, tooltip: { callbacks: { label: ctx => ctx.raw + ' h (' + res.dept_avg[ctx.dataIndex].avg_hours + ')' } } } }
            });
            // chart 3: late trend - fixed maintainAspectRatio + gaps for weekends/holidays
            const lateLabels = res.late_trend.map(x => x.date.slice(5));
            const lateData = res.late_trend.map(x => x.late);
            if(c3) c3.destroy();
            c3 = new Chart(document.getElementById('chartLate'), {
                type: 'bar',
                data: { labels: lateLabels, datasets: [{ label: 'Late', data: lateData, backgroundColor: 'rgba(234,179,8,0.75)', borderColor: '#a16207', borderWidth: 1, borderRadius: 3, skipNull: true }]},
                options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, autoSkip: true, maxTicksLimit: 15 } }, y: { beginAtZero:true, ticks:{ stepSize:1, precision:0 }, grid: { color: 'rgba(100,116,139,0.08)' } } }, plugins: { legend: { display:false }, tooltip: { callbacks: { label: ctx => ctx.parsed.y==null ? 'Weekend/Holiday' : ctx.parsed.y + ' late' } } } }
            });
        }).fail(function(xhr){ console.error(xhr); });
    }
    $('#ana-apply').click(load);
    $('#ana-month').change(load);
    load();
});
</script>
</body>
</html>
