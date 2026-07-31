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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');
            // incomplete|trialing|active|past_due|canceled|unpaid — mirrors
            // Stripe's own subscription status values so syncing is a
            // direct copy, not a translation.
            $table->string('status')->nullable()->after('stripe_subscription_id');
            $table->timestamp('current_period_end')->nullable()->after('status');
            $table->boolean('cancel_at_period_end')->default(false)->after('current_period_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['stripe_subscription_id', 'status', 'current_period_end', 'cancel_at_period_end']);
        });
    }
};
