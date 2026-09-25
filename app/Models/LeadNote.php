<?php

namespace App\Models;

use App\Enums\NoteVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeadNote extends Model
{
    use SoftDeletes;

    protected $fillable = ['note', 'visibility'];

    protected function casts(): array
    {
        return ['visibility' => NoteVisibility::class];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function histories(): HasMany
    {
        return $this->hasMany(LeadNoteHistory::class)->latest('id');
    }
}
