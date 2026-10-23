<?php

return [
    'user' => [
        ['key' => 'dashboard', 'label' => 'Tổng quan', 'icon' => 'grid-1x2', 'route' => 'dashboard'],
        ['key' => 'profiles', 'label' => 'Tài khoản Zalo', 'icon' => 'person-badge', 'illustration' => 'empty-profiles'],
        ['key' => 'messages', 'label' => 'Nhắn tin', 'icon' => 'chat-left-text', 'children' => [
            ['key' => 'message-friends', 'label' => 'Nhắn bạn bè', 'icon' => 'chat-left-text', 'illustration' => 'empty-batches'],
            ['key' => 'message-groups', 'label' => 'Nhắn nhóm', 'icon' => 'chat-left-text', 'illustration' => 'empty-batches'],
            ['key' => 'message-lists', 'label' => 'Theo tệp', 'icon' => 'chat-left-text', 'illustration' => 'empty-batches'],
            ['key' => 'message-phones', 'label' => 'Theo số điện thoại', 'icon' => 'chat-left-text', 'illustration' => 'empty-batches'],
            ['key' => 'message-labels', 'label' => 'Theo nhãn', 'icon' => 'chat-left-text', 'illustration' => 'empty-batches'],
            ['key' => 'message-birthdays', 'label' => 'Sinh nhật', 'icon' => 'chat-left-text', 'illustration' => 'empty-batches'],
        ]],
        ['key' => 'automation', 'label' => 'Chăm sóc tự động', 'icon' => 'lightning-charge', 'children' => [
            ['key' => 'automation-labels', 'label' => 'Automation theo nhãn', 'icon' => 'lightning-charge', 'illustration' => 'empty-batches'],
            ['key' => 'drip', 'label' => 'Kịch bản nhiều bước', 'icon' => 'lightning-charge', 'illustration' => 'empty-batches'],
            ['key' => 'rotation', 'label' => 'Chiến dịch xoay vòng', 'icon' => 'lightning-charge', 'illustration' => 'empty-batches'],
        ]],
        ['key' => 'inbox', 'label' => 'Hộp thư', 'icon' => 'inbox', 'illustration' => 'empty-inbox'],
        ['key' => 'friends', 'label' => 'Bạn bè', 'icon' => 'people', 'children' => [
            ['key' => 'friends', 'label' => 'Quản lý bạn bè', 'icon' => 'people', 'illustration' => 'empty-search'],
            ['key' => 'friend-invites', 'label' => 'Kết bạn theo tệp', 'icon' => 'people', 'illustration' => 'empty-search'],
            ['key' => 'friend-requests', 'label' => 'Lời mời kết bạn', 'icon' => 'people', 'illustration' => 'empty-search'],
        ]],
        ['key' => 'customers', 'label' => 'Khách hàng', 'icon' => 'person-lines-fill', 'children' => [
            ['key' => 'contact-lists', 'label' => 'Tệp khách hàng', 'icon' => 'person-lines-fill', 'illustration' => 'empty-search'],
            ['key' => 'scan-members', 'label' => 'Quét thành viên nhóm', 'icon' => 'person-lines-fill', 'illustration' => 'empty-search'],
            ['key' => 'scan-links', 'label' => 'Quét bằng link nhóm', 'icon' => 'person-lines-fill', 'illustration' => 'empty-search'],
            ['key' => 'opt-outs', 'label' => 'Từ chối nhận tin', 'icon' => 'person-lines-fill', 'illustration' => 'empty-search'],
        ]],
        ['key' => 'groups', 'label' => 'Nhóm', 'icon' => 'collection', 'children' => [
            ['key' => 'group-invite', 'label' => 'Mời vào nhóm', 'icon' => 'collection', 'illustration' => 'empty-search'],
            ['key' => 'group-join', 'label' => 'Tham gia bằng link', 'icon' => 'collection', 'illustration' => 'empty-search'],
            ['key' => 'group-check', 'label' => 'Kiểm tra link nhóm', 'icon' => 'collection', 'illustration' => 'empty-search'],
            ['key' => 'group-leave', 'label' => 'Rời nhóm hàng loạt', 'icon' => 'collection', 'illustration' => 'empty-search'],
        ]],
        ['key' => 'reports', 'label' => 'Báo cáo', 'icon' => 'bar-chart', 'illustration' => 'empty-batches'],
        ['key' => 'billing', 'label' => 'Gói & thanh toán', 'icon' => 'credit-card'],
        ['key' => 'affiliate', 'label' => 'Affiliate', 'icon' => 'gift'],
    ],
    'admin' => [
        ['key' => 'dashboard', 'label' => 'Bảng điều khiển', 'icon' => 'grid-1x2', 'route' => 'admin.dashboard'],
        ['key' => 'users', 'label' => 'Người dùng', 'icon' => 'people', 'roles' => ['admin']],
        ['key' => 'plans', 'label' => 'Gói dịch vụ', 'icon' => 'boxes', 'roles' => ['admin']],
        ['key' => 'payments', 'label' => 'Thanh toán & đơn hàng', 'icon' => 'receipt', 'roles' => ['admin']],
        ['key' => 'affiliate', 'label' => 'Affiliate', 'icon' => 'gift', 'roles' => ['admin']],
        ['key' => 'nodes', 'label' => 'Node (App A)', 'icon' => 'hdd-network', 'roles' => ['admin']],
        ['key' => 'proxies', 'label' => 'Proxy', 'icon' => 'shield-lock', 'roles' => ['admin']],
        ['key' => 'profiles', 'label' => 'Giám sát profile', 'icon' => 'person-badge', 'roles' => ['admin']],
        ['key' => 'queue', 'label' => 'Hàng đợi & job', 'icon' => 'stack', 'roles' => ['admin']],
        ['key' => 'settings', 'label' => 'Cấu hình hệ thống', 'icon' => 'sliders', 'roles' => ['admin']],
        ['key' => 'announcements', 'label' => 'Thông báo hệ thống', 'icon' => 'megaphone', 'roles' => ['admin']],
        ['key' => 'moderation', 'label' => 'Kiểm duyệt nội dung', 'icon' => 'shield-check', 'roles' => ['admin']],
        ['key' => 'audit', 'label' => 'Nhật ký kiểm toán', 'icon' => 'journal-text', 'roles' => ['admin']],
    ],
];
