<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('visitas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('nombre_apellido');
            $table->string('identificacion', 50); // Cambiado a string
            $table->text('hta')->nullable();
            $table->text('dm')->nullable();
            $table->date('fecha');
            $table->text('telefono')->nullable();
            $table->text('zona')->nullable();
            $table->double('peso')->nullable();
            $table->double('talla')->nullable();
            $table->double('imc')->nullable();
            $table->double('perimetro_abdominal')->nullable();
            $table->integer('frecuencia_cardiaca')->nullable();
            $table->integer('frecuencia_respiratoria')->nullable();
            $table->string('tension_arterial', 20)->nullable();
            $table->double('glucometria')->nullable();
            $table->double('temperatura')->nullable();
            $table->text('familiar')->nullable();
            $table->text('riesgo_fotografico')->nullable();
            $table->text('abandono_social')->nullable();
            $table->text('motivo')->nullable();
            $table->text('medicamentos')->nullable();
            $table->text('factores')->nullable();
            $table->text('conductas')->nullable();
            $table->text('novedades')->nullable();
            $table->date('proximo_control')->nullable();
            $table->text('firma')->nullable();
            $table->uuid('idusuario');
            $table->uuid('idpaciente');
            $table->timestamps();

            $table->foreign('idusuario')->references('id')->on('usuarios');
            $table->foreign('idpaciente')->references('id')->on('pacientes');
        });
    }

    public function down()
    {
        Schema::dropIfExists('visitas');
    }
};