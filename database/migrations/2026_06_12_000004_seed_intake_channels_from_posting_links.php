<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

return new class extends Migration
{
    public function up(): void
    {
        // Kanäle, die an MEHR als einem Posting hängen = geteilte Eingangskanäle (Sammeladresse, WhatsApp)
        $sharedChannelIds = DB::table('rec_posting_comms_channel')
            ->select('comms_channel_id')
            ->groupBy('comms_channel_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('comms_channel_id');

        foreach ($sharedChannelIds as $channelId) {
            $teamId = DB::table('comms_channels')->where('id', $channelId)->value('team_id');
            if (!$teamId) {
                continue;
            }

            $exists = DB::table('rec_intake_channels')
                ->where('comms_channel_id', $channelId)
                ->where('team_id', $teamId)
                ->exists();

            if (!$exists) {
                DB::table('rec_intake_channels')->insert([
                    'uuid' => UuidV7::generate(),
                    'comms_channel_id' => $channelId,
                    'team_id' => $teamId,
                    'default_posting_id' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Geteilte Verknüpfungen lösen — Zuordnung macht ab jetzt die Pipeline
            DB::table('rec_posting_comms_channel')->where('comms_channel_id', $channelId)->delete();
        }

        // Exklusive Verknüpfungen (genau 1 Posting) bleiben als dedizierte Kanäle bestehen.
    }

    public function down(): void
    {
        // Bewusst kein Rollback der Datenbereinigung (Altzustand nicht rekonstruierbar).
    }
};
