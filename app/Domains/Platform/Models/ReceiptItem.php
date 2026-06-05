<?php

namespace App\Domains\Platform\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptItem extends Model
{
    use HasFactory, HasUuidColumn;

    protected $table = 'invoice_items';

    protected $fillable = [
        'uuid',
        'invoice_id',
        'service_id',
        'description',
        'quantity',
        'unit_price',
        'total',
        'sat_clave_prod_serv',
        'sat_clave_unidad',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'invoice_id');
    }
}
