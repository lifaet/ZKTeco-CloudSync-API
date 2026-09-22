<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { height: 100%; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    background: linear-gradient(135deg, #f0f4f8 0%, #d9e8f5 50%, #f0f4f8 100%);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    color: #1e293b;
}

/* Header Navigation - Light Glass Effect */
.navbar-header {
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid rgba(100, 116, 139, 0.1);
    padding: 0.75rem 1.5rem;
    color: #1e293b;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.navbar-header .logo { font-size: 1.1rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 0.4rem; letter-spacing: -0.3px; }
.navbar-header .logo i { color: #0284c7; }
.navbar-header .date-time { font-size: 0.75rem; opacity: 0.7; }

/* Sidebar - Light Glass Effect */
.sidebar {
    width: 180px;
    position: fixed;
    top: 56px;
    bottom: 0;
    background: rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(8px);
    border-right: 1px solid rgba(100, 116, 139, 0.1);
    color: #475569;
    padding: 0.75rem 0;
    overflow-y: auto;
    z-index: 999;
    transition: transform 0.3s ease;
}
.sidebar.hidden {
    transform: translateX(-100%);
}
.sidebar-toggle {
    display: none;
    background: none;
    border: none;
    color: #1e293b;
    font-size: 1.2rem;
    cursor: pointer;
    padding: 0.5rem;
    transition: all 0.2s ease;
}
.sidebar-toggle:hover {
    color: #0284c7;
}
.sidebar a {
    color: #475569;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.6rem 1rem;
    transition: all 0.2s ease;
    border-left: 2px solid transparent;
    font-size: 0.85rem;
}
.sidebar a:hover {
    background: rgba(2, 132, 199, 0.08);
    border-left-color: #0284c7;
    color: #0284c7;
}
.sidebar a.active {
    background: rgba(2, 132, 199, 0.12);
    border-left-color: #0284c7;
    color: #0284c7;
    font-weight: 500;
}
.sidebar hr { border-color: rgba(100, 116, 139, 0.1); margin: 0.5rem 0; }
.sidebar a.logout-btn { color: #dc2626; }
.sidebar a.logout-btn:hover { color: #b91c1c; background: rgba(220, 38, 38, 0.08); }

/* Content - Full Height */
.content {
    margin-left: 180px;
    margin-top: 56px;
    margin-bottom: 40px;
    padding: 0;
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    transition: margin-left 0.3s ease;
}
.content-wrapper {
    background: rgba(255, 255, 255, 0.4);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(100, 116, 139, 0.1);
    border-radius: 0;
    padding: 0.8rem;
    box-shadow: none;
    flex: 1;
    display: flex;
    flex-direction: column;
}

/* Filters - Light Glass Effect */
.filters {
    display: flex;
    gap: 0.6rem;
    flex-wrap: wrap;
    margin-bottom: 0.8rem;
    align-items: center;
    padding: 0.8rem;
    background: rgba(255, 255, 255, 0.6);
    border: 1px solid rgba(100, 116, 139, 0.1);
    border-radius: 6px;
    backdrop-filter: blur(8px);
    flex-shrink: 0;
}
.filters input,
.filters select {
    min-width: 90px;
    max-width: 180px;
    padding: 0.4rem 0.6rem;
    border: 1px solid rgba(100, 116, 139, 0.2);
    border-radius: 5px;
    font-size: 0.8rem;
    background: rgba(255, 255, 255, 0.7);
    color: #1e293b;
    backdrop-filter: blur(4px);
    transition: all 0.2s;
}
.filters input:focus,
.filters select:focus {
    border-color: #0284c7;
    box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
    outline: none;
    background: rgba(255, 255, 255, 0.9);
}

#filter-date, #filter-month { width: 160px !important; }
#filter-user-select, #filter-user { width: 140px !important; }

/* Buttons */
.btn {
    border-radius: 5px;
    font-weight: 500;
    transition: all 0.2s ease;
    border: none;
    font-size: 0.8rem;
    padding: 0.4rem 0.8rem;
}
.btn-primary {
    background: rgba(2, 132, 199, 0.9);
    color: #fff;
    border: 1px solid rgba(2, 132, 199, 0.4);
    backdrop-filter: blur(4px);
}
.btn-primary:hover {
    background: rgba(2, 132, 199, 1);
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
}
.btn-outline-secondary {
    border: 1px solid rgba(100, 116, 139, 0.3);
    color: #475569;
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(4px);
}
.btn-outline-secondary:hover {
    background: rgba(2, 132, 199, 0.1);
    border-color: #0284c7;
    color: #0284c7;
}
.btn-outline-success {
    background: rgba(34, 197, 94, 0.8);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: #fff;
    backdrop-filter: blur(4px);
}
.btn-outline-success:hover {
    background: rgba(34, 197, 94, 0.95);
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
}

/* Tables */
.table {
    border-radius: 0;
    overflow: hidden;
    margin-bottom: 0;
    font-size: 0.85rem;
    flex: 1;
}
.table thead {
    background: rgba(255, 255, 255, 0.8);
    color: #1e293b;
    border-bottom: 2px solid rgba(100, 116, 139, 0.15);
}
.table thead th {
    padding: 0.6rem 0.6rem;
    font-weight: 600;
    border: none;
}
.table tbody td {
    padding: 0.5rem 0.6rem;
    vertical-align: middle;
    border-color: rgba(100, 116, 139, 0.08);
}
.table tbody tr {
    transition: all 0.2s;
    border-bottom: 1px solid rgba(100, 116, 139, 0.08);
    background: rgba(255, 255, 255, 0.2);
}
.table tbody tr:hover {
    background: rgba(2, 132, 199, 0.08);
}

/* Toast Notifications */
.toast-container {
    position: fixed;
    top: 80px;
    right: 1rem;
    z-index: 2000;
}
.toast {
    border-radius: 8px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
    border: 1px solid rgba(100, 116, 139, 0.1);
    backdrop-filter: blur(8px);
    font-size: 0.85rem;
}
.toast.bg-success { background: rgba(34, 197, 94, 0.85) !important; }
.toast.bg-danger { background: rgba(220, 38, 38, 0.85) !important; }
.toast.bg-info { background: rgba(2, 132, 199, 0.85) !important; }
.toast .toast-body { color: #fff; }

/* Footer */
footer {
    background: rgba(255, 255, 255, 0.4);
    backdrop-filter: blur(8px);
    border-top: 1px solid rgba(100, 116, 139, 0.1);
    color: #64748b;
    padding: 0.75rem 1.5rem;
    font-size: 0.75rem;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 998;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
footer .copyright {
    margin-left: 180px;
}
footer .maintenance {
    margin-right: 0;
}
footer .maintenance a {
    color: #0284c7;
    text-decoration: none;
    transition: color 0.2s;
}
footer .maintenance a:hover {
    color: #0369a1;
    text-decoration: underline;
}


/* Responsive */
@media (max-width: 768px) {
    .sidebar-toggle { display: block; }
    .sidebar { width: 160px; transform: translateX(-160px); }
    .sidebar.active { transform: translateX(0); }
    .content { margin-left: 0; margin-bottom: 40px; }
    .navbar-header { padding: 0.5rem 0.75rem; }
    .navbar-header .logo { font-size: 0.95rem; }
    .navbar-header .logo i { display: none; }
    .navbar-header .date-time { display: none; }
    .filters { gap: 0.4rem; padding: 0.6rem; font-size: 0.75rem; }
    .filters input, .filters select { font-size: 0.7rem; padding: 0.3rem 0.5rem; }
    #filter-date, #filter-month { width: 90px !important; }
    #filter-user-select, #filter-user { width: 110px !important; }
    .btn { font-size: 0.7rem; padding: 0.3rem 0.6rem; }
    .table { font-size: 0.75rem; }
    .table thead th, .table tbody td { padding: 0.4rem 0.4rem; }
    footer { padding: 0.6rem 1rem; font-size: 0.7rem; }
    footer .copyright { margin-left: 0; }
}

@media (max-width: 480px) {
    .sidebar-toggle { display: block; }
    .sidebar { width: 140px; transform: translateX(-140px); }
    .sidebar.active { transform: translateX(0); }
    .content { margin-left: 0; margin-bottom: 40px; }
    .navbar-header { padding: 0.4rem 0.5rem; }
    .navbar-header .logo { font-size: 0.85rem; }
    .sidebar a { font-size: 0.75rem; padding: 0.5rem 0.75rem; gap: 0.4rem; }
    .sidebar a i { font-size: 0.9rem; }
    .filters { padding: 0.5rem; gap: 0.3rem; }
    .filters input, .filters select { font-size: 0.65rem; }
    .btn { font-size: 0.65rem; padding: 0.25rem 0.5rem; }
    .table { font-size: 0.7rem; }
    .table thead th, .table tbody td { padding: 0.3rem 0.3rem; }
    footer { padding: 0.5rem 0.75rem; font-size: 0.65rem; }
    footer .copyright { margin-left: 0; }
}

/* Scrollbar styling */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: rgba(100, 116, 139, 0.08); }
::-webkit-scrollbar-thumb { background: rgba(2, 132, 199, 0.4); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(2, 132, 199, 0.6); }

/* Ensure DataTables stretch to container width and recalc reliably */
.dataTable, #attendanceTable, #userTable { width: 100% !important; }
</style>
