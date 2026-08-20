<?php

namespace Tests\Feature;

use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Kontrata e converse() me API-në REALE të Gemini-t (task #379).
 *
 * gemini-3.7-flash kthen 400 INVALID_ARGUMENT ("Function call is missing a
 * thought_signature in functionCall parts") nëse historiku i bisedës
 * rindërtohet vetëm me name+args — radha e modelit duhet kthyer VERBATIM
 * (me thoughtSignature + id) dhe çdo functionResponse duhet të mbajë id-në
 * e thirrjes. Këto teste e ngurtësojnë atë kontratë me Http::fake; testi
 * real-api e provon kundër API-së së vërtetë kur ekzekutohet me qëllim.
 */
class GeminiConverseTest extends TestCase
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

        config()->set('services.gemini.key', 'secret-test-key');
        config()->set('services.gemini.model', 'gemini-test-model');
        config()->set('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
    }

    private function functionCallResponse(array $parts): array
    {
        return [
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => $parts],
                'finishReason' => 'STOP',
            ]],
        ];
    }

    private function finalReplyResponse(): array
    {
        return $this->functionCallResponse([[
            'functionCall' => [
                'name' => 'guest_reply',
                'args' => ['confident' => true, 'reply' => 'Totali 190 EUR.', 'kind' => 'informative'],
            ],
        ]]);
    }

    public function test_tool_round_echoes_model_content_verbatim_with_thought_signature_and_call_id(): void
    {
        $modelParts = [[
            'functionCall' => [
                'name' => 'check_availability',
                'args' => ['check_in' => '2026-08-28', 'check_out' => '2026-08-30', 'adults' => 2],
                'id' => 'call_777',
            ],
            'thoughtSignature' => 'SIG-OPAQUE-BYTES',
        ]];

        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push($this->functionCallResponse($modelParts))
            ->push($this->finalReplyResponse());

        $quote = ['currency' => 'EUR', 'nights' => 2, 'stay_total' => 190.5];
        $result = app(GeminiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: 28-30 gusht, 2 persona',
            self::TOOLS,
            ['check_availability' => fn (array $args): array => $quote],
            'guest_reply',
        );

        $this->assertSame(['check_availability'], $result['toolsUsed']);
        $this->assertSame('Totali 190 EUR.', $result['args']['reply']);

        // Forma e telit (raw body i dekoduar): radha e modelit kthehet
        // VERBATIM — me thoughtSignature dhe id.
        $second = json_decode((string) collect(Http::recorded())->last()[0]->body(), true);
        $this->assertSame(['role' => 'model', 'parts' => $modelParts], $second['contents'][1]);

        // functionResponse mban id-në e thirrjes që i përket.
        $this->assertSame([
            'functionResponse' => [
                'name' => 'check_availability',
                'response' => $quote,
                'id' => 'call_777',
            ],
        ], $second['contents'][2]['parts'][0]);
    }

    public function test_parallel_function_calls_each_get_a_response_in_the_same_order(): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push($this->functionCallResponse([
                [
                    'functionCall' => ['name' => 'check_availability', 'args' => ['check_in' => '2026-08-28', 'check_out' => '2026-08-30'], 'id' => 'call_1'],
                    'thoughtSignature' => 'SIG-1',
                ],
                [
                    'functionCall' => ['name' => 'get_thread_reservation', 'args' => [], 'id' => 'call_2'],
                ],
            ]))
            ->push($this->finalReplyResponse());

        $result = app(GeminiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: sa kam për të paguar dhe a ka dhoma?',
            self::TOOLS,
            [
                'check_availability' => fn (array $args): array => ['stay_total' => 190.0],
                'get_thread_reservation' => fn (array $args): array => ['balance' => 50.0],
            ],
            'guest_reply',
        );

        $this->assertSame(['check_availability', 'get_thread_reservation'], $result['toolsUsed']);

        $parts = collect(Http::recorded())->last()[0]->data()['contents'][2]['parts'];
        $this->assertCount(2, $parts);
        $this->assertSame('call_1', $parts[0]['functionResponse']['id']);
        $this->assertSame(['stay_total' => 190.0], $parts[0]['functionResponse']['response']);
        $this->assertSame('call_2', $parts[1]['functionResponse']['id']);
        $this->assertSame(['balance' => 50.0], $parts[1]['functionResponse']['response']);
    }

    public function test_premature_final_in_a_mixed_turn_is_rejected_and_tools_still_run(): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push($this->functionCallResponse([
                [
                    'functionCall' => ['name' => 'guest_reply', 'args' => ['confident' => true, 'reply' => 'Çmimi 999 EUR.', 'kind' => 'informative'], 'id' => 'call_early'],
                    'thoughtSignature' => 'SIG-EARLY',
                ],
                [
                    'functionCall' => ['name' => 'check_availability', 'args' => ['check_in' => '2026-08-28', 'check_out' => '2026-08-30'], 'id' => 'call_tool'],
                ],
            ]))
            ->push($this->finalReplyResponse());

        $executed = false;
        $result = app(GeminiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: 28-30 gusht, 2 persona',
            self::TOOLS,
            ['check_availability' => function (array $args) use (&$executed): array {
                $executed = true;

                return ['stay_total' => 190.0];
            }],
            'guest_reply',
        );

        // Finalja e parakohshme NUK pranohet — mjeti ekzekutohet dhe përgjigja
        // e vërtetë vjen nga raundi pasues, e ankoruar në rezultatet e mjetit.
        $this->assertTrue($executed);
        $this->assertSame(['check_availability'], $result['toolsUsed']);
        $this->assertSame('Totali 190 EUR.', $result['args']['reply']);

        $parts = collect(Http::recorded())->last()[0]->data()['contents'][2]['parts'];
        $this->assertCount(2, $parts);
        $this->assertSame('call_early', $parts[0]['functionResponse']['id']);
        $this->assertArrayHasKey('error', $parts[0]['functionResponse']['response']);
        $this->assertSame('call_tool', $parts[1]['functionResponse']['id']);
        $this->assertSame(['stay_total' => 190.0], $parts[1]['functionResponse']['response']);
    }

    public function test_zero_argument_tool_call_keeps_args_as_a_json_object_on_the_wire(): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push($this->functionCallResponse([[
                'functionCall' => ['name' => 'get_thread_reservation', 'args' => new \stdClass, 'id' => 'call_res'],
                'thoughtSignature' => 'SIG-RES',
            ]]))
            ->push($this->finalReplyResponse());

        app(GeminiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: sa kam për të paguar?',
            self::TOOLS,
            ['get_thread_reservation' => fn (array $args): array => ['balance' => 50.0]],
            'guest_reply',
        );

        // Forma e telit, jo struktura e dekoduar: `args` duhet të mbetet `{}`
        // (objekt JSON) — dekodimi array do ta bënte `[]` dhe API-ja kthen 400.
        $rawBody = (string) collect(Http::recorded())->last()[0]->body();
        $this->assertStringContainsString('"args":{}', $rawBody);
        $this->assertStringNotContainsString('"args":[]', $rawBody);
        $this->assertStringContainsString('"thoughtSignature":"SIG-RES"', $rawBody);
    }

    public function test_function_response_omits_id_when_the_model_sent_none(): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push($this->functionCallResponse([[
                'functionCall' => ['name' => 'check_availability', 'args' => ['check_in' => '2026-08-28', 'check_out' => '2026-08-30']],
            ]]))
            ->push($this->finalReplyResponse());

        app(GeminiClient::class)->converse(
            'SYSTEM',
            'MYSAFIRI: 28-30 gusht',
            self::TOOLS,
            ['check_availability' => fn (array $args): array => ['stay_total' => 190.0]],
            'guest_reply',
        );

        $response = collect(Http::recorded())->last()[0]->data()['contents'][2]['parts'][0]['functionResponse'];
        $this->assertArrayNotHasKey('id', $response);
    }

    /**
     * Kundër API-së së VËRTETË të Gemini-t — kategoria që mock-u s'e mbulon dot
     * (mësimi i #379). Ekzekutohet VETËM me qëllim, që të mos digjet çelësi i
     * paguar në çdo xhirim lokal të suitës:
     *
     *   GEMINI_REAL_API=1 php artisan test --filter=GeminiConverseTest
     *
     * Në CI (pa çelës / pa flamur) kapërcehet gjithmonë.
     */
    #[Group('real-api')]
    public function test_real_api_completes_a_tool_round_and_returns_the_final_reply(): void
    {
        $key = (string) env('GEMINI_API_KEY', env('GOOGLE_API_KEY'));
        if ($key === '' || ! env('GEMINI_REAL_API')) {
            $this->markTestSkipped('Kërkon GEMINI_API_KEY dhe GEMINI_REAL_API=1 (thirrje e paguar kundër API-së reale).');
        }

        Http::allowStrayRequests();
        config()->set('services.gemini.key', $key);
        config()->set('services.gemini.model', 'gemini-flash-latest');

        $result = app(GeminiClient::class)->converse(
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
        $this->assertNotSame('', trim((string) $result['args']['reply']));
        $this->assertStringContainsString('190', $result['args']['reply']);
    }

    /**
     * Rruga me mjet PA parametra kundër API-së reale — modeli dërgon `args: {}`
     * dhe jehona duhet ta ruajë si objekt JSON (kategoria e dytë e 400-ës).
     * Ekzekutohet vetëm me GEMINI_REAL_API=1 (shih testin më lart).
     */
    #[Group('real-api')]
    public function test_real_api_completes_a_zero_argument_tool_round(): void
    {
        $key = (string) env('GEMINI_API_KEY', env('GOOGLE_API_KEY'));
        if ($key === '' || ! env('GEMINI_REAL_API')) {
            $this->markTestSkipped('Kërkon GEMINI_API_KEY dhe GEMINI_REAL_API=1 (thirrje e paguar kundër API-së reale).');
        }

        Http::allowStrayRequests();
        config()->set('services.gemini.key', $key);
        config()->set('services.gemini.model', 'gemini-flash-latest');

        $result = app(GeminiClient::class)->converse(
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
