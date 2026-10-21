@props(['id', 'columns' => [], 'rows' => [], 'caption' => 'Danh sách dữ liệu', 'emptyTitle' => 'Chưa có dữ liệu', 'emptyDescription' => 'Dữ liệu sẽ xuất hiện tại đây khi được cập nhật.', 'pageSize' => 10])
<section id="{{ $id }}" class="data-table" data-table data-page-size="{{ max(1, (int) $pageSize) }}" {{ $attributes }}>
    @if(count($rows))
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <label class="table-search"><i class="bi bi-search text-body-secondary" aria-hidden="true"></i><span class="visually-hidden">Tìm trong {{ $caption }}</span><input class="form-control form-control-sm" type="search" placeholder="Tìm trong danh sách…" data-table-search></label>
        <span class="small text-body-secondary" data-table-summary aria-live="polite"></span>
    </div>
    <div class="table-responsive"><table class="table align-middle">
        <caption class="visually-hidden">{{ $caption }}</caption>
        <thead><tr>@foreach($columns as $key => $column)<th scope="col" aria-sort="none"><button type="button" class="table-sort" data-table-sort="{{ $loop->index }}">{{ $column }}<i class="bi bi-arrow-down-up ms-2" aria-hidden="true"></i></button></th>@endforeach</tr></thead>
        <tbody>@foreach($rows as $row)<tr>@foreach($columns as $key => $column)<td>{{ data_get($row, $key, '—') }}</td>@endforeach</tr>@endforeach</tbody>
    </table></div>
    <p class="text-center text-body-secondary py-4" data-table-no-results hidden>Không tìm thấy kết quả phù hợp.</p>
    <nav class="d-flex justify-content-end gap-2" aria-label="Phân trang {{ $caption }}"><button type="button" class="btn btn-outline-secondary btn-sm" data-table-prev>Trước</button><button type="button" class="btn btn-outline-secondary btn-sm" data-table-next>Sau</button></nav>
    @else
    <x-empty-state :title="$emptyTitle" :description="$emptyDescription" />
    @endif
</section>

