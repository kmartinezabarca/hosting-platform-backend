<?php

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostalCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'postal_code',
        'state',
        'city',
        'township',
        'country',
    ];

    /**
     * Scope a query to search by postal code.
     */
    public function scopeByCode($query, $code, $country = 'MX')
    {
        return $query->where('postal_code', $code)
                     ->where('country', $country);
    }
}
