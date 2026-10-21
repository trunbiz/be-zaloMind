<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_login_page_is_available_in_vietnamese(): void
    {
        $this->get('/login')->assertOk()->assertSee('Chào mừng trở lại')->assertSee('lang="vi"', false);
    }
}
