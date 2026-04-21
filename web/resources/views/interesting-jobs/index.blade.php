@extends('layouts.app', [
    'title' => 'Interesting Jobs',
    'heading' => 'Interesting Jobs',
    'subheading' => $jobs->total().' shortlisted roles in the shared SQLite database.',
])

@section('content')
    <form method="get" action="{{ route('interesting-jobs.index') }}">
        <div class="panel filters">
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
            <label for="source">Source</label>
            <select id="source" name="source">
                <option value="">Any</option>
                @foreach ($sourceOptions as $source)
                    <option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ strtoupper($source) }}</option>
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
        </div>
        <div class="actions">
            <button class="button" type="submit">Apply filters</button>
            <a class="button secondary" href="{{ route('interesting-jobs.index') }}">Reset</a>
            <a class="button secondary" href="{{ route('false-negatives.index') }}">Review rejected jobs</a>
            <a class="button secondary" href="{{ route('candidate-review.index') }}">Review missed candidates</a>
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
                            <div class="muted">Source: {{ strtoupper($job->source) }} · {{ $job->source_job_id }}</div>
                            <div style="margin-top: 8px;">
                                <a href="{{ $job->url }}" target="_blank" rel="noreferrer">Open listing</a>
                            </div>
                        </td>
                        <td>
                            <span class="pill {{ $job->ai_decision }}">{{ strtoupper($job->ai_decision) }}</span>
                            @if ($job->ai_llm_decision)
                                <span class="pill {{ $job->ai_llm_decision }}">LLM {{ strtoupper($job->ai_llm_decision) }}</span>
                            @endif
                            <span class="pill status">{{ strtoupper($job->shortlist_status) }}</span>
                            @if ($job->false_negative)
                                <span class="pill duplicate">FALSE NEGATIVE</span>
                            @endif
                            @if ($job->remote_type)
                                <span class="pill">{{ strtoupper($job->remote_type) }}</span>
                            @endif
                            @if (\Illuminate\Support\Str::contains((string) $job->ai_reason, 'language penalty: fr advert'))
                                <span class="pill language">FR PENALTY</span>
                            @endif
                            @if (($job->duplicate_count ?? 1) > 1)
                                <span class="pill duplicate">DUP x{{ $job->duplicate_count }}</span>
                            @endif
                            @if (($job->probable_duplicate_count ?? 0) > 0)
                                <span class="pill duplicate">POSSIBLE DUP {{ $job->probable_duplicate_count }}</span>
                            @endif
                            @if ($job->contract_type)
                                <div class="muted" style="margin-top: 8px;">{{ $job->contract_type }}</div>
                            @endif
                        </td>
                        <td>
                            <div class="score">{{ number_format((float) $job->ai_score, 0) }}</div>
                            <div class="muted" style="margin-top: 6px;">Rule</div>
                            @if (!is_null($job->ai_llm_score))
                                <div class="score" style="margin-top: 12px;">{{ number_format((float) $job->ai_llm_score, 0) }}</div>
                                <div class="muted" style="margin-top: 6px;">LLM</div>
                            @endif
                        </td>
                        <td class="muted">
                            {{ \Illuminate\Support\Str::limit($job->notes ?: ($job->ai_llm_reason ?: $job->ai_reason), 160) }}
                            @if (($job->duplicate_count ?? 1) > 1 && !empty($job->duplicate_sources_json))
                                <div style="margin-top: 8px;">
                                    {{ collect($job->duplicate_sources_json)->pluck('source')->unique()->implode(', ') }}
                                </div>
                            @endif
                            @if (($job->probable_duplicate_count ?? 0) > 0 && !empty($job->probable_duplicate_matches))
                                <div style="margin-top: 8px;">
                                    Possible: {{ $job->probable_duplicate_matches->pluck('title')->map(fn ($title) => \Illuminate\Support\Str::limit($title, 52))->implode(' · ') }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="actions" style="justify-content: flex-end;">
                                <a class="button secondary icon-button" href="{{ route('interesting-jobs.edit', $job) }}" aria-label="Edit job">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M12 20h9" />
                                        <path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
                                    </svg>
                                </a>
                                <details class="menu-wrap">
                                    <summary class="button secondary menu-button" aria-label="More actions">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <circle cx="5" cy="12" r="1.8" />
                                            <circle cx="12" cy="12" r="1.8" />
                                            <circle cx="19" cy="12" r="1.8" />
                                        </svg>
                                    </summary>
                                    <div class="menu-panel">
                                        <form method="post" action="{{ route('interesting-jobs.quick-action', $job) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="not_relevant">
                                            <button class="button secondary" type="submit">Not relevant</button>
                                        </form>
                                        <form method="post" action="{{ route('interesting-jobs.quick-action', $job) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="already_applied">
                                            <button class="button secondary" type="submit">Already applied</button>
                                        </form>
                                        <form method="post" action="{{ route('interesting-jobs.quick-action', $job) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="reject">
                                            <button class="button secondary" type="submit">Rejected</button>
                                        </form>
                                    </div>
                                </details>
                            </div>
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
