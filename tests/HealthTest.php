<?php

use App\App;
use PHPUnit\Framework\TestCase;

class HealthTest extends TestCase
{
    public function testHealthEndpointReturnsJson(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/health';

        ob_start();
        (new App())->handle();
        $output = ob_get_clean();

        $this->assertNotEmpty($output);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('details', $decoded);
    }
}

