<div class="modal fade command-modal" id="command-palette" tabindex="-1" aria-labelledby="command-palette-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <h2 id="command-palette-title" class="visually-hidden">Tìm trang trong zaloMind</h2>
        <div class="command-input-wrap"><i class="bi bi-search text-primary" aria-hidden="true"></i><label for="command-search" class="visually-hidden">Tên trang cần tìm</label><input id="command-search" type="search" class="form-control" placeholder="Bạn muốn đi đến đâu?" autocomplete="off" data-command-search><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Đóng tìm kiếm"></button></div>
        <div class="command-results" data-command-results>
            @foreach($commands as $command)
            <a href="{{ $command['url'] }}" class="command-result" data-command-item><span class="command-result-icon"><i class="bi bi-{{ $command['icon'] }}" aria-hidden="true"></i></span><span><strong>{{ $command['label'] }}</strong><small>{{ $command['group'] }}</small></span><i class="bi bi-arrow-return-left ms-auto text-body-tertiary" aria-hidden="true"></i></a>
            @endforeach
            <p class="p-4 text-center text-body-secondary mb-0" data-command-empty hidden>Không tìm thấy trang phù hợp.</p>
        </div>
        <div class="command-footer"><span><kbd>↑</kbd> <kbd>↓</kbd> di chuyển · <kbd>Enter</kbd> mở trang</span><span><kbd>Esc</kbd> đóng</span></div>
    </div></div>
</div>

