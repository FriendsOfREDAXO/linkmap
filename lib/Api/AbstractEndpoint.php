<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Permission;
use rex;
use rex_api_function;
use rex_api_result;
use rex_clang;
use rex_response;

/**
 * Gemeinsame Basis aller Linkmap-Endpunkte: JSON-Antwort, Login- und
 * Strukturrecht-Pruefung, clang-Aufloesung. Antwortet immer direkt per
 * rex_response::sendJson() und beendet den Request -- rex_api_result wird nur
 * wegen der Signatur deklariert.
 */
abstract class AbstractEndpoint extends rex_api_function
{
    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        if (!rex::getUser()) {
            $this->fail(rex_response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }
        if (!Permission::hasStructureAccess()) {
            $this->fail(rex_response::HTTP_FORBIDDEN, 'Permission denied');
        }

        $this->send($this->handle());
    }

    /** @return array<string, mixed> */
    abstract protected function handle(): array;

    /** @param array<string, mixed> $data */
    protected function send(array $data): never
    {
        rex_response::sendJson($data);
        exit;
    }

    protected function fail(string $status, string $message): never
    {
        rex_response::setStatus($status);
        rex_response::sendJson(['error' => $message]);
        exit;
    }

    /** clang aus dem Request, Fallback auf die Startsprache. */
    protected function clang(): int
    {
        $clang = rex_request('clang', 'int', 0);
        return rex_clang::exists($clang) ? $clang : rex_clang::getStartId();
    }
}
