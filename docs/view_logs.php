<?php
// Real-time log viewer for debugging
// Access at: /ai/placement/modgen/view_logs.php

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Modgen Debug Logs - Real-Time Viewer</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            color: #4ec9b0;
            border-bottom: 2px solid #4ec9b0;
            padding-bottom: 10px;
        }
        .controls {
            background: #252526;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        button {
            background: #0e639c;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-family: monospace;
        }
        button:hover {
            background: #1177bb;
        }
        .log-container {
            background: #1e1e1e;
            border: 1px solid #464647;
            border-radius: 4px;
            padding: 15px;
            max-height: 600px;
            overflow-y: auto;
            line-height: 1.6;
        }
        .log-line {
            margin: 2px 0;
            padding: 2px 0;
        }
        .log-debug {
            color: #ce9178;
        }
        .log-template {
            color: #4ec9b0;
            font-weight: bold;
        }
        .log-error {
            color: #f48771;
        }
        .log-success {
            color: #89d185;
        }
        .timestamp {
            color: #858585;
        }
        .filter-info {
            color: #858585;
            font-size: 12px;
        }
        input {
            padding: 8px;
            background: #3c3c3c;
            color: #d4d4d4;
            border: 1px solid #464647;
            border-radius: 4px;
            font-family: monospace;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            background: #252526;
            padding: 10px;
            border-radius: 4px;
        }
        .stat {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .stat-value {
            color: #4ec9b0;
            font-weight: bold;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Modgen Template Debug Logs - Real-Time Viewer</h1>
        
        <div class="stats" id="stats">
            <div class="stat">
                <span>Log Lines:</span>
                <span class="stat-value" id="line-count">0</span>
            </div>
            <div class="stat">
                <span>Debug Lines:</span>
                <span class="stat-value" id="debug-count">0</span>
            </div>
            <div class="stat">
                <span>Template Lines:</span>
                <span class="stat-value" id="template-count">0</span>
            </div>
        </div>
        
        <div class="controls">
            <button onclick="refreshLogs()">🔄 Refresh Now</button>
            <label>
                <input type="checkbox" id="auto-refresh" checked> Auto-refresh (2s)
            </label>
            <label>
                Filter: <input type="text" id="filter" placeholder="Type to filter..." style="width: 200px;">
            </label>
            <button onclick="clearFilter()">Clear Filter</button>
            <button onclick="downloadLogs()">⬇️ Download</button>
            <span class="filter-info" id="filter-info"></span>
        </div>
        
        <div class="log-container" id="logs">
            Loading logs...
        </div>
    </div>

    <script>
        let autoRefreshEnabled = true;
        let allLogs = [];
        let filteredLogs = [];
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function colorizeLog(line) {
            const text = escapeHtml(line);
            
            // Color code different log types
            if (text.includes('DEBUG:')) {
                return '<span class="log-debug">' + text + '</span>';
            }
            if (text.includes('Template') || text.includes('template') || text.includes('curriculum')) {
                return '<span class="log-template">' + text + '</span>';
            }
            if (text.includes('ERROR') || text.includes('EXCEPTION') || text.includes('WARNING')) {
                return '<span class="log-error">' + text + '</span>';
            }
            if (text.includes('✓') || text.includes('generated') || text.includes('extracted')) {
                return '<span class="log-success">' + text + '</span>';
            }
            
            return text;
        }
        
        function refreshLogs() {
            fetch('view_logs.php?ajax=1')
                .then(r => r.json())
                .then(data => {
                    allLogs = data.logs || [];
                    applyFilter();
                    updateStats();
                })
                .catch(e => console.error('Error loading logs:', e));
        }
        
        function applyFilter() {
            const filter = document.getElementById('filter').value.toLowerCase();
            
            if (filter === '') {
                filteredLogs = allLogs;
                document.getElementById('filter-info').textContent = '';
            } else {
                filteredLogs = allLogs.filter(line => line.toLowerCase().includes(filter));
                document.getElementById('filter-info').textContent = 
                    `Showing ${filteredLogs.length} of ${allLogs.length} lines`;
            }
            
            renderLogs();
        }
        
        function renderLogs() {
            const container = document.getElementById('logs');
            const html = filteredLogs.map((line, i) => {
                const colored = colorizeLog(line);
                return `<div class="log-line">${colored}</div>`;
            }).join('');
            
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        }
        
        function updateStats() {
            const debugCount = allLogs.filter(l => l.includes('DEBUG:')).length;
            const templateCount = allLogs.filter(l => 
                l.includes('template') || l.includes('Template') || l.includes('curriculum')).length;
            
            document.getElementById('line-count').textContent = allLogs.length;
            document.getElementById('debug-count').textContent = debugCount;
            document.getElementById('template-count').textContent = templateCount;
        }
        
        function clearFilter() {
            document.getElementById('filter').value = '';
            applyFilter();
        }
        
        function downloadLogs() {
            const text = allLogs.join('\n');
            const blob = new Blob([text], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'modgen-debug-' + new Date().toISOString().split('T')[0] + '.log';
            a.click();
            URL.revokeObjectURL(url);
        }
        
        // Set up filtering
        document.getElementById('filter').addEventListener('input', applyFilter);
        
        // Set up auto-refresh
        document.getElementById('auto-refresh').addEventListener('change', (e) => {
            autoRefreshEnabled = e.target.checked;
            if (autoRefreshEnabled) {
                startAutoRefresh();
            }
        });
        
        function startAutoRefresh() {
            if (autoRefreshEnabled) {
                refreshLogs();
                setTimeout(startAutoRefresh, 2000);
            }
        }
        
        // Initial load
        refreshLogs();
        startAutoRefresh();
    </script>
</body>
</html>
<?php
// Handle AJAX request for logs
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    $log_file = '/Users/tom/moodledata45/modgen_logs/debug.log';
    $logs = [];
    
    if (file_exists($log_file)) {
        $lines = file($log_file);
        // Get last 500 lines
        $lines = array_slice($lines, -500);
        $logs = array_map('rtrim', $lines);
    }
    
    echo json_encode(['logs' => $logs]);
    exit;
}
?>
