<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

return new class extends Migration
{
    public function up(): void
    {
        // 1. For each rec_position: create a default phase "Bewerbung" (order=1)
        $positions = DB::table('rec_positions')->get();

        foreach ($positions as $position) {
            DB::table('rec_phases')->insert([
                'uuid' => UuidV7::generate(),
                'team_id' => $position->team_id,
                'rec_position_id' => $position->id,
                'name' => 'Bewerbung',
                'order' => 1,
                'auto_pilot_settings' => null,
                'auto_advance' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Migrate extra field definitions: context_type rec_position → rec_phase
        $positionClass = 'Platform\\Recruiting\\Models\\RecPosition';
        $phaseClass = 'Platform\\Recruiting\\Models\\RecPhase';

        $definitions = DB::table('core_extra_field_definitions')
            ->where('context_type', $positionClass)
            ->whereNotNull('context_id')
            ->get();

        foreach ($definitions as $def) {
            $phase = DB::table('rec_phases')
                ->where('rec_position_id', $def->context_id)
                ->where('order', 1)
                ->first();

            if ($phase) {
                DB::table('core_extra_field_definitions')
                    ->where('id', $def->id)
                    ->update([
                        'context_type' => $phaseClass,
                        'context_id' => $phase->id,
                    ]);
            }
        }

        // Also migrate type-global definitions (context_id = null) for rec_position
        DB::table('core_extra_field_definitions')
            ->where('context_type', $positionClass)
            ->whereNull('context_id')
            ->update(['context_type' => $phaseClass]);

        // 3. Assign all active applicants to phase 1 of their primary position
        $applicants = DB::table('rec_applicants')
            ->where('is_active', true)
            ->get();

        foreach ($applicants as $applicant) {
            // Find primary position via oldest posting
            $posting = DB::table('rec_applicant_posting')
                ->where('rec_applicant_id', $applicant->id)
                ->orderBy('applied_at')
                ->orderBy('id')
                ->first();

            if (!$posting) {
                continue;
            }

            $postingRecord = DB::table('rec_postings')
                ->where('id', $posting->rec_posting_id)
                ->first();

            if (!$postingRecord) {
                continue;
            }

            $phase = DB::table('rec_phases')
                ->where('rec_position_id', $postingRecord->rec_position_id)
                ->where('order', 1)
                ->first();

            if ($phase) {
                DB::table('rec_applicants')
                    ->where('id', $applicant->id)
                    ->update(['rec_phase_id' => $phase->id]);
            }
        }
    }

    public function down(): void
    {
        $positionClass = 'Platform\\Recruiting\\Models\\RecPosition';
        $phaseClass = 'Platform\\Recruiting\\Models\\RecPhase';

        // Reverse: migrate definitions back to position context
        $phases = DB::table('rec_phases')->get();

        foreach ($phases as $phase) {
            DB::table('core_extra_field_definitions')
                ->where('context_type', $phaseClass)
                ->where('context_id', $phase->id)
                ->update([
                    'context_type' => $positionClass,
                    'context_id' => $phase->rec_position_id,
                ]);
        }

        DB::table('core_extra_field_definitions')
            ->where('context_type', $phaseClass)
            ->whereNull('context_id')
            ->update(['context_type' => $positionClass]);

        // Reset phase_id on applicants
        DB::table('rec_applicants')->update(['rec_phase_id' => null]);

        // Delete default phases
        DB::table('rec_phases')->delete();
    }
};
