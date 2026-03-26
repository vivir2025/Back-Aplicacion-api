<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Usuario;
use App\Models\Sede;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // El UUID de la sede que ya tenés en la base de datos
        $miSedeId = 'id de sede';

        // 2. Creamos el Usuario Administrador asociado a ese ID
        Usuario::create([
            'id' => Str::uuid()->toString(),
            'usuario' => 'admin',
            'correo' => 'admin@fundacion.com',
            'nombre' => 'Administrador Bornive',
            'contrasena' => Hash::make('secreto123'),
            'rol' => 'admin',
            'estado' => 'activo',
            'idsede' => $miSedeId, // Lo mandamos directo
        ]);
    }

}
