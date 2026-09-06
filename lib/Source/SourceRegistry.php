<?php

namespace FriendsOfRedaxo\Linkmap\Source;

final class SourceRegistry
{
    /** @var array<string, SourceProviderInterface> */
    private static array $providers = [];

    public static function register(SourceProviderInterface $provider): void
    {
        self::$providers[$provider->getId()] = $provider;
    }

    /** @return array<string, SourceProviderInterface> */
    public static function getProviders(): array
    {
        return self::$providers;
    }

    public static function getProvider(string $id): ?SourceProviderInterface
    {
        return self::$providers[$id] ?? null;
    }

    public static function getProviderForLink(string $link): ?SourceProviderInterface
    {
        foreach (self::$providers as $provider) {
            if ($provider->ownsLink($link)) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Provider samt Containern fuer den eingeloggten User (#lm-config).
     *
     * @return list<array{id: string, label: string, icon: string, containers: list<array{id: string, label: string, icon: string, linkable: bool, addUrl?: string}>}>
     */
    public static function getAvailableSources(): array
    {
        $sources = [];
        foreach (self::$providers as $provider) {
            $containers = $provider->getContainers();
            if ([] === $containers) {
                continue;
            }
            $sources[] = [
                'id' => $provider->getId(),
                'label' => $provider->getLabel(),
                'icon' => $provider->getIcon(),
                'containers' => $containers,
            ];
        }
        return $sources;
    }
}
