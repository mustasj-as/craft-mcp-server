<?php

namespace Mustasj\CraftMcp\tools;

use Craft;
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
     * @param bool $drafts List upubliserte utkast i stedet for publiserte entries
     * @return array
     */
    public function listArticles(
        string $section,
        ?string $query = null,
        ?string $site = null,
        int $limit = 20,
        int $offset = 0,
        bool $drafts = false,
    ): array {
        $site ??= SiteHelper::defaultKey();

        SectionPolicy::assertAllowed($section, SectionPolicy::READ);

        $entryQuery = Entry::find()
            ->section($section)
            ->site(SiteHelper::resolve($site)->handle)
            ->limit(min(max($limit, 1), 100))
            ->offset(max($offset, 0));

        if ($drafts) {
            // Bare utkast av entries som aldri er publisert — utkast av
            // eksisterende entries hentes via get_entry på utkastets id.
            // Uten rett til andres utkast i seksjonen ser brukeren bare sine
            // egne, i stedet for at titlene lekker.
            $entryQuery->drafts()->draftOf(false)->status(null);
            $user = Craft::$app->getUser()->getIdentity();
            $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section);

            if ($user === null || $sectionModel === null || !$user->can("viewPeerEntryDrafts:$sectionModel->uid")) {
                $entryQuery->creatorId($user?->id ?? 0);
            }
        }

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
     * @param int|null $draftId Utkast-ID fra create_entry/update_entry (alternativ til id og slug)
     * @return array
     */
    public function getArticle(
        string $section,
        ?int $id = null,
        ?string $slug = null,
        ?string $site = null,
        ?int $draftId = null,
    ): array {
        $site ??= SiteHelper::defaultKey();

        if ($id === null && $slug === null && $draftId === null) {
            throw new ToolCallException('Provide "id", "slug" or "draftId".');
        }

        SectionPolicy::assertAllowed($section, SectionPolicy::READ);

        $query = Entry::find()
            ->section($section)
            ->site(SiteHelper::resolve($site)->handle)
            ->status(null);

        // Element-ID-er er unike, så utkast kan tas med uten tvetydighet — det
        // er slik en agent leser tilbake det create_entry nettopp laget
        // (`elementId`). Slug-oppslag holdes til publiserte entries, ellers
        // kunne et utkast med samme slug skygge for den live versjonen.
        if ($id !== null) {
            $query->id($id)->drafts(null);
        }

        if ($draftId !== null) {
            $query->draftId($draftId);
        }

        if ($slug !== null) {
            $query->slug($slug);
        }

        $entry = $query->one();

        if ($entry === null) {
            $what = match (true) {
                $draftId !== null => "draftId $draftId",
                $id !== null => "id $id",
                default => "slug \"$slug\"",
            };
            throw new ToolCallException(sprintf('Entry not found (%s) in section "%s" on site "%s".', $what, $section, $site));
        }

        return EntrySerializer::serialize(EntryAccess::requireViewable($entry));
    }
}
