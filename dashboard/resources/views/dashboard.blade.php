<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
<title>Dynamic Attendance Dashboard</title>
<link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
@include('partials.styles')
</head>
<body>

<!-- Header Navigation -->
<nav class="navbar-header">
    <button class="sidebar-toggle" id="sidebarToggle" title="Menu"><i class="bi bi-list"></i></button>
    <a href="/" style="font: inherit; color: inherit; text-decoration: none;">
    <div class="logo">
        <i class="bi bi-clock-history"></i>Attendance Dashboard
    </div>
    </a>
    <div class="date-time" id="currentDateTime"></div>
</nav>

<!-- Sidebar Navigation -->
<div class="sidebar" id="sidebar">
    <a href="#" data-type="dashboard" class="active"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="#" data-type="daily"><i class="bi bi-calendar-day"></i> Daily</a>
    <a href="#" data-type="monthly"><i class="bi bi-calendar-month"></i> Monthly</a>
    <a href="#" data-type="user"><i class="bi bi-person-circle"></i> User-wise</a>
    <a href="#" data-type="directory"><i class="bi bi-people"></i> User Directory</a>
    @if(env('ATTENDANCE2_ENABLED'))
    <a href="/attendance2" id="attendance2Link"><i class="bi bi-shield-lock"></i> Door Monitor <span id="doorLiveBadge" class="badge bg-info ms-auto" style="display:none;">0</span></a>
    @endif
    <hr>
    <a href="#" id="settingsMenuBtn"><i class="bi bi-gear"></i> Settings</a>
    <a href="#" id="logoutBtn" class="logout-btn"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<!-- Main Content -->
