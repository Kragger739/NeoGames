<?php

namespace App\Models;

use App\Enums\DdfLanguage;
use App\Enums\DdfQuestionCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DdfQuestion extends Model
{
    protected $fillable = [
        'category',
        'language',
        'text',
        'correct_answer',
        'dataset_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'category' => DdfQuestionCategory::class,
            'language' => DdfLanguage::class,
            'position' => 'integer',
        ];
    }

    public function answers(): HasMany
    {
        return $this->hasMany(DdfAnswer::class);
    }

    /** The Workshop dataset this question belongs to, or null for a built-in question. */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function scopeBuiltIn(Builder $query): Builder
    {
        return $query->whereNull('dataset_id');
    }
}
