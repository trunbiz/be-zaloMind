@php
    $formSection = $form['section'] ?? $page['key'];
    $formKey = $formSection.':'.$form['action'].':'.($form['id'] ?? 'new');
    $useOld = old('_form') === $formKey;
@endphp
<form method="post" enctype="multipart/form-data" action="{{ route($isAdmin ? 'admin.action' : 'workspace.action', ['section' => $formSection, 'action' => $form['action']]) }}" @if(isset($form['confirm'])) data-confirm="{{ $form['confirm'] }}" @elseif(in_array($form['style'] ?? '', ['danger','outline-danger'])) data-confirm="Bạn muốn {{ mb_strtolower($form['label']) }}? Các thao tác đã được Zalo thực hiện sẽ không được thu hồi." @endif>
    @csrf
    <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    <input type="hidden" name="_form" value="{{ $formKey }}">
    @if(isset($form['id']))<input type="hidden" name="id" value="{{ $form['id'] }}">@endif
    @if(isset($form['version']))<input type="hidden" name="expected_version" value="{{ $form['version'] }}">@endif
    <div class="row g-3">
    @foreach($form['fields'] as $field)
        @php
            $fieldId = 'f-'.substr(hash('sha256', $formKey.':'.$field['name'].':'.$loop->index), 0, 16);
            $value = $field['type'] === 'password' ? '' : ($useOld ? old($field['name'], $field['value']) : $field['value']);
        @endphp
        @if($field['type'] === 'hidden')<input type="hidden" name="{{ $field['name'] }}" value="{{ $value }}">@continue @endif
        <div class="{{ !$inline && !in_array($field['type'], ['textarea','file','recipients']) ? 'col-md-6' : 'col-12' }}">
            <label class="form-label" for="{{ $fieldId }}">{{ $field['label'] }}@if($field['required'])<span class="text-danger ms-1" aria-label="bắt buộc">*</span>@endif</label>
            @if($field['type'] === 'recipients')
            <div data-recipient-picker>
                <input type="hidden" name="recipient_selection" value="selected">
                <input id="{{ $fieldId }}" type="search" class="form-control mb-2" placeholder="Tìm theo tên…" aria-label="Tìm người nhận" data-recipient-search>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-recipient-select>Chọn tất cả kết quả</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-recipient-clear>Bỏ chọn</button>
                    <span class="small text-muted" data-recipient-count aria-live="polite">Đã chọn 0</span>
                </div>
                <div class="border rounded p-2 overflow-auto" style="max-height: 320px">
                    @foreach($field['options'] as $recipient)
                    <label class="d-flex align-items-center gap-2 p-2" data-recipient-row data-profile="{{ $recipient['profile_id'] }}">
                        <input class="form-check-input m-0 flex-shrink-0" type="checkbox" name="selected_recipients[]" value="{{ $recipient['id'] }}" @checked(in_array($recipient['id'], (array) $value, true))>
                        <span>{{ $recipient['name'] }}</span>
                    </label>
                    @endforeach
                    <p class="text-muted small m-2" data-recipient-empty hidden>Không có kết quả. Hãy thử tên khác hoặc đồng bộ danh sách của tài khoản Zalo đã chọn.</p>
                </div>
            </div>
            @elseif($field['type'] === 'textarea')
            <textarea id="{{ $fieldId }}" name="{{ $field['name'] }}" class="form-control" rows="4" @required($field['required'])>{{ $value }}</textarea>
            @elseif(in_array($field['type'], ['select','multiselect']))
            <select id="{{ $fieldId }}" name="{{ $field['name'] }}{{ $field['type'] === 'multiselect' ? '[]' : '' }}" class="form-select" @required($field['required']) @if($field['type'] === 'multiselect') multiple size="4" @endif>
                @if($field['type'] !== 'multiselect' && !$field['required'])<option value="">Không chọn</option>@endif
                @foreach($field['options'] as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected($field['type'] === 'multiselect' ? in_array((string) $optionValue, array_map('strval', (array) $value), true) : (string) $value === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach
            </select>
            @else
            <input id="{{ $fieldId }}" name="{{ $field['name'] }}" class="form-control" type="{{ $field['type'] }}" @if($field['type'] !== 'file') value="{{ $value }}" @else accept=".csv,text/csv" @endif @if($field['type'] === 'number') min="0" step="1" @endif @if($field['type'] === 'password') autocomplete="new-password" @endif @required($field['required'])>
            @endif
            @if($field['help'])<div class="form-text">{{ $field['help'] }}</div>@endif
        </div>
    @endforeach
    <div class="col-12"><button class="btn btn-{{ $form['style'] ?? 'primary' }}" type="submit">{{ $inline && !($form['direct'] ?? false) ? 'Xác nhận' : $form['label'] }}</button></div>
    </div>
</form>
