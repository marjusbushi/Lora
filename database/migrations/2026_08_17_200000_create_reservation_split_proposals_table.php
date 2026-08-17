<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Propozimet e ndarjes së qëndrimit (plan #723): kur inventari natë-për-natë e
// mbulon rezervimin por asnjë dhomë e vetme s'del dot e lirë (mysafirë me
// check-in e bërë e ankorojnë kalendarin), importuesi krijon një propozim me
// segmentet e qëndrimit — recepsioni flet me mysafirin dhe vendos. Rezultati
// regjistrohet gjithmonë; anulimi bëhet VETËM nga stafi përmes Booking.com,
// kurrë nga sistemi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_split_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            // [{room_id, check_in, check_out}, ...] — same room type throughout.
            $table->json('segments');
            $table->string('status', 20)->default('pending'); // pending | accepted | declined
            // accepted | declined_upgraded | declined_escalated — what the desk did.
            $table->string('outcome', 30)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // The global banner counts pending rows per tenant on every page load.
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_split_proposals');
    }
};
