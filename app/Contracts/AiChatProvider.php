<?php

namespace App\Contracts;

/**
 * DERA E PËRBASHKËT e bisedës AI (task #408, vendimi i Marjusit pas
 * mastermind-it të modeleve): çdo provider (Google Gemini, OpenAI, nesër
 * kushdo) hyn në Lora përmes KËSAJ kontrate. Truri i Lorës — rregullat e
 * shitjes, motorët e çmimeve, rezervimi, portat e sigurisë — s'di asgjë për
 * providerin; ai vetëm flet "gjuhën e telit" të secilit.
 *
 * Kontrata është ajo e GeminiClient::converse() historik, plus metadata e
 * PËRDORIMIT (tokena) që i duhet matjes per-tenant (task #409).
 */
interface AiChatProvider
{
    /** A ka platforma çelës për këtë provider — pa të, provideri s'ekziston. */
    public function configured(): bool;

    /** Id-ja e saktë e modelit që do të flasë (e fiksuar në config — kurrë alias). */
    public function model(): string;

    /**
     * Bisedë shumë-raundëshe me mjete: modeli thërret mjetet e $executors
     * (PHP i ekzekuton dhe ia kthen rezultatin), dhe përgjigja FINALE vjen
     * DETYRIMISHT si thirrje e mjetit $finalToolName. Rrjedha, afatet dhe
     * raundet janë përgjegjësi e shoferit; portat e sigurisë veprojnë SIPËR,
     * mbi rezultatin — njësoj për çdo provider.
     *
     * $onUsage (gjetjet Codex #568): SINK-u i faturimit per-RAUND — thirret
     * pas ÇDO përgjigjeje të suksesshme të providerit me tokenat e atij
     * raundi, të etiketuara me modelin që e SHËRBEU vërtet. Kështu dëshmia
     * e faturimit mbetet e plotë edhe kur biseda dështon në një raund të
     * mëvonshëm (provideri i ka faturuar raundet e suksesshme), dhe raundet
     * e shërbyera nga rezerva çmohen me çmimin e VET, jo të primarit.
     *
     * @param  array<int,array{name:string,description?:string,input_schema?:array}>  $tools
     * @param  array<string,callable(array):array>  $executors  emri i mjetit → ekzekutuesi server-side
     * @param  null|callable(array{input:int,output:int,thinking:int,provider:string,model:string}):void  $onUsage
     * @return array{args:array<string,mixed>,toolsUsed:array<int,string>,usage:array{input:int,output:int,thinking:int,provider:string,model:string}}
     */
    public function converse(string $system, string $userMessage, array $tools, array $executors, string $finalToolName, int $maxTokens = 2048, int $timeoutSeconds = 60, int $maxToolRounds = 3, ?callable $onUsage = null): array;
}
