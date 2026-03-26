<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterestingJob extends Model
{
    protected $table = 'interesting_jobs';

    protected $fillable = [
        'source',
        'source_job_id',
        'canonical_job_key',
        'title',
        'company',
        'url',
        'location_raw',
        'remote_type',
        'contract_type',
        'ai_score',
        'ai_reason',
        'ai_decision',
        'ai_llm_score',
        'ai_llm_reason',
        'ai_llm_decision',
        'ai_llm_model',
        'ai_llm_usage_json',
        'ai_llm_scored_at',
        'duplicate_count',
        'duplicate_sources_json',
        'shortlist_status',
        'notes',
        'description_snapshot',
        'salary_snapshot',
        'source_snapshot_json',
        'snapshot_taken_at',
        'cover_letter_draft',
        'cover_letter_generated_at',
        'cover_letter_model',
        'cover_letter_usage_json',
        'promoted_at',
        'updated_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'ai_score' => 'float',
            'ai_llm_score' => 'float',
            'duplicate_count' => 'integer',
            'duplicate_sources_json' => 'array',
            'source_snapshot_json' => 'array',
            'ai_llm_usage_json' => 'array',
            'cover_letter_usage_json' => 'array',
            'snapshot_taken_at' => 'datetime',
            'ai_llm_scored_at' => 'datetime',
            'cover_letter_generated_at' => 'datetime',
            'promoted_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
