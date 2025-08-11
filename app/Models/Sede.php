<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sede extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'nombresede'
    ];

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'idsede');
    }

    public function pacientes()
    {
        return $this->hasMany(Paciente::class, 'idsede');
    }
}