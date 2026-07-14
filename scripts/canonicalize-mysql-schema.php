#!/usr/bin/env php
<?php

declare(strict_types=1);

$input = stream_get_contents(STDIN);

if ($input === false) {
    fwrite(STDERR, "Unable to read schema from standard input.\n");
    exit(1);
}

$lines = preg_split('/\R/', rtrim($input, "\r\n"));

if ($lines === false) {
    fwrite(STDERR, "Unable to split schema into lines.\n");
    exit(1);
}

$output = [];
$definitions = [];
$insideCreateTable = false;

$flushDefinitions = static function () use (&$output, &$definitions): void {
    if ($definitions === []) {
        return;
    }

    sort($definitions, SORT_STRING);

    foreach ($definitions as $definition) {
        $output[] = "  {$definition}";
    }

    $definitions = [];
};

foreach ($lines as $line) {
    $line = preg_replace('/AUTO_INCREMENT=\d+/', 'AUTO_INCREMENT=N', $line);

    if ($line === null) {
        fwrite(STDERR, "Unable to normalize schema.\n");
        exit(1);
    }

    if (str_starts_with($line, 'CREATE TABLE ')) {
        $flushDefinitions();
        $insideCreateTable = true;
    }

    if ($insideCreateTable
        && preg_match('/^\s+(?:(?:PRIMARY|UNIQUE|FULLTEXT|SPATIAL) KEY|KEY|CONSTRAINT|CHECK)\b/', $line) === 1) {
        $definitions[] = rtrim(trim($line), ',');

        continue;
    }

    if ($insideCreateTable && preg_match('/^\)\s+ENGINE=/', $line) === 1) {
        $flushDefinitions();
        $insideCreateTable = false;
    }

    $output[] = $line;
}

$flushDefinitions();

fwrite(STDOUT, implode(PHP_EOL, $output).PHP_EOL);
