@extends('layouts.app', [
    'title' => 'Config',
    'heading' => 'Config',
    'subheading' => 'Edit the jobfinder YAML files used by scraping and scoring.',
])

@section('content')
    @foreach ($files as $fileKey => $file)
        <div class="panel" style="padding: 18px; margin-bottom: 18px;">
            <div class="eyebrow">{{ $file['label'] }}</div>
            <div class="muted" style="margin-bottom: 12px;">{{ $file['path'] }}</div>

            <form method="post" action="{{ route('jobfinder-config.update') }}">
                @csrf
                <input type="hidden" name="file_key" value="{{ $fileKey }}">

                <div style="margin-bottom: 14px;">
                    <label for="contents_{{ $fileKey }}">Contents</label>
                    <textarea
                        id="contents_{{ $fileKey }}"
                        name="contents"
                        class="config-editor"
                    >{{ old('file_key') === $fileKey ? old('contents') : $file['contents'] }}</textarea>
                    @if ($errors->any() && old('file_key') === $fileKey)
                        <div style="color: var(--warn); margin-top: 8px;">{{ $errors->first() }}</div>
                    @endif
                </div>

                <div class="actions">
                    <button class="button" type="submit">Save {{ $file['label'] }}</button>
                </div>
            </form>
        </div>
    @endforeach
@endsection
