
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('vehicle_id')
                ->constrained()
                ->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            // Price snapshot at booking creation
            $table->decimal('daily_rate', 10, 2);
            $table->unsignedInteger('total_days');
            $table->decimal('total_amount', 10, 2);

            $table->string('status')->default('pending');

            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'status', 'start_date', 'end_date']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
