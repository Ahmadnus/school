<?php

namespace App\Models;

use App\Enums\TargetScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostTarget extends Model
{
    use HasFactory;

    protected $fillable = ['post_id', 'scope', 'target_id'];

    protected function casts(): array
    {
        return ['scope' => TargetScope::class];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** The human label of the chip, e.g. "شعبة: علمي - إناث". */
    public function describe(): string
    {
        $name = match ($this->scope) {
            TargetScope::School => null,
            TargetScope::Grade => Grade::find($this->target_id)?->name,
            TargetScope::Section => (function () {
                $section = Section::with('grade')->find($this->target_id);

                return $section ? $section->grade->name.' - '.$section->name : null;
            })(),
            TargetScope::Student => Student::find($this->target_id)?->full_name,
            TargetScope::Subject => Subject::find($this->target_id)?->name,
        };

        return $name === null ? $this->scope->label() : $this->scope->label().': '.$name;
    }
}
