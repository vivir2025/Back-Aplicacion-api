<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pacientes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('identificacion', 50)->unique(); // Cambiado a string
            $table->date('fecnacimiento');
            $table->string('nombre', 100);
            $table->string('apellido', 100);
            $table->string('genero', 20);
            $table->double('longitud')->nullable();
            $table->double('latitud')->nullable();
            $table->uuid('idsede');
            $table->timestamps();
        
            $table->foreign('idsede')->references('id')->on('sedes');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pacientes');
    }
};