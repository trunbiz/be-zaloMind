@if($isAdmin)
<x-admin-layout title="Bảng điều khiển">@include('workspace.home-content')</x-admin-layout>
@else
<x-app-layout title="Tổng quan">@include('workspace.home-content')</x-app-layout>
@endif
