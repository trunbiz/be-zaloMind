<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table) {
            $table->timestamp('dispatched_at')->nullable()->index();
        });
        // Retain the original age of attempts already delivered under the 120-second lease policy.
        DB::table('agent_commands')->where('attempt', '>', 0)->orderBy('id')->chunkById(200, function ($commands) {
            foreach ($commands as $command) {
                $at = $command->started_at ?: ($command->lease_expires_at ? Carbon::parse($command->lease_expires_at)->subSeconds(120) : $command->created_at);
                $changes = ['dispatched_at' => $at];
                if (in_array($command->status, ['leased', 'running', 'unknown'], true)) {
                    $changes['lease_expires_at'] = Carbon::parse($at)->addHours(12);
                }
                DB::table('agent_commands')->where('id', $command->id)->update($changes);
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', fn (Blueprint $table) => $table->dropColumn('dispatched_at'));
    }
};
