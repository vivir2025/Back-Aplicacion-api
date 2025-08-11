<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('medicamentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('nombmedicamento');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('medicamentos');
    }
};