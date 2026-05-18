<?php

namespace App\Models\RokePet;

use Illuminate\Database\Eloquent\Model;

class AppAdmin extends Model
{
    protected $connection = 'roke_pet';
    protected $table = 'app_admins';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'user_id';
    public $timestamps = false;

    protected $fillable = ['user_id', 'notes'];
}
