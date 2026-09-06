<?php

namespace FriendsOfRedaxo\Linkmap\Link\Provider;

use FriendsOfRedaxo\Linkmap\Link\Scheme;
use FriendsOfRedaxo\Linkmap\Link\SchemeProviderInterface;
use rex_addon;
use rex_article;
use rex_i18n;
use rex_yrewrite;
use Url\Profile;
use Url\Url;
use Url\UrlManagerSql;

use function count;

/**
 * url-Addon: jedes Profil einer Tabelle ist ein Schema (Key = Namespace).
 * Zugehoerigkeit eines Datensatzes (Restriktionen) und die URL kommen aus
 * der URL-Tabelle des Addons; fehlt der Eintrag, wird er wie in
 * UrlManager::getRewriteUrl() einmalig erzeugt.
 */
final class UrlAddonSchemeProvider implements SchemeProviderInterface
{
    public static function isAvailable(): bool
    {
        return rex_addon::get('url')->isAvailable() && class_exists(Profile::class);
    }

    public function getId(): string
    {
        return 'url';
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('linkmap_scheme_provider_url');
    }

    public function getSchemes(string $table): array
    {
        $schemes = [];
        foreach (Profile::getAll() as $profile) {
            if ($profile->getTableName() !== $table) {
                continue;
            }
            $articleId = (int) $profile->getArticleId();
            $clang = $profile->getArticleClangId();
            $article = rex_article::get($articleId, null !== $clang ? (int) $clang : null);
            $domain = '';
            if (rex_addon::get('yrewrite')->isAvailable() && $articleId > 0) {
                $domainObj = rex_yrewrite::getDomainByArticleId($articleId, null !== $clang ? (int) $clang : null);
                $domain = 'default' === $domainObj->getName() ? '' : (string) $domainObj->getName();
            }
            $schemes[] = new Scheme(
                $this->getId(),
                (string) $profile->getNamespace(),
                $this->getLabel() . ' „' . $profile->getNamespace() . '“ → ' . ($article ? $article->getName() : '#' . $articleId) . ('' !== $domain ? ' (' . $domain . ')' : ''),
                $table,
                null !== $clang && (int) $clang > 0 ? (int) $clang : null,
                $domain,
                $articleId,
            );
        }
        return $schemes;
    }

    public function getUrl(Scheme $scheme, int $datasetId, int $clang): ?string
    {
        // getByNamespace() liefert ein (gefiltertes) Array, kein einzelnes Profil
        $profiles = array_values((array) Profile::getByNamespace($scheme->key));
        $profile = $profiles[0] ?? null;
        if (!$profile instanceof Profile || !$scheme->supportsClang($clang)) {
            return null;
        }

        $records = UrlManagerSql::getOrigin($profile, $datasetId, $clang);
        if (0 === count($records)) {
            $profile->buildUrlsByDatasetId($datasetId);
            $records = UrlManagerSql::getOrigin($profile, $datasetId, $clang);
        }
        if (0 === count($records)) {
            return null;
        }

        $url = Url::get((string) $records[0]['url']);
        if ($url->getDomain() === Url::getCurrent()->getDomain()) {
            return $url->getPath();
        }
        $rewriter = Url::getRewriter();
        $protocol = $rewriter ? ($rewriter->getSchemeByDomain($url->getDomain()) ?: ($rewriter->isHttps() ? 'https' : 'http')) : 'https';
        $url->withScheme($protocol);
        return $url->getSchemeAndHttpHost() . $url->getPath();
    }
}
