<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_device_tokens')) {
            Schema::create('user_device_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->text('token');
                $table->enum('platform', ['web', 'android', 'ios']);
                $table->string('device_name')->nullable();
                $table->string('app_version', 50)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index('platform');
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('database_enabled')->default(true);
                $table->boolean('email_enabled')->default(true);
                $table->boolean('push_enabled')->default(true);
                $table->boolean('sms_enabled')->default(false);
                $table->boolean('complaint_created')->default(true);
                $table->boolean('complaint_assigned')->default(true);
                $table->boolean('complaint_status_updated')->default(true);
                $table->boolean('sla_breached')->default(true);
                $table->boolean('complaint_resolved')->default(true);
                $table->boolean('complaint_closed')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notification_delivery_logs')) {
            Schema::create('notification_delivery_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_notification_id')->nullable()->constrained('user_notifications')->nullOnDelete();
                $table->foreignId('complaint_id')->nullable()->constrained()->nullOnDelete();
                $table->enum('channel', ['database', 'email', 'push', 'sms']);
                $table->string('type');
                $table->string('recipient')->nullable();
                $table->enum('status', ['pending', 'sent', 'failed', 'skipped']);
                $table->string('provider')->nullable();
                $table->string('provider_message_id')->nullable();
                $table->text('error_message')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();

                $table->index('user_id');
                $table->index('channel');
                $table->index('status');
                $table->index('type');
                $table->index('complaint_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_logs');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('user_device_tokens');
    }
};
