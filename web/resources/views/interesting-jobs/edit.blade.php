@extends('layouts.app', [
    'title' => 'Edit Job',
    'heading' => $job->title,
    'subheading' => $job->company,
    'showGlobalNav' => false,
    'backHref' => route('interesting-jobs.index'),
    'backAriaLabel' => 'Back to listing',
])

@section('header_actions')
    <form method="post" action="{{ route('interesting-jobs.quick-action', $job) }}">
        @csrf
        <input type="hidden" name="action" value="reject">
        <button class="button secondary" type="submit">Rejected</button>
    </form>
    <form method="post" action="{{ route('interesting-jobs.quick-action', $job) }}">
        @csrf
        <input type="hidden" name="action" value="not_relevant">
        <button class="button secondary" type="submit">Not Relevant</button>
    </form>
    <form method="post" action="{{ route('interesting-jobs.quick-action', $job) }}">
        @csrf
        <input type="hidden" name="action" value="already_applied">
        <button class="button secondary" type="submit">Already Applied</button>
    </form>
@endsection

@section('content')
    <div class="meta-grid">
        <div class="meta-card">
            <div class="eyebrow">Decision</div>
            <div><span class="pill {{ $job->ai_decision }}">{{ strtoupper($job->ai_decision) }}</span></div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">LLM Decision</div>
            <div>
                @if ($job->ai_llm_decision)
                    <span class="pill {{ $job->ai_llm_decision }}">{{ strtoupper($job->ai_llm_decision) }}</span>
                @else
                    Not scored
                @endif
            </div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Score</div>
            <div class="score">{{ number_format((float) $job->ai_score, 0) }}</div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">LLM Score</div>
            <div>
                @if (!is_null($job->ai_llm_score))
                    <span class="score">{{ number_format((float) $job->ai_llm_score, 0) }}</span>
                @else
                    Not scored
                @endif
            </div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Remote</div>
            <div>{{ $job->remote_type ?: 'Unknown' }}</div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Contract</div>
            <div>{{ $job->contract_type ?: 'Unknown' }}</div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Source</div>
            <div>{{ strtoupper($job->source) }} · {{ $job->source_job_id }}</div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Snapshot</div>
            <div>{{ $job->snapshot_taken_at?->format('Y-m-d H:i') ?? 'Not captured yet' }}</div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Duplicates</div>
            <div>{{ ($job->duplicate_count ?? 1) > 1 ? 'Representative of '.$job->duplicate_count.' source rows' : 'Single source row' }}</div>
        </div>
    </div>

    <div class="panel" style="padding: 22px;">
        <div style="margin-bottom: 18px;">
            <a href="{{ $job->url }}" target="_blank" rel="noreferrer">Open original listing</a>
        </div>

        <form method="post" action="{{ route('interesting-jobs.generate-cover-letter', $job) }}" style="margin-bottom: 18px;">
            @csrf
            <div class="actions">
                <button class="button" type="submit" data-loading-text="Generating...">Generate cover letter</button>
                @if ($job->cover_letter_generated_at)
                    <span class="muted">
                        Last generated {{ $job->cover_letter_generated_at->format('Y-m-d H:i') }}
                        @if ($job->cover_letter_model)
                            · {{ $job->cover_letter_model }}
                        @endif
                    </span>
                @endif
            </div>
        </form>

        <div style="margin-bottom: 18px;">
            <label>AI Reason</label>
            <div class="muted">{{ $job->ai_reason ?: 'No scoring reason recorded.' }}</div>
        </div>

        <div style="margin-bottom: 18px;">
            <label>LLM Reason</label>
            <div class="muted">{{ $job->ai_llm_reason ?: 'No LLM scoring reason recorded.' }}</div>
            @if ($job->ai_llm_scored_at || $job->ai_llm_model)
                <div class="muted" style="margin-top: 8px;">
                    @if ($job->ai_llm_scored_at)
                        LLM scored {{ $job->ai_llm_scored_at->format('Y-m-d H:i') }}
                    @endif
                    @if ($job->ai_llm_model)
                        · {{ $job->ai_llm_model }}
                    @endif
                </div>
            @endif
            @if ($job->ai_llm_usage_json)
                <div class="muted" style="margin-top: 8px;">
                    Token usage:
                    input {{ $job->ai_llm_usage_json['input_tokens'] ?? '?' }},
                    output {{ $job->ai_llm_usage_json['output_tokens'] ?? '?' }},
                    total {{ $job->ai_llm_usage_json['total_tokens'] ?? '?' }}
                </div>
            @endif
        </div>

        @if (($job->duplicate_count ?? 1) > 1 && !empty($job->duplicate_sources_json))
            <div style="margin-bottom: 18px;">
                <label>Duplicate Sources</label>
                <div class="muted">
                    @foreach ($job->duplicate_sources_json as $duplicate)
                        <div>{{ strtoupper($duplicate['source'] ?? 'unknown') }} · {{ $duplicate['source_job_id'] ?? '?' }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($probableDuplicates->isNotEmpty())
            <div style="margin-bottom: 18px;">
                <label>Probable Duplicates</label>
                <div class="muted">
                    @foreach ($probableDuplicates as $duplicate)
                        <div style="margin-bottom: 10px;">
                            <a href="{{ route('interesting-jobs.edit', $duplicate) }}">{{ $duplicate->title }}</a>
                            · {{ $duplicate->company }}
                            · {{ strtoupper($duplicate->source) }} · {{ $duplicate->source_job_id }}
                            · match {{ $duplicate->probable_duplicate_score }}
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div style="margin-bottom: 18px;">
            <label>Salary Snapshot</label>
            <div class="muted">{{ $job->salary_snapshot ?: 'No salary snapshot recorded.' }}</div>
        </div>

        <div style="margin-bottom: 24px;">
            <label>Description Snapshot</label>
            <div class="muted" style="white-space: pre-wrap; line-height: 1.5;">{{ $job->description_snapshot ?: 'No local description snapshot recorded.' }}</div>
        </div>

        <div style="margin-bottom: 24px;">
            <label>Cover Letter Draft</label>
            <div class="muted" style="white-space: pre-wrap; line-height: 1.6;">{{ $job->cover_letter_draft ?: 'No cover letter draft generated yet.' }}</div>
            @if ($job->cover_letter_usage_json)
                <div class="muted" style="margin-top: 10px;">
                    @if (!empty($job->cover_letter_usage_json['variant_label']))
                        Variant {{ $job->cover_letter_usage_json['variant_label'] }}
                        @if (!empty($job->cover_letter_usage_json['variant_matched_keywords']))
                            · {{ implode(', ', array_slice($job->cover_letter_usage_json['variant_matched_keywords'], 0, 3)) }}
                        @endif
                        <br>
                    @endif
                    Token usage:
                    input {{ $job->cover_letter_usage_json['input_tokens'] ?? '?' }},
                    output {{ $job->cover_letter_usage_json['output_tokens'] ?? '?' }},
                    total {{ $job->cover_letter_usage_json['total_tokens'] ?? '?' }}
                </div>
            @endif
        </div>

        <form method="post" action="{{ route('interesting-jobs.update', $job) }}" class="edit-grid">
            @csrf

            <div>
                <label for="shortlist_status">Shortlist status</label>
                <select id="shortlist_status" name="shortlist_status">
                    @foreach ($statusOptions as $status)
                        <option value="{{ $status }}" @selected(old('shortlist_status', $job->shortlist_status) === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                @error('shortlist_status')
                    <div style="color: var(--warn); margin-top: 6px;">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="notes">Notes</label>
                <div class="muted" style="margin-bottom: 8px;">
                    Use <code>&lt;letter&gt;...&lt;/letter&gt;</code> for cover-letter-specific guidance.
                    Example: <code>&lt;letter&gt;While not a Python expert, I use Python for scripting and automation and bring strong backend architecture experience from similar languages.&lt;/letter&gt;</code>
                </div>
                <textarea id="notes" name="notes">{{ old('notes', $job->notes) }}</textarea>
                @error('notes')
                    <div style="color: var(--warn); margin-top: 6px;">{{ $message }}</div>
                @enderror
            </div>

            <div class="actions">
                <button class="button" type="submit">Save changes</button>
            </div>
        </form>
    </div>
@endsection
