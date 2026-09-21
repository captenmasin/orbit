<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

class RecordAccessEvent
{
    public function handle(string $operation, string $result, ?string $connectionId = null, ?string $secretId = null): void
    {
        DB::table('credential_access_events')->insert([
            'connection_id' => $connectionId,
            'secret_id' => $secretId,
            'operation' => $operation,
            'result' => $result,
            'created_at' => now(),
        ]);
        $boundary = DB::table('credential_access_events')->orderByDesc('id')->skip(999)->value('id');
        if ($boundary) {
            DB::table('credential_access_events')->where('id', '<', $boundary)->delete();
        }
    }
}
