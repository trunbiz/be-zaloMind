<?php

namespace App\Http\Controllers;

use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Services\Navigation;
use App\Services\WorkspaceActions;
use App\Services\WorkspaceScreens;
use App\Support\AgentApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function show(Request $request, Navigation $navigation, WorkspaceScreens $screens, string $section): View
    {
        $area = $request->routeIs('admin.*') ? 'admin' : 'user';
        $page = $navigation->page($area, $section, $request->user());
        abort_if(isset($page['route']), 404);

        $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|string|max:50', 'profile_id' => 'nullable|uuid',
            'type' => 'nullable|in:user,group', 'conversation_type' => 'nullable|in:user,group', 'conversation_id' => 'nullable|string|max:191',
            'threads_page' => 'sometimes|integer|min:1', 'messages_page' => 'sometimes|integer|min:1']);

        if ($section === 'inbox' && $request->boolean('fragment')) {
            return view('workspace.inbox-panels', ['inbox' => $screens->screen($request->user(), $section, $area === 'admin', $request)['inbox']]);
        }

        return view('workspace.section', ['page' => $page, 'isAdmin' => $area === 'admin', 'screen' => $screens->screen($request->user(), $section, $area === 'admin', $request)]);
    }

    public function action(Request $request, Navigation $navigation, WorkspaceActions $actions, string $section, string $action)
    {
        $admin = $request->routeIs('admin.*');
        $navigation->page($admin ? 'admin' : 'user', $section, $request->user());
        $request->validate(['request_key' => 'required|uuid']);
        $result = DB::transaction(function () use ($request, $actions, $section, $action, $admin) {
            DB::table('agent_runtime')->where('id', 1)->lockForUpdate()->first();
            $key = hash('sha256', $request->user()->id.':'.($admin ? 'admin' : 'user').':'.$section.':'.$action.':'.$request->input('request_key'));
            $body = $request->except(['_token', 'csv_file']);
            if ($request->hasFile('csv_file')) {
                $body['file_hash'] = hash_file('sha256', $request->file('csv_file')->getRealPath());
            }
            $hash = AgentApi::hash($body);
            $existing = DB::table('platform_requests')->where('id', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->body_hash, $hash), 409, 'Idempotency conflict');

                return json_decode($existing->response, true);
            }
            $result = $actions->run($request->user(), $section, $action, $request, $admin);
            DB::table('platform_requests')->insert(['id' => $key, 'user_id' => $request->user()->id, 'body_hash' => $hash, 'response' => json_encode($result), 'created_at' => now()]);

            return $result;
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $result, 'meta' => AgentApi::meta()]);
        }

        $params = ['section' => $section];
        if ($section === 'inbox' && in_array($action, ['reply', 'read-conversation'], true)) {
            $params += $request->only(['profile_id', 'conversation_type', 'conversation_id']);
        }

        return redirect()->route($admin ? 'admin.section' : 'workspace.section', $params)->with('success', $result['message']);
    }

    public function live(Request $request)
    {
        $admin = $request->routeIs('admin.*');
        $profiles = AgentProfile::when(! $admin, fn ($q) => $q->where('user_id', $request->user()->id))->limit(500)->get()->map(function ($profile) {
            $qr = $profile->qr;
            if ($qr && now()->gte($qr['expires_at'])) {
                $qr = null;
            }

            return ['id' => $profile->id, 'status' => WorkspaceScreens::status($profile->status), 'qr' => $qr, 'version' => $profile->version, 'hint' => app(\App\Services\ProfileLifecycle::class)->hint($profile)];
        });

        return response()->json(['data' => $profiles])->header('Cache-Control', 'no-store');
    }

    public function export(Request $request)
    {
        $query = AgentCommand::whereIn('profile_id', AgentProfile::withTrashed()->where('user_id', $request->user()->id)->select('id'))->latest();

        return response()->streamDownload(function () use ($query) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['id', 'profile_id', 'type', 'status', 'scheduled_at', 'finished_at', 'error']);
            foreach ($query->cursor() as $command) {
                $values = [$command->id, $command->profile_id, $command->type, $command->status, $command->available_at?->toISOString(), $command->finished_at?->toISOString(), $command->result['error']['message'] ?? $command->result['workflow_error'] ?? ''];
                fputcsv($stream, array_map(fn ($value) => preg_match('/^[=+\-@\t\r]/', (string) $value) ? "'".$value : $value, $values));
            }
            fclose($stream);
        }, 'zalomind-report-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
