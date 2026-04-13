@extends('layouts.app', [
    'title' => 'False Negative Review',
    'heading' => 'False Negative Review',
    'subheading' => 'Review rejected jobs, flag misses, and inspect safe config suggestions before editing criteria.',
    'backHref' => route('interesting-jobs.index'),
    'backAriaLabel' => 'Back to interesting jobs',
])

@section('content')
    <form method="get" action="{{ route('false-negatives.index') }}" class="panel filters">
        <div>
            <label for="scope">Scope</label>
            <select id="scope" name="scope">
                <option value="rejected" @selected(($filters['scope'] ?? '') === 'rejected')>Rejected + flagged</option>
                <option value="flagged" @selected(($filters['scope'] ?? '') === 'flagged')>Flagged only</option>
                <option value="all" @selected(($filters['scope'] ?? '') === 'all')>All reject signals</option>
            </select>
        </div>
        <div>
            <label for="q">Search</label>
            <input id="q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Title, company, reason">
        </div>
        <div class="actions">
            <button class="button" type="submit">Apply filters</button>
            <a class="button secondary" href="{{ route('false-negatives.index') }}">Reset</a>
        </div>
    </form>

    <div class="meta-grid">
        <div class="meta-card">
            <div class="eyebrow">Flagged Jobs</div>
            <div class="score">{{ $suggestions['flagged_count'] }}</div>
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
            <div class="muted">No false negatives flagged yet. Mark jobs here first, then the suggestion summary will highlight missing title keywords, tech keywords, and exclusion conflicts.</div>
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
                        @if (!is_null($suggestions['thresholds']['median_flagged_score'] ?? null))
                            <div class="muted" style="margin-top: 10px;">
                                Median flagged score: {{ number_format((float) $suggestions['thresholds']['median_flagged_score'], 0) }}.
                                Treat threshold changes as a last resort; title and tech-keyword fixes are usually safer.
                            </div>
                        @endif
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
                            <div class="job-title">{{ $job->title }}</div>
                            <div>{{ $job->company }}</div>
                            <div class="muted">{{ $job->location_raw ?: 'Location unknown' }}</div>
                            <div class="muted">Source: {{ strtoupper($job->source) }} · {{ $job->source_job_id }}</div>
                            <div style="margin-top: 8px;">
                                <a href="{{ route('interesting-jobs.edit', $job) }}">Open detail</a>
                                ·
                                <a href="{{ $job->url }}" target="_blank" rel="noreferrer">Listing</a>
                            </div>
                        </td>
                        <td>
                            <span class="pill {{ $job->ai_decision }}">{{ strtoupper($job->ai_decision) }}</span>
                            <span class="pill status">{{ strtoupper($job->shortlist_status) }}</span>
                            @if ($job->false_negative)
                                <span class="pill duplicate">FALSE NEGATIVE</span>
                            @endif
                            <div class="muted" style="margin-top: 8px;">Rule score {{ number_format((float) $job->ai_score, 0) }}</div>
                            <div class="muted" style="margin-top: 8px;">{{ \Illuminate\Support\Str::limit($job->ai_reason, 140) }}</div>
                        </td>
                        <td>
                            @if ($job->false_negative)
                                <div class="muted" style="margin-bottom: 8px;">
                                    Marked {{ $job->false_negative_marked_at?->format('Y-m-d H:i') ?? 'recently' }}
                                </div>
                            @endif
                            <div class="muted">{{ \Illuminate\Support\Str::limit($job->false_negative_reason ?: 'No reviewer reason yet.', 180) }}</div>
                        </td>
                        <td>
                            <form method="post" action="{{ route('false-negatives.update', $job) }}" class="edit-grid" style="gap: 8px;">
                                @csrf
                                <div>
                                    <label for="false_negative_reason_{{ $job->id }}">Why should this have passed?</label>
                                    <textarea id="false_negative_reason_{{ $job->id }}" name="false_negative_reason" style="min-height: 100px;">{{ $job->false_negative_reason }}</textarea>
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
                        <td colspan="4" class="muted">No rejected jobs matched your filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">
            {{ $jobs->links() }}
        </div>
    </div>
@endsection
