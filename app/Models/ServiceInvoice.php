<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id','rfc','name','zip','regimen','uso_cfdi','constancia',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
