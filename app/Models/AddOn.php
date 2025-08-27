<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class AddOn extends Model
{
  use HasFactory;

  protected $fillable = ['uuid','slug','name','description','price','currency','is_active','metadata'];
  protected $casts = ['price'=>'decimal:2','is_active'=>'boolean','metadata'=>'array'];

  protected static function booted(): void {
    static::creating(function ($m) { $m->uuid ??= (string) Str::uuid(); });
  }

  public function plans() {
    return $this->belongsToMany(ServicePlan::class, 'add_on_plan')
      ->withPivot(['is_default'])->withTimestamps();
  }
}
