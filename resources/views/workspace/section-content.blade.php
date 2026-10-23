@if($errors->any())
<div class="alert alert-danger" role="alert"><strong>Chưa thể thực hiện thao tác.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if($screen['intro'])<p class="text-body-secondary mb-4">{{ $screen['intro'] }}</p>@endif
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <span class="badge {{ $screen['agent_online'] ? 'text-bg-success' : 'text-bg-secondary' }} p-2"><i class="bi bi-circle-fill me-2" aria-hidden="true"></i>{{ $screen['agent_online'] ? 'Agent đang kết nối' : 'Agent chưa kết nối — tác vụ sẽ chờ trong hàng đợi' }}</span>
    <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithoutQuery(['fragment']) }}">Cập nhật dữ liệu</a>@if(isset($screen['export_url']))<a class="btn btn-sm btn-outline-primary" href="{{ $screen['export_url'] }}">Tải báo cáo CSV</a>@endif</div>
</div>
@foreach($screen['notes'] as $note)<div class="alert alert-info">{{ $note }}</div>@endforeach
@if(isset($screen['inbox']))@include('workspace.inbox', ['inbox' => $screen['inbox']])@endif
@if(isset($screen['referral_url']))<x-card title="Liên kết giới thiệu của bạn" class="mb-4"><label for="referral-link" class="form-label">Chia sẻ liên kết này để ghi nhận người được giới thiệu</label><input id="referral-link" class="form-control" readonly value="{{ $screen['referral_url'] }}" onclick="this.select()"></x-card>@endif
@if(count($screen['stats']))
<div class="row g-3 mb-4">@foreach($screen['stats'] as $label => $value)<div class="col-sm-6 col-xl-3"><x-stat-card :label="$label" :value="$value" icon="bar-chart" /></div>@endforeach</div>
@endif
@if(count($screen['forms']))
<div class="row g-4 mb-4">@foreach($screen['forms'] as $form)<div class="{{ count($screen['forms']) > 1 ? 'col-xl-6' : 'col-12' }}"><x-card :title="$form['label']">@include('workspace.form', ['form' => $form, 'inline' => false])</x-card></div>@endforeach</div>
@endif
@if($page['key'] === 'profiles')
<div class="row g-4 mb-4" data-platform-poll="{{ route($isAdmin ? 'admin.live' : 'workspace.live') }}">
    @forelse($screen['profiles'] as $card)
    <div class="col-md-6 col-xl-4" data-live-profile="{{ $card['profile']->id }}"><x-card :title="$card['profile']->display_name">
        <p><span class="badge text-bg-light" data-profile-status>{{ \App\Services\WorkspaceScreens::status($card['profile']->status) }}</span></p>
        <p class="small text-body-secondary text-break">{{ $card['profile']->zalo_user_id ? 'Zalo ID: '.$card['profile']->zalo_user_id : 'Chưa đăng nhập Zalo' }}</p>
        <div class="text-center mb-3"><img data-profile-qr alt="Mã QR đăng nhập Zalo" width="240" height="240" class="img-fluid bg-white p-2 rounded border" @if($card['qr']) src="data:image/png;base64,{{ $card['qr']['image_base64'] }}" @else hidden @endif><p class="small text-body-secondary mt-2" data-profile-qr-hint>{{ $card['hint'] }}</p></div>
        @if($card['unknown'])<div class="alert alert-warning small">Có {{ $card['unknown'] }} tác vụ chưa xác nhận được kết quả. Hệ thống không gửi lại để tránh trùng. Bạn vẫn có thể ngắt kết nối hoặc xóa tài khoản.</div>@endif
        <div class="d-flex flex-wrap gap-2">@foreach($card['actions'] as $form)@if($form['direct'] ?? false)@include('workspace.form', ['form' => $form, 'inline' => true])@else<details class="w-100"><summary class="btn btn-sm btn-{{ $form['style'] }} w-100">{{ $form['label'] }}</summary><div class="border rounded p-3 mt-2">@include('workspace.form', ['form' => $form, 'inline' => true])</div></details>@endif @endforeach</div>
    </x-card></div>
    @empty
    <div class="col-12"><x-empty-state title="Chưa có tài khoản Zalo" description="Thêm tài khoản ở biểu mẫu phía trên để bắt đầu đăng nhập bằng QR." illustration="empty-profiles" /></div>
    @endforelse
</div>
@endif
@if(in_array($page['key'], ['users','contact-lists','reports','queue','billing','payments']))
<form method="get" class="d-flex flex-wrap gap-2 mb-4" role="search">
    @if(in_array($page['key'], ['users','contact-lists']))<label class="visually-hidden" for="workspace-search">Tìm kiếm</label><input id="workspace-search" class="form-control" style="max-width:320px" name="q" value="{{ request('q') }}" placeholder="Tìm theo tên hoặc email">@endif
    @if(in_array($page['key'], ['reports','queue','billing','payments']))<select name="status" class="form-select" style="max-width:240px" aria-label="Lọc trạng thái"><option value="">Tất cả trạng thái</option>@foreach(\App\Services\WorkspaceScreens::STATUS as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select>@endif
    <button class="btn btn-outline-primary" type="submit">Lọc</button>
</form>
@endif
@foreach($screen['tables'] as $table)
<x-card :title="$table['title']" class="mb-4">
    @if(count($table['rows']))
    <div class="table-responsive"><table class="table align-middle"><thead><tr>@foreach($table['headers'] as $header)<th scope="col">{{ $header }}</th>@endforeach<th scope="col">Thao tác</th></tr></thead><tbody>
    @foreach($table['rows'] as $row)
    <tr>@foreach($row['cells'] as $cell)<td class="text-break" style="min-width:100px;max-width:360px;white-space:pre-wrap">{{ $cell }}</td>@endforeach<td style="min-width:180px;max-width:360px">
        @foreach($row['actions'] ?? [] as $form)<details class="mb-2"><summary class="btn btn-sm btn-{{ $form['style'] ?? 'primary' }}">{{ $form['label'] }}</summary><div class="p-3 border rounded mt-2" style="min-width:240px">@include('workspace.form', ['form' => $form, 'inline' => true])</div></details>@endforeach
        @if(!empty($row['detail']))<details><summary class="small text-primary">Xem kết quả</summary><pre class="small p-2 bg-body-tertiary rounded mt-2" style="max-width:360px;max-height:320px;white-space:pre-wrap">{{ $row['detail'] }}</pre></details>@endif
        @if(empty($row['actions']) && empty($row['detail']))<span class="text-body-secondary">—</span>@endif
    </td></tr>
    @endforeach
    </tbody></table></div>
    @if(isset($table['pagination']))<div class="mt-3">{{ $table['pagination']->withQueryString()->links() }}</div>@endif
    @else
    <p class="text-body-secondary py-4 text-center mb-0">Chưa có dữ liệu. Dữ liệu sẽ xuất hiện sau khi bạn tạo mới hoặc agent đồng bộ.</p>
    @endif
</x-card>
@endforeach
