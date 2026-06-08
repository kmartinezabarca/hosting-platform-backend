<?php

namespace App\Domains\Platform\Models;

use App\Traits\HasUuidColumn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptItem extends Model
{
    use HasFactory, HasUuidColumn;

    protected $table = 'receipt_items';

    protected $fillable = [
        'uuid',
        'receipt_id',
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

    /** Líneas de un RECIBO (comprobante de pago). */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }
}
