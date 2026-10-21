<?php

return [
    'user' => [
        ['key' => 'dashboard', 'label' => 'Tổng quan', 'icon' => 'grid-1x2', 'route' => 'dashboard'],
        ['key' => 'profiles', 'label' => 'Tài khoản Zalo', 'icon' => 'person-badge', 'phase' => 4, 'illustration' => 'empty-profiles'],
        ['key' => 'messages', 'label' => 'Nhắn tin', 'icon' => 'chat-left-text', 'children' => [
            ['key' => 'message-friends', 'label' => 'Nhắn bạn bè', 'icon' => 'chat-left-text', 'phase' => 6, 'illustration' => 'empty-batches'],
            ['key' => 'message-groups', 'label' => 'Nhắn nhóm', 'icon' => 'chat-left-text', 'phase' => 6, 'illustration' => 'empty-batches'],
            ['key' => 'message-lists', 'label' => 'Theo tệp', 'icon' => 'chat-left-text', 'phase' => 6, 'illustration' => 'empty-batches'],
            ['key' => 'message-phones', 'label' => 'Theo số điện thoại', 'icon' => 'chat-left-text', 'phase' => 6, 'illustration' => 'empty-batches'],
            ['key' => 'message-labels', 'label' => 'Theo nhãn', 'icon' => 'chat-left-text', 'phase' => 6, 'illustration' => 'empty-batches'],
            ['key' => 'message-birthdays', 'label' => 'Sinh nhật', 'icon' => 'chat-left-text', 'phase' => 6, 'illustration' => 'empty-batches'],
        ]],
        ['key' => 'automation', 'label' => 'Chăm sóc tự động', 'icon' => 'lightning-charge', 'children' => [
            ['key' => 'automation-labels', 'label' => 'Automation theo nhãn', 'icon' => 'lightning-charge', 'phase' => 8, 'illustration' => 'empty-batches'],
            ['key' => 'drip', 'label' => 'Kịch bản nhiều bước', 'icon' => 'lightning-charge', 'phase' => 8, 'illustration' => 'empty-batches'],
            ['key' => 'rotation', 'label' => 'Chiến dịch xoay vòng', 'icon' => 'lightning-charge', 'phase' => 8, 'illustration' => 'empty-batches'],
        ]],
        ['key' => 'inbox', 'label' => 'Hộp thư', 'icon' => 'inbox', 'phase' => 9, 'illustration' => 'empty-inbox'],
        ['key' => 'friends', 'label' => 'Bạn bè', 'icon' => 'people', 'children' => [
            ['key' => 'friends', 'label' => 'Quản lý bạn bè', 'icon' => 'people', 'phase' => 5, 'illustration' => 'empty-search'],
            ['key' => 'friend-invites', 'label' => 'Kết bạn theo tệp', 'icon' => 'people', 'phase' => 5, 'illustration' => 'empty-search'],
            ['key' => 'friend-requests', 'label' => 'Lời mời kết bạn', 'icon' => 'people', 'phase' => 5, 'illustration' => 'empty-search'],
        ]],
        ['key' => 'customers', 'label' => 'Khách hàng', 'icon' => 'person-lines-fill', 'children' => [
            ['key' => 'contact-lists', 'label' => 'Tệp khách hàng', 'icon' => 'person-lines-fill', 'phase' => 5, 'illustration' => 'empty-search'],
            ['key' => 'scan-members', 'label' => 'Quét thành viên nhóm', 'icon' => 'person-lines-fill', 'phase' => 5, 'illustration' => 'empty-search'],
            ['key' => 'scan-links', 'label' => 'Quét bằng link nhóm', 'icon' => 'person-lines-fill', 'phase' => 5, 'illustration' => 'empty-search'],
            ['key' => 'opt-outs', 'label' => 'Từ chối nhận tin', 'icon' => 'person-lines-fill', 'phase' => 5, 'illustration' => 'empty-search'],
        ]],
        ['key' => 'groups', 'label' => 'Nhóm', 'icon' => 'collection', 'children' => [
            ['key' => 'group-invite', 'label' => 'Mời vào nhóm', 'icon' => 'collection', 'phase' => 5, 'illustration' => 'empty-search'],
            ['key' => 'group-join', 'label' => 'Tham gia bằng link', 'icon' => 'collection', 'phase' => 5, 'illustration' => 'empty-search'],
            ['key' => 'group-check', 'label' => 'Kiểm tra link nhóm', 'icon' => 'collection', 'phase' => 5, 'illustration' => 'empty-search'],
            ['key' => 'group-leave', 'label' => 'Rời nhóm hàng loạt', 'icon' => 'collection', 'phase' => 5, 'illustration' => 'empty-search'],
        ]],
        ['key' => 'reports', 'label' => 'Báo cáo', 'icon' => 'bar-chart', 'phase' => 7, 'illustration' => 'empty-batches'],
        ['key' => 'billing', 'label' => 'Gói & thanh toán', 'icon' => 'credit-card', 'phase' => 10],
        ['key' => 'affiliate', 'label' => 'Affiliate', 'icon' => 'gift', 'phase' => 10],
    ],
    'admin' => [
        ['key' => 'dashboard', 'label' => 'Bảng điều khiển', 'icon' => 'grid-1x2', 'route' => 'admin.dashboard'],
        ['key' => 'users', 'label' => 'Người dùng', 'icon' => 'people', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'plans', 'label' => 'Gói dịch vụ', 'icon' => 'boxes', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'payments', 'label' => 'Thanh toán & đơn hàng', 'icon' => 'receipt', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'affiliate', 'label' => 'Affiliate', 'icon' => 'gift', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'nodes', 'label' => 'Node (App A)', 'icon' => 'hdd-network', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'proxies', 'label' => 'Proxy', 'icon' => 'shield-lock', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'profiles', 'label' => 'Giám sát profile', 'icon' => 'person-badge', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'queue', 'label' => 'Hàng đợi & job', 'icon' => 'stack', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'settings', 'label' => 'Cấu hình hệ thống', 'icon' => 'sliders', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'announcements', 'label' => 'Thông báo hệ thống', 'icon' => 'megaphone', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'moderation', 'label' => 'Kiểm duyệt nội dung', 'icon' => 'shield-check', 'roles' => ['admin'], 'phase' => 9],
        ['key' => 'audit', 'label' => 'Nhật ký kiểm toán', 'icon' => 'journal-text', 'roles' => ['admin'], 'phase' => 9],
    ],
];
