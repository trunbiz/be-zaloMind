<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Upgrade databases that installed the initial TEXT column before QR support.
        // Base64 plus encryption can exceed TEXT's 64 KiB limit.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE agent_profiles MODIFY qr LONGTEXT NULL');
        }
    }

    public function down(): void
    {
        // Keep the wider column to avoid truncating encrypted QR values on rollback.
    }
};
