<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('usuario');
            $table->text('correo');
            $table->text('nombre');
            $table->text('contrasena');
            $table->string('rol', 50);
            $table->string('estado', 50);
            $table->uuid('idsede');
            $table->timestamps();
        
            $table->foreign('idsede')->references('id')->on('sedes');
            
            // Índices con longitud especificada
            $table->rawIndex("usuario(100)", 'usuarios_usuario_index');
            $table->rawIndex("correo(100)", 'usuarios_correo_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('usuarios');
    }
};