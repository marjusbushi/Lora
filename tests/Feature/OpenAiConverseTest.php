<?php

namespace Tests\Feature;

use App\Services\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Kontrata e telit të shoferit OpenAI (task #408) — mësimet e MISTAKE #145
 * (thoughtSignature i Gemini-t) të zbatuara që në ditën e parë: radha e
 * asistentit kthehet VERBATIM, çdo tool_call merr tool_call_id-në e vet,
 * finalja pranohet vetëm e vetme. Testet fake ngurtësojnë formën e telit;
 * testet real-api provojnë kundër OpenAI të vërtetë (OPENAI_REAL_API=1).
 */
class OpenAiConverseTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLS = [
        [
            'name' => 'check_availability',
            'description' => 'Kontrollon dhomat e lira dhe çmimet.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'check_in' => ['type' => 'string'],
                    'check_out' => ['type' => 'string'],
                    'adults' => ['type' => 'integer'],
                ],
                'required' => ['check_in', 'check_out'],
            ],
        ],
        [
            'name' => 'get_thread_reservation',
            'description' => 'Kthen rezervimin e lidhur me bisedën.',
            'input_schema' => ['type' => 'object'],
        ],
        [
            'name' => 'guest_reply',
            'description' => 'Përgjigja e strukturuar.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'confident' => ['type' => 'boolean'],
                    'reply' => ['type' => 'string'],
                    'kind' => ['type' => 'string', 'enum' => ['small_talk', 'informative', 'clarifying']],
                ],
                'required' => ['confident', 'reply', 'kind'],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openai.key', 'secret-test-key');
        config()->set('services.openai.model', 'gpt-test-luna');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    }

    private function toolCallsResponse(array $toolCalls, array $extraMessageFields = [], array $usage = ['prompt_tokens' => 100, 'completion_tokens' => 20, 'completion_tokens_details' => ['reasoning_tokens' => 5]]): array
    {
        return [
            'choices' => [[
                'message' => array_merge([
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => $toolCalls,
                ], $extraMessageFields),
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => $usage,
        ];
    }

    private function finalReplyResponse(): array
    {
        return $this->toolCallsResponse([[
            'id' => 'call_final',
            'type' => 'function',
            'function' => ['name' => 'guest_reply', 'arguments' => json_encode(['confident' => true, 'reply' => 'Totali 190 EUR.', 'kind' => 'informative'])],
        ]], [], ['prompt_tokens' => 150, 'completion_tokens' => 30, 'completion_tokens_details' => ['reasoning_tokens' => 8]]);
    }

    public function test_tool_round_echoes_the_assistant_message_verbatim_with_tool_call_ids(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push($this->toolCallsResponse([[
                'id' => 'call_777',
                'type' => 'function',
                'function' => ['name' => 'check_availability', 'arguments' => json_encode(['check_in' => '2026-08-28', 'check_out' => '2026-08-30', 'adults' => 2])],
            ]], ['proprietary_field' => 'OPAQUE-STATE']))
            ->push($this->finalReplyResponse());

        $quote = ['currency' => 'EUR', 'nights' => 2, 'stay_total' => 190.5];
        $result = app(OpenAiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: 28-30 gusht, 2 persona',
            self::TOOLS,
            ['check_availability' => fn (array $args): array => $quote],
            'guest_reply',
        );

        $this->assertSame(['check_availability'], $result['toolsUsed']);
        $this->assertSame('Totali 190 EUR.', $result['args']['reply']);

        $second = json_decode((string) collect(Http::recorded())->last()[0]->body(), true);
        // Radha e asistentit kthehet VERBATIM — me çdo fushë që dërgoi modeli
        // (parimi i thoughtSignature: historiku i rindërtuar me dorë = minë).
        $this->assertSame('OPAQUE-STATE', $second['messages'][2]['proprietary_field']);
        $this->assertSame('call_777', $second['messages'][2]['tool_calls'][0]['id']);
        // Rezultati i mjetit lidhet me tool_call_id-në e vet.
        $this->assertSame('tool', $second['messages'][3]['role']);
        $this->assertSame('call_777', $second['messages'][3]['tool_call_id']);
        $this->assertSame($quote, json_decode($second['messages'][3]['content'], true));
        // Raundi i lirë DETYRON një mjet (cilindo); reasoning 'none' — i
        // detyruar nga API-ja reale (mjete + effort ≠ none → 400).
        $this->assertSame('required', $second['tool_choice']);
        $this->assertSame('none', $second['reasoning_effort']);
    }

    public function test_premature_final_in_a_mixed_turn_is_rejected_and_tools_still_run(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push($this->toolCallsResponse([
                ['id' => 'call_early', 'type' => 'function', 'function' => ['name' => 'guest_reply', 'arguments' => json_encode(['confident' => true, 'reply' => 'Çmimi 999 EUR.', 'kind' => 'informative'])]],
                ['id' => 'call_tool', 'type' => 'function', 'function' => ['name' => 'check_availability', 'arguments' => json_encode(['check_in' => '2026-08-28', 'check_out' => '2026-08-30'])]],
            ]))
            ->push($this->finalReplyResponse());

        $executed = false;
        $result = app(OpenAiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: 28-30 gusht',
            self::TOOLS,
            ['check_availability' => function (array $args) use (&$executed): array {
                $executed = true;

                return ['stay_total' => 190.0];
            }],
            'guest_reply',
        );

        $this->assertTrue($executed);
        $this->assertSame('Totali 190 EUR.', $result['args']['reply']);

        // Finalja e parakohshme mori REFUZIM si rezultat mjeti — jo pranim.
        $second = json_decode((string) collect(Http::recorded())->last()[0]->body(), true);
        $earlyResult = collect($second['messages'])->firstWhere('tool_call_id', 'call_early');
        $this->assertStringContainsString('thirrje të vetme', $earlyResult['content']);
    }

    public function test_zero_argument_tool_call_and_empty_result_survive_the_round_trip(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push($this->toolCallsResponse([[
                'id' => 'call_zero',
                'type' => 'function',
                'function' => ['name' => 'get_thread_reservation', 'arguments' => '{}'],
            ]]))
            ->push($this->finalReplyResponse());

        $received = null;
        app(OpenAiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: sa kam për të paguar?',
            self::TOOLS,
            ['get_thread_reservation' => function (array $args) use (&$received): array {
                $received = $args;

                return [];
            }],
            'guest_reply',
        );

        $this->assertSame([], $received);
        // Rezultati bosh serializohet si objekt JSON — jo si listë bosh.
        $second = json_decode((string) collect(Http::recorded())->last()[0]->body(), true);
        $this->assertSame('{}', collect($second['messages'])->firstWhere('tool_call_id', 'call_zero')['content']);
    }

    public function test_unreadable_tool_arguments_fail_loud(): void
    {
        Http::fakeSequence('api.openai.com/*')->push($this->toolCallsResponse([[
            'id' => 'call_bad',
            'type' => 'function',
            'function' => ['name' => 'check_availability', 'arguments' => '{jo-json'],
        ]]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('argumente të palexueshme');

        app(OpenAiClient::class)->converse('SYSTEM', 'MYSAFIRI: datat', self::TOOLS, [], 'guest_reply');
    }

    public function test_usage_is_summed_across_rounds_with_provider_identity(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push($this->toolCallsResponse([[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'check_availability', 'arguments' => json_encode(['check_in' => '2026-08-28', 'check_out' => '2026-08-30'])],
            ]]))
            ->push($this->finalReplyResponse());

        $result = app(OpenAiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: 28-30 gusht',
            self::TOOLS,
            ['check_availability' => fn (array $args): array => ['stay_total' => 190]],
            'guest_reply',
        );

        // completion_tokens i PËRFSHIN arsyetimin — output raportohet i NDARË
        // (20-5=15, 30-8=22) që të mos faturohet dy herë (Codex #568 P1).
        $this->assertSame([
            'input' => 250,
            'output' => 37,
            'thinking' => 13,
            'provider' => 'openai',
            'model' => 'gpt-test-luna',
        ], $result['usage']);
    }

    /** Codex #568: sink-u i faturimit thirret per-RAUND — dëshmia e plotë edhe për bisedat shumë-raundëshe. */
    public function test_usage_sink_fires_once_per_successful_round(): void
    {
        Http::fakeSequence('api.openai.com/*')
            ->push($this->toolCallsResponse([[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'check_availability', 'arguments' => json_encode(['check_in' => '2026-08-28', 'check_out' => '2026-08-30'])],
            ]]))
            ->push($this->finalReplyResponse());

        $rounds = [];
        app(OpenAiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: 28-30 gusht',
            self::TOOLS,
            ['check_availability' => fn (array $args): array => ['stay_total' => 190]],
            'guest_reply',
            onUsage: function (array $usage) use (&$rounds): void {
                $rounds[] = $usage;
            },
        );

        $this->assertCount(2, $rounds);
        $this->assertSame(15, $rounds[0]['output']);
        $this->assertSame('gpt-test-luna', $rounds[0]['model']);
        $this->assertSame(22, $rounds[1]['output']);
    }

    /** Codex #569 P1: 200 i FATURUAR pa tool_calls (finish_reason=length) regjistrohet PARA se validimi të hedhë. */
    public function test_usage_sink_fires_even_when_a_billable_200_has_no_valid_call(): void
    {
        Http::fakeSequence('api.openai.com/*')->push([
            'choices' => [['message' => ['role' => 'assistant', 'content' => null], 'finish_reason' => 'length']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 2048, 'completion_tokens_details' => ['reasoning_tokens' => 2048]],
        ]);

        $rounds = [];
        try {
            app(OpenAiClient::class)->converse('SYSTEM', 'MYSAFIRI: hej', self::TOOLS, [], 'guest_reply', onUsage: function (array $usage) use (&$rounds): void {
                $rounds[] = $usage;
            });
            $this->fail('Duhej të hidhte length.');
        } catch (\RuntimeException) {
            // S'kishte thirrje — po tokenat U PAGUAN dhe u regjistruan.
        }

        $this->assertCount(1, $rounds);
        $this->assertSame(2048, $rounds[0]['thinking']);
        $this->assertSame(0, $rounds[0]['output']);
    }

    public function test_timeout_carries_the_cool_retry_marker(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('(timeout)');

        app(OpenAiClient::class)->converse('SYSTEM', 'MYSAFIRI: hej', self::TOOLS, [], 'guest_reply');
    }

    public function test_5xx_carries_the_cool_retry_marker(): void
    {
        Http::fakeSequence('api.openai.com/*')->pushStatus(503);

        $this->expectException(\RuntimeException::class);
        // I njëjti shënjues "gabim (5xx)" që njeh riprova e ftohtë (task #403).
        $this->expectExceptionMessage('gabim (503)');

        app(OpenAiClient::class)->converse('SYSTEM', 'MYSAFIRI: hej', self::TOOLS, [], 'guest_reply');
    }

    /**
     * Kundër API-së së VËRTETË të OpenAI — kategoria që mock-u s'e mbulon dot
     * (mësimi i #379/#145: minat e telit i zbulon vetëm provideri real).
     * Ekzekutohet VETËM me qëllim:
     *
     *   OPENAI_REAL_API=1 php artisan test --filter=OpenAiConverseTest
     *
     * Pa çelës / pa flamur kapërcehet gjithmonë (çelësi vjen kur Marjusi
     * hap llogarinë — deri atëherë kontrata fake e ngurtëson formën).
     */
    #[Group('real-api')]
    public function test_real_api_completes_a_tool_round_and_returns_the_final_reply(): void
    {
        $key = (string) env('OPENAI_API_KEY');
        if ($key === '' || ! env('OPENAI_REAL_API')) {
            $this->markTestSkipped('Kërkon OPENAI_API_KEY dhe OPENAI_REAL_API=1 (thirrje e paguar kundër API-së reale).');
        }

        Http::allowStrayRequests();
        config()->set('services.openai.key', $key);
        config()->set('services.openai.model', (string) env('OPENAI_MODEL', 'gpt-5.6-luna'));

        $result = app(OpenAiClient::class)->converse(
            'Je Lora, recepsionistja e hotelit. Kur mysafiri jep datat, thirr check_availability dhe përgjigju vetëm me numrat e mjetit. Mbylle me guest_reply.',
            "BISEDA:\nMYSAFIRI: Per 28 gusht 2026 a ke per dy persona, checkout 30 gusht?",
            self::TOOLS,
            [
                'check_availability' => fn (array $args): array => [
                    'currency' => 'EUR',
                    'nights' => 2,
                    'room_types' => [[
                        'name' => 'Dhomë Dyshe Standard',
                        'rooms_available' => 3,
                        'stay_total' => 190.0,
                        'price_per_night' => 95.0,
                    ]],
                ],
                'get_thread_reservation' => fn (array $args): array => ['error' => 'Asnjë rezervim i lidhur.'],
            ],
            'guest_reply',
            1024,
            75,
        );

        $this->assertContains('check_availability', $result['toolsUsed']);
        $this->assertStringContainsString('190', $result['args']['reply']);
        $this->assertGreaterThan(0, $result['usage']['input']);
    }

    /** Rruga me mjet PA parametra kundër API-së reale — arguments '{}' duhet të mbijetojë. */
    #[Group('real-api')]
    public function test_real_api_completes_a_zero_argument_tool_round(): void
    {
        $key = (string) env('OPENAI_API_KEY');
        if ($key === '' || ! env('OPENAI_REAL_API')) {
            $this->markTestSkipped('Kërkon OPENAI_API_KEY dhe OPENAI_REAL_API=1 (thirrje e paguar kundër API-së reale).');
        }

        Http::allowStrayRequests();
        config()->set('services.openai.key', $key);
        config()->set('services.openai.model', (string) env('OPENAI_MODEL', 'gpt-5.6-luna'));

        $result = app(OpenAiClient::class)->converse(
            'Je Lora, recepsionistja e hotelit. Kur mysafiri pyet për rezervimin e tij (datat, pagesën, bilancin), thirr get_thread_reservation dhe përgjigju vetëm me të dhënat e mjetit. Mbylle me guest_reply.',
            "BISEDA:\nMYSAFIRI: Sa kam mbetur për të paguar nga rezervimi im?",
            self::TOOLS,
            [
                'check_availability' => fn (array $args): array => ['error' => 'Jo për këtë pyetje.'],
                'get_thread_reservation' => fn (array $args): array => [
                    'guest_first_name' => 'Andi',
                    'check_in' => '2026-08-28',
                    'check_out' => '2026-08-30',
                    'currency' => 'EUR',
                    'total' => 190.0,
                    'paid' => 140.0,
                    'balance' => 50.0,
                ],
            ],
            'guest_reply',
            1024,
            75,
        );

        $this->assertContains('get_thread_reservation', $result['toolsUsed']);
        $this->assertStringContainsString('50', $result['args']['reply']);
    }
}
