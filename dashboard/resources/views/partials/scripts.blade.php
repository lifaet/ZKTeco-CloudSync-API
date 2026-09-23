<script>
let table;
let currentType = 'dashboard';
let lastCheckId = 0; // last known entry ID
// Polling interval (ms) for /api/check-latest. Lower values = more frequent checks.
// Be careful lowering too far: very frequent polling increases DB/load. Default 2000ms = 2s.
const CHECK_LATEST_INTERVAL_MS = 4000;

// Update current date and time in header
function updateDateTime() {
    const now = new Date();
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
    document.getElementById('currentDateTime').textContent = now.toLocaleDateString('en-US', options);
}
updateDateTime();
setInterval(updateDateTime, 1000); // update every second

// Mobile sidebar toggle
$('#sidebarToggle').click(function(e){
    e.preventDefault();
    $('#sidebar').toggleClass('active');
});

// Close sidebar when clicking on a menu item
$('.sidebar a').click(function(e){
    if ($(window).width() <= 768) {
        $('#sidebar').removeClass('active');
    }
});

// User directory will be loaded from the server; start empty.
window.userDirectory = [];

function loadUsersIntoSelect() {
    const sel = $('#filter-user-select');
    sel.empty().append('<option value="">All Users</option>');

    // populate with client-side user directory first (so names show)
    (window.userDirectory || []).forEach(s => {
        sel.append(`<option value="${s.id}">${s.id} - ${s.name}</option>`);
    });

    // then try to fetch additional users from server and append any missing ones
    $.getJSON('/api/users').done(function(users){
        const existing = new Set((window.userDirectory || []).map(s => String(s.id)));
        users.forEach(u => {
            const uid = String(u.id || u.user_id || u);
            if (!existing.has(uid)) {
                sel.append(`<option value="${uid}">${uid}</option>`);
                existing.add(uid);
            }
        });
    }).fail(function(){
        // endpoint not available -> we already populated from userDirectory
    });
}

function navigateDay(offset) {
    const d = new Date($('#filter-date').val());
    d.setDate(d.getDate() + offset);
    $('#filter-date').val(d.toISOString().slice(0,10));
}

function navigateMonth(offset) {
    const cur = $('#filter-month').val();
    if (!cur) return;
    const parts = cur.split('-');
    const y = parseInt(parts[0],10);
    const m = parseInt(parts[1],10) - 1;
    const dt = new Date(y, m + offset, 1);
    const mm = String(dt.getMonth()+1).padStart(2,'0');
    $('#filter-month').val(`${dt.getFullYear()}-${mm}`);
}

function updateFilters(type){
    currentType = type;
    // sync type select
    $('#filter-type').val(type);

    // show/hide inputs
    const isDailyLike = (type === 'daily' || type === 'dashboard');
    $('#filter-date').toggleClass('d-none', !isDailyLike);
    $('#filter-month').toggleClass('d-none', type !== 'monthly');
    $('#filter-user').toggleClass('d-none', type !== 'user');
    $('#filter-user-select').toggleClass('d-none', type !== 'user');
    $('#filter-dept').toggleClass('d-none', type !== 'daily'); // dept only for log table
    // dashboard vs daily: dashboard = charts+summary, daily = table+summary, others hide both
    if (type === 'dashboard') {
        $('#dashboardSection').removeClass('d-none');
        $('#summaryCards').removeClass('d-none');
        $('#dashboardCharts').removeClass('d-none');
        $('#attendanceSection').addClass('d-none');
        $('#userSection').addClass('d-none');
        $('#copy-daily, #export-daily, #formatted-copy').addClass('d-none');
        $('#addAttendanceBtn').addClass('d-none');
        $('.colvis-dropdown').addClass('d-none');
    } else if (type === 'daily') {
        $('#dashboardSection').removeClass('d-none');
        $('#summaryCards').removeClass('d-none');
        $('#dashboardCharts').addClass('d-none');
        $('#attendanceSection').removeClass('d-none');
        $('#userSection').addClass('d-none');
        // copy/export handled separately via drawCallback but ensure visible
        $('#addAttendanceBtn').removeClass('d-none');
        $('.colvis-dropdown').removeClass('d-none');
    } else if (type === 'directory') {
        $('#dashboardSection').addClass('d-none');
        $('#attendanceSection').addClass('d-none');
        $('#userSection').removeClass('d-none');
    } else {
        // monthly / user : table only, no dashboard
        $('#dashboardSection').addClass('d-none');
        $('#attendanceSection').removeClass('d-none');
        $('#userSection').addClass('d-none');
    }
    if (type === 'dashboard') {
        // fetch dashboard summary (present/absent/late/avg) for today
        const dashDate = $('#filter-date').val() || new Date().toISOString().slice(0,10);
        $.getJSON('/api/attendance-summary', { type: 'daily', date: dashDate, dept: '', draw: 1, start: 0, length: 1 }).done(function(j){
            if (j && j.summary) {
                $('#sumPresent').text(j.summary.present ?? '—');
                $('#sumAbsent').text(j.summary.absent ?? '—');
                $('#sumLate').text(j.summary.late ?? '—');
                $('#sumAvg').text(j.summary.avg_work ?? '—');
                $('#dashOffice').text('Office: ' + (j.summary.office_start||'09:30') + ' – ' + (j.summary.office_end||'17:00'));
            }
        });
        if (typeof loadDashboardCharts === 'function') loadDashboardCharts();
    }

    // show prev/next controls only for relevant types
    $('.prev-day, .next-day').toggle(type === 'daily' || type === 'dashboard');
    $('.prev-month, .next-month').toggle(type === 'monthly');

    // copy/export only for daily
    $('#copy-daily, #export-daily, #formatted-copy').toggleClass('d-none', type !== 'daily');

    // "Last Day Last Punch" and Flag only for daily (not monthly/user/dashboard)
    try { if (typeof table !== 'undefined' && table && table.column) table.column(2).visible(type === 'daily'); } catch(e) {}
    try { if (typeof table !== 'undefined' && table && table.column) table.column(6).visible(type === 'daily'); } catch(e) {}

    // hide apply-filter for user page
    $('#apply-filter').toggleClass('d-none', type === 'directory');

    if (type === 'user') loadUsersIntoSelect();
    if (type === 'daily') loadDeptFilter();
}
// --- UI Improvements helpers ---

function updateLastWorkingDayChip(lastWorkDay){
    const chip = $('#lastWorkingDayChip');
    if (!lastWorkDay) { chip.addClass('d-none'); return; }
    try {
        const d = new Date(lastWorkDay + 'T00:00:00');
        const txt = d.toLocaleDateString(undefined, { month:'short', day:'numeric', year:'numeric' });
        chip.html('<i class="bi bi-calendar-week"></i> Last work: ' + txt).removeClass('d-none');
    } catch(e){
        chip.html('<i class="bi bi-calendar-week"></i> Last work: ' + lastWorkDay).removeClass('d-none');
    }
}

function loadDeptFilter(){
    const sel = $('#filter-dept');
    if (!sel.length) return;
    const depts = [...new Set((window.userDirectory || []).map(u => (u.department || u.dept || '').trim()).filter(Boolean))].sort();
    const cur = sel.val();
    sel.find('option:not(:first)').remove();
    depts.forEach(d => sel.append(`<option value="${$('<div>').text(d).html()}">${$('<div>').text(d).html()}</option>`));
    if (cur) sel.val(cur);
}

// Dept filter now server-side via d.dept — client filter removed

