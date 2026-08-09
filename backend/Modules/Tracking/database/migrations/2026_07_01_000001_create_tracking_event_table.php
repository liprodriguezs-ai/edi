<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_event', function (Blueprint $table): void {
            $table->comment('Bitácora ligera de despacho de eventos de tracking (Facebook CAPI / GA4 MP) para depurar conversiones');
            // CENTRAL: los eventos ocurren antes de que exista tenant (visitante anónimo,
            // registro), así que esta tabla NO lleva tenant_id.
            BaseModel::columns($table, false);

            $table->string('event_name')->comment('Nombre canónico del evento (Lead, StartTrial, CompleteRegistration, InitiateCheckout, Purchase)');
            $table->uuid('event_id')->comment('event_id compartido con el hit del navegador para deduplicación en Meta/GA4');
            $table->string('channel')->comment('Canal de despacho: facebook | google_analytics | log');
            $table->string('status')->default('queued')->comment('Estado de la entrega: queued|sent|failed');
            $table->json('request_payload')->nullable()->comment('Payload enviado al canal (para depurar qué se mandó)');
            $table->text('response_snippet')->nullable()->comment('Fragmento de la respuesta o del error del canal');

            $table->unique(['event_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_event');
    }
};
