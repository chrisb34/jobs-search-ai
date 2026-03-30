@extends('layouts.app', [
    'title' => 'Console',
    'heading' => 'Console',
    'subheading' => 'Run allowlisted Python jobfinder commands manually from the UI.',
])

@section('content')
    <div class="panel" style="padding: 18px; margin-bottom: 18px;">
        <form method="post" action="{{ route('console.run') }}" class="filters">
            @csrf
            <div>
                <label for="action">Action</label>
                <select id="action" name="action">
                    @foreach ($actions as $key => $action)
                        <option value="{{ $key }}">{{ $action['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="pages">Pages</label>
                <input id="pages" type="text" name="pages" value="1" placeholder="Used for saved searches">
            </div>
            <div>
                <label for="search_name">Search name</label>
                <select id="search_name" name="search_name">
                    <option value="">All saved searches</option>
                    @foreach ($savedSearchNames as $savedSearchName)
                        <option value="{{ $savedSearchName }}">{{ $savedSearchName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="min_rule_score">Min rule score</label>
                <input id="min_rule_score" type="text" name="min_rule_score" value="35" placeholder="Used for LLM scoring">
            </div>
            <div class="checkbox">
                <input id="only_unscored" type="checkbox" name="only_unscored" value="1">
                <label for="only_unscored" style="margin: 0;">Only unscored</label>
            </div>
            <div class="actions">
                <button class="button" type="submit" data-loading-text="Running...">Run command</button>
            </div>
        </form>
    </div>

    <div class="panel" style="padding: 18px; margin-bottom: 18px;">
        <div class="eyebrow">Available Actions</div>
        @foreach ($actions as $action)
            <div style="margin-bottom: 12px;">
                <strong>{{ $action['label'] }}</strong>
                <div class="muted">{{ $action['description'] }}</div>
            </div>
        @endforeach
    </div>

    @if ($result)
        <div class="panel" style="padding: 18px;">
            <div class="eyebrow">Last Run</div>
            <div style="margin-bottom: 8px;"><strong>Action:</strong> {{ $result['action'] }}</div>
            <div style="margin-bottom: 8px;"><strong>Ran at:</strong> {{ $result['ran_at'] }}</div>
            <div style="margin-bottom: 8px;"><strong>Exit code:</strong> {{ $result['exit_code'] }}</div>
            <div style="margin-bottom: 8px;"><strong>Status:</strong> {{ $result['successful'] ? 'Success' : 'Failed' }}</div>
            <div style="margin-bottom: 14px;"><strong>Command:</strong> <code>{{ $result['command'] }}</code></div>

            <div style="margin-bottom: 14px;">
                <label>Output</label>
                <pre class="console-output">{{ $result['output'] ?: 'No stdout output.' }}</pre>
            </div>

            @if (!empty($result['error_output']))
                <div>
                    <label>Error output</label>
                    <pre class="console-output console-error">{{ $result['error_output'] }}</pre>
                </div>
            @endif
        </div>
    @endif
@endsection
