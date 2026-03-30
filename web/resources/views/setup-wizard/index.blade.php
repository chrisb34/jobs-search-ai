@extends('layouts.app', [
    'title' => 'Setup Wizard',
    'heading' => 'Setup Wizard',
    'subheading' => 'Configure database access and generate local profile files from a CV.',
])

@section('content')
    <div class="panel" style="padding: 22px; margin-bottom: 18px;">
        <div class="eyebrow">Architecture</div>
        <div class="muted" style="line-height: 1.6;">
            This wizard keeps tracked templates generic and writes only local override files:
            <code>config/criteria.local.yaml</code> and <code>web/config/applicant.local.php</code>.
            Database settings are written to <code>web/.env</code> after a connection check.
        </div>
    </div>

    <div class="panel" style="padding: 22px; margin-bottom: 18px;">
        <div class="eyebrow">1. Database Setup</div>
        <form method="post" action="{{ route('setup-wizard.database') }}" class="filters" style="padding: 0; margin: 16px 0 0;">
            @csrf
            <div>
                <label for="connection">Driver</label>
                <select id="connection" name="connection">
                    @foreach (['sqlite', 'mysql', 'mariadb', 'pgsql'] as $driver)
                        <option value="{{ $driver }}" @selected(old('connection', $dbSettings['connection']) === $driver)>{{ $driver }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="database">Database / Path</label>
                <input id="database" type="text" name="database" value="{{ old('database', $dbSettings['database']) }}" placeholder="../data/jobs.db or database name">
            </div>
            <div>
                <label for="host">Host</label>
                <input id="host" type="text" name="host" value="{{ old('host', $dbSettings['host']) }}" placeholder="127.0.0.1">
            </div>
            <div>
                <label for="port">Port</label>
                <input id="port" type="text" name="port" value="{{ old('port', $dbSettings['port']) }}" placeholder="3306 / 5432">
            </div>
            <div>
                <label for="username">Username</label>
                <input id="username" type="text" name="username" value="{{ old('username', $dbSettings['username']) }}">
            </div>
            <div>
                <label for="password">Password</label>
                <input id="password" type="text" name="password" value="{{ old('password', $dbSettings['password']) }}">
            </div>
            <div class="actions" style="grid-column: 1 / -1;">
                <button class="button" type="submit">Save and validate DB settings</button>
            </div>
        </form>
    </div>

    <div class="panel" style="padding: 22px; margin-bottom: 18px;">
        <div class="eyebrow">2. Generate Local Config From CV</div>
        <form method="post" action="{{ route('setup-wizard.generate') }}" enctype="multipart/form-data" class="edit-grid">
            @csrf
            <div>
                <label for="cv_file">CV file</label>
                <input id="cv_file" type="file" name="cv_file" accept=".txt,.md,.docx,.doc,.pdf">
                <div class="muted" style="margin-top: 8px;">
                    Preferred formats: <code>txt</code>, <code>md</code>, <code>docx</code>.
                    Supported with more caveats: <code>doc</code>, <code>pdf</code>.
                    Text-based PDFs usually work, but complex layouts, multi-column CVs, and scanned/image PDFs can extract poorly.
                    PDF extraction requires <code>pdftotext</code>; DOC extraction uses <code>textutil</code>.
                </div>
            </div>
            <div>
                <label for="extra_context">Extra context</label>
                <textarea id="extra_context" name="extra_context" placeholder="Add role preferences, industries to avoid, geography preferences, language notes, etc.">{{ old('extra_context') }}</textarea>
            </div>
            <div class="actions">
                <button class="button" type="submit">Generate local criteria and applicant profile</button>
            </div>
        </form>
    </div>

    @if ($wizardResult)
        <div class="panel" style="padding: 22px; margin-bottom: 18px;">
            <div class="eyebrow">Generation Result</div>
            <div class="muted" style="margin-bottom: 12px;">Extracted CV text length: {{ number_format($wizardResult['cv_length'] ?? 0) }} characters</div>
            <label>Generated criteria.local.yaml</label>
            <pre class="console-output" style="margin-bottom: 16px;">{{ $wizardResult['criteria_preview'] ?? 'No preview available.' }}</pre>
            <label>Generated applicant.local.php</label>
            <pre class="console-output">{{ $wizardResult['applicant_preview'] ?? 'No preview available.' }}</pre>
        </div>
    @endif

    <div class="panel" style="padding: 22px; margin-bottom: 18px;">
        <div class="eyebrow">Current Local Files</div>
        <label>config/criteria.local.yaml</label>
        <pre class="console-output" style="margin-bottom: 16px;">{{ $generatedFiles['criteria'] ?: 'File not created yet.' }}</pre>
        <label>web/config/applicant.local.php</label>
        <pre class="console-output">{{ $generatedFiles['applicant'] ?: 'File not created yet.' }}</pre>
    </div>
@endsection