function initColvis(){
    const menu = $('#colvisMenu');
    if (!menu.length || !table) return;
    const cols = [
        { idx: 2, label: 'Last Day Punch' },
        { idx: 7, label: 'Type' },
        { idx: 8, label: 'VerifyID' }
    ];
    menu.empty();
    cols.forEach(c => {
        const visible = (()=>{ try{ return table.column(c.idx).visible(); }catch(e){return true;} })();
        menu.append(`<label><input type="checkbox" data-col="${c.idx}" ${visible?'checked':''}> ${c.label}</label>`);
    });
}

function showSkeleton(){
    const tbody = $('#attendanceTable tbody');
    if (!tbody.length) return;
    tbody.empty();
    for(let i=0;i<5;i++){
        tbody.append(`<tr class="skeleton-row"><td><span class="skeleton" style="width:60%"></span></td><td><span class="skeleton" style="width:50%"></span></td><td><span class="skeleton" style="width:70%"></span></td><td><span class="skeleton" style="width:40%"></span></td><td><span class="skeleton" style="width:40%"></span></td><td><span class="skeleton" style="width:50%"></span></td><td><span class="skeleton" style="width:30%"></span></td><td><span class="skeleton" style="width:30%"></span></td><td><span class="skeleton" style="width:30%"></span></td><td><span class="skeleton" style="width:20%"></span></td></tr>`);
    }
}

function validatePunchTimes(){
    const fp = ($('#edit-first-punch').val() || '').trim();
    const lp = ($('#edit-last-punch').val() || '').trim();
    const err = $('#edit-punch-error');
    if (fp && lp && fp > lp) { err.removeClass('d-none'); return false; }
    err.addClass('d-none'); return true;
}
function validateAddPunchTimes(){
    const fp = ($('#add-first-punch').val() || '').trim();
    const lp = ($('#add-last-punch').val() || '').trim();
    const err = $('#add-punch-error');
    if (fp && lp && fp > lp) { err.removeClass('d-none'); return false; }
    err.addClass('d-none'); return true;
}

// Sidebar persist + door badge
function initSidebarPersist(){
    const key = 'zkteco_sidebar_collapsed';
    try {
        const collapsed = localStorage.getItem(key) === '1';
        if (collapsed && $(window).width() > 768) {
            $('#sidebar').addClass('collapsed');
            $('.content').css('margin-left', '60px');
            $('footer .copyright').css('margin-left', '60px');
        }
    } catch(e){}
    // double-click sidebar to collapse (desktop)
    $('#sidebar').on('dblclick', function(e){
        if ($(window).width() <= 768) return;
        $(this).toggleClass('collapsed');
        const isCollapsed = $(this).hasClass('collapsed');
        try{ localStorage.setItem(key, isCollapsed?'1':'0'); }catch(e){}
        if (isCollapsed) {
            $('.content').css('margin-left', '60px');
            $('footer .copyright').css('margin-left', '60px');
            $(this).find('a').each(function(){ const txt=$(this).text().trim(); $(this).attr('title', txt); });
        } else {
            $('.content').css('margin-left', '180px');
            $('footer .copyright').css('margin-left', '180px');
        }
    });
}

function initDoorBadge(){
    const badge = $('#doorLiveBadge');
    if (!badge.length) return;
    function refresh(){
        // attendance2 may not be enabled — fail silently
        $.getJSON('/api/door-pulse').done(function(res){
            const count = (res && (res.count ?? res.recordsTotal ?? res.total ?? (res.data && res.data.length) ?? res.count)) || 0;
            if (count > 0) badge.text(count + ' today').show();
            else badge.text('0 today').show();
        }).fail(function(){
            $.getJSON('/api/attendance2-summary', { start:0, length:1, draw:1 }).done(function(res){ const c=(res&&res.recordsTotal)||0; badge.text(c+' today').show(); }).fail(function(){
                $.getJSON('/api/check-latest', { type: 'door' }).done(function(res){
                    const c = res && res.count ? res.count : null;
                    if (c !== null) badge.text(c + ' today').show();
                }).fail(function(){});
            });
        });
    }
    refresh();
    setInterval(refresh, 30000);
}



let dashC1=null, dashC2=null, dashC3=null;
function loadDashboardCharts(){
    if (currentType !== 'dashboard') return;
    const dateVal = $('#filter-date').val() || new Date().toISOString().slice(0,10);
    const month = dateVal.slice(0,7);
    if (loadDashboardCharts._lastMonth === month && loadDashboardCharts._loading) return;
    loadDashboardCharts._lastMonth = month;
    loadDashboardCharts._loading = true;
    $.getJSON('/api/analytics', { month }, function(res){
        loadDashboardCharts._loading = false;
        $('#dashOffice').text('Office: ' + (res.office_start||'09:30') + ' – ' + (res.office_end||'17:00'));
        // present %
        const labels1 = res.daily_present.map(x => x.date.slice(8));
        const data1 = res.daily_present.map(x => x.pct);
        if(dashC1) try{ dashC1.destroy(); }catch(e){}
        const c1el = document.getElementById('dashChartPresent');
        if(c1el) dashC1 = new Chart(c1el, {
            type: 'line',
            data: { labels: labels1, datasets: [{ label: 'Present %', data: data1, borderColor: '#0284c7', backgroundColor: 'rgba(2,132,199,0.12)', fill: true, tension: 0.35, spanGaps: false, pointRadius: 2, pointHoverRadius: 4, borderWidth: 2, clip: false }] },
            options: { responsive: true, maintainAspectRatio: false, layout: { padding: { top: 8, right: 6 } }, interaction: { intersect: false, mode: 'index' }, scales: { x: { grid: { color: 'rgba(100,116,139,0.08)' } }, y: { min:0, max:100, grace: '4%', ticks: { stepSize: 20, callback: v => v+'%' }, grid: { color: 'rgba(100,116,139,0.08)' } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ctx.parsed.y==null ? 'No data' : ctx.parsed.y+'% (' + (res.daily_present[ctx.dataIndex].present||0) + '/' + (res.daily_present[ctx.dataIndex].total||0) + ')' } } } }
        });
        // dept avg
        const deptLabels = res.dept_avg.map(x => x.dept);
        const deptHours = res.dept_avg.map(x => (x.avg_sec/3600).toFixed(2));
        if(dashC2) try{ dashC2.destroy(); }catch(e){}
        const c2el = document.getElementById('dashChartDept');
        if(c2el) dashC2 = new Chart(c2el, {
            type: 'bar',
            data: { labels: deptLabels, datasets: [{ label: 'Avg Hours', data: deptHours, backgroundColor: 'rgba(2,132,199,0.65)', borderColor: '#0284c7', borderWidth: 1, borderRadius: 4 }]},
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { min:0, max:12, title: { display:true, text:'Hours' }, grid: { color: 'rgba(100,116,139,0.08)' } }, y: { grid: { display: false } } }, plugins: { legend: { display:false }, tooltip: { callbacks: { label: ctx => ctx.raw + ' h (' + res.dept_avg[ctx.dataIndex].avg_hours + ')' } } } }
        });
        // late
        const lateLabels = res.late_trend.map(x => x.date.slice(5));
        const lateData = res.late_trend.map(x => x.late);
        if(dashC3) try{ dashC3.destroy(); }catch(e){}
        const c3el = document.getElementById('dashChartLate');
        if(c3el) dashC3 = new Chart(c3el, {
            type: 'bar',
            data: { labels: lateLabels, datasets: [{ label: 'Late', data: lateData, backgroundColor: 'rgba(234,179,8,0.75)', borderColor: '#a16207', borderWidth: 1, borderRadius: 3, skipNull: true }]},
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45, autoSkip: true, maxTicksLimit: 15 } }, y: { beginAtZero:true, ticks:{ stepSize:1, precision:0 }, grid: { color: 'rgba(100,116,139,0.08)' } } }, plugins: { legend: { display:false }, tooltip: { callbacks: { label: ctx => ctx.parsed.y==null ? 'Weekend/Holiday' : ctx.parsed.y + ' late' } } } }
        });
    }).fail(function(){ loadDashboardCharts._loading = false; });
}
function showToast(title, message){
    const isError = /error|fail|invalid/i.test(title);
    const toastId = 'toast-' + Date.now();
    const bg = isError ? 'text-bg-danger' : 'text-bg-success';
    const toastHTML = `
      <div id="${toastId}" class="toast align-items-center ${bg} border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body">
            <strong>${title}</strong><br>${message}
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
      </div>`;
        if ($('.toast-container').length === 0) {
                $('body').append('<div class="toast-container position-fixed p-3" style="z-index: 2100; top:70px; right:0;"></div>');
        }
        $('.toast-container').append(toastHTML);
    const delay = isError ? 5000 : 3000;
    const toastEl = new bootstrap.Toast(document.getElementById(toastId), { delay: delay, autohide: true });
    toastEl.show();
    // auto remove from DOM after hide
    document.getElementById(toastId).addEventListener('hidden.bs.toast', function(){ $(this).remove(); });
}

