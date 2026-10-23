<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Inbox
{
    public function screen(Collection $profiles, Request $request): array
    {
        $profileIds = $profiles->pluck('id');
        if ($request->filled('profile_id')) {
            abort_unless($profileIds->contains($request->input('profile_id')), 404);
        }
        $summary = DB::table('agent_records')->whereIn('profile_id', $profileIds)->where('kind', 'messages')
            ->whereNotNull('conversation_id')->select('profile_id', 'conversation_type', 'conversation_id')
            ->selectRaw('MAX(message_sent_at) AS latest_at, COUNT(*) AS message_count, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread_count')
            ->groupBy('profile_id', 'conversation_type', 'conversation_id');
        $threads = DB::query()->fromSub($summary, 'threads')->leftJoin('agent_records as peer', function ($join) {
            $join->on('peer.profile_id', '=', 'threads.profile_id')->on('peer.provider_id', '=', 'threads.conversation_id')
                ->where('peer.group_id', '=', '')->where(function ($query) {
                    $query->where(fn ($q) => $q->where('threads.conversation_type', 'user')->where('peer.kind', 'contacts'))
                        ->orWhere(fn ($q) => $q->where('threads.conversation_type', 'group')->where('peer.kind', 'groups'));
                });
        })->select('threads.*', 'peer.data as peer_data');
        $describe = function ($thread) use ($profiles) {
            $peer = json_decode($thread->peer_data ?? '{}', true);
            $thread->name = $peer['name'] ?? $peer['display_name'] ?? (($thread->conversation_type === 'group' ? 'Nhóm ' : 'Liên hệ ').$thread->conversation_id);
            $thread->profile = $profiles->firstWhere('id', $thread->profile_id);
            $latest = $this->messages($thread)->orderByDesc('message_sent_at')->orderByDesc('id')->first();
            $thread->preview = json_decode($latest->data ?? '{}', true)['content']['text'] ?? '';

            return $thread;
        };
        $filtered = (clone $threads)
            ->when($request->filled('profile_id'), fn ($q) => $q->where('threads.profile_id', $request->input('profile_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('threads.conversation_type', $request->input('type')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->input('q').'%';
                $q->where(fn ($q) => $q->where('threads.conversation_id', 'like', $term)->orWhere('peer.data->name', 'like', $term)->orWhere('peer.data->display_name', 'like', $term));
            });
        $conversations = $filtered->orderByDesc('latest_at')->orderBy('threads.profile_id')->orderBy('threads.conversation_type')->orderBy('threads.conversation_id')->paginate(25, ['*'], 'threads_page');
        $conversations->getCollection()->transform($describe);
        $selected = null;
        if ($request->filled('conversation_id')) {
            abort_unless($request->filled('profile_id') && $request->filled('conversation_type'), 422);
            $selected = (clone $threads)->where('threads.profile_id', $request->input('profile_id'))->where('threads.conversation_type', $request->input('conversation_type'))->where('threads.conversation_id', $request->input('conversation_id'))->first();
            abort_unless($selected, 404);
            $selected = $describe($selected);
        } elseif ($conversations->count()) {
            $selected = $conversations->first();
        }
        $messages = $selected ? $this->messages($selected)->orderByDesc('message_sent_at')->orderByDesc('id')->paginate(50, ['*'], 'messages_page') : null;
        $senderNames = [];
        if ($messages) {
            $senderNames = DB::table('agent_records')->where('profile_id', $selected->profile_id)->whereIn('kind', ['contacts', 'group_members'])
                ->whereIn('provider_id', $messages->pluck('sender_id')->filter()->unique())->get()->mapWithKeys(function ($row) {
                    return [$row->provider_id => json_decode($row->data, true)['display_name'] ?? $row->provider_id];
                })->all();
        }

        return compact('profiles', 'conversations', 'selected', 'messages', 'senderNames');
    }

    private function messages(object $thread)
    {
        return DB::table('agent_records')->where('kind', 'messages')->where('profile_id', $thread->profile_id)
            ->where('conversation_type', $thread->conversation_type)->where('conversation_id', $thread->conversation_id);
    }
}
