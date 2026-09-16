<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One polymorphic table for every owner (decision 12-a). The gallery is
        // a filtered view over the image rows here, not a table of its own (13-a).
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->enum('owner_type', ['message', 'post', 'excuse', 'report_card']);
            $table->unsignedBigInteger('owner_id');
            $table->string('path');
            $table->string('name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['school_id', 'mime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
