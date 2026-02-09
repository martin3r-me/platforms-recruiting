<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_auto_pilot_states', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'code']);
            $table->index(['team_id', 'is_active']);
        });

        // Seed default states
        $states = [
            ['code' => 'new', 'name' => 'Neu', 'description' => 'Neu (noch nicht bearbeitet)'],
            ['code' => 'contact_check', 'name' => 'Kontaktprüfung', 'description' => 'Kontaktprüfung'],
            ['code' => 'data_collection', 'name' => 'Daten sammeln', 'description' => 'Daten sammeln'],
            ['code' => 'waiting_for_applicant', 'name' => 'Warte auf Bewerber', 'description' => 'Warte auf Bewerber'],
            ['code' => 'review_needed', 'name' => 'Prüfung erforderlich', 'description' => 'Prüfung erforderlich'],
            ['code' => 'completed', 'name' => 'Abgeschlossen', 'description' => 'Abgeschlossen'],
        ];

        foreach ($states as $state) {
            \Illuminate\Support\Facades\DB::table('rec_auto_pilot_states')->insert(array_merge($state, [
                'uuid' => \Illuminate\Support\Str::uuid(),
                'is_active' => true,
                'team_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_auto_pilot_states');
    }
};
