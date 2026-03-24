@extends('layouts.app', [
    'title' => 'Interesting Jobs',
    'heading' => 'Interesting Jobs',
    'subheading' => $jobs->total().' shortlisted roles in the shared SQLite database.',
])

@section('content')
    <form method="get" action="{{ route('interesting-jobs.index') }}" class="panel filters">
        <div>
            <label for="q">Search</label>
            <input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Title, company, notes">
        </div>
        <div>
            <label for="decision">Decision</label>
            <select id="decision" name="decision">
                <option value="">Any</option>
                @foreach ($decisionOptions as $decision)
                    <option value="{{ $decision }}" @selected(($filters['decision'] ?? '') === $decision)>{{ ucfirst($decision) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">Any</option>
                @foreach ($statusOptions as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="min_score">Min score</label>
            <input class="compact-input" id="min_score" type="text" name="min_score" value="{{ $filters['min_score'] ?? '' }}" placeholder="e.g. 35">
        </div>
        <div class="checkbox">
            <input id="remote_only" type="checkbox" name="remote_only" value="1" @checked(($filters['remote_only'] ?? null) === '1')>
            <label for="remote_only" style="margin: 0;">Remote only</label>
        </div>
        <div class="actions">
            <button class="button" type="submit">Apply filters</button>
            <a class="button secondary" href="{{ route('interesting-jobs.index') }}">Reset</a>
        </div>
    </form>

    <div class="panel table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Signals</th>
                    <th>Score</th>
                    <th>Notes</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($jobs as $job)
                    <tr>
                        <td>
                            <div class="job-title">{{ $job->title }}</div>
                            <div>{{ $job->company }}</div>
                            <div class="muted">{{ $job->location_raw ?: 'Location unknown' }}</div>
                            <div style="margin-top: 8px;">
                                <a href="{{ $job->url }}" target="_blank" rel="noreferrer">Open listing</a>
                            </div>
                        </td>
                        <td>
                            <span class="pill {{ $job->ai_decision }}">{{ strtoupper($job->ai_decision) }}</span>
                            <span class="pill status">{{ strtoupper($job->shortlist_status) }}</span>
                            @if ($job->remote_type)
                                <span class="pill">{{ strtoupper($job->remote_type) }}</span>
                            @endif
                            @if (\Illuminate\Support\Str::contains((string) $job->ai_reason, 'language penalty: fr advert'))
                                <span class="pill language">FR PENALTY</span>
                            @endif
                            @if ($job->contract_type)
                                <div class="muted" style="margin-top: 8px;">{{ $job->contract_type }}</div>
                            @endif
                        </td>
                        <td>
                            <div class="score">{{ number_format((float) $job->ai_score, 0) }}</div>
                        </td>
                        <td class="muted">
                            {{ \Illuminate\Support\Str::limit($job->notes ?: $job->ai_reason, 160) }}
                        </td>
                        <td>
                            <a class="button secondary" href="{{ route('interesting-jobs.edit', $job) }}">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="muted">No shortlisted jobs matched your filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">
            {{ $jobs->links() }}
        </div>
    </div>
@endsection
