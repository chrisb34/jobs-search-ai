@extends('layouts.app', [
    'title' => 'Edit Job',
    'heading' => $job->title,
    'subheading' => $job->company.' · '.($job->location_raw ?: 'Location unknown'),
])

@section('content')
    <div class="meta-grid">
        <div class="meta-card">
            <div class="eyebrow">Decision</div>
            <div><span class="pill {{ $job->ai_decision }}">{{ strtoupper($job->ai_decision) }}</span></div>
        </div>
        <div class="meta-card">
            <div class="eyebrow">Score</div>
            <div class="score">{{ number_format((float) $job->ai_score, 0) }}</div>
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
            <div class="eyebrow">Snapshot</div>
            <div>{{ $job->snapshot_taken_at?->format('Y-m-d H:i') ?? 'Not captured yet' }}</div>
        </div>
    </div>

    <div class="panel" style="padding: 22px;">
        <div style="margin-bottom: 18px;">
            <a href="{{ $job->url }}" target="_blank" rel="noreferrer">Open original listing</a>
        </div>

        <div style="margin-bottom: 18px;">
            <label>AI Reason</label>
            <div class="muted">{{ $job->ai_reason ?: 'No scoring reason recorded.' }}</div>
        </div>

        <div style="margin-bottom: 18px;">
            <label>Salary Snapshot</label>
            <div class="muted">{{ $job->salary_snapshot ?: 'No salary snapshot recorded.' }}</div>
        </div>

        <div style="margin-bottom: 24px;">
            <label>Description Snapshot</label>
            <div class="muted" style="white-space: pre-wrap; line-height: 1.5;">{{ $job->description_snapshot ?: 'No local description snapshot recorded.' }}</div>
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
                <textarea id="notes" name="notes">{{ old('notes', $job->notes) }}</textarea>
                @error('notes')
                    <div style="color: var(--warn); margin-top: 6px;">{{ $message }}</div>
                @enderror
            </div>

            <div class="actions">
                <button class="button" type="submit">Save changes</button>
                <a class="button secondary" href="{{ route('interesting-jobs.index') }}">Back to list</a>
            </div>
        </form>
    </div>
@endsection
