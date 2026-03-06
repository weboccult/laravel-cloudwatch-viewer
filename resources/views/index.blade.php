<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CloudWatch Viewer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
        --bg:        #0a0a0f;
        --surface:   #111118;
        --surface2:  #1a1a24;
        --border:    #2a2a3a;
        --accent:    #00ff88;
        --accent-dim:#00cc6a;
        --text:      #e2e8f0;
        --text-muted:#6b7280;
        --text-dim:  #9ca3af;
        --error:     #ff4d4d;
        --warning:   #f59e0b;
        --info:      #3b82f6;
        --debug:     #a855f7;
        --radius:    6px;
    }

    html, body { height: 100%; background: var(--bg); color: var(--text); font-family: 'IBM Plex Sans', sans-serif; font-size: 14px; line-height: 1.5; }

    /* ── HEADER ───────────────────────────────────────────────── */
    .header {
        position: sticky; top: 0; z-index: 100;
        display: flex; align-items: center; gap: 10px;
        padding: 0 24px; height: 52px;
        background: var(--surface); border-bottom: 1px solid var(--border);
    }
    .header-dot {
        width: 8px; height: 8px; border-radius: 50%;
        background: var(--accent);
        box-shadow: 0 0 6px var(--accent);
        animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
        0%,100% { opacity: 1; box-shadow: 0 0 6px var(--accent); }
        50%      { opacity: .5; box-shadow: 0 0 12px var(--accent); }
    }
    .header-title {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 13px; font-weight: 600; letter-spacing: .08em;
        color: var(--text);
    }
    .header-title span { color: var(--accent); }

    /* ── LAYOUT ───────────────────────────────────────────────── */
    .layout { display: flex; height: calc(100vh - 52px); overflow: hidden; }

    /* ── SIDEBAR ──────────────────────────────────────────────── */
    .sidebar {
        width: 280px; flex-shrink: 0;
        background: var(--surface); border-right: 1px solid var(--border);
        overflow-y: auto; padding: 16px 16px 24px;
        display: flex; flex-direction: column; gap: 20px;
    }
    .sidebar::-webkit-scrollbar { width: 4px; }
    .sidebar::-webkit-scrollbar-track { background: transparent; }
    .sidebar::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

    .section-label {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 10px; font-weight: 600; letter-spacing: .12em;
        color: var(--text-muted); text-transform: uppercase;
        margin-bottom: 8px;
    }

    /* Log groups */
    .group-list { display: flex; flex-direction: column; gap: 6px; }
    .group-item { display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .group-item input[type="checkbox"] {
        appearance: none; width: 14px; height: 14px; flex-shrink: 0;
        border: 1px solid var(--border); border-radius: 3px;
        background: var(--surface2); cursor: pointer; position: relative;
        transition: border-color .15s, background .15s;
    }
    .group-item input[type="checkbox"]:checked {
        background: var(--accent); border-color: var(--accent);
    }
    .group-item input[type="checkbox"]:checked::after {
        content: ''; position: absolute; left: 4px; top: 1px;
        width: 4px; height: 8px; border: 2px solid #000;
        border-left: none; border-top: none; transform: rotate(45deg);
    }
    .group-item label {
        font-size: 12px; color: var(--text-dim); cursor: pointer;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 210px;
    }
    .group-item:hover label { color: var(--text); }

    /* Level pills */
    .level-pills { display: flex; flex-wrap: wrap; gap: 6px; }
    .level-pill {
        padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 500;
        font-family: 'IBM Plex Mono', monospace; letter-spacing: .04em;
        border: 1px solid var(--border); background: transparent;
        color: var(--text-muted); cursor: pointer;
        transition: all .15s;
    }
    .level-pill:hover { border-color: var(--text-dim); color: var(--text); }
    .level-pill.active[data-level="ALL"]     { background: var(--accent); border-color: var(--accent); color: #000; }
    .level-pill.active[data-level="ERROR"]   { background: var(--error); border-color: var(--error); color: #fff; }
    .level-pill.active[data-level="WARNING"] { background: var(--warning); border-color: var(--warning); color: #000; }
    .level-pill.active[data-level="INFO"]    { background: var(--info); border-color: var(--info); color: #fff; }
    .level-pill.active[data-level="DEBUG"]   { background: var(--debug); border-color: var(--debug); color: #fff; }

    /* Date inputs */
    .date-group { display: flex; flex-direction: column; gap: 6px; }
    .date-label { font-size: 11px; color: var(--text-muted); }
    input[type="datetime-local"] {
        width: 100%; padding: 6px 8px; font-size: 11px;
        font-family: 'IBM Plex Mono', monospace;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius); color: var(--text);
        outline: none; transition: border-color .15s;
        color-scheme: dark;
    }
    input[type="datetime-local"]:focus { border-color: var(--accent); }

    /* Text inputs */
    .search-group { display: flex; flex-direction: column; gap: 6px; }
    .search-input {
        width: 100%; padding: 6px 8px; font-size: 12px;
        font-family: 'IBM Plex Mono', monospace;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius); color: var(--text);
        outline: none; transition: border-color .15s;
    }
    .search-input::placeholder { color: var(--text-muted); }
    .search-input:focus { border-color: var(--accent); }

    .divider { border: none; border-top: 1px solid var(--border); }

    /* Buttons */
    .btn-primary {
        width: 100%; padding: 9px; font-size: 12px; font-weight: 600;
        font-family: 'IBM Plex Mono', monospace; letter-spacing: .06em;
        background: var(--accent); color: #000; border: none;
        border-radius: var(--radius); cursor: pointer;
        transition: background .15s, transform .1s;
    }
    .btn-primary:hover { background: var(--accent-dim); }
    .btn-primary:active { transform: scale(.98); }
    .btn-secondary {
        width: 100%; padding: 7px; font-size: 11px; font-weight: 500;
        font-family: 'IBM Plex Mono', monospace; letter-spacing: .04em;
        background: transparent; color: var(--text-muted);
        border: 1px solid var(--border); border-radius: var(--radius);
        cursor: pointer; transition: all .15s;
    }
    .btn-secondary:hover { border-color: var(--text-dim); color: var(--text); }

    /* ── MAIN CONTENT ─────────────────────────────────────────── */
    .main { flex: 1; overflow-y: auto; display: flex; flex-direction: column; }
    .main::-webkit-scrollbar { width: 6px; }
    .main::-webkit-scrollbar-track { background: transparent; }
    .main::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

    /* Loading bar */
    .loading-bar { height: 2px; background: var(--border); position: relative; overflow: hidden; }
    .loading-bar-inner {
        position: absolute; top: 0; left: -40%; width: 40%; height: 100%;
        background: linear-gradient(90deg, transparent, var(--accent), transparent);
        animation: slide 1.2s linear infinite;
        display: none;
    }
    .loading-bar-inner.active { display: block; }
    @keyframes slide { from { left: -40%; } to { left: 100%; } }

    /* Toolbar */
    .toolbar {
        display: flex; align-items: center; justify-content: space-between;
        padding: 10px 20px; border-bottom: 1px solid var(--border);
        background: var(--surface);
    }
    .toolbar-count {
        font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--text-muted);
    }
    .toolbar-count strong { color: var(--accent); }

    /* Table */
    .table-wrapper { flex: 1; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    thead th {
        padding: 10px 14px; text-align: left; font-size: 10px; font-weight: 600;
        font-family: 'IBM Plex Mono', monospace; letter-spacing: .1em;
        color: var(--text-muted); text-transform: uppercase;
        background: var(--surface); border-bottom: 1px solid var(--border);
        position: sticky; top: 0; z-index: 10;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .default-cell { font-size: 12px; color: var(--text-dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
    tbody tr:hover { background: var(--surface2); }
    tbody td { padding: 9px 14px; font-size: 12px; vertical-align: middle; overflow: hidden; }

    .ts-cell { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--text-dim); white-space: nowrap; }

    .badge {
        display: inline-block; padding: 2px 7px; border-radius: 20px;
        font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 600;
        letter-spacing: .04em; white-space: nowrap;
    }
    .badge-ERROR   { background: rgba(255,77,77,.15);   color: #ff6b6b; border: 1px solid rgba(255,77,77,.3); }
    .badge-WARNING { background: rgba(245,158,11,.15); color: #fbbf24; border: 1px solid rgba(245,158,11,.3); }
    .badge-INFO    { background: rgba(59,130,246,.15);  color: #60a5fa; border: 1px solid rgba(59,130,246,.3); }
    .badge-DEBUG   { background: rgba(168,85,247,.15);  color: #c084fc; border: 1px solid rgba(168,85,247,.3); }
    .badge-DEFAULT { background: rgba(107,114,128,.15); color: #9ca3af; border: 1px solid rgba(107,114,128,.3); }

    .msg-cell {
        max-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        cursor: pointer; color: var(--text);
    }
    .msg-cell:hover { color: var(--accent); }

    .uid-cell { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--text-dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .url-cell { font-size: 11px; color: var(--text-dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .rid-cell {
        font-family: 'IBM Plex Mono', monospace; font-size: 11px;
        color: var(--info); cursor: pointer; white-space: nowrap;
        text-decoration: underline; text-decoration-style: dotted;
    }
    .rid-cell:hover { color: #93c5fd; }

    /* Empty state */
    .empty-state {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        flex: 1; gap: 12px; padding: 60px 20px; color: var(--text-muted);
    }
    .empty-icon { font-size: 40px; opacity: .4; }
    .empty-title { font-size: 14px; font-weight: 500; color: var(--text-dim); }
    .empty-sub { font-size: 12px; text-align: center; max-width: 280px; }

    /* Error banner */
    .error-banner {
        display: none; margin: 16px 20px; padding: 10px 14px;
        background: rgba(255,77,77,.1); border: 1px solid rgba(255,77,77,.3);
        border-radius: var(--radius); color: #ff6b6b; font-size: 12px;
        font-family: 'IBM Plex Mono', monospace;
    }

    /* Pagination */
    .pagination {
        display: flex; align-items: center; justify-content: center; gap: 4px;
        padding: 12px 20px; border-top: 1px solid var(--border);
        background: var(--surface);
    }
    .page-btn {
        min-width: 32px; height: 28px; padding: 0 6px;
        background: transparent; border: 1px solid var(--border);
        border-radius: 4px; color: var(--text-dim); font-size: 12px;
        font-family: 'IBM Plex Mono', monospace; cursor: pointer;
        transition: all .15s; display: flex; align-items: center; justify-content: center;
    }
    .page-btn:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
    .page-btn.active { background: var(--accent); border-color: var(--accent); color: #000; font-weight: 600; }
    .page-btn:disabled { opacity: .3; cursor: default; }
    .page-ellipsis { color: var(--text-muted); font-size: 12px; padding: 0 4px; }

    /* ── MODAL ────────────────────────────────────────────────── */
    .modal-backdrop {
        display: none; position: fixed; inset: 0; z-index: 200;
        background: rgba(0,0,0,.7); backdrop-filter: blur(2px);
        align-items: center; justify-content: center;
        padding: 20px;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 10px; width: 100%; max-width: 680px;
        max-height: 88vh; overflow-y: auto; padding: 24px;
        display: flex; flex-direction: column; gap: 16px;
    }
    .modal::-webkit-scrollbar { width: 4px; }
    .modal::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
    .modal-header { display: flex; align-items: center; justify-content: space-between; }
    .modal-title { font-family: 'IBM Plex Mono', monospace; font-size: 13px; font-weight: 600; color: var(--accent); }
    .modal-close {
        width: 28px; height: 28px; background: var(--surface2); border: 1px solid var(--border);
        border-radius: 4px; color: var(--text-muted); cursor: pointer; font-size: 16px;
        display: flex; align-items: center; justify-content: center; line-height: 1;
        transition: all .15s;
    }
    .modal-close:hover { border-color: var(--error); color: var(--error); }
    .modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
    .modal-field label {
        display: block; font-size: 10px; font-weight: 600; letter-spacing: .1em;
        text-transform: uppercase; color: var(--text-muted); margin-bottom: 3px;
        font-family: 'IBM Plex Mono', monospace;
    }
    .modal-field .val {
        font-size: 12px; color: var(--text); word-break: break-all;
        font-family: 'IBM Plex Mono', monospace;
    }
    .modal-field.full { grid-column: 1 / -1; }
    .modal-divider { border: none; border-top: 1px solid var(--border); }
    .modal-raw-label {
        font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 600;
        letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted);
    }
    .modal-raw {
        background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
        padding: 12px; font-family: 'IBM Plex Mono', monospace; font-size: 11px;
        color: var(--text-dim); overflow-x: auto; white-space: pre; line-height: 1.6;
        max-height: 280px;
    }
</style>
</head>
<body>

<!-- HEADER -->
<header class="header">
    <div class="header-dot"></div>
    <div class="header-title">Cloud<span>Watch</span> Viewer</div>
</header>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <!-- LOG GROUPS -->
        <div>
            <div class="section-label">Log Groups</div>
            <div class="group-list" id="groupList">
                @forelse ($logGroups as $group)
                <div class="group-item">
                    <input type="checkbox" id="grp_{{ $loop->index }}"
                           value="{{ $group['value'] }}" checked>
                    <label for="grp_{{ $loop->index }}" title="{{ $group['value'] }}">
                        {{ $group['name'] }}
                    </label>
                </div>
                @empty
                <p style="font-size:11px;color:var(--text-muted);">No log groups configured.<br>Edit <code style="color:var(--accent)">config/cloudwatch-viewer.php</code>.</p>
                @endforelse
            </div>
        </div>

        <!-- LOG LEVEL -->
        <div>
            <div class="section-label">Log Level</div>
            <div class="level-pills">
                @foreach (['ALL','ERROR','WARNING','INFO','DEBUG'] as $lvl)
                <button class="level-pill {{ $lvl === 'ALL' ? 'active' : '' }}"
                        data-level="{{ $lvl }}">{{ $lvl }}</button>
                @endforeach
            </div>
        </div>

        <!-- DATE RANGE -->
        <div>
            <div class="section-label">Date Range</div>
            <div class="date-group">
                <div class="date-label">From</div>
                <input type="datetime-local" id="startDate">
                <div class="date-label" style="margin-top:4px;">To</div>
                <input type="datetime-local" id="endDate">
            </div>
        </div>

        <!-- SEARCH -->
        <div>
            <div class="section-label">Search</div>
            <div class="search-group">
                <input type="text" id="filterMessage"    class="search-input" placeholder="Message">
                <input type="text" id="filterUserId"     class="search-input" placeholder="User ID">
                <input type="text" id="filterRequestId"  class="search-input" placeholder="Request ID">
                <input type="text" id="filterUrl"        class="search-input" placeholder="URL / Endpoint">
            </div>
        </div>

        <hr class="divider">

        <button class="btn-primary" id="searchBtn">→ Search Logs</button>
        <button class="btn-secondary" id="resetBtn">Reset Filters</button>

    </aside>

    <!-- MAIN -->
    <main class="main" id="mainArea">

        <!-- Loading bar -->
        <div class="loading-bar"><div class="loading-bar-inner" id="loadingBar"></div></div>

        <!-- Error banner -->
        <div class="error-banner" id="errorBanner"></div>

        <!-- Toolbar -->
        <div class="toolbar" id="toolbar" style="display:none;">
            <div class="toolbar-count" id="toolbarCount"></div>
        </div>

        <!-- Table wrapper -->
        <div class="table-wrapper" id="tableWrapper" style="display:none;">
            <table>
                <thead>
                    @php
                        $colWidths = [
                            '@timestamp'         => '160px',
                            'level_name'         => '90px',
                            'message'            => '',
                            'context.request_id' => '100px',
                            'context.user_id'    => '110px',
                            'context.url'        => '160px',
                            '@logStream'         => '140px',
                        ];
                    @endphp
                    <tr>
                        @foreach ($columns as $col)
                            @php $w = $colWidths[$col['field']] ?? '120px'; @endphp
                            <th style="{{ $w ? 'width:'.$w.';' : '' }}">{{ $col['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody id="logTableBody"></tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination" id="paginationBar" style="display:none;"></div>

        <!-- Empty state -->
        <div class="empty-state" id="emptyState">
            <div class="empty-icon">◈</div>
            <div class="empty-title">No logs loaded</div>
            <div class="empty-sub">Select log groups, set your filters, and click <strong>Search Logs</strong> to query CloudWatch Insights.</div>
        </div>

    </main>
</div>

<!-- DETAIL MODAL -->
<div class="modal-backdrop" id="modalBackdrop">
    <div class="modal" id="modalContent" role="dialog" aria-modal="true">
        <div class="modal-header">
            <div class="modal-title">Log Entry Detail</div>
            <button class="modal-close" id="modalClose" title="Close (Esc)">✕</button>
        </div>
        <div class="modal-grid" id="modalFields"></div>
        <hr class="modal-divider">
        <div class="modal-raw-label">Raw JSON</div>
        <pre class="modal-raw" id="modalRaw"></pre>
    </div>
</div>

<script>
(function () {
    'use strict';

    const PAGE_SIZE   = 25;
    const FETCH_URL   = '{{ route('cloudwatch-viewer.fetch') }}';
    const COLUMNS     = @json($columns);

    let allLogs       = [];
    let currentPage   = 1;
    let activeLevel   = 'ALL';

    // ── Helpers ────────────────────────────────────────────────
    function escHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function levelBadge(level) {
        const map = { ERROR:'badge-ERROR', WARNING:'badge-WARNING', INFO:'badge-INFO', DEBUG:'badge-DEBUG' };
        const cls = map[(level||'').toUpperCase()] || 'badge-DEFAULT';
        return `<span class="badge ${cls}">${escHtml(level || '—')}</span>`;
    }

    function shortRid(rid) {
        return rid ? rid.slice(0, 8) : '—';
    }

    function formatTs(ts) {
        if (!ts) return '—';
        try {
            // CloudWatch returns timestamps like "2024-01-15 12:34:56.789"
            const d = new Date(ts.replace(' ', 'T'));
            if (isNaN(d)) return escHtml(ts);
            return d.toLocaleString(undefined, {
                year:'numeric', month:'2-digit', day:'2-digit',
                hour:'2-digit', minute:'2-digit', second:'2-digit',
                hour12: false
            });
        } catch(e) { return escHtml(ts); }
    }

    // ── Cell renderer ─────────────────────────────────────────
    function renderCell(field, val, idx) {
        const v = val ?? '';
        switch (field) {
            case '@timestamp':
                return `<td class="ts-cell">${escHtml(formatTs(v))}</td>`;
            case 'level_name':
                return `<td>${levelBadge((v).toUpperCase())}</td>`;
            case 'message':
                return `<td class="msg-cell" data-idx="${idx}" title="${escHtml(v)}">${escHtml(v || '—')}</td>`;
            case 'context.request_id': {
                const rid = v;
                return `<td class="rid-cell" data-rid="${escHtml(rid)}" title="${escHtml(rid)}">${escHtml(rid ? rid.slice(0, 8) : '—')}</td>`;
            }
            default:
                return `<td class="default-cell" title="${escHtml(v)}">${escHtml(v || '—')}</td>`;
        }
    }

    // ── Default date range (last 24 hours) ────────────────────
    function setDefaultDates() {
        const now  = new Date();
        const from = new Date(now.getTime() - 24 * 60 * 60 * 1000);
        const fmt  = d => {
            const pad = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        };
        document.getElementById('startDate').value = fmt(from);
        document.getElementById('endDate').value   = fmt(now);
    }

    // ── Level pill selection ───────────────────────────────────
    document.querySelectorAll('.level-pill').forEach(pill => {
        pill.addEventListener('click', () => {
            document.querySelectorAll('.level-pill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            activeLevel = pill.dataset.level;
        });
    });

    // ── Build query params ─────────────────────────────────────
    function buildParams() {
        const params = new URLSearchParams();

        document.querySelectorAll('#groupList input[type="checkbox"]:checked').forEach(cb => {
            params.append('log_groups[]', cb.value);
        });

        params.set('level',      activeLevel);
        params.set('message',    document.getElementById('filterMessage').value.trim());
        params.set('user_id',    document.getElementById('filterUserId').value.trim());
        params.set('request_id', document.getElementById('filterRequestId').value.trim());
        params.set('url',        document.getElementById('filterUrl').value.trim());
        params.set('start_date', document.getElementById('startDate').value);
        params.set('end_date',   document.getElementById('endDate').value);

        return params;
    }

    // ── Fetch logs ─────────────────────────────────────────────
    async function fetchLogs() {
        const params = buildParams();

        if (!params.getAll('log_groups[]').length) {
            showError('Please select at least one log group.');
            return;
        }

        setLoading(true);
        hideError();
        hideResults();

        try {
            const res  = await fetch(`${FETCH_URL}?${params.toString()}`);
            const data = await res.json();

            if (!res.ok) {
                showError(data.error || `Server error (${res.status})`);
                return;
            }

            allLogs     = data.logs || [];
            currentPage = 1;
            renderTable();
        } catch (err) {
            showError('Network error: ' + err.message);
        } finally {
            setLoading(false);
        }
    }

    // ── Render table ───────────────────────────────────────────
    function renderTable() {
        const tbody     = document.getElementById('logTableBody');
        const toolbar   = document.getElementById('toolbar');
        const tableWrap = document.getElementById('tableWrapper');
        const pagination= document.getElementById('paginationBar');
        const empty     = document.getElementById('emptyState');
        const count     = document.getElementById('toolbarCount');

        if (allLogs.length === 0) {
            toolbar.style.display   = 'none';
            tableWrap.style.display = 'none';
            pagination.style.display= 'none';
            empty.style.display     = 'flex';
            empty.querySelector('.empty-title').textContent = 'No results found';
            empty.querySelector('.empty-sub').textContent   = 'Try adjusting your filters or expanding the date range.';
            return;
        }

        empty.style.display      = 'none';
        toolbar.style.display    = 'flex';
        tableWrap.style.display  = 'block';

        const totalPages = Math.ceil(allLogs.length / PAGE_SIZE);
        currentPage      = Math.max(1, Math.min(currentPage, totalPages));

        const pageStart  = (currentPage - 1) * PAGE_SIZE;
        const pageLogs   = allLogs.slice(pageStart, pageStart + PAGE_SIZE);

        count.innerHTML = `Showing <strong>${pageStart + 1}–${pageStart + pageLogs.length}</strong> of <strong>${allLogs.length}</strong> results`;

        tbody.innerHTML = pageLogs.map((log, idx) => {
            const absIdx = pageStart + idx;
            const cells  = COLUMNS.map(col => renderCell(col.field, log[col.field], absIdx)).join('');
            return `<tr>${cells}</tr>`;
        }).join('');

        // Message click → modal
        tbody.querySelectorAll('.msg-cell').forEach(cell => {
            cell.addEventListener('click', () => openModal(allLogs[+cell.dataset.idx]));
        });

        // Request ID click → filter
        tbody.querySelectorAll('.rid-cell').forEach(cell => {
            cell.addEventListener('click', () => {
                const rid = cell.dataset.rid;
                if (rid) {
                    document.getElementById('filterRequestId').value = rid;
                    fetchLogs();
                }
            });
        });

        renderPagination(totalPages);
    }

    // ── Pagination ─────────────────────────────────────────────
    function renderPagination(totalPages) {
        const bar = document.getElementById('paginationBar');
        if (totalPages <= 1) { bar.style.display = 'none'; return; }

        bar.style.display = 'flex';
        const pages = smartPages(currentPage, totalPages);

        bar.innerHTML = `
            <button class="page-btn" id="pagePrev" ${currentPage === 1 ? 'disabled' : ''}>‹ Prev</button>
            ${pages.map(p => p === '…'
                ? `<span class="page-ellipsis">…</span>`
                : `<button class="page-btn ${p === currentPage ? 'active' : ''}" data-page="${p}">${p}</button>`
            ).join('')}
            <button class="page-btn" id="pageNext" ${currentPage === totalPages ? 'disabled' : ''}>Next ›</button>
        `;

        bar.querySelector('#pagePrev').addEventListener('click', () => goPage(currentPage - 1, totalPages));
        bar.querySelector('#pageNext').addEventListener('click', () => goPage(currentPage + 1, totalPages));
        bar.querySelectorAll('[data-page]').forEach(btn => {
            btn.addEventListener('click', () => goPage(+btn.dataset.page, totalPages));
        });
    }

    function smartPages(cur, total) {
        if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
        const pages = [];
        pages.push(1);
        if (cur > 3)              pages.push('…');
        for (let p = Math.max(2, cur-1); p <= Math.min(total-1, cur+1); p++) pages.push(p);
        if (cur < total - 2)      pages.push('…');
        pages.push(total);
        return pages;
    }

    function goPage(page, total) {
        currentPage = Math.max(1, Math.min(page, total));
        renderTable();
        document.getElementById('mainArea').scrollTop = 0;
    }

    // ── Modal ──────────────────────────────────────────────────
    function openModal(log) {
        const fields = document.getElementById('modalFields');
        const raw    = document.getElementById('modalRaw');

        const FIELD_LABELS = {
            '@timestamp':          'Timestamp',
            'level_name':          'Level',
            'message':             'Message',
            'context.user_id':     'User ID',
            'context.request_id':  'Request ID',
            'context.method':      'Method',
            'context.url':         'URL',
            'context.ip':          'IP Address',
            'context.environment': 'Environment',
            '@logStream':          'Log Stream',
        };

        fields.innerHTML = Object.entries(log)
            .filter(([, v]) => v != null && v !== '')
            .map(([key, val]) => {
                const label = FIELD_LABELS[key] || key;
                const isMsg = key === 'message';
                return `<div class="modal-field${isMsg ? ' full' : ''}">
                    <label>${escHtml(label)}</label>
                    <div class="val">${escHtml(val)}</div>
                </div>`;
            }).join('');

        raw.textContent = JSON.stringify(log, null, 2);

        document.getElementById('modalBackdrop').classList.add('open');
    }

    function closeModal() {
        document.getElementById('modalBackdrop').classList.remove('open');
    }

    document.getElementById('modalClose').addEventListener('click', closeModal);
    document.getElementById('modalBackdrop').addEventListener('click', e => {
        if (e.target === document.getElementById('modalBackdrop')) closeModal();
    });

    // ── UI state helpers ───────────────────────────────────────
    function setLoading(on) {
        document.getElementById('loadingBar').classList.toggle('active', on);
        document.getElementById('searchBtn').disabled = on;
        document.getElementById('searchBtn').textContent = on ? '… Querying' : '→ Search Logs';
    }

    function showError(msg) {
        const el = document.getElementById('errorBanner');
        el.textContent = '⚠ ' + msg;
        el.style.display = 'block';
    }

    function hideError() {
        document.getElementById('errorBanner').style.display = 'none';
    }

    function hideResults() {
        document.getElementById('toolbar').style.display    = 'none';
        document.getElementById('tableWrapper').style.display = 'none';
        document.getElementById('paginationBar').style.display = 'none';
    }

    // ── Reset ──────────────────────────────────────────────────
    document.getElementById('resetBtn').addEventListener('click', () => {
        document.querySelectorAll('#groupList input[type="checkbox"]').forEach(cb => cb.checked = true);
        document.querySelectorAll('.level-pill').forEach(p => {
            p.classList.toggle('active', p.dataset.level === 'ALL');
        });
        activeLevel = 'ALL';
        setDefaultDates();
        document.getElementById('filterMessage').value   = '';
        document.getElementById('filterUserId').value    = '';
        document.getElementById('filterRequestId').value = '';
        document.getElementById('filterUrl').value       = '';
    });

    // ── Search button ──────────────────────────────────────────
    document.getElementById('searchBtn').addEventListener('click', fetchLogs);

    // ── Enter key triggers search ──────────────────────────────
    ['filterMessage','filterUserId','filterRequestId','filterUrl','startDate','endDate'].forEach(id => {
        document.getElementById(id).addEventListener('keydown', e => {
            if (e.key === 'Enter') fetchLogs();
        });
    });

    // ── Escape closes modal ────────────────────────────────────
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeModal();
    });

    // ── Init ───────────────────────────────────────────────────
    setDefaultDates();

})();
</script>
</body>
</html>
