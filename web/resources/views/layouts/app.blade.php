<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Jobs AI' }}</title>
    <style>
        :root {
            --bg: #f6f1e8;
            --panel: #fffdf9;
            --ink: #1f2933;
            --muted: #5b6874;
            --line: #d8ccb8;
            --accent: #165d4a;
            --accent-soft: #e4f1ec;
            --warn: #a2461e;
            --shadow: 0 12px 30px rgba(31, 41, 51, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top right, #efe7d8, transparent 28%),
                linear-gradient(180deg, #f8f4ed 0%, var(--bg) 100%);
        }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .page { max-width: 1280px; margin: 0 auto; padding: 32px 20px 48px; }
        .header { display: flex; justify-content: space-between; align-items: end; gap: 16px; margin-bottom: 24px; }
        .eyebrow { letter-spacing: 0.12em; text-transform: uppercase; font-size: 12px; color: var(--muted); margin-bottom: 8px; }
        h1 { margin: 0; font-size: clamp(2rem, 4vw, 3.4rem); line-height: 0.95; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 18px; box-shadow: var(--shadow); }
        .flash { margin-bottom: 16px; padding: 12px 14px; background: var(--accent-soft); border: 1px solid #b4d4c8; border-radius: 12px; }
        .filters, .edit-grid { display: grid; gap: 12px; }
        .filters { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); padding: 16px; margin-bottom: 18px; }
        label { display: block; font-size: 0.9rem; color: var(--muted); margin-bottom: 6px; }
        input[type="text"], textarea, select { width: 100%; border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; font: inherit; background: #fff; color: var(--ink); }
        textarea { min-height: 180px; resize: vertical; }
        .config-editor { min-height: 360px; font-family: "SFMono-Regular", Menlo, Monaco, Consolas, monospace; font-size: 0.92rem; line-height: 1.5; }
        .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .button { display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--accent); background: var(--accent); color: #fff; padding: 10px 14px; border-radius: 999px; font-weight: 600; cursor: pointer; }
        .button.secondary { background: transparent; color: var(--accent); }
        .button[disabled] { opacity: 0.8; cursor: wait; }
        .button .spinner { display: none; width: 14px; height: 14px; margin-right: 8px; border-radius: 999px; border: 2px solid currentColor; border-right-color: transparent; animation: spin 0.75s linear infinite; }
        .button.is-loading .spinner { display: inline-block; }
        .header-links { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); }
        .job-title { font-size: 1rem; font-weight: 700; margin-bottom: 4px; }
        .muted { color: var(--muted); }
        .pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 10px; font-size: 0.78rem; font-weight: 700; border: 1px solid var(--line); background: #f7f1e7; margin-right: 6px; margin-bottom: 6px; }
        .pill.high { background: #dff3eb; border-color: #b1d8c8; }
        .pill.maybe { background: #f8eecf; border-color: #e6d28a; }
        .pill.reject { background: #f7ddd6; border-color: #e2b5a8; }
        .pill.status { background: #eef2f4; border-color: #d6dde2; }
        .pill.language { background: #efe6fb; border-color: #ccb7ec; color: #5d3a8c; }
        .pill.duplicate { background: #e5eef8; border-color: #b4cbe5; color: #2e5479; }
        .score { font-size: 1.2rem; font-weight: 700; }
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 18px 0; }
        .meta-card { padding: 14px; border: 1px solid var(--line); border-radius: 12px; background: #fffcf7; }
        .pagination { padding: 16px; }
        .pagination svg { width: 16px; height: 16px; }
        .checkbox { display: flex; align-items: center; gap: 8px; padding-top: 28px; }
        .compact-input { max-width: 120px; }
        .console-output { margin: 0; padding: 12px; border-radius: 12px; border: 1px solid var(--line); background: #faf7f1; overflow-x: auto; white-space: pre-wrap; line-height: 1.5; font-family: "SFMono-Regular", Menlo, Monaco, Consolas, monospace; font-size: 0.9rem; }
        .console-error { background: #fff1ec; border-color: #e5beb1; }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @media (max-width: 720px) {
            .header { align-items: start; flex-direction: column; }
            th:nth-child(4), td:nth-child(4) { display: none; }
        }
    </style>
</head>
<body>
    <main class="page">
        <div class="header">
            <div>
                <div class="eyebrow">Jobs AI Admin</div>
                <h1>{{ $heading ?? 'Interesting Jobs' }}</h1>
            </div>
            <div class="header-links">
                <a class="button secondary" href="{{ route('interesting-jobs.index') }}">Shortlist</a>
                <a class="button secondary" href="{{ route('setup-wizard.index') }}">Setup</a>
                <a class="button secondary" href="{{ route('console.index') }}">Console</a>
                <a class="button secondary" href="{{ route('jobfinder-config.index') }}">Config</a>
                <div class="muted">{{ $subheading ?? 'Review and update shortlisted roles.' }}</div>
            </div>
        </div>
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('form').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    const submitter = event.submitter;
                    if (!submitter || !submitter.dataset.loadingText) {
                        return;
                    }

                    if (submitter.dataset.loadingApplied === '1') {
                        event.preventDefault();
                        return;
                    }

                    submitter.dataset.loadingApplied = '1';
                    submitter.dataset.originalHtml = submitter.innerHTML;
                    submitter.classList.add('is-loading');
                    submitter.disabled = true;
                    submitter.innerHTML = '<span class="spinner" aria-hidden="true"></span><span>' + submitter.dataset.loadingText + '</span>';
                });
            });
        });
    </script>
</body>
</html>