<div class="content">
    <div class="content-wrapper">
    <div class="filters d-flex align-items-center gap-2 flex-nowrap" style="margin-bottom: 1rem; overflow-x:auto; overflow-y:visible; -webkit-overflow-scrolling:touch;">
        <!-- Type selector — single pill -->
        <select id="filter-type" class="form-select form-select-sm" style="width:115px; min-width:115px;">
            <option value="dashboard">Dashboard</option>
            <option value="daily">Daily</option>
            <option value="monthly">Monthly</option>
            <option value="user">User</option>
            <option value="directory">Directory</option>
        </select>

        <div class="input-group flex-nowrap" style="width:auto; flex-shrink:0;">
            <button class="btn btn-outline-secondary btn-sm prev-day" title="Previous day" style="display:none;"><i class="bi bi-chevron-left"></i></button>
            <input type="date" id="filter-date" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" style="width:135px; min-width:135px;">
            <button class="btn btn-outline-secondary btn-sm next-day" title="Next day" style="display:none;"><i class="bi bi-chevron-right"></i></button>
        </div>

        <div class="input-group flex-nowrap" style="width:auto; flex-shrink:0;">
            <button class="btn btn-outline-secondary btn-sm prev-month" title="Previous month" style="display:none;"><i class="bi bi-chevron-left"></i></button>
            <input type="month" id="filter-month" class="form-control form-control-sm d-none" value="{{ date('Y-m') }}" style="width:135px; min-width:135px;">
            <button class="btn btn-outline-secondary btn-sm next-month" title="Next month" style="display:none;"><i class="bi bi-chevron-right"></i></button>
        </div>

        <div class="input-group flex-nowrap" style="width:auto; flex-shrink:0;">
            <input type="text" id="filter-user" class="form-control form-control-sm d-none" placeholder="User ID" style="width:135px; min-width:135px;">
            <select id="filter-user-select" class="form-select form-select-sm d-none" style="width:135px; min-width:135px;"></select>
        </div>

        <select id="filter-dept" class="form-select form-select-sm d-none" style="width:125px; min-width:125px; flex-shrink:0;">
            <option value="">All Departments</option>
        </select>

        <button id="apply-filter" class="btn btn-primary btn-sm" style="flex-shrink:0;">Apply</button>
        <button id="addAttendanceBtn" class="btn btn-warning btn-sm" style="flex-shrink:0;">Add</button>

        <div class="vr mx-1 d-none d-md-block" style="flex-shrink:0;"></div>

        <button class="btn btn-outline-secondary btn-sm preset-btn" data-preset="today" title="Today" style="flex-shrink:0;">Today</button>
        <button class="btn btn-outline-secondary btn-sm preset-btn" data-preset="yesterday" title="Yesterday" style="flex-shrink:0;">Yesterday</button>
        <button class="btn btn-outline-secondary btn-sm preset-btn" data-preset="thisMonth" title="This Month" style="flex-shrink:0;">This Month</button>

        <div class="d-flex align-items-center gap-2" style="flex-shrink:0; margin-left:auto;">
            <span id="lastWorkingDayChip" class="badge bg-light text-dark border d-none" style="white-space:nowrap;"><i class="bi bi-calendar-week"></i> Last work: —</span>
            <button id="copy-daily" class="btn btn-outline-secondary btn-sm d-none" style="flex-shrink:0;">Copy</button>
            <button id="export-daily" class="btn btn-outline-success btn-sm d-none" style="flex-shrink:0;">Export</button>
            <button id="formatted-copy" class="btn btn-outline-primary btn-sm d-none" style="flex-shrink:0;"><i class="bi bi-clipboard-check"></i> Formatted</button>
            <div class="colvis-dropdown" style="flex-shrink:0;">
                <button id="colvisBtn" class="btn btn-outline-secondary btn-sm" title="Columns"><i class="bi bi-layout-three-columns"></i></button>
                <div id="colvisMenu" class="colvis-menu"></div>
            </div>
            <button id="settingsBtn" class="btn btn-outline-secondary btn-sm" title="Weekend &amp; Holiday Settings" style="flex-shrink:0;"><i class="bi bi-gear"></i></button>
        </div>
    </div>

    <div id="dashboardSection">
    <!-- Dashboard: summary + charts (present/absent/late + analytics) -->
    <div id="summaryCards" class="row g-2 mb-3 d-none">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background: rgba(255,255,255,0.85); backdrop-filter: blur(8px);">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:36px; height:36px; background: rgba(34,197,94,0.15); color:#16a34a;"><i class="bi bi-check-circle"></i></div>
                    <div><div class="small text-muted" style="font-size:0.7rem;">Present</div><div class="fw-bold" id="sumPresent">—</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background: rgba(255,255,255,0.85); backdrop-filter: blur(8px);">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:36px; height:36px; background: rgba(239,68,68,0.15); color:#dc2626;"><i class="bi bi-x-circle"></i></div>
                    <div><div class="small text-muted" style="font-size:0.7rem;">Absent</div><div class="fw-bold" id="sumAbsent">—</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background: rgba(255,255,255,0.85); backdrop-filter: blur(8px);">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:36px; height:36px; background: rgba(234,179,8,0.2); color:#a16207;"><i class="bi bi-clock-history"></i></div>
                    <div><div class="small text-muted" style="font-size:0.7rem;">Late</div><div class="fw-bold" id="sumLate">—</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background: rgba(255,255,255,0.85); backdrop-filter: blur(8px);">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:36px; height:36px; background: rgba(2,132,199,0.15); color:#0284c7;"><i class="bi bi-stopwatch"></i></div>
                    <div><div class="small text-muted" style="font-size:0.7rem;">Avg Work</div><div class="fw-bold" id="sumAvg">—</div></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Dashboard charts (daily only) - combines analytics -->
    <div id="dashboardCharts" class="row g-3 mb-3 d-none">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="background: rgba(255,255,255,0.85); backdrop-filter: blur(8px); border-radius:12px;">
                <div class="card-header d-flex align-items-center gap-2" style="background: rgba(255,255,255,0.6); font-weight:600;"><i class="bi bi-bar-chart"></i> Daily Attendance % (this month) <span class="ms-auto small text-muted" id="dashOffice"></span></div>
                <div class="card-body" style="height:220px;"><canvas id="dashChartPresent"></canvas></div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm" style="background: rgba(255,255,255,0.85); backdrop-filter: blur(8px); border-radius:12px;">
                <div class="card-header" style="background: rgba(255,255,255,0.6); font-weight:600;"><i class="bi bi-people"></i> Dept Avg Work Hours</div>
                <div class="card-body" style="height:260px;"><canvas id="dashChartDept"></canvas></div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm" style="background: rgba(255,255,255,0.85); backdrop-filter: blur(8px); border-radius:12px;">
                <div class="card-header" style="background: rgba(255,255,255,0.6); font-weight:600;"><i class="bi bi-clock-history"></i> Late Arrivals — Last 30 Days</div>
                <div class="card-body" style="height:260px;"><canvas id="dashChartLate"></canvas></div>
            </div>
        </div>
    </div>
    </div>
    <div id="attendanceSection">
    <table id="attendanceTable" class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>User</th>
                <th>Date</th>
                <th>Last Day Last Punch</th>
                <th>First Punch</th>
                <th>Last Punch</th>
                <th>Work Time</th>
                <th>Flag</th>
                <th>Type</th>
                <th>VerifyID</th>
                <th>Actions</th>
            </tr>
        </thead>
    </table>
    </div>

    <!-- User Directory Section (client-side) -->
    <div id="userSection" class="d-none">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0">User Directory</h5>
            <button id="addUserBtn" class="btn btn-primary btn-sm">Add User</button>
        </div>

        <table id="userTable" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Title</th>
                    <th>Department</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <!-- User modals moved to page-level for proper stacking (see bottom of file) -->
    </div>

    </div>
