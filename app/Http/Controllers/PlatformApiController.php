<?php

namespace App\Http\Controllers;

use App\Models\AffiliateCommission;
use App\Models\AgentCommand;
use App\Models\AgentProfile;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceItem;
use App\Services\Platform;
use App\Services\WorkspaceActions;
use App\Support\AgentApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PlatformApiController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'device_name' => 'required|string|max:100']);
        $key = 'platform-login:'.hash('sha256', strtolower($data['email']).':'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['error' => ['code' => 'RATE_LIMITED', 'message' => 'Vui lòng thử lại sau.', 'retryable' => true], 'meta' => AgentApi::meta()], 429, ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            AgentApi::fail('INVALID_CREDENTIALS', 'Email hoặc mật khẩu không đúng.', 401);
        }
        if ($user->status !== 'active') {
            AgentApi::fail('ACCOUNT_SUSPENDED', 'Tài khoản đã bị khóa.', 403);
        }
        RateLimiter::clear($key);
        $expires = now()->addDay();
        $token = $user->createToken($data['device_name'], ['*'], $expires);

        return AgentApi::ok(['access_token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toISOString(), 'user' => $this->user($user)]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function user(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'status', 'version', 'email_verified_at', 'created_at', 'updated_at']) + ['roles' => $user->getRoleNames()->all(), 'referral_code' => $user->referral_code];
    }

    public function me(Request $request)
    {
        if ($request->isMethod('PATCH')) {
            $data = $request->validate(['name' => 'required|string|max:255', 'expected_version' => 'required|integer|min:1']);

            return DB::transaction(function () use ($request, $data) {
                $user = User::where('id', $request->user()->id)->lockForUpdate()->firstOrFail();
                app(Platform::class)->version($user, $data);
                $user->forceFill(['name' => $data['name'], 'version' => $user->version + 1])->save();

                return AgentApi::ok($this->user($user));
            });
        }

        return AgentApi::ok($this->user($request->user()));
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string', 'password' => 'required|string|min:12|max:128|confirmed']);
        DB::transaction(function () use ($request, $data) {
            $user = User::where('id', $request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                AgentApi::fail('INVALID_CREDENTIALS', 'Mật khẩu hiện tại không đúng.', 422);
            }
            $user->forceFill(['password' => $data['password'], 'version' => $user->version + 1])->save();
            $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();
        });

        return response()->noContent();
    }

    public function dashboard(Request $request, Platform $platform)
    {
        $admin = $request->route('admin', false);
        $profiles = AgentProfile::when(! $admin, fn ($q) => $q->where('user_id', $request->user()->id));
        $data = ['profile_count' => (clone $profiles)->count(), 'connected_profile_count' => (clone $profiles)->where('status', 'connected')->count(), 'queued_command_count' => AgentCommand::whereIn('profile_id', $profiles->select('id'))->where('status', 'queued')->count()];
        if ($admin) {
            $runtime = DB::table('agent_runtime')->where('id', 1)->first();
            $data += ['users_total' => User::count(), 'users_active' => User::where('status', 'active')->count(), 'profiles_connected' => $data['connected_profile_count'], 'orders_pending' => Order::where('status', 'pending')->count(), 'revenue_paid_vnd' => (int) Order::where('status', 'paid')->sum('amount_vnd'), 'nodes_online' => (int) ($runtime->enabled && $runtime->last_heartbeat_at && now()->diffInSeconds($runtime->last_heartbeat_at) <= 90)];
        } else {
            $data += ['current_subscription' => $platform->subscription($request->user()), 'limits' => $platform->limits($request->user()), 'messages_used_today' => $platform->used($request->user())];
        }

        return AgentApi::ok($data);
    }

    public function read(Request $request, Platform $platform)
    {
        $admin = (bool) $request->route('admin', false);
        $resource = $request->route('resource');
        $id = $request->route('id');
        $user = $request->user();
        $request->validate(['limit' => 'sometimes|integer|min:1|max:100', 'q' => 'nullable|string|max:100', 'status' => 'nullable|string|max:50', 'user_id' => 'nullable|integer', 'profile_id' => 'nullable|uuid', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        if ($resource === 'subscription') {
            return AgentApi::ok($platform->subscription($user));
        }
        if ($resource === 'settings') {
            return AgentApi::ok($platform->settings() + ['version' => WorkspaceItem::where('kind', 'settings')->value('version') ?? 0]);
        }
        if ($resource === 'nodes') {
            $runtime = DB::table('agent_runtime')->where('id', 1)->first();
            $heartbeat = json_decode($runtime->heartbeat ?? '{}', true);

            return AgentApi::ok([['id' => 'default', 'enabled' => (bool) $runtime->enabled, 'max_concurrency' => $runtime->max_concurrency, 'last_heartbeat_at' => $runtime->last_heartbeat_at, 'app_version' => $heartbeat['app_version'] ?? null, 'capabilities' => $heartbeat['capabilities'] ?? []]]);
        }
        if (in_array($resource, ['inbox', 'friend-requests', 'friends', 'group-list'], true)) {
            if ($resource === 'inbox') {
                $request->validate(['conversation_type' => 'nullable|in:user,group', 'conversation_id' => 'nullable|string|max:191']);
            }
            $kind = ['inbox' => 'messages', 'friend-requests' => 'friend_requests', 'friends' => 'contacts', 'group-list' => 'groups'][$resource];
            $query = DB::table('agent_records')->whereIn('profile_id', AgentProfile::where('user_id', $user->id)->select('id'))->where('kind', $kind);
            if ($request->filled('profile_id')) {
                $query->where('profile_id', $request->input('profile_id'));
            }
            if ($resource === 'inbox') {
                $query->when($request->filled('conversation_type'), fn ($q) => $q->where('conversation_type', $request->input('conversation_type')))
                    ->when($request->filled('conversation_id'), fn ($q) => $q->where('conversation_id', $request->input('conversation_id')));
            }
            $serialize = function ($row) {
                $data = json_decode($row->data, true);
                if ($row->read_at) {
                    $data['read_at'] = \Carbon\Carbon::parse($row->read_at)->toISOString();
                }

                return ['id' => $row->id, 'profile_id' => $row->profile_id, 'data' => $data];
            };
            if ($id) {
                $record = $query->where('id', $id)->first();
                abort_unless($record, 404);

                return AgentApi::ok($serialize($record));
            }
            $page = $query->orderBy('id')->cursorPaginate($request->integer('limit', 50));

            return AgentApi::ok(collect($page->items())->map($serialize)->all(), 200, ['next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
        }
        if (in_array($resource, ['qr', 'contacts', 'groups'], true)) {
            $profile = $platform->own(AgentProfile::class, $id, $user, $admin);
            if ($resource === 'qr') {
                $qr = $profile->qr;
                if (! $qr || now()->gte($qr['expires_at'])) {
                    AgentApi::fail('RESOURCE_NOT_FOUND', 'Chưa có QR còn hạn.', 404);
                }

                return AgentApi::ok($qr + ['status' => $profile->status])->header('Cache-Control', 'no-store');
            }
            $query = DB::table('agent_records')->where('profile_id', $profile->id)->where('kind', $resource);
            $page = $query->orderBy('id')->cursorPaginate($request->integer('limit', 50));

            return AgentApi::ok(collect($page->items())->map(fn ($row) => json_decode($row->data, true))->all(), 200, ['next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
        }
        $model = match ($resource) {
            'users' => User::class, 'profiles' => AgentProfile::class, 'plans' => Plan::class,
            'orders', 'payments' => Order::class, 'commands' => AgentCommand::class,
            'campaigns' => Campaign::class, 'subscriptions' => Subscription::class,
            'commissions' => AffiliateCommission::class,
            'announcements', 'proxies', 'automations', 'opt-outs', 'customers' => WorkspaceItem::class,
            default => null,
        };
        if ($resource === 'audit-logs') {
            $page = DB::table('audit_logs')->orderBy('id')->cursorPaginate($request->integer('limit', 50));

            return AgentApi::ok($page->items(), 200, ['next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
        }
        abort_unless($model, 404);
        $query = $model::query();
        if ($model === WorkspaceItem::class) {
            $kind = ['announcements' => 'announcement', 'proxies' => 'proxy', 'automations' => 'automation', 'opt-outs' => 'opt_out', 'customers' => 'contact'][$resource];
            $query->where('kind', $kind);
            if (in_array($resource, ['announcements', 'proxies'], true)) {
                $query->whereNull('user_id');
            } else {
                $query->where('user_id', $user->id);
            }
        } elseif (! $admin && ! in_array($resource, ['plans'], true)) {
            if ($resource === 'commands') {
                $query->whereIn('profile_id', AgentProfile::withTrashed()->where('user_id', $user->id)->select('id'));
            } else {
                $query->where('user_id', $user->id);
            }
        }
        if ($resource === 'payments') {
            $query->whereIn('status', ['paid', 'refunded']);
        }
        if (! $admin && $resource === 'plans') {
            $query->where('status', 'active');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $searchFields = match ($resource) {
                'users' => ['name', 'email'], 'plans' => ['name', 'code'], 'profiles' => ['display_name'],
                'orders', 'payments' => ['number'], 'commands' => ['id', 'type'],
                'campaigns' => ['name'], 'announcements', 'proxies', 'automations', 'opt-outs', 'customers' => ['title'], default => [],
            };
            if ($searchFields) {
                $query->where(function ($builder) use ($searchFields, $request) {
                    foreach ($searchFields as $field) {
                        $builder->orWhere($field, 'like', '%'.$request->input('q').'%');
                    }
                });
            }
        }
        if ($request->filled('user_id') && $admin && in_array($resource, ['orders', 'profiles', 'campaigns', 'subscriptions', 'commissions'], true)) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('profile_id') && in_array($resource, ['commands', 'campaigns'], true)) {
            $query->where('profile_id', $request->input('profile_id'));
        }
        foreach (['from' => '>=', 'to' => '<='] as $filter => $operator) {
            if ($request->filled($filter)) {
                $query->where('created_at', $operator, \Carbon\Carbon::parse($request->input($filter))->setTimezone(config('app.timezone')));
            }
        }
        if ($id) {
            $record = $query->findOrFail($id);
            $data = $this->serialize($record);
            if ($resource === 'orders') {
                $data['payment_instructions'] = array_intersect_key($platform->settings(), array_flip(['bank_name', 'bank_account', 'bank_holder', 'payment_note']));
                $data['subscriptions'] = Subscription::where('order_id', $record->id)->get();
            }

            return AgentApi::ok($data);
        }
        if (! $admin && $resource === 'announcements') {
            $items = $query->get()->filter(fn ($item) => $item->status === 'active' && now()->gte($item->data['published_at']) && (empty($item->data['expires_at']) || now()->lt($item->data['expires_at'])))->values()->map(fn ($record) => $this->serialize($record));

            return AgentApi::ok($items);
        }
        $page = $query->orderBy('id')->cursorPaginate($request->integer('limit', 50));

        return AgentApi::ok(collect($page->items())->map(fn ($record) => $this->serialize($record))->all(), 200, ['next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
    }

    private function serialize($record): array
    {
        if ($record instanceof User) {
            return $this->user($record);
        }
        $data = $record->toArray();
        if ($record instanceof AgentProfile) {
            unset($data['qr'], $data['stream_id'], $data['last_sequence']);
        }
        if ($record instanceof Plan) {
            $data['limits'] = ['profiles_max' => $record->profiles_max, 'messages_per_day' => $record->messages_per_day];
        }
        if ($record instanceof Order) {
            $data['payment_status'] = match ($record->status) {
                'paid' => 'paid', 'refunded' => 'refunded', default => 'unpaid'
            };
        }
        if ($record instanceof WorkspaceItem && $record->kind === 'proxy') {
            unset($data['data']['password']);
        }

        return $data;
    }

    public function mutate(Request $request, WorkspaceActions $actions)
    {
        $section = $request->route('section');
        $operation = $request->route('operation');
        $admin = (bool) $request->route('admin', false);
        if ($request->is('api/v1/messages')) {
            $request->validate(['recipient.type' => 'required|in:user,group', 'recipient.id' => 'required|string|max:191', 'content.type' => 'required|in:text', 'content.text' => 'required|string|max:2000']);
            $request->merge(['conversation_id' => $request->input('recipient.id'), 'conversation_type' => $request->input('recipient.type'), 'text' => $request->input('content.text')]);
        }
        if ($section === 'settings') {
            $request->merge(array_merge(app(Platform::class)->settings(), $request->all()));
        }
        if ($section === 'nodes' && $request->route('id') && $request->route('id') !== 'default') {
            abort(404);
        }
        if ($request->route('id')) {
            $request->merge(['id' => $request->route('id')]);
        }
        if ($section === 'profiles' && $operation === 'sync') {
            $request->validate(['kind' => 'required|in:contacts,groups']);
            $operation = 'sync-'.$request->input('kind');
        }
        if ($section === 'plans' && $request->has('limits')) {
            $request->merge(['profiles_max' => $request->input('limits.profiles_max'), 'messages_per_day' => $request->input('limits.messages_per_day')]);
        }
        $key = $request->header('Idempotency-Key');
        if ($key !== null && ! Str::isUuid($key)) {
            AgentApi::fail('VALIDATION_FAILED', 'Idempotency-Key phải là UUID.', 422);
        }
        if ($request->isMethod('POST') && ! $key) {
            AgentApi::fail('VALIDATION_FAILED', 'Cần header Idempotency-Key.', 422);
        }
        $result = DB::transaction(function () use ($request, $actions, $admin, $section, $operation, $key) {
            DB::table('agent_runtime')->where('id', 1)->lockForUpdate()->first();
            $scope = hash('sha256', $request->user()->id.':'.$request->method().':'.$request->path().':'.($key ?? Str::uuid()));
            $hash = AgentApi::hash($request->all());
            $stored = DB::table('platform_requests')->where('id', $scope)->first();
            if ($stored) {
                if (! hash_equals($stored->body_hash, $hash)) {
                    AgentApi::fail('IDEMPOTENCY_CONFLICT', 'Khóa đã dùng với dữ liệu khác.');
                }

                return json_decode($stored->response, true);
            }
            $result = $actions->run($request->user(), $section, $operation, $request, $admin);
            DB::table('platform_requests')->insert(['id' => $scope, 'user_id' => $request->user()->id, 'body_hash' => $hash, 'response' => json_encode($result), 'created_at' => now()]);

            return $result;
        });
        $status = in_array($operation, ['login', 'logout', 'sync-contacts', 'sync-groups'], true) || ($section === 'inbox' && $operation === 'reply') || str_starts_with($section, 'message-') ? 202 : ($operation === 'create' ? 201 : 200);
        if ($section === 'profiles' && in_array($operation, ['login', 'logout', 'delete'], true)) {
            $status = ($result['pending'] ?? false) ? 202 : 200;
        }

        return AgentApi::ok($result['record'] ?? $result, $status, ['message' => $result['message']]);
    }
}
