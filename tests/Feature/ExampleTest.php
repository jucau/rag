<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExampleTest extends TestCase
{
    #[Test]
    public function the_home_page_renders_the_chat(): void
    {
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('data-page', escape: false);
        $response->assertSee('Chat');
    }
}