</div>

<!-- Maintenance Credit -->
<!-- Moved to footer -->

<!-- Footer -->
<footer>
    <div class="copyright">&copy; {{ date("Y") }} Attendance Management System</div>
    <div class="maintenance" style="margin-left: auto;">Maintenance by <a href="https://lifaet.github.io">ZIM</a></div>
</footer>

<!-- hidden logout form -->
<form id="logoutForm" method="POST" action="/logout" style="display:none;">
    @csrf
</form>

<!-- Weekend & Holiday Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear"></i> Weekend &amp; Holiday Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Weekend Days</label>
                    <div id="weekend-days" class="d-flex flex-wrap gap-3">
                        <div class="form-check"><input class="form-check-input weekend-day" type="checkbox" value="0" id="wd-0"><label class="form-check-label" for="wd-0">Sunday</label></div>
                        <div class="form-check"><input class="form-check-input weekend-day" type="checkbox" value="1" id="wd-1"><label class="form-check-label" for="wd-1">Monday</label></div>
                        <div class="form-check"><input class="form-check-input weekend-day" type="checkbox" value="2" id="wd-2"><label class="form-check-label" for="wd-2">Tuesday</label></div>
                        <div class="form-check"><input class="form-check-input weekend-day" type="checkbox" value="3" id="wd-3"><label class="form-check-label" for="wd-3">Wednesday</label></div>
                        <div class="form-check"><input class="form-check-input weekend-day" type="checkbox" value="4" id="wd-4"><label class="form-check-label" for="wd-4">Thursday</label></div>
                        <div class="form-check"><input class="form-check-input weekend-day" type="checkbox" value="5" id="wd-5"><label class="form-check-label" for="wd-5">Friday</label></div>
                        <div class="form-check"><input class="form-check-input weekend-day" type="checkbox" value="6" id="wd-6"><label class="form-check-label" for="wd-6">Saturday</label></div>
                    </div>
                    <small class="text-muted">Used to find the previous working day for the "Last Day Last Punch" column.</small>
                </div>
                <hr>
                <div class="mb-3">
                    <label class="form-label fw-bold">Office Hours</label>
                    <div class="row g-2" style="max-width:360px;">
                        <div class="col-4"><label class="form-label small">Start</label><input type="time" id="office-start" class="form-control form-control-sm" value="09:30"></div>
                        <div class="col-4"><label class="form-label small">End</label><input type="time" id="office-end" class="form-control form-control-sm" value="17:00"></div>
                        <div class="col-4"><label class="form-label small">Tolerance &plusmn; (min)</label><input type="number" id="office-grace" class="form-control form-control-sm" value="5" min="0" max="60"></div>
                    </div>
                    <small class="text-muted">On time within &plusmn; tolerance of Start (first punch only). Late &gt; +tolerance, Early &lt; -tolerance</small>
                </div>
                <hr>
                <div class="mb-2">
                    <label class="form-label fw-bold">Holidays</label>
                    <div class="input-group mb-2" style="max-width:280px;">
                        <input type="date" id="holiday-input" class="form-control">
                        <button type="button" id="addHolidayBtn" class="btn btn-outline-primary">Add</button>
                    </div>
                    <ul id="holiday-list" class="list-group" style="max-height:200px; overflow-y:auto;"></ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="saveSettingsBtn" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add / Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" id="user-id">
                    <div class="mb-3">
                        <label class="form-label">ID</label>
                        <input type="text" id="user-input-id" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" id="user-input-name" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" id="user-input-title" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <input type="text" id="user-input-dept" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">View Order</label>
                        <input type="number" id="user-input-order" class="form-control" min="0" placeholder="e.g. 1 (lower shows first in daily log)">
                        <small class="text-muted">Controls the position of this user in the daily log. Leave empty to show after ordered users (sorted by ID).</small>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" id="user-input-active" class="form-check-input" checked>
                        <label class="form-check-label" for="user-input-active">Active</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="saveUserBtn" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete User Modal -->
