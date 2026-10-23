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
        Schema::table('agent_records', function (Blueprint $table) {
            $table->string('conversation_type', 10)->nullable();
            $table->string('conversation_id', 191)->nullable();
            $table->string('sender_id', 191)->nullable();
            $table->timestamp('message_sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->index(['profile_id', 'kind', 'conversation_type', 'conversation_id', 'message_sent_at'], 'inbox_conversation_index');
        });
        $owners = DB::table('agent_profiles')->pluck('zalo_user_id', 'id');
        DB::table('agent_records')->where('kind', 'messages')->orderBy('id')->chunkById(200, function ($records) use ($owners) {
            foreach ($records as $record) {
                $data = json_decode($record->data, true);
                DB::table('agent_records')->where('id', $record->id)->update([
                    'conversation_type' => $data['conversation']['type'] ?? null,
                    'conversation_id' => $data['conversation']['id'] ?? null,
                    'sender_id' => $data['sender_id'] ?? null,
                    'message_sent_at' => isset($data['sent_at']) ? Carbon::parse($data['sent_at'])->setTimezone(config('app.timezone')) : $record->created_at,
                    'read_at' => isset($data['read_at']) ? Carbon::parse($data['read_at'])->setTimezone(config('app.timezone')) : ((($data['historical'] ?? false) || ($data['sender_id'] ?? '') === ($owners[$record->profile_id] ?? null)) ? $record->created_at : null),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_records', function (Blueprint $table) {
            $table->dropIndex('inbox_conversation_index');
            $table->dropColumn(['conversation_type', 'conversation_id', 'sender_id', 'message_sent_at', 'read_at']);
        });
    }
};
