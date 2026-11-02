<?php

namespace Mustasj\CraftMcp\tools;

use Craft;
use craft\elements\Entry;
use DateTimeInterface;
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

    /**
     * Henter de siste revisjonene av en entry: når, av hvem, og om entryen
     * var slått på i den revisjonen.
     *
     * Svarer på «når og av hvem ble denne endret / slått av?», som
     * `dateUpdated` alene ikke kan: den flyttes også av resave og migreringer.
     * `creator` er bare visningsnavnet (ADR 0009).
     *
     * @param string $section Seksjonshandle, fra de konfigurerte seksjonene
     * @param int|null $id Entry-ID (ikke utkast)
     * @param string|null $slug Entry-slug (alternativ til id)
     * @param string|null $site Språkkode. Utelatt gir standard-siten, se SiteHelper::defaultKey()
     * @param int $limit Antall revisjoner, nyeste først (1–20)
     * @return array
     */
    public function getEntryHistory(
        string $section,
        ?int $id = null,
        ?string $slug = null,
        ?string $site = null,
        int $limit = 5,
    ): array {
        $site ??= SiteHelper::defaultKey();

        if ($id === null && $slug === null) {
            throw new ToolCallException('Provide "id" or "slug".');
        }

        SectionPolicy::assertAllowed($section, SectionPolicy::READ);

        $siteHandle = SiteHelper::resolve($site)->handle;
        $query = Entry::find()
            ->section($section)
            ->site($siteHandle)
            ->status(null);

        if ($id !== null) {
            $query->id($id);
        }

        if ($slug !== null) {
            $query->slug($slug);
        }

        $entry = $query->one();

        if ($entry === null) {
            $what = $id !== null ? "id $id" : "slug \"$slug\"";
            throw new ToolCallException(sprintf('Entry not found (%s) in section "%s" on site "%s". Drafts have no revision history; pass the published entry\'s id.', $what, $section, $site));
        }

        EntryAccess::requireViewable($entry);

        $revisionQuery = Entry::find()
            ->revisionOf($entry)
            ->site($siteHandle)
            ->status(null);

        $totalRevisions = (int)(clone $revisionQuery)->count();

        /** @var Entry[] $revisions */
        $revisions = $revisionQuery
            ->orderBy(['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC])
            ->limit(min(max($limit, 1), 20))
            ->all();

        $enabled = self::_isEnabled($entry);

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'status' => $entry->getStatus(),
            'enabled' => $enabled,
            'dateUpdated' => $entry->dateUpdated?->format(DateTimeInterface::ATOM),
            'totalRevisions' => $totalRevisions,
            'revisions' => array_map(static fn(Entry $revision) => [
                'num' => $revision->revisionNum,
                'date' => $revision->dateCreated?->format(DateTimeInterface::ATOM),
                // Bare fullt navn, aldri e-post, brukernavn eller id (ADR 0009).
                // Ikke getName(): den faller tilbake til brukernavnet, som
                // ofte er e-postadressen.
                'creator' => $revision->getCreator()?->fullName ?: null,
                'notes' => $revision->revisionNotes,
                'enabled' => self::_isEnabled($revision),
            ], $revisions),
            'note' => self::_historyNote($enabled, $revisions[0] ?? null),
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Om entryen er slått på for siten den er hentet på.
     *
     * Begge nivåene må sjekkes: med én site setter Craft (og `resave
     * --setEnabledForSite`) `enabled` på elementet, mens per-site-verdien
     * blir stående `true`.
     *
     * @param Entry $entry
     * @return bool
     */
    private static function _isEnabled(Entry $entry): bool
    {
        return $entry->enabled && $entry->getEnabledForSite() !== false;
    }

    /**
     * Forklarer avvik mellom entryens status og siste revisjon, så agenten
     * ikke trekker feil slutning av en historikk med hull.
     *
     * @param bool $enabled
     * @param Entry|null $latest
     * @return string|null
     */
    private static function _historyNote(bool $enabled, ?Entry $latest): ?string
    {
        if ($latest === null) {
            return 'No revisions found. The section may not have versioning enabled, or the entry has never been saved in a way that creates one.';
        }

        if ($enabled !== self::_isEnabled($latest)) {
            return sprintf(
                'The entry is currently %s, but its latest revision is %s: the status was changed without creating a revision (a resave command, a migration or a script), some time after %s.',
                $enabled ? 'enabled' : 'disabled',
                $enabled ? 'disabled' : 'enabled',
                $latest->dateCreated?->format(DateTimeInterface::ATOM),
            );
        }

        return null;
    }
}
