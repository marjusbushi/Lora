<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReleaseHealthTest extends TestCase
{
    private bool $releaseFileExisted;

    private string $releaseFileOriginalContents = '';

    private string $releasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releasePath = storage_path('framework/release');
        $this->releaseFileExisted = is_file($this->releasePath);

        if ($this->releaseFileExisted) {
            $this->releaseFileOriginalContents = (string) file_get_contents($this->releasePath);
        }
    }

    protected function tearDown(): void
    {
        if ($this->releaseFileExisted) {
            file_put_contents($this->releasePath, $this->releaseFileOriginalContents);
        } elseif (is_file($this->releasePath)) {
            unlink($this->releasePath);
        }

        parent::tearDown();
    }

    public function test_release_health_returns_the_exact_deployed_commit_without_caching(): void
    {
        $release = '065e2f6a51550330f079bd09775c49d116b5f307';
        file_put_contents($this->releasePath, $release."\n");

        $response = $this->get('/up/release');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText($release);
        $this->assertSame($release, $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_release_health_fails_closed_when_the_release_file_is_missing(): void
    {
        if (is_file($this->releasePath)) {
            unlink($this->releasePath);
        }

        $response = $this->get('/up/release');

        $response->assertServiceUnavailable()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_release_health_fails_closed_for_an_invalid_release_identifier(): void
    {
        file_put_contents($this->releasePath, "not-a-commit\n");

        $response = $this->get('/up/release');

        $response->assertServiceUnavailable()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
