<form method="get" class="row g-2 mb-3" role="search">
    <div class="col-md-4"><label class="visually-hidden" for="inbox-profile">Tài khoản Zalo</label><select id="inbox-profile" name="profile_id" class="form-select"><option value="">Tất cả tài khoản Zalo</option>@foreach($inbox['profiles'] as $profile)<option value="{{ $profile->id }}" @selected(request('profile_id') === $profile->id)>{{ $profile->display_name }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="visually-hidden" for="inbox-type">Loại hội thoại</label><select id="inbox-type" name="type" class="form-select"><option value="">Tất cả hội thoại</option><option value="user" @selected(request('type') === 'user')>Cá nhân</option><option value="group" @selected(request('type') === 'group')>Nhóm</option></select></div>
    <div class="col-md-4"><label class="visually-hidden" for="inbox-search">Tìm hội thoại</label><input id="inbox-search" type="search" name="q" maxlength="100" class="form-control" placeholder="Tìm tên bạn bè hoặc nhóm…" value="{{ request('q') }}"></div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Tìm kiếm</button></div>
</form>
@php
    $selected = $inbox['selected'];
    $selection = $selected ? ['profile_id' => $selected->profile_id, 'conversation_type' => $selected->conversation_type, 'conversation_id' => $selected->conversation_id] : [];
    $liveUrl = route('workspace.section', ['section' => 'inbox', 'fragment' => 1] + $selection + request()->only(['q', 'type', 'threads_page', 'messages_page']));
@endphp
<div data-inbox-live="{{ $liveUrl }}" data-chat-older="{{ request()->integer('messages_page', 1) > 1 ? 'true' : 'false' }}">
    <div class="small text-body-secondary mb-2" data-inbox-status role="status">Tự cập nhật mỗi 5 giây</div>
    @include('workspace.inbox-panels')
</div>
@if($selected)
<div class="row mt-3"><div class="col-lg-8 offset-lg-4">
    <div class="card p-3">
        <form method="post" action="{{ route('workspace.action', ['section' => 'inbox', 'action' => 'reply']) }}">
            @csrf
            <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="profile_id" value="{{ $selected->profile_id }}">
            <input type="hidden" name="conversation_type" value="{{ $selected->conversation_type }}">
            <input type="hidden" name="conversation_id" value="{{ $selected->conversation_id }}">
            <label class="form-label" for="chat-reply">Trả lời {{ $selected->name }}</label>
            <textarea id="chat-reply" name="text" rows="3" maxlength="2000" class="form-control" required placeholder="Nhập tin nhắn…">{{ old('text') }}</textarea>
            <div class="d-flex justify-content-between align-items-center gap-2 mt-2"><span class="small text-body-secondary">Gửi bằng {{ $selected->profile->display_name }}. Tin gửi sẽ xuất hiện khi App đồng bộ.</span><button class="btn btn-primary flex-shrink-0" type="submit" @disabled($selected->profile->status !== 'connected')><i class="bi bi-send me-2" aria-hidden="true"></i>Gửi tin</button></div>
            @if($selected->profile->status !== 'connected')<p class="small text-warning mt-2 mb-0">Kết nối lại tài khoản Zalo để gửi tin.</p>@endif
        </form>
        <form method="post" class="mt-3" action="{{ route('workspace.action', ['section' => 'inbox', 'action' => 'read-conversation']) }}">
            @csrf
            <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            @foreach($selection as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <button class="btn btn-sm btn-outline-secondary" type="submit">Đánh dấu đã đọc trên web</button>
        </form>
    </div>
</div></div>
@endif
