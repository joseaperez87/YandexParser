<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationSnapshot extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'parse_run_id',
        'rating',
        'ratings_count',
        'reviews_count',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parseRun(): BelongsTo
    {
        return $this->belongsTo(ParseRun::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ParseChange::class, 'snapshot_id');
    }
}
