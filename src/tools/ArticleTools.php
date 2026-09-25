<?php

namespace Mustasj\CraftMcp\tools;

use craft\elements\Entry;
use Mcp\Exception\ToolCallException;
use Mustasj\CraftMcp\helpers\EntryAccess;
use Mustasj\CraftMcp\helpers\EntrySerializer;
use Mustasj\CraftMcp\helpers\SectionPolicy;
use Mustasj\CraftMcp\helpers\SiteHelper;

/**
 * MCP-verktøy for artikler og landingssider.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class ArticleTools
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Lister artikler eller landingssider.
     *
     * @param string $section Seksjon («artikler» eller «landingssider»)
     * @param string|null $query Fritekstsøk
     * @param string|null $site Språkkode. Utelatt gir standard-siten, se SiteHelper::defaultKey()
     * @param int $limit Maks antall resultater (1–100)
     * @param int $offset Antall resultater å hoppe over
     * @return array
     */
    public function listArticles(
        string $section,
        ?string $query = null,
        ?string $site = null,
        int $limit = 20,
        int $offset = 0,
    ): array {
        $site ??= SiteHelper::defaultKey();

        SectionPolicy::assertAllowed($section, SectionPolicy::READ);

        $entryQuery = Entry::find()
            ->section($section)
            ->site(SiteHelper::resolve($site)->handle)
            ->limit(min(max($limit, 1), 100))
            ->offset(max($offset, 0));

        if ($query !== null && trim($query) !== '') {
            $entryQuery->search(trim($query))->orderBy('score');
        }

        $total = (int)(clone $entryQuery)->limit(null)->offset(0)->count();

        return [
            'total' => $total,
            'results' => array_map(
                static fn(Entry $entry) => EntrySerializer::summarize($entry),
                $entryQuery->all(),
            ),
        ];
    }

    /**
     * Henter en komplett artikkel eller landingsside.
     *
     * @param string $section Seksjonshandle, fra de konfigurerte seksjonene
     * @param int|null $id Entry-ID
     * @param string|null $slug Entry-slug (alternativ til id)
     * @param string|null $site Språkkode. Utelatt gir standard-siten, se SiteHelper::defaultKey()
     * @return array
     */
    public function getArticle(
        string $section,
        ?int $id = null,
        ?string $slug = null,
        ?string $site = null,
    ): array {
        $site ??= SiteHelper::defaultKey();

        if ($id === null && $slug === null) {
            throw new ToolCallException('Provide either "id" or "slug".');
        }

        SectionPolicy::assertAllowed($section, SectionPolicy::READ);

        $query = Entry::find()
            ->section($section)
            ->site(SiteHelper::resolve($site)->handle)
            ->status(null);

        if ($id !== null) {
            $query->id($id);
        }

        if ($slug !== null) {
            $query->slug($slug);
        }

        $entry = $query->one();

        if ($entry === null) {
            throw new ToolCallException(sprintf('Entry not found (%s) in section "%s" on site "%s".', $id !== null ? "id $id" : "slug \"$slug\"", $section, $site));
        }

        return EntrySerializer::serialize(EntryAccess::requireViewable($entry));
    }
}
