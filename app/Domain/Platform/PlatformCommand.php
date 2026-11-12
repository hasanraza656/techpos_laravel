<?php

namespace App\Domain\Platform;

use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class PlatformCommand
{
    public function run(Request $r, string $permission, callable $work): array
    {
        $d = $r->validate(['request_key' => 'required|uuid', 'password' => 'required|current_password:web', 'reason' => 'required|string|min:5|max:1000']);
        $payload = $r->except(['password', 'request_key', 'new_password', 'owner_password']);
        $sort = function (array $a) use (&$sort): array {
            if (! array_is_list($a)) {
                ksort($a);
            }foreach ($a as &$v) {
                if (is_array($v)) {
                    $v = $sort($v);
                }
            }

            return $a;
        };
        $hash = hash('sha256', $permission.'|'.$r->path().'|'.json_encode($sort($payload)));

        return DB::transaction(function () use ($r, $permission, $work, $d, $hash) {
            // Fixed order protects concurrent changes to the last platform root.
            DB::table('users')->where('is_superadmin', true)->orderBy('id')->lockForUpdate()->get();
            DB::table('users')->where('id', $r->user()->id)->lockForUpdate()->first();
            $actor = $r->user()->fresh();
            app(PlatformAccess::class)->authorize($actor, $permission);
            if (! Hash::check($r->input('password'), $actor->password)) {
                throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
            }
            $old = DB::table('platform_commands')->where('request_key', $d['request_key'])->first();
            if ($old) {
                abort_unless($old->actor_id === $actor->id && hash_equals($old->payload_hash, $hash), 409, 'This request key belongs to a different action.');

                return json_decode($old->result, true);
            }
            $result = $work($d['reason']);
            DB::table('platform_commands')->insert(['request_key' => $d['request_key'], 'actor_id' => $actor->id, 'action' => $permission, 'payload_hash' => $hash, 'result' => json_encode($result), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('platform.command', null, $permission, ['reason' => $d['reason'], 'request_key' => $d['request_key'], 'route' => $r->route()?->uri()]);

            return $result;
        }, 3);
    }
}
