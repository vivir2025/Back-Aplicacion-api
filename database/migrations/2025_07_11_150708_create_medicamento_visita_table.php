<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('medicamento_visita', function (Blueprint $table) {
            $table->uuid('medicamento_id');
            $table->uuid('visita_id');
            $table->text('indicaciones')->nullable();
            $table->primary(['medicamento_id', 'visita_id']);
            
            $table->foreign('medicamento_id')->references('id')->on('medicamentos')->onDelete('cascade');
            $table->foreign('visita_id')->references('id')->on('visitas')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('medicamento_visita');
    }
};