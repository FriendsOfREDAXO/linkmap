<?php

namespace FriendsOfRedaxo\Linkmap\Link;

/**
 * Ein URL-Schema fuer YForm-Datensaetze einer Tabelle: ein url-Addon-Profil,
 * ein virtual_urls-Profil oder ein von einem anderen Addon registriertes
 * Schema. Identifiziert ueber "<provider>:<key>", genau dieser String steht
 * bei expliziter Wahl im Link (yform://tabelle/42?scheme=url:news-id).
 */
final class Scheme
{
    public function __construct(
        public readonly string $provider,
        public readonly string $key,
        public readonly string $label,
        public readonly string $table,
        /** null = alle Sprachen */
        public readonly ?int $clang = null,
        /** '' = alle Domains (yrewrite-Domain-Name) */
        public readonly string $domain = '',
        public readonly int $articleId = 0,
    ) {}

    public function id(): string
    {
        return $this->provider . ':' . $this->key;
    }

    public function supportsClang(int $clang): bool
    {
        return null === $this->clang || $this->clang === $clang;
    }

    public function supportsDomain(string $domain): bool
    {
        return '' === $this->domain || '' === $domain || $this->domain === $domain;
    }
}
