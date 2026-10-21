@if($isAdmin)
<x-admin-layout :title="$page['label']">
    @include('workspace.section-content')
</x-admin-layout>
@else
<x-app-layout :title="$page['label']">
    @include('workspace.section-content')
</x-app-layout>
@endif

