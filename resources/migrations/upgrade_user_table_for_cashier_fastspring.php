<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpgradeUserTableForCashierFastspring extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('trial_plan')->nullable();
            $table->string('fastspring_id')->nullable();
            $table->string('fastspring_company')->nullable();
            $table->string('phone')->nullable();
            $table->string('language')->nullable();
            $table->string('country')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['trial_ends_at', 'trial_plan', 'fastspring_id', 'fastspring_company', 'phone', 'language', 'country']);
        });
    }
}
