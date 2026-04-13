@extends('layouts.app', [
    'title' => 'Candidate Review',
    'heading' => 'Candidate Review',
    'subheading' => 'Inspect scraped jobs that never made the shortlist, then flag the real misses for criteria tuning.',
    'backHref' => route('interesting-jobs.index'),
    'backAriaLabel' => 'Back to interesting jobs',
])

@section('content')
    <form method="get" action="{{ route('candidate-review.index') }}" class="panel filters">
        <div>
            <label for="scope">Scope</label>
            <select id="scope" name="scope">
                <option value="rejected" @selected(($filters['scope'] ?? '') === 'rejected')>Rejected + flagged</option>
                <option value="all" @selected(($filters['scope'] ?? '') === 'all')>All not shortlisted</option>
                <option value="flagged" @selected(($filters['scope'] ?? '') === 'flagged')>Flagged only</option>
            </select>
        </div>
        <div>
            <label for="q">Search</label>
            <input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Title, company, reason">
        </div>
        <div class="actions">
            <button class="button" type="submit">Apply filters</button>
            <a class="button secondary" href="{{ route('candidate-review.index') }}">Reset</a>
            <a class="button secondary" href="{{ route('false-negatives.index') }}">Shortlist feedback</a>
        </div>
    </form>

    <div class="meta-grid">
        <div class="meta-card">
            <div class="eyebrow">Flagged Total</div>
            <div class="score">{{ $suggestions['flagged_count'] }}</div>
            <div class="muted" style="margin-top: 8px;">
                {{ $suggestions['flagged_candidate_count'] ?? 0 }} candidate rows · {{ $suggestions['flagged_shortlist_count'] ?? 0 }} shortlist rows
            </div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Current High Threshold</div>
            <div>{{ number_format((float) ($suggestions['thresholds']['high'] ?? 0), 0) }}</div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Current Maybe Threshold</div>
            <div>{{ number_format((float) ($suggestions['thresholds']['maybe'] ?? 0), 0) }}</div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Lowest Flagged Score</div>
            <div>
                @if (!is_null($suggestions['thresholds']['min_flagged_score'] ?? null))
                    {{ number_format((float) $suggestions['thresholds']['min_flagged_score'], 0) }}
                @else
                    No flagged jobs yet
                @endif
            </div>
        </div>
    </div>

    <div class="panel" style="padding: 18px 22px; margin-bottom: 18px;">
        <div class="eyebrow">Suggestion Summary</div>
        @if (($suggestions['flagged_count'] ?? 0) === 0)
            <div class="muted">No false negatives flagged yet. Mark candidate misses here or shortlist misses on the feedback page, then use the summary to update criteria safely.</div>
        @else
            <div class="meta-grid" style="margin: 12px 0 0;">
                <div class="meta-card">
                    <div class="eyebrow">Suggested title keywords</div>
                    @if (empty($suggestions['title_candidates']))
                        <div class="muted">No clear title-pattern additions yet.</div>
                    @else
                        @foreach ($suggestions['title_candidates'] as $candidate)
                            <div style="margin-bottom: 10px;">
                                <strong>{{ $candidate['keyword'] }}</strong>
                                <div class="muted">Seen in {{ $candidate['count'] }} flagged jobs</div>
                                <div class="muted">{{ implode(' · ', $candidate['example_titles']) }}</div>
                            </div>
                        @endforeach
                    @endif
                </div>
                <div class="meta-card">
                    <div class="eyebrow">Suggested tech keywords</div>
                    @if (empty($suggestions['tech_candidates']))
                        <div class="muted">No missing stack terms stood out yet.</div>
                    @else
                        @foreach ($suggestions['tech_candidates'] as $candidate)
                            <div style="margin-bottom: 10px;">
                                <strong>{{ $candidate['keyword'] }}</strong>
                                <div class="muted">Seen in {{ $candidate['count'] }} flagged jobs</div>
                                <div class="muted">{{ implode(' · ', $candidate['example_titles']) }}</div>
                            </div>
                        @endforeach
                    @endif
                </div>
                <div class="meta-card">
                    <div class="eyebrow">Potential exclusion conflicts</div>
                    @if (empty($suggestions['excluded_conflicts']))
                        <div class="muted">No current exclusion keywords appeared in flagged jobs.</div>
                    @else
                        @foreach ($suggestions['excluded_conflicts'] as $conflict)
                            <div style="margin-bottom: 10px;">
                                <strong>{{ $conflict['keyword'] }}</strong>
                                <div class="muted">Matched {{ $conflict['count'] }} flagged jobs</div>
                                <div class="muted">{{ implode(' · ', collect($conflict['jobs'])->pluck('title')->take(3)->all()) }}</div>
                            </div>
                        @endforeach
                    @endif
                </div>
                <div class="meta-card">
                    <div class="eyebrow">Threshold guidance</div>
                    @if (!is_null($suggestions['thresholds']['min_flagged_score'] ?? null))
                        <div class="muted">
                            Lowering <code>maybe</code> from {{ number_format((float) $suggestions['thresholds']['maybe'], 0) }}
                            to {{ number_format((float) $suggestions['thresholds']['min_flagged_score'], 0) }}
                            would allow every currently flagged job to clear the rule threshold.
                        </div>
                    @else
                        <div class="muted">Threshold guidance appears after you flag at least one false negative.</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="panel table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Current outcome</th>
                    <th>Feedback</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($jobs as $job)
                    <tr>
                        <td>
                            <div class="job-title">{{ $job->title ?: 'Untitled role' }}</div>
                            <div>{{ $job->company ?: 'Unknown company' }}</div>
                            <div class="muted">{{ $job->location_raw ?: 'Location unknown' }}</div>
                            <div class="muted">Source: {{ strtoupper($job->source) }} · {{ $job->source_job_id }}</div>
                            <div style="margin-top: 8px;">
                                @if ($job->interesting_job_id)
                                    <a href="{{ route('interesting-jobs.edit', $job->interesting_job_id) }}">Open shortlist row</a>
                                    ·
                                @endif
                                @if ($job->url)
                                    <a href="{{ $job->url }}" target="_blank" rel="noreferrer">Listing</a>
                                @endif
                            </div>
                        </td>
                        <td>
                            <span class="pill {{ $job->ai_decision ?: 'status' }}">{{ strtoupper($job->ai_decision ?: 'unknown') }}</span>
                            @if ($job->ai_llm_decision)
                                <span class="pill {{ $job->ai_llm_decision }}">LLM {{ strtoupper($job->ai_llm_decision) }}</span>
                            @endif
                            @if ($job->shortlist_status)
                                <span class="pill status">{{ strtoupper($job->shortlist_status) }}</span>
                            @else
                                <span class="pill status">NOT SHORTLISTED</span>
                            @endif
                            @if ($job->false_negative)
                                <span class="pill duplicate">FALSE NEGATIVE</span>
                            @endif
                            @if ($job->remote_type)
                                <div class="muted" style="margin-top: 8px;">{{ strtoupper($job->remote_type) }}</div>
                            @endif
                            <div class="muted" style="margin-top: 8px;">Rule score {{ number_format((float) $job->ai_score, 0) }}</div>
                            <div class="muted" style="margin-top: 8px;">{{ \Illuminate\Support\Str::limit($job->ai_reason, 140) }}</div>
                        </td>
                        <td>
                            @if ($job->false_negative)
                                <div class="muted" style="margin-bottom: 8px;">
                                    Marked {{ \Illuminate\Support\Carbon::parse($job->false_negative_marked_at)->format('Y-m-d H:i') }}
                                </div>
                            @endif
                            <div class="muted">{{ \Illuminate\Support\Str::limit($job->false_negative_reason ?: 'No reviewer reason yet.', 180) }}</div>
                        </td>
                        <td>
                            <form method="post" action="{{ route('candidate-review.update') }}" class="edit-grid" style="gap: 8px;">
                                @csrf
                                <input type="hidden" name="source" value="{{ $job->source }}">
                                <input type="hidden" name="source_job_id" value="{{ $job->source_job_id }}">
                                <div>
                                    <label for="candidate_false_negative_reason_{{ $job->source }}_{{ $job->source_job_id }}">Why should this have passed?</label>
                                    <textarea id="candidate_false_negative_reason_{{ $job->source }}_{{ $job->source_job_id }}" name="false_negative_reason" style="min-height: 100px;">{{ $job->false_negative_reason }}</textarea>
                                </div>
                                <div class="actions">
                                    <button class="button" type="submit" name="false_negative" value="1">Mark false negative</button>
                                    @if ($job->false_negative)
                                        <button class="button secondary" type="submit" name="false_negative" value="0">Clear</button>
                                    @endif
                                </div>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="muted">No candidate jobs matched your filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">
            {{ $jobs->links() }}
        </div>
    </div>
@endsection