<div class="modal fade" id="userDeleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete this user?
                <input type="hidden" id="user-delete-id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirmDeleteUser" class="btn btn-danger">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Attendance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" id="edit-user-id">
                    <input type="hidden" id="edit-date">
                    <div class="mb-3">
                        <label class="form-label">First Punch</label>
                        <input type="time" class="form-control" id="edit-first-punch" step="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Last Punch</label>
                        <input type="time" class="form-control" id="edit-last-punch" step="1">
                        <small id="edit-punch-error" class="text-danger d-none">First punch cannot be later than last punch</small>
                    </div>
                    <input type="hidden" id="edit-original-punch">
                    <div class="mb-3">
                        <label class="form-label">VerifyID (1 For FINGERPRINT, 4 For RFID)</label>
                        <input type="text" class="form-control" id="edit-status">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveEdit">Save changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this attendance record?</p>
                <div id="delete-details" class="alert alert-light border small text-muted mb-2 d-none"></div>
                <input type="hidden" id="delete-user-id">
                <input type="hidden" id="delete-date">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDelete">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- user UI moved to separate blade (/user) -->

            <!-- Add Attendance Modal -->
            <div class="modal fade" id="addAttendanceModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Add Attendance (Manual)</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="addAttendanceForm">
                                <div class="mb-3">
                                    <label class="form-label">User</label>
                                    <select id="add-user-select" class="form-select">
                                        <option value="">Select user</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Date</label>
                                    <input type="date" id="add-date" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">First Punch</label>
                                    <input type="time" step="1" id="add-first-punch" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Last Punch (optional)</label>
                                    <input type="time" step="1" id="add-last-punch" class="form-control">
                                <small id="add-punch-error" class="text-danger d-none">First punch cannot be later than last punch</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">VerifyID (optional)</label>
                                    <input type="text" id="add-status" class="form-control" placeholder="1 for fingerprint, 4 for RFID">
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" id="saveAddAttendance" class="btn btn-primary">Add Attendance</button>
                        </div>
                    </div>
                </div>
            </div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
@include('partials.scripts')

</body>
</html>