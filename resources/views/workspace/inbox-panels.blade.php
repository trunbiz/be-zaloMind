<div class="row g-3" data-inbox-panels>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-body"><strong>Cuộc hội thoại</strong><span class="text-body-secondary small ms-2">{{ $inbox['conversations']->total() }}</span></div>
            <div class="list-group list-group-flush overflow-auto" style="max-height: 620px">
                @forelse($inbox['conversations'] as $thread)
                @php
                    $active = $inbox['selected'] && $thread->profile_id === $inbox['selected']->profile_id && $thread->conversation_type === $inbox['selected']->conversation_type && $thread->conversation_id === $inbox['selected']->conversation_id;
                    $threadUrl = route('workspace.section', ['section' => 'inbox', 'profile_id' => $thread->profile_id, 'conversation_type' => $thread->conversation_type, 'conversation_id' => $thread->conversation_id] + request()->only(['q', 'type', 'threads_page']));
                @endphp
                <a href="{{ $threadUrl }}" class="list-group-item list-group-item-action p-3 {{ $active ? 'active' : '' }}" @if($active) aria-current="true" @endif>
                    <div class="d-flex justify-content-between gap-2"><strong class="text-break">{{ $thread->name }}</strong>@if($thread->unread_count)<span class="badge rounded-pill {{ $active ? 'text-bg-light' : 'text-bg-primary' }} align-self-start">{{ $thread->unread_count }}<span class="visually-hidden"> tin chưa đọc</span></span>@endif</div>
                    <div class="small mt-1">{{ $thread->conversation_type === 'group' ? 'Nhóm' : 'Cá nhân' }} · {{ $thread->profile->display_name }}</div>
                    <div class="text-truncate small mt-2">{{ $thread->preview }}</div>
                    <time class="small d-block mt-1" datetime="{{ \Carbon\Carbon::parse($thread->latest_at)->toISOString() }}">{{ \Carbon\Carbon::parse($thread->latest_at)->format('d/m/Y H:i') }}</time>
                </a>
                @empty
                <div class="p-4 text-center text-body-secondary">Chưa có cuộc hội thoại phù hợp. Tin nhắn sẽ xuất hiện khi App đồng bộ dữ liệu Zalo.</div>
                @endforelse
            </div>
            @if($inbox['conversations']->hasPages())<div class="p-3">{{ $inbox['conversations']->appends(request()->except(['fragment', 'threads_page']))->links() }}</div>@endif
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card h-100">
            @if($inbox['selected'])
            @php
                $selected = $inbox['selected'];
            @endphp
            <div class="card-header bg-body p-3">
                <h2 class="h5 mb-1">{{ $selected->name }}</h2>
                <div class="small text-body-secondary">{{ $selected->conversation_type === 'group' ? 'Hội thoại nhóm' : 'Hội thoại cá nhân' }} · {{ $selected->profile->display_name }} · {{ $selected->message_count }} tin nhắn</div>
            </div>
            <div class="p-3 overflow-auto bg-body-tertiary" style="height: 520px" data-chat-scroll aria-label="Lịch sử tin nhắn">
                @if($inbox['messages']->hasPages())
                <div class="mb-3">{{ $inbox['messages']->appends(request()->except(['fragment', 'messages_page']) + ['profile_id' => $selected->profile_id, 'conversation_type' => $selected->conversation_type, 'conversation_id' => $selected->conversation_id])->links() }}</div>
                @endif
                @foreach($inbox['messages']->getCollection()->reverse() as $message)
                @php
                    $data = json_decode($message->data, true);
                    $own = $message->sender_id === $selected->profile->zalo_user_id;
                @endphp
                <div class="d-flex mb-3 {{ $own ? 'justify-content-end' : 'justify-content-start' }}" data-chat-message="{{ $message->id }}">
                    <div class="rounded-3 p-3 shadow-sm {{ $own ? 'chat-bubble-own' : 'bg-body border' }}" style="max-width: 85%; min-width: 120px">
                        <div class="small fw-semibold mb-1">{{ $own ? 'Bạn' : ($inbox['senderNames'][$message->sender_id] ?? ($selected->conversation_type === 'user' ? $selected->name : $message->sender_id)) }}</div>
                        <div class="text-break" style="white-space: pre-wrap">{{ $data['content']['text'] ?? '' }}</div>
                        <div class="small mt-2 opacity-75"><time datetime="{{ \Carbon\Carbon::parse($message->message_sent_at)->toISOString() }}">{{ \Carbon\Carbon::parse($message->message_sent_at)->format('d/m/Y H:i') }}</time>@if($data['historical'] ?? false)<span class="ms-2">Lịch sử đồng bộ</span>@endif</div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="p-5 text-center text-body-secondary">Chọn một cuộc hội thoại để xem tin nhắn.</div>
            @endif
        </div>
    </div>
</div>
