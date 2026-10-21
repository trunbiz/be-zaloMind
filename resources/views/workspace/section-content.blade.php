<x-card>
    <div class="d-flex justify-content-between align-items-center gap-3 mb-2"><span class="section-eyebrow">{{ $isAdmin ? 'QUẢN TRỊ HỆ THỐNG' : 'KHÔNG GIAN LÀM VIỆC' }}</span><span class="badge text-bg-light">Giai đoạn {{ $page['phase'] }}</span></div>
    <x-empty-state :title="$page['label'].' đang được chuẩn bị'" :description="'Giao diện điều hướng đã sẵn sàng. Chức năng này sẽ được triển khai ở giai đoạn '.$page['phase'].' theo lộ trình dự án.'" :illustration="$page['illustration'] ?? 'empty-search'" action="Về tổng quan" :href="route($isAdmin ? 'admin.dashboard' : 'dashboard')" />
</x-card>

