<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runtime', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('max_concurrency')->default(2);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->json('heartbeat')->nullable();
        });
        DB::table('agent_runtime')->insert(['id' => 1, 'enabled' => true, 'max_concurrency' => 2]);

        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('status')->default('disconnected');
            $table->string('zalo_user_id')->nullable();
            $table->text('avatar_url')->nullable();
            $table->unsignedInteger('assignment_version')->default(1);
            $table->uuid('stream_id')->nullable();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->longText('qr')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('agent_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('agent_profiles')->cascadeOnDelete();
            $table->unsignedInteger('assignment_version')->default(1);
            $table->string('type');
            $table->string('status')->default('queued')->index();
            $table->uuid('execution_key')->unique();
            $table->json('payload');
            $table->unsignedInteger('attempt')->default(0);
            $table->text('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->unsignedBigInteger('progress_seq')->default(0);
            $table->unsignedInteger('progress_percent')->default(0);
            $table->string('stage')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('agent_idempotency', function (Blueprint $table) {
            $table->string('scope', 64)->primary();
            $table->string('body_hash', 64);
            $table->unsignedSmallInteger('status_code');
            $table->json('response');
            $table->timestamp('created_at');
        });
        Schema::create('agent_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->foreignUuid('profile_id')->constrained('agent_profiles')->cascadeOnDelete();
            $table->uuid('stream_id');
            $table->unsignedBigInteger('sequence');
            $table->string('body_hash', 64);
            $table->longText('body');
            $table->boolean('processed')->default(false);
            $table->timestamp('created_at');
            $table->unique(['profile_id', 'stream_id', 'sequence'], 'agent_event_sequence_unique');
        });
        Schema::create('agent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('profile_id')->constrained('agent_profiles')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('group_id', 191)->default('');
            $table->string('provider_id', 191);
            $table->json('data');
            $table->timestamps();
            $table->unique(['profile_id', 'kind', 'group_id', 'provider_id'], 'agent_record_unique');
        });
        Schema::create('agent_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('agent_profiles')->cascadeOnDelete();
            $table->unsignedInteger('assignment_version');
            $table->string('kind', 40);
            $table->string('group_id', 191)->default('');
            $table->string('status')->default('staging');
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('agent_snapshot_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('snapshot_id')->constrained('agent_snapshots')->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->string('body_hash', 64);
            $table->json('items');
            $table->unique(['snapshot_id', 'page']);
        });
        Schema::create('agent_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('command_id')->constrained('agent_commands')->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->text('path');
            $table->string('mime_type');
            $table->string('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['agent_attachments', 'agent_snapshot_pages', 'agent_snapshots', 'agent_records', 'agent_events', 'agent_idempotency', 'agent_commands', 'agent_profiles', 'agent_runtime'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
