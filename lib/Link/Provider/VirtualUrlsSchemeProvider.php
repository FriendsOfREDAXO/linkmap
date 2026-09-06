<?php

namespace FriendsOfRedaxo\Linkmap\Link\Provider;

use FriendsOfRedaxo\Linkmap\Link\Scheme;
use FriendsOfRedaxo\Linkmap\Link\SchemeProviderInterface;
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsHelper;
use rex_addon;
use rex_article;
use rex_i18n;

/**
 * virtual_urls: jedes aktive Profil einer Tabelle ist ein Schema (Key =
 * Profil-ID). Nutzt VirtualUrlsHelper::getProfileById()/getUrlByProfile()
 * (virtual_urls >= 1.2).
 */
final class VirtualUrlsSchemeProvider implements SchemeProviderInterface
{
    public static function isAvailable(): bool
    {
        // getUrlByProfile() gibt es erst ab virtual_urls 1.2 (PR #2)
        return rex_addon::get('virtual_urls')->isAvailable()
            && class_exists(VirtualUrlsHelper::class)
            && version_compare((string) rex_addon::get('virtual_urls')->getVersion(), '1.2.0', '>=');
    }

    public function getId(): string
    {
        return 'vu';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('linkmap_scheme_provider_vu');
    }

    public function getSchemes(string $table): array
    {
        $schemes = [];
        foreach (VirtualUrlsHelper::getProfilesByTable($table) as $profile) {
            $articleId = (int) $profile['article_id'];
            $clang = (int) ($profile['clang_id'] ?? -1);
            $article = rex_article::get($articleId, $clang > 0 ? $clang : null);
            $domain = (string) ($profile['domain'] ?? '');
            $schemes[] = new Scheme(
                $this->getId(),
                (string) $profile['id'],
                $this->getLabel() . ' „/' . $profile['trigger_segment'] . '/“ → ' . ($article ? $article->getName() : '#' . $articleId) . ('' !== $domain ? ' (' . $domain . ')' : ''),
                $table,
                $clang > 0 ? $clang : null,
                $domain,
                $articleId,
            );
        }
        return $schemes;
    }

    public function getUrl(Scheme $scheme, int $datasetId, int $clang): ?string
    {
        if (!$scheme->supportsClang($clang)) {
            return null;
        }
        $profile = VirtualUrlsHelper::getProfileById((int) $scheme->key);
        if (null === $profile) {
            return null;
        }
        return VirtualUrlsHelper::getUrlByProfile($profile, $datasetId, $clang);
    }
}
