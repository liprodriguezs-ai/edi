<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('media');
        Schema::create('media', function (Blueprint $table) {
            if (env('DB_CONNECTION') == 'sqlite') {
                $table->uuid('id');
            } else {
                if (env('DB_CONNECTION') === 'pgsql') {
                    $table->uuid('id')->primary()->unique()->default(DB::raw('(uuid_generate_v4())'));
                } else {
                    $table->uuid('id')->primary()->unique()->default(DB::raw('(UUID())'));
                }
            }
            $table->morphs('model'); // no funciona
            // $table->uuidMorphs('model');
            $table->string('collection_name')->comment('Spatie Media Library collection name (e.g., "avatar", "documents")');
            $table->string('name')->comment('Logical name of the media item');
            $table->string('file_name')->comment('Actual filename stored on disk');
            $table->string('mime_type')->nullable()->comment('MIME type of the file (e.g., "image/jpeg")');
            $table->string('disk')->comment('Laravel filesystem disk where the file is stored');
            $table->string('conversions_disk')->nullable()->comment('Disk used for storing generated image conversions');
            $table->unsignedBigInteger('size')->comment('File size in bytes');
            $table->json('manipulations')->comment('JSON array of image manipulations to apply');
            $table->json('custom_properties')->comment('JSON map of arbitrary custom properties');
            $table->json('generated_conversions')->comment('JSON map tracking which conversions have been generated');
            $table->json('responsive_images')->comment('JSON data for responsive image variants');
            $table->unsignedInteger('order_column')->nullable()->index()->comment('Sort order within the collection');

            $table->nullableTimestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