function checkNewPunch(){
    // Use $.ajax with cache:false to avoid cached responses from proxies
    $.ajax({
        url: '/api/check-latest',
        method: 'GET',
        dataType: 'json',
        cache: false,
        data: { last_id: lastCheckId }
    }).done(function(res){
        // Defensive extraction: API might return {id:...} or {data:{id:...}} or {latest:{id:...}}
        function extractId(r){
            if (!r) return 0;
            if (r.id) return parseInt(r.id,10) || 0;
            if (r.latest && r.latest.id) return parseInt(r.latest.id,10) || 0;
            if (r.data && r.data.id) return parseInt(r.data.id,10) || 0;
            // try first element if array
            if (Array.isArray(r) && r.length && r[0].id) return parseInt(r[0].id,10) || 0;
            return 0;
        }

        const id = extractId(res);
        console.debug('check-latest response', res, 'extractedId', id, 'lastCheckId', lastCheckId);

        // If we haven't initialized lastCheckId yet (fresh page or initial failure),
        // set it to the server-provided id but DO NOT show a toast — this prevents
        // showing the current latest row as "new" on every poll when the server
        // falls back to returning the most recent row.
        if (!lastCheckId || lastCheckId === 0) {
            lastCheckId = id;
            return;
        }

        if (id && id > lastCheckId) {
            lastCheckId = id;
            // Build a friendly message. If specific fields exist, show them; otherwise show a generic notice.
            const user = res.user_id || res.user || null;
            const time = res.time || res.timestamp || null;
            const date = res.date || null;
            let message = '';
            if (user || time || date) {
                // prefer a punch-style message when details available
                message = `User ${user || 'Unknown'}` + (time ? ` recorded at ${time}` : '') + (date ? ` on ${date}` : '');
            } else {
                message = `New record added (id: ${id})`;
            }
            showToast('New Data', message);
            if (table && table.ajax && typeof table.ajax.reload === 'function') {
                table.ajax.reload(null, false); // reload data silently
            }
        }
    }).fail(function(xhr, status, err){
        // ignore 204/no-content or silent failures, but log others for debugging
        if (xhr && xhr.status && xhr.status !== 204) {
            console.debug('check-latest request failed', xhr.status, status, err);
        }
    });
}

