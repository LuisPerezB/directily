<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `promotion_revisions` (solo inserción). Ver docs/03-modelo-datos.md.
 * La escribe App\Services\Promotions\PromotionRevisionRecorder; este modelo es de lectura.
 */
class PromotionRevision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'discount_pct' => 'float',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'known_at' => 'datetime',
            'deleted' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id')->withTrashed();
    }
}
