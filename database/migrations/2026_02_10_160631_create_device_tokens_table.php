<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            
            // ✅ Relación con tu tabla usuarios (UUID)
            $table->char('user_id', 36);
            $table->foreign('user_id')
                  ->references('id')
                  ->on('usuarios') // ← Tu tabla de usuarios
                  ->onDelete('cascade'); // Si se elimina el usuario, se eliminan sus tokens
            
            // ✅ Token FCM del dispositivo
            $table->string('fcm_token', 255)->unique();
            
            // ✅ Plataforma del dispositivo
            $table->enum('platform', ['android', 'ios', 'web']);
            
            // ✅ Nombre del dispositivo (opcional)
            $table->string('device_name', 100)->nullable();
            
            // ✅ Estado del token (activo/inactivo)
            $table->boolean('is_active')->default(true);
            
            // ✅ Última vez que se usó
            $table->timestamp('last_used_at')->nullable();
            
            // ✅ Timestamps
            $table->timestamps();
            
            // ✅ Índices para mejorar búsquedas
            $table->index('user_id');
            $table->index('is_active');
            $table->index(['user_id', 'is_active']); // Índice compuesto
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