$(document).ready(function(){
    // include CSRF token for all AJAX POST requests
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    // if any AJAX returns 401, redirect to login
    $(document).ajaxError(function(event, jqxhr){
        if (jqxhr && jqxhr.status === 401) {
            window.location = '/login';
        }
    });

    table = $('#attendanceTable').DataTable({
        serverSide: true,
        processing: true,
        language: {
            emptyTable: `<div class="empty-state"><div class="empty-icon"><i class="bi bi-inbox"></i></div><div class="empty-title">No punches for this filter</div><div class="empty-sub">Try another date — <span class="js-empty-date"></span> — or check User Directory</div></div>`,
            processing: '<div class="d-flex align-items-center gap-2"><div class="spinner-border spinner-border-sm text-primary" role="status"></div> Loading…</div>'
        },
        ajax: {
            url: '/api/attendance-summary',
            data: function(d){
                d.type = currentType;
                d.date = $('#filter-date').val();
                d.month = $('#filter-month').val();
                d.user = $('#filter-user').val();
                d.dept = $('#filter-dept').val() || '';
            },
            error: function(xhr, textStatus, error){
                console.error('DataTables ajax error', xhr && xhr.status, textStatus, error);
                if (xhr && xhr.status === 401) return; // handled by ajaxError
                const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to load attendance data' + (xhr && xhr.status ? ' ('+xhr.status+')' : '');
                // avoid spamming on every poll
                if (!window._dtErrorShown) {
                    showToast('Error', msg);
                    window._dtErrorShown = true;
                    setTimeout(()=> window._dtErrorShown=false, 8000);
                }
            }
        },
        columns: [
            { data: 'user_id', render: function(data, type, row) {
                // try to find user info from client-side directory
                try {
                    var sid = parseInt(data, 10);
                    var s = (window.userDirectory || []).find(x => parseInt(x.id,10) === sid);
                    if (s) {
                        // show two-line format: ID Name then Designation, Department (skip missing parts gracefully)
                        var dept = s.department || s.dept || '';
                        var title = s.title || '';
                        var subLine = title + (dept ? (title ? ', ' : '') + dept : '');
                        return `<div><strong>${s.name} (${data})</strong>${subLine ? '<br><small class="text-muted">' + subLine + '</small>' : ''}</div>`;
                    }
                } catch (e) { }
                return data;
            } },
            { data: 'date' },
            { data: 'prev_punch', orderable: false, defaultContent: '', render: function(data, type, row) {
                if (!data) return '<span class="text-muted">—</span>';
                var dateLabel = '';
                if (row.prev_date) {
                    try {
                        var d = new Date(row.prev_date + 'T00:00:00');
                        dateLabel = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                    } catch(e) { dateLabel = row.prev_date; }
                }
                // stale = punch is older than the last working day (missed that day)
                var cls = row.prev_stale ? 'text-warning' : 'text-muted';
                var title = row.prev_stale ? ' title="Older than the last working day"' : '';
                return '<div' + title + '>' + data + (dateLabel ? '<br><small class="' + cls + '">' + dateLabel + (row.prev_stale ? ' ⚠' : '') + '</small>' : '') + '</div>';
            } },
            { data: 'first_punch', render: function(data, type, row) {
                if (row.is_absent) {
                    return '<strong style="color: #dc2626;">' + data + '</strong>';
                }
                return data;
            } },
            { data: 'last_punch', render: d => d ? d : '' },
            { data: 'work_time', render: d => d ? d : '' },
            { data: 'late_status', render: function(d, type, row){
                if (row.is_absent) return '<span class="badge bg-danger">Absent</span>';
                if (d === 'late') return '<span class="badge bg-warning text-dark">Late</span>';
                if (d === 'early') return '<span class="badge bg-info">Early</span>';
                return '<span class="badge bg-success">On Time</span>';
            }},
            { data: 'punch', render: function(d, type, row) {
                // treat numeric/string 255 as automatic machine-sourced
                if (d == 255 || d === '255') return '<span class="badge bg-info text-white">Auto</span>';
                if (d) return '<span class="badge bg-warning text-dark">Manual</span>';
                return '';
            } },
            { data: 'status', render: function(data, type, row) {
                // Map status codes to short labels for the dashboard
                // 1 -> FP, 4 -> RF, otherwise show 'Other'
                if (data == 1 || data === '1') return 'FINID';
                if (data == 4 || data === '4') return 'RFID';
                return 'N/A';
            } },
            {
                data: null,
                render: function(data, type, row) {
                    if (row.is_absent) {
                        return ''; // No actions for absent rows
                    }
                    return `
                        <button class="btn btn-sm btn-outline-primary edit-btn" data-user="${row.user_id}" data-date="${row.date}">
                            <i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger delete-btn" data-user="${row.user_id}" data-date="${row.date}">
                            <i class="bi bi-trash"></i></button>
                    `;
                }
            }
        ],
        order: [[1, 'desc']],
        lengthMenu: [[25, 50, 100, 1000000], [25, 50, 100, "All"]],
        pageLength: 25,
        createdRow: function(row, data){
            if (data.is_absent) $(row).addClass('row-absent');
            else if (data.late_flag) $(row).addClass('row-late');
            else if (data.work_time) {
                const parts = String(data.work_time).split(':');
                const h = parseInt(parts[0]||0,10), m = parseInt(parts[1]||0,10);
                const mins = h*60 + m;
                if (mins > 0 && mins < 480) $(row).addClass('row-short');
            }
        },
        drawCallback: function(settings){
            try {
                const api = this.api();
                const json = api.ajax.json();
                if (json && json.last_working_day) updateLastWorkingDayChip(json.last_working_day);
                // update empty date hint
                const d = $('#filter-date').val() || '';
                $('.js-empty-date').text(d);
                // summary for daily table (dashboard summary is fetched separately)
                if (json && json.summary && currentType === 'daily') {
                    $('#sumPresent').text(json.summary.present ?? '—');
                    $('#sumAbsent').text(json.summary.absent ?? '—');
                    $('#sumLate').text(json.summary.late ?? '—');
                    $('#sumAvg').text(json.summary.avg_work ?? '—');
                    // keep summary visible (dashboardSection handles visibility)
                }
                // dashboard charts are handled via loadDashboardCharts for dashboard type, not here
            } catch(e){}
        },
        preDrawCallback: function(settings){
            // show skeleton on first draw while processing
            const api = this.api();
            // DataTables shows processing indicator, skeleton is optional
        },
        initComplete: function(settings, json){
            if (json && json.last_working_day) updateLastWorkingDayChip(json.last_working_day);
            initColvis();
            loadDeptFilter();
            // hook dept filter
            $('#filter-dept').on('change', function(){ table.draw(); });
        }
    });


    // --- UI Improvements: filter type select, presets, colvis, sidebar, door badge ---
    // Filter type select syncs with hash/sidebar
    $('#filter-type').on('change', function(){
        const v = $(this).val();
        if (v) window.location.hash = v;
    });
    // Dept filter
    $('#filter-dept').on('change', function(){ if (table) table.draw(); });

    // Preset buttons
    $('.preset-btn').on('click', function(){
        const preset = $(this).data('preset');
        const today = new Date();
        if (preset === 'today') {
            const d = today.toISOString().slice(0,10);
            $('#filter-date').val(d);
            $('#filter-type').val('daily');
            window.location.hash = 'daily';
            updateFilters('daily');
            table.ajax.reload();
        } else if (preset === 'yesterday') {
            const d = new Date(today); d.setDate(d.getDate()-1);
            const ds = d.toISOString().slice(0,10);
            $('#filter-date').val(ds);
            $('#filter-type').val('daily');
            window.location.hash = 'daily';
            updateFilters('daily');
            table.ajax.reload();
        } else if (preset === 'thisMonth') {
            const m = today.toISOString().slice(0,7);
            $('#filter-month').val(m);
            $('#filter-type').val('monthly');
            window.location.hash = 'monthly';
            updateFilters('monthly');
            table.ajax.reload();
        }
    });

    // Column visibility — fixed position to avoid clipping in scrollable filters
    $('#colvisBtn').on('click', function(e){
        e.stopPropagation();
        const btn = $(this);
        const menu = $('#colvisMenu');
        if (menu.hasClass('show')) { menu.removeClass('show'); return; }
        const rect = btn[0].getBoundingClientRect();
        menu.css({ top: (rect.bottom + 6) + 'px', right: (window.innerWidth - rect.right) + 'px', left: 'auto' }).addClass('show');
    });
    $(document).on('click', function(e){
        if (!$(e.target).closest('.colvis-dropdown').length) $('#colvisMenu').removeClass('show');
    });
    $(window).on('resize scroll', function(){ $('#colvisMenu').removeClass('show'); });
    $('#colvisMenu').on('change', 'input[type="checkbox"]', function(){
        const col = parseInt($(this).data('col'),10);
        const vis = $(this).is(':checked');
        try { table.column(col).visible(vis); } catch(e){}
    });

    // Punch validation live
    $('#edit-first-punch, #edit-last-punch').on('change input', validatePunchTimes);
    $('#add-first-punch, #add-last-punch').on('change input', validateAddPunchTimes);

    // Init sidebar persist and door badge
    initSidebarPersist();
    initDoorBadge();

    // initialize filter UI for current view via URL hash (deep-linking)
    // Supported hashes: #daily (default), #monthly, #user, #directory
    function applyView(view) {
        const selected = view || 'dashboard';
        currentType = selected;
        $('.sidebar a').removeClass('active');
        // mark matching sidebar item active
        $('.sidebar a').each(function(){ if ($(this).data('type') === selected) $(this).addClass('active'); });
        // show/hide main sections - dashboard vs daily vs others
        if (selected === 'directory') {
            $('#dashboardSection').addClass('d-none');
            $('#attendanceSection').addClass('d-none');
            $('#userSection').removeClass('d-none');
        } else if (selected === 'dashboard') {
            // handled in updateFilters, but ensure correct here too
            $('#userSection').addClass('d-none');
            // dashboardSection visibility handled in updateFilters
        } else {
            $('#userSection').addClass('d-none');
            // attendanceSection visibility handled in updateFilters
        }
        updateFilters(selected);
        // reload table only for table views, not dashboard/directory
        if (selected !== 'directory' && selected !== 'dashboard' && table && table.ajax && typeof table.ajax.reload === 'function') {
            table.ajax.reload();
            // Delay adjust so DOM reflow/animations complete before recalculating widths
            setTimeout(function(){
                try { if (table && table.columns && typeof table.columns.adjust === 'function') { table.columns.adjust().draw(false); } } catch(e) { console.debug('columns.adjust failed', e); }
            }, 120);
        }
    }

    // set view from current hash (strip leading '#') - default dashboard (first menu)
    function setViewFromHash() {
        const hash = (window.location.hash || '').replace(/^#/, '');
        applyView(hash || 'dashboard');
    }

    // clicking sidebar items updates the URL hash — hashchange handles the actual view change
    $('.sidebar a').click(function(e){
        const selected = $(this).data('type');
        if (typeof selected !== 'undefined') {
            e.preventDefault();
            // update hash which creates a history entry and fires hashchange
            window.location.hash = selected;
        }
        // if link has no data-type, allow normal navigation
    });

    // respond to back/forward and manual hash changes
    window.addEventListener('hashchange', setViewFromHash);

    // initialize on load
    setViewFromHash();

    // Prev/Next handlers
    $('.prev-day').click(function(){ navigateDay(-1); table.ajax.reload(); });
    $('.next-day').click(function(){ navigateDay(1); table.ajax.reload(); });
    $('.prev-month').click(function(){ navigateMonth(-1); table.ajax.reload(); });
    $('.next-month').click(function(){ navigateMonth(1); table.ajax.reload(); });

    $('#apply-filter').click(function(){
        if (!$('#filter-user-select').hasClass('d-none')){
            $('#filter-user').val($('#filter-user-select').val());
        }
        table.ajax.reload();
    });

    // Logout button handling: submit hidden POST form to /logout
    $('#logoutBtn').click(function(e){
        e.preventDefault();
        $('#logoutForm').submit();
    });

    $('#apply-filter').click(function(){ table.ajax.reload(); });

    // first check last punch (store numeric id) and only start polling after we have attempted
    $.ajax({ url: '/api/check-latest', method: 'GET', dataType: 'json', cache: false })
        .done(function(res){
            lastCheckId = res && res.id ? (parseInt(res.id, 10) || 0) : lastCheckId;
            // start polling after initial successful fetch
            setInterval(checkNewPunch, CHECK_LATEST_INTERVAL_MS);
        }).fail(function(xhr, status){
            console.debug('initial check-latest failed', status, xhr && xhr.status);
            // even on failure, start polling so we can recover later
            setInterval(checkNewPunch, CHECK_LATEST_INTERVAL_MS);
        });

    // Handle Edit button click
    $('#attendanceTable').on('click', '.edit-btn', function() {
        const row = table.row($(this).closest('tr')).data();
        $('#edit-user-id').val(row.user_id);
        $('#edit-date').val(row.date);
        $('#edit-first-punch').val(row.first_punch);
        $('#edit-last-punch').val(row.last_punch);
        // store original punch value (machine/preset marker like 255)
        $('#edit-original-punch').val(row.punch);
        $('#edit-status').val(row.status);
        $('#editModal').modal('show');
    });

    // Handle Save Edit
    $('#saveEdit').click(function() {
        if (!validatePunchTimes()) { showToast('Error', 'First punch cannot be later than last punch'); return; }
        // Determine punch to send: if the original punch was 255 (machine auto),
        // mark it as manual when a human edits.
        const originalPunch = String($('#edit-original-punch').val() || '');
        const punchToSend = (originalPunch === '255' || originalPunch == 255) ? 'manual' : originalPunch;

        const data = {
            user_id: $('#edit-user-id').val(),
            date: $('#edit-date').val(),
            first_punch: $('#edit-first-punch').val(),
            last_punch: $('#edit-last-punch').val(),
            punch: punchToSend,
            status: $('#edit-status').val()
        };

        $.ajax({
            url: '/api/attendance/update',
            method: 'POST',
            data: data,
            success: function(response) {
                console.debug('saveEdit: ajax success', response);
                $('#editModal').modal('hide');
                showToast('Success', 'Attendance record updated successfully');
                // Delay slightly so modal hide animation completes, then reload table robustly
                setTimeout(function(){
                    try {
                        if (table && table.ajax && typeof table.ajax.reload === 'function') {
                            console.debug('saveEdit: calling table.ajax.reload via instance');
                            table.ajax.reload(null, false);
                            try { if (table.columns && typeof table.columns.adjust === 'function') table.columns.adjust().draw(false); } catch(e){}
                            return;
                        }
                    } catch(e) { console.debug('saveEdit: table.reload failed', e); }
                    try {
                        console.debug('saveEdit: calling fallback DataTable selector reload');
                        $('#attendanceTable').DataTable().ajax.reload(null, false);
                        $('#attendanceTable').DataTable().columns.adjust().draw(false);
                    } catch(e) { console.debug('saveEdit: fallback reload failed', e); }
                }, 150);
            },
            error: function(xhr) {
                const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to update attendance record';
                showToast('Error', msg);
            }
        });
    });

    // Handle Delete button click
    $('#attendanceTable').on('click', '.delete-btn', function() {
        const row = table.row($(this).closest('tr')).data();
        $('#delete-user-id').val(row.user_id);
        $('#delete-date').val(row.date);
        const details = `User ${row.user_id} — ${row.date} — ${row.first_punch || '—'} → ${row.last_punch || '—'}`;
        $('#delete-details').text(details).removeClass('d-none');
        $('#deleteModal').modal('show');
    });

    // Handle Delete Confirmation
    $('#confirmDelete').click(function() {
        const data = {
            user_id: $('#delete-user-id').val(),
            date: $('#delete-date').val()
        };

        $.ajax({
            url: '/api/attendance/delete',
            method: 'POST',
            data: data,
            success: function(response) {
                console.debug('confirmDelete: ajax success', response);
                $('#deleteModal').modal('hide');
                showToast('Success', 'Attendance record deleted successfully');
                setTimeout(function(){
                    try {
                        if (table && table.ajax && typeof table.ajax.reload === 'function') {
                            console.debug('confirmDelete: calling table.ajax.reload via instance');
                            table.ajax.reload(null, false);
                            try { if (table.columns && typeof table.columns.adjust === 'function') table.columns.adjust().draw(false); } catch(e){}
                            return;
                        }
                    } catch(e) { console.debug('confirmDelete: table.reload failed', e); }
                    try {
                        console.debug('confirmDelete: calling fallback DataTable selector reload');
                        $('#attendanceTable').DataTable().ajax.reload(null, false);
                        $('#attendanceTable').DataTable().columns.adjust().draw(false);
                    } catch(e) { console.debug('confirmDelete: fallback reload failed', e); }
                }, 150);
            },
            error: function(xhr) {
                const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to delete attendance record';
                showToast('Error', msg);
            }
        });
    });

    // Prevent the Edit form from submitting and causing a page reload on Enter.
    $('#editForm').on('submit', function(e){
        e.preventDefault();
        $('#saveEdit').click();
    });

    // If user presses Enter while the edit modal is focused, trigger save instead of submitting the page.
    $('#editModal').on('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#saveEdit').click();
        }
    });

    // Ensure we reload the table if modal hide completes and previous save/delete flagged a refresh
    var __needsAttendanceReload = false;
    $('#saveEdit, #confirmDelete').on('click', function(){ __needsAttendanceReload = true; });
    $('#editModal, #deleteModal').on('hidden.bs.modal', function(){
        if (!__needsAttendanceReload) return;
        __needsAttendanceReload = false;
        try {
            if (table && table.ajax && typeof table.ajax.reload === 'function') {
                console.debug('hidden.bs.modal: reloading table');
                table.ajax.reload(null, false);
                try { if (table.columns && typeof table.columns.adjust === 'function') table.columns.adjust().draw(false); } catch(e){}
                return;
            }
        } catch(e){ console.debug('hidden.bs.modal: reload via instance failed', e); }
        try { $('#attendanceTable').DataTable().ajax.reload(null, false); $('#attendanceTable').DataTable().columns.adjust().draw(false); } catch(e){ console.debug('hidden.bs.modal: fallback reload failed', e); }
    });

    // Similarly, bind Enter in delete modal to confirm delete to avoid accidental page submit/reload.
    $('#deleteModal').on('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#confirmDelete').click();
        }
    });


    // Handle Copy Daily button (client-side, all filtered rows, robust)
    // Shared: fetch ALL daily rows from the server (not just the visible page)
    // and build export header + rows. Uses server-provided name/title/department.
    function fetchAllDailyRows(done) {
        const date = $('#filter-date').val();
        if (!date) { showToast('Error', 'Pick a date first'); return; }
        $.getJSON('/api/attendance-summary', { type: 'daily', date: date, start: 0, length: 1000000, draw: 1 })
            .done(function(res) {
                const data = (res && res.data) || [];
                const header = ['User', 'Name', 'Designation', 'Department', 'Date',
                                'Last Day Last Punch', 'First Punch', 'Last Punch',
                                'Work Time', 'Type', 'VerifyID'];
                const rows = data.map(function(row) {
                    // prefer server-provided info, fall back to the client-side directory
                    let name = row.name || '';
                    let desig = row.title || '';
                    let dept = row.department || '';
                    if (!name) {
                        try {
                            const sid = parseInt(row.user_id, 10);
                            const s = (window.userDirectory || []).find(x => parseInt(x.id,10) === sid);
                            if (s) { name = s.name || ''; desig = s.title || ''; dept = s.dept || s.department || ''; }
                        } catch (e) {}
                    }
                    return [
                        row.user_id,
                        name,
                        desig,
                        dept,
                        row.date,
                        (row.prev_punch ? row.prev_punch + (row.prev_date ? ' (' + row.prev_date + ')' : '') : ''),
                        row.first_punch,
                        row.last_punch,
                        row.work_time,
                        row.punch,
                        row.status
                    ];
                });
                done(header, rows);
            })
            .fail(function() {
                showToast('Error', 'Failed to fetch attendance data');
            });
    }

    $('#copy-daily').click(function() {
        fetchAllDailyRows(function(header, rows) {
            const lines = [header].concat(rows);
            const text = lines.map(cols => cols.join('\t')).join('\n');
            // Fallback for clipboard API
            function fallbackCopy(text) {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    showToast('Copied', 'All ' + rows.length + ' rows copied to clipboard');
                }, function() {
                    fallbackCopy(text);
                    showToast('Copied', 'All ' + rows.length + ' rows copied to clipboard');
                });
            } else {
                fallbackCopy(text);
                showToast('Copied', 'All ' + rows.length + ' rows copied to clipboard');
            }
        });
    });

    // Handle Export Daily button (all rows for the selected date)
    $('#export-daily').click(function() {
        fetchAllDailyRows(function(header, rows) {
            // Create a CSV string
            let csv = '';
            csv += header.join(',') + '\r\n';
            rows.forEach(function(row){
                csv += row.map(val => '"' + (val ? String(val).replace(/"/g, '""') : '') + '"').join(',') + '\r\n';
            });
            // Download as .csv (Excel will open it)
            let blob = new Blob(['\ufeff' + csv], {type: 'text/csv;charset=utf-8'});
            let url = URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = 'attendance_daily_export_' + ($('#filter-date').val() || '') + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showToast('Exported', 'CSV with ' + rows.length + ' rows downloaded');
        });
    });

    // --- Weekend & Holiday settings ---
    let settingsHolidays = [];

    function renderHolidayList() {
        const ul = $('#holiday-list');
        ul.empty();
        if (!settingsHolidays.length) {
            ul.append('<li class="list-group-item text-muted">No holidays configured</li>');
            return;
        }
        settingsHolidays.slice().sort().forEach(function(d){
            ul.append(
                '<li class="list-group-item d-flex justify-content-between align-items-center py-1">' +
                d +
                '<button type="button" class="btn btn-sm btn-outline-danger holiday-remove" data-date="' + d + '"><i class="bi bi-x"></i></button>' +
                '</li>'
            );
        });
    }

    $('#settingsMenuBtn').click(function(e){ e.preventDefault(); $('#settingsModal').modal('show'); });
    $('#settingsBtn').click(function(){
        $.ajax({ url: '/api/settings', method: 'GET', dataType: 'json', cache: false })
            .done(function(res){
                const wd = (res && Array.isArray(res.weekend_days)) ? res.weekend_days : [5,6];
                $('.weekend-day').each(function(){
                    $(this).prop('checked', wd.indexOf(Number($(this).val())) !== -1);
                });
                settingsHolidays = (res && Array.isArray(res.holidays)) ? res.holidays : [];
                renderHolidayList();
                $('#office-start').val(res.office_start || '09:30');
                $('#office-end').val(res.office_end || '17:00');
                $('#office-grace').val(res.office_grace ?? 5);
                $('#settingsModal').modal('show');
            }).fail(function(){
                showToast('Error', 'Failed to load settings');
            });
    });

    $('#addHolidayBtn').click(function(){
        const d = $('#holiday-input').val();
        if (!d) return;
        if (settingsHolidays.indexOf(d) === -1) settingsHolidays.push(d);
        $('#holiday-input').val('');
        renderHolidayList();
    });

    $('#holiday-list').on('click', '.holiday-remove', function(){
        const d = $(this).data('date');
        settingsHolidays = settingsHolidays.filter(x => x !== d);
        renderHolidayList();
    });

    $('#saveSettingsBtn').click(function(){
        const weekendDays = $('.weekend-day:checked').map(function(){ return Number($(this).val()); }).get();
        const officeStart = $('#office-start').val() || '09:30';
        const officeEnd = $('#office-end').val() || '17:00';
        const officeGrace = parseInt($('#office-grace').val() || '5', 10) || 5;
        $.ajax({
            url: '/api/settings',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ weekend_days: weekendDays, holidays: settingsHolidays, office_start: officeStart, office_end: officeEnd, office_grace: officeGrace })
        }).done(function(){
            $('#settingsModal').modal('hide');
            showToast('Saved', 'Settings updated');
            if (table && table.ajax && typeof table.ajax.reload === 'function') table.ajax.reload(null, false);
        }).fail(function(xhr){
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to save settings';
            showToast('Error', msg);
        });
    });

    // Handle Formatted Copy button (Daily Attendance Record on the clipboard, paste into Excel/Sheets/Docs)
    $('#formatted-copy').click(function() {
        const date = $('#filter-date').val();
        if (!date) { showToast('Error', 'Pick a date first'); return; }

        function fmtDMY(iso) {
            if (!iso) return '';
            const p = String(iso).split('-');
            return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : iso;
        }
        function fmtHM(t) {
            if (!t) return '';
            const p = String(t).split(':');
            return p.length >= 2 ? (p[0] + ':' + p[1]) : t;
        }
        function esc(s) {
            return $('<div>').text(s == null ? '' : String(s)).html();
        }

        // copy rich HTML to the clipboard, with fallbacks for http / older browsers
        function copyHtmlToClipboard(html, plain) {
            if (navigator.clipboard && window.ClipboardItem && window.isSecureContext) {
                return navigator.clipboard.write([new ClipboardItem({
                    'text/html': new Blob([html], { type: 'text/html' }),
                    'text/plain': new Blob([plain], { type: 'text/plain' })
                })]);
            }
            return new Promise(function(resolve, reject) {
                const div = document.createElement('div');
                div.setAttribute('contenteditable', 'true');
                div.style.position = 'fixed';
                div.style.left = '-9999px';
                div.style.top = '0';
                div.innerHTML = html;
                document.body.appendChild(div);
                const range = document.createRange();
                range.selectNodeContents(div);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                let ok = false;
                try { ok = document.execCommand('copy'); } catch (e) {}
                sel.removeAllRanges();
                document.body.removeChild(div);
                ok ? resolve() : reject(new Error('copy command failed'));
            });
        }

        // fetch ALL rows for the day (not just the visible page)
        $.getJSON('/api/attendance-summary', { type: 'daily', date: date, start: 0, length: 1000000, draw: 1 })
            .done(function(res) {
                const rows = (res && res.data) || [];
                const lastWorkDay = res && res.last_working_day ? res.last_working_day : '';

                const BR = '<br style="mso-data-placement:same-cell;">';
                const td = 'border:1px solid #000; padding:0 8px; vertical-align:middle; text-align:center; line-height:1.2; mso-line-height-rule:exactly; height:30px;';

                let html = '<table style="border-collapse:collapse; font-family:Arial, sans-serif; font-size:11pt; color:#000;">';
                html += '<colgroup>'
                     + '<col style="width:70px;">'
                     + '<col style="width:180px;">'
                     + '<col style="width:40px;">'
                     + '<col style="width:110px;">'
                     + '<col style="width:110px;">'
                     + '<col style="width:220px;">'
                     + '<col style="width:40px;">'
                     + '</colgroup>';

                html += '<tr style="height:42px;">'
                     + '<td colspan="7" style="' + td + 'font-size:16pt; font-weight:bold; line-height:1.25; vertical-align:middle; padding:0 8px;">'
                     + 'Daily Attendance Record' + BR + 'Date: ' + fmtDMY(date) + '</td></tr>';

                html += '<tr style="height:36px;">'
                     + '<td style="' + td + ' font-weight:bold;">ID No.</td>'
                     + '<td colspan="2" style="' + td + ' font-weight:bold;">Name</td>'
                     + '<td style="' + td + ' font-weight:bold;">Check out' + BR + '(' + fmtDMY(lastWorkDay) + ')</td>'
                     + '<td style="' + td + ' font-weight:bold;">Check in' + BR + '(' + fmtDMY(date) + ')</td>'
                     + '<td colspan="2" style="' + td + ' font-weight:bold;">Remarks</td>'
                     + '</tr>';

                // plain-text version (tab separated) for apps that only take text
                let plainLines = ['Daily Attendance Record', 'Date: ' + fmtDMY(date), ['ID No.', 'Name', 'Check out (' + fmtDMY(lastWorkDay) + ')', 'Check in (' + fmtDMY(date) + ')', 'Remarks'].join('\t')];

                rows.forEach(function(r) {
                    let name = r.name || '', title = r.title || '', dept = r.department || '';
                    if (!name) {
                        try {
                            const s = (window.userDirectory || []).find(x => parseInt(x.id, 10) === parseInt(r.user_id, 10));
                            if (s) { name = s.name || ''; title = s.title || ''; dept = s.dept || s.department || ''; }
                        } catch (e) {}
                    }
                    const subLine = title + (dept ? (title ? ', ' : '') + dept : '');

                    let checkOut = fmtHM(r.prev_punch);
                    if (checkOut && r.prev_stale && r.prev_date) checkOut += ' (' + fmtDMY(r.prev_date) + ')';
                    const checkIn = r.is_absent ? '' : fmtHM(r.first_punch);
                    const remark = r.remarks || '';

                    html += '<tr style="height:32px;">'
                         + '<td style="' + td + '">' + esc(r.user_id) + '</td>'
                         + '<td colspan="2" style="' + td + ' text-align:left;">' + esc(name) + (subLine ? BR + '<span style="font-size:9pt;">' + esc(subLine) + '</span>' : '') + '</td>'
                         + '<td style="' + td + '">' + esc(checkOut) + '</td>'
                         + '<td style="' + td + '">' + esc(checkIn) + '</td>'
                         + '<td colspan="2" style="' + td + ' text-align:left;">' + esc(remark) + '</td>'
                         + '</tr>';

                    plainLines.push([r.user_id, name + (subLine ? ' - ' + subLine : ''), checkOut, checkIn, remark].join('\t'));
                });

                for (let i = 0; i < 4; i++) {
                    html += '<tr style="height:28px;">'
                         + '<td style="border:none; padding:0; vertical-align:middle; text-align:center; background-color:#fff;"></td>'
                         + '<td style="border:none; padding:0; vertical-align:middle; text-align:center; background-color:#fff;"></td>'
                         + '<td style="border:none; padding:0; vertical-align:middle; text-align:center; background-color:#fff;"></td>'
                         + '<td style="border:none; padding:0; vertical-align:middle; text-align:center; background-color:#fff;"></td>'
                         + '<td style="border:none; padding:0; vertical-align:middle; text-align:center; background-color:#fff;"></td>'
                         + '<td style="border:none; padding:0; vertical-align:middle; text-align:center; background-color:#fff;"></td>'
                         + '<td style="border:none; padding:0; vertical-align:middle; text-align:center; background-color:#fff;"></td>'
                         + '</tr>';
                }

                html += '<tr>'
                     + '<td style="border:none; padding:4px 8px; vertical-align:middle; text-align:left; background-color:#fff;"></td>'
                     + '<td style="border-top:1px solid #000; border-left:none; border-right:none; border-bottom:none; padding:4px 8px; vertical-align:middle; text-align:middle; background-color:#fff;">Prepared by</td>'
                     + '<td style="border:none; padding:4px 8px; vertical-align:middle; text-align:left; background-color:#fff;"></td>'
                     + '<td style="border:none; padding:4px 8px; vertical-align:middle; text-align:left; background-color:#fff;"></td>'
                     + '<td style="border:none; padding:4px 8px; vertical-align:middle; text-align:left; background-color:#fff;"></td>'
                     + '<td style="border-top:1px solid #000; border-left:none; border-right:none; border-bottom:none; padding:4px 8px; vertical-align:middle; text-align:middle; background-color:#fff;">Secretary General</td>'
                     + '<td style="border:none; padding:4px 8px; vertical-align:middle; text-align:left; background-color:#fff;"></td>'
                     + '</tr>';

                html += '</table>';

                copyHtmlToClipboard(html, plainLines.join('\n')).then(function() {
                    showToast('Copied', 'Formatted report copied — paste it into Excel / Google Sheets');
                }, function() {
                    showToast('Error', 'Could not copy to clipboard');
                });
            })
            .fail(function() {
                showToast('Error', 'Failed to fetch attendance data for the report');
            });
    });

    // --- User directory: prefer server-side storage via API, fallback to localStorage ---
    function loadUserFromServer() {
        $.ajax({ url: '/api/users', method: 'GET', dataType: 'json', cache: false })
            .done(function(res){
                if (Array.isArray(res)) {
                    window.userDirectory = res;
                } else {
                    window.userDirectory = [];
                }
                renderUserTable();
                loadDeptFilter();
            }).fail(function(){
                window.userDirectory = [];
                renderUserTable();
                showToast('Error', 'Failed to load users from server. Changes will not be saved.');
            });
    }

    function saveUserToLocalCache(){
        // no-op: persistence is server-side only now
    }

    function renderUserTable() {
        const tbody = $('#userTable tbody');
        if (!tbody.length) return; // no user table on this page
        tbody.empty();
        // sort by view_order (nulls last), then by id — same order as the daily log
        const sorted = (window.userDirectory || []).slice().sort(function(a, b){
            const oa = (a.view_order === null || a.view_order === undefined || a.view_order === '') ? Infinity : Number(a.view_order);
            const ob = (b.view_order === null || b.view_order === undefined || b.view_order === '') ? Infinity : Number(b.view_order);
            if (oa === ob) return (parseInt(a.id,10) || 0) - (parseInt(b.id,10) || 0);
            return oa - ob;
        });
        sorted.forEach(function(s){
            const id = s.id;
            const name = s.name || '';
            const title = s.title || '';
            const dept = s.dept || s.department || '';
            const hasOrder = !(s.view_order === null || s.view_order === undefined || s.view_order === '');
            const orderVal = hasOrder ? Number(s.view_order) : '';
            const orderSortVal = hasOrder ? Number(s.view_order) : 999999999;
            const active = s.active !== false; // default to true if not set
            const statusBadge = active
                ? '<span class="badge bg-success">Active</span>'
                : '<span class="badge bg-secondary">Inactive</span>';
            const tr = $(
                '<tr data-id="'+id+'">' +
                '<td>' + id + '</td>' +
                '<td>' + $('<div>').text(name).html() + '</td>' +
                '<td>' + $('<div>').text(title).html() + '</td>' +
                '<td>' + $('<div>').text(dept).html() + '</td>' +
                '<td data-order="' + orderSortVal + '">' + (hasOrder ? orderVal : '<span class="text-muted">—</span>') + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' +
                    '<button class="btn btn-sm btn-outline-primary user-edit" data-id="'+id+'"><i class="bi bi-pencil"></i></button> ' +
                    '<button class="btn btn-sm btn-outline-danger user-delete" data-id="'+id+'"><i class="bi bi-trash"></i></button>' +
                '</td>' +
                '</tr>'
            );
            tbody.append(tr);
        });

        // initialize or re-draw DataTable for user list
        if ($.fn.DataTable.isDataTable('#userTable')) {
            try { $('#userTable').DataTable().destroy(); } catch(e){}
        }
        const st = $('#userTable').DataTable({
            paging: true,
            searching: true,
            info: true,
            lengthChange: false,
            pageLength: 25,
            order: [[4, 'asc']]
        });
        try { if (st && st.columns && typeof st.columns.adjust === 'function') st.columns.adjust().draw(false); } catch(e) { console.debug('user columns.adjust failed', e); }
        // also ensure attendance table recalculates after user table operations
        setTimeout(function(){
            try { if (table && table.columns && typeof table.columns.adjust === 'function') table.columns.adjust().draw(false); } catch(e) { console.debug('attendance columns.adjust failed', e); }
        }, 150);
    }

    // wire buttons
    $('#addUserBtn').click(function(){
        $('#user-id').val('');
        $('#user-input-id').val('');
        $('#user-input-name').val('');
        $('#user-input-title').val('');
        $('#user-input-dept').val('');
        $('#user-input-order').val('');
        $('#user-input-active').prop('checked', true); // default to active
        $('#userModal').modal('show');
    });

    // Save (Add / Edit)
    $('#saveUserBtn').click(function(){
        const originalId = String($('#user-id').val() || '').trim();
        const idVal = String($('#user-input-id').val()).trim();
        const name = String($('#user-input-name').val() || '').trim();
        const title = String($('#user-input-title').val() || '').trim();
        const dept = String($('#user-input-dept').val() || '').trim();
        const orderRaw = String($('#user-input-order').val() || '').trim();
        const viewOrder = orderRaw === '' ? null : Number(orderRaw);
        const active = $('#user-input-active').is(':checked');

        if (!idVal || !name) {
            showToast('Error', 'Please provide at least ID and Name');
            return;
        }

        // ensure userDirectory exists in-memory
        window.userDirectory = window.userDirectory || [];

        // edit mode
        if (originalId) {
            // call PUT /api/users/{id}
            $.ajax({
                url: '/api/users/' + encodeURIComponent(originalId),
                method: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify({ name: name, title: title, department: dept, active: active, view_order: viewOrder })
            }).done(function(updated){
                // update local copy with server response and refresh UI
                const idx = window.userDirectory.findIndex(x => String(x.id) === String(originalId));
                if (idx !== -1) window.userDirectory[idx] = updated;
                renderUserTable();
                $('#userModal').modal('hide');
                showToast('Saved', 'User updated');
                if (table && table.ajax && typeof table.ajax.reload === 'function') table.ajax.reload(null, false);
            }).fail(function(xhr){
                const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to update user on server';
                showToast('Error', msg);
            });
            return;
        }

        // create mode
        $.ajax({
            url: '/api/users',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: isNaN(idVal) ? idVal : Number(idVal), name: name, title: title, department: dept, active: active, view_order: viewOrder })
        }).done(function(created){
            window.userDirectory = window.userDirectory || [];
            window.userDirectory.push(created);
            renderUserTable();
            $('#userModal').modal('hide');
            showToast('Saved', 'User added');
            if (table && table.ajax && typeof table.ajax.reload === 'function') table.ajax.reload(null, false);
        }).fail(function(xhr){
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to add user on server';
            showToast('Error', msg);
        });
    });

    // Edit / Delete handlers delegated
    $('#userTable tbody').on('click', '.user-edit', function(){
        const id = $(this).data('id');
        const s = (window.userDirectory || []).find(x => String(x.id) === String(id));
        if (!s) return showToast('Error', 'User not found');
        $('#user-id').val(s.id);
        $('#user-input-id').val(s.id);
        $('#user-input-name').val(s.name || '');
        $('#user-input-title').val(s.title || '');
        $('#user-input-dept').val(s.dept || s.department || '');
        $('#user-input-order').val((s.view_order === null || s.view_order === undefined) ? '' : s.view_order);
        $('#user-input-active').prop('checked', s.active !== false);
        $('#userModal').modal('show');
    });

    $('#userTable tbody').on('click', '.user-delete', function(){
        const id = $(this).data('id');
        $('#user-delete-id').val(id);
        $('#userDeleteModal').modal('show');
    });

    $('#userTable tbody').on('click', '.user-toggle', function(){
        const id = $(this).data('id');
        const s = (window.userDirectory || []).find(x => String(x.id) === String(id));
        if (!s) return showToast('Error', 'User not found');

        const newActive = !s.active; // toggle active status
        $.ajax({
            url: '/api/users/' + encodeURIComponent(id),
            method: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify({ active: newActive })
        }).done(function(response){
            // Update local user directory
            s.active = newActive;
            renderUserTable();
            showToast('Success', 'User ' + (newActive ? 'activated' : 'deactivated'));
            // Reload attendance table to reflect changes
            if (table && table.ajax && typeof table.ajax.reload === 'function') table.ajax.reload(null, false);
        }).fail(function(xhr){
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to update user status';
            showToast('Error', msg);
        });
    });

    $('#confirmDeleteUser').click(function(){
        const id = String($('#user-delete-id').val());
        $.ajax({ url: '/api/users/' + encodeURIComponent(id), method: 'DELETE' }).done(function(){
            window.userDirectory = (window.userDirectory || []).filter(x => String(x.id) !== id);
            renderUserTable();
            $('#userDeleteModal').modal('hide');
            showToast('Deleted', 'User removed');
            if (table && table.ajax && typeof table.ajax.reload === 'function') table.ajax.reload(null, false);
        }).fail(function(xhr){
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to delete user on server';
            showToast('Error', msg);
        });
    });

    // Initialize user list from server and render
    loadUserFromServer();

    // Add Attendance button handler
    $('#addAttendanceBtn').click(function(){
        const sel = $('#add-user-select');
        sel.empty().append('<option value="">Select user</option>');
        (window.userDirectory || []).forEach(s => {
            sel.append(`<option value="${s.id}">${s.id} - ${s.name || ''}</option>`);
        });
        // try to fetch any users not present in userDirectory
        $.getJSON('/api/users').done(function(users){
            const existing = new Set((window.userDirectory || []).map(s => String(s.id)));
            users.forEach(u => {
                const uid = String(u.id || u.user_id || u);
                if (!existing.has(uid)) sel.append(`<option value="${uid}">${uid}</option>`);
            });
        }).fail(function(){});

        $('#add-date').val($('#filter-date').val() || new Date().toISOString().slice(0,10));
        $('#add-first-punch').val('');
        $('#add-last-punch').val('');
        $('#add-status').val('');
        $('#addAttendanceModal').modal('show');
    });

    // Save new attendance
    $('#saveAddAttendance').click(function(){
        const user_id = $('#add-user-select').val();
        const date = $('#add-date').val();
        const first_punch = $('#add-first-punch').val();
        const last_punch = $('#add-last-punch').val();
        const status = $('#add-status').val();

        if (!user_id || !date || !first_punch) {
            showToast('Error', 'Please select user and provide date and first punch');
            return;
        }
        if (!validateAddPunchTimes()) { showToast('Error', 'First punch cannot be later than last punch'); return; }

        $.ajax({
            url: '/api/attendance/add',
            method: 'POST',
            data: {
                user_id: user_id,
                date: date,
                first_punch: first_punch,
                last_punch: last_punch,
                status: status
            }
        }).done(function(res){
            $('#addAttendanceModal').modal('hide');
            showToast('Success', 'Attendance added successfully');
            setTimeout(function(){ try { if (table && table.ajax && typeof table.ajax.reload === 'function') { table.ajax.reload(null, false); table.columns.adjust().draw(false); return; } } catch(e){} try { $('#attendanceTable').DataTable().ajax.reload(null, false); $('#attendanceTable').DataTable().columns.adjust().draw(false); } catch(e){} }, 150);
        }).fail(function(xhr){
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to add attendance';
            showToast('Error', msg);
        });
    });

});
</script>
