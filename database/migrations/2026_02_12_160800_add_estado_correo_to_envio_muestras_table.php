<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('envio_muestras', function (Blueprint $table) {
            $table->boolean('estado_correo')->default(false)->after('observaciones');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('envio_muestras', function (Blueprint $table) {
            $table->dropColumn('estado_correo');
        });
    }
};
