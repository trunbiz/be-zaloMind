<?php

return [
    'required' => 'Vui lòng nhập :attribute.',
    'string' => ':Attribute phải là chuỗi ký tự.',
    'email' => ':Attribute không đúng định dạng.',
    'lowercase' => ':Attribute phải viết thường.',
    'unique' => ':Attribute đã được sử dụng.',
    'confirmed' => 'Xác nhận :attribute không khớp.',
    'current_password' => 'Mật khẩu hiện tại không chính xác.',
    'min' => ['string' => ':Attribute phải có ít nhất :min ký tự.'],
    'max' => ['string' => ':Attribute không được vượt quá :max ký tự.'],
    'attributes' => [
        'name' => 'họ và tên', 'email' => 'địa chỉ email',
        'password' => 'mật khẩu', 'current_password' => 'mật khẩu hiện tại',
        'password_confirmation' => 'xác nhận mật khẩu', 'token' => 'mã xác nhận',
    ],
];
