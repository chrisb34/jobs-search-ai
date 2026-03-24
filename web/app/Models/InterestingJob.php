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
        'shortlist_status',
        'notes',
        'description_snapshot',
        'salary_snapshot',
        'source_snapshot_json',
        'snapshot_taken_at',
        'promoted_at',
        'updated_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'ai_score' => 'float',
            'source_snapshot_json' => 'array',
            'snapshot_taken_at' => 'datetime',
            'promoted_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
