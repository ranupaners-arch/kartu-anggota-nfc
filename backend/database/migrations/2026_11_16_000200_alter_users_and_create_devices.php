<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $t->foreignId('member_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $t->foreignId('division_id')->nullable()->after('member_id')->constrained()->nullOnDelete();
            $t->string('role')->default('member')->after('password'); // super_admin|division_admin|scanner|member
            $t->text('two_factor_secret')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
        });

        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('uuid')->unique();         // device_id dari app
            $t->string('name');
            $t->unsignedBigInteger('token_id')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('organization_id');
            $t->dropConstrainedForeignId('member_id');
            $t->dropConstrainedForeignId('division_id');
            $t->dropColumn(['role', 'two_factor_secret', 'two_factor_confirmed_at']);
        });
    }
};
