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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token'); // FCM token, APNs device token, etc.
            $table->string('type')->default('fcm'); // fcm, apns, onesignal
            $table->string('platform')->default('android'); // android, ios
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'token']); // Prevent duplicates
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
