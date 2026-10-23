<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('referral_code')->nullable()->unique();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('users')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update(['referral_code' => (string) Str::uuid()]);
        });
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedBigInteger('monthly_price_vnd')->default(0);
            $table->unsignedBigInteger('yearly_price_vnd')->default(0);
            $table->unsignedInteger('profiles_max')->default(1);
            $table->unsignedInteger('messages_per_day')->default(100);
            $table->string('status')->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        DB::table('plans')->insert(['id' => (string) Str::uuid(), 'code' => 'free', 'name' => 'Miễn phí', 'monthly_price_vnd' => 0, 'yearly_price_vnd' => 0, 'profiles_max' => 1, 'messages_per_day' => 100, 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->json('plan_snapshot');
            $table->string('billing_period');
            $table->unsignedBigInteger('amount_vnd');
            $table->string('currency', 3)->default('VND');
            $table->string('status')->default('pending')->index();
            $table->string('payment_reference')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('refund_reference')->nullable()->unique();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->json('limits_snapshot');
            $table->string('status')->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->index();
            $table->timestamps();
        });
        Schema::create('workspace_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 40)->index();
            $table->string('title');
            $table->string('status')->default('active');
            $table->longText('data')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('profile_id')->constrained('agent_profiles')->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');
            $table->string('status')->default('scheduled');
            $table->string('dedupe_key')->nullable()->unique();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('proxy_id')->nullable()->constrained('workspace_items')->nullOnDelete();
        });
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->foreignUuid('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->uuid('parent_command_id')->nullable()->unique();
            $table->timestamp('available_at')->nullable()->index();
            $table->unsignedInteger('quota_units')->default(0);
            $table->json('workflow')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_vnd');
            $table->string('status')->default('pending');
            $table->string('payment_reference')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('resource_type');
            $table->string('resource_id');
            $table->text('reason')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->index();
        });
        Schema::create('platform_requests', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body_hash', 64);
            $table->json('response');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_requests');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('affiliate_commissions');
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn(['parent_command_id', 'available_at', 'quota_units', 'workflow', 'version']);
        });
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proxy_id');
            $table->dropColumn('version');
        });
        foreach (['campaigns', 'workspace_items', 'subscriptions', 'orders', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropColumn(['status', 'version', 'referral_code']);
        });
    }
};
