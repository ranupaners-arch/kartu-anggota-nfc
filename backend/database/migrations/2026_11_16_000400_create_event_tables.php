<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->constrained()->cascadeOnDelete();
            $t->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->string('type')->default('general');
            $t->string('location')->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->timestamp('checkin_opens_at');
            $t->timestamp('late_after');
            $t->timestamp('checkin_closes_at');
            $t->boolean('is_mandatory')->default(true);
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['organization_id', 'starts_at']);
        });

        Schema::create('event_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
        });

        Schema::create('event_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('division_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('member_id')->nullable()->constrained()->cascadeOnDelete();
            $t->timestamps();
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('session_id')->constrained('event_sessions')->cascadeOnDelete();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status'); // hadir|telat|izin|sakit|alpa
            $t->string('source')->default('nfc'); // nfc|qr|manual
            $t->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $t->uuid('client_uuid')->nullable()->unique(); // kunci idempotensi sinkron offline
            $t->timestamp('scanned_at')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->boolean('needs_review')->default(false);
            $t->string('review_reason')->nullable();
            $t->boolean('override')->default(false);
            $t->timestamp('voided_at')->nullable();
            $t->timestamps();
            $t->unique(['session_id', 'member_id']);
            $t->index(['event_id', 'status']);
        });

        Schema::create('attendance_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $t->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('from_status')->nullable();
            $t->string('to_status')->nullable();
            $t->string('reason');
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('excuse_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->string('type'); // izin|sakit
            $t->text('reason');
            $t->string('attachment_path')->nullable();
            $t->string('status')->default('pending'); // pending|approved|rejected
            $t->text('review_note')->nullable();
            $t->boolean('used')->default(true);
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'member_id']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->string('entity')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->jsonb('payload')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'excuse_requests', 'attendance_changes', 'attendances', 'event_targets', 'event_sessions', 'events'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
