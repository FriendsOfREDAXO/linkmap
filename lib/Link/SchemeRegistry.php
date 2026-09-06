<?php

namespace FriendsOfRedaxo\Linkmap\Link;

final class SchemeRegistry
{
    /** @var array<string, SchemeProviderInterface> */
    private static array $providers = [];

    public static function register(SchemeProviderInterface $provider): void
    {
        self::$providers[$provider->getId()] = $provider;
    }

    /** @return array<string, SchemeProviderInterface> */
    public static function getProviders(): array
    {
        return self::$providers;
    }

    public static function getProvider(string $id): ?SchemeProviderInterface
    {
        return self::$providers[$id] ?? null;
    }

    /**
     * Alle Schemata aller Provider fuer eine Tabelle.
     *
     * @return list<Scheme>
     */
    public static function getSchemes(string $table): array
    {
        $schemes = [];
        foreach (self::$providers as $provider) {
            foreach ($provider->getSchemes($table) as $scheme) {
                $schemes[] = $scheme;
            }
        }
        return $schemes;
    }

    public static function getScheme(string $table, string $schemeId): ?Scheme
    {
        foreach (self::getSchemes($table) as $scheme) {
            if ($scheme->id() === $schemeId) {
                return $scheme;
            }
        }
        return null;
    }
}
