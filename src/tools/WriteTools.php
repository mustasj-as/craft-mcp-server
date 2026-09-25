<?php

namespace Mustasj\CraftMcp\tools;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use Mcp\Exception\ToolCallException;
use Mustasj\CraftMcp\helpers\FieldValues;
use Mustasj\CraftMcp\helpers\SectionPolicy;
use Mustasj\CraftMcp\helpers\SiteHelper;

/**
 * MCP-verktøy for å opprette og endre innhold.
 *
 * Alle skriveoperasjoner er draft-først: create_entry og update_entry lager
 * utkast, og publish_draft må kalles eksplisitt for å publisere. Tilgang
 * håndheves av Crafts permissions for brukeren tokenet tilhører.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class WriteTools
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string Beskrivelse brukt for `fields`-parametrene
     */
    public const FIELDS_DESCRIPTION = 'Field values keyed by field handle (see get_content_model). Matrix fields: array of blocks [{"type": "<entryTypeHandle>", "title": "...", "fields": {...}}] — replaces the entire field content; include existing block ids as {"id": 123, ...} to keep them, and omit "title" for block types without a title field. Blocks nest: a Matrix field inside a block\'s "fields" takes the same array-of-blocks shape. Category fields: array of slugs. Asset/entry relation fields: array of element ids.';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Oppretter en ny entry som utkast.
     *
     * @param string $section Seksjon
     * @param string $title Tittel
     * @param array $fields Feltverdier per handle
     * @param string|null $site Språkkode. Utelatt gir standard-siten, se SiteHelper::defaultKey()
     * @param string|null $slug Ønsket slug (genereres fra tittel hvis utelatt)
     * @param string|null $entryType Entry-type-handle (default: seksjonens første)
     * @param int|null $parentId Forelder i structure-seksjoner (null = toppnivå)
     * @return array
     * @throws \Throwable
     */
    public function createEntry(
        string $section,
        string $title,
        array $fields = [],
        ?string $site = null,
        ?string $slug = null,
        ?string $entryType = null,
        ?int $parentId = null,
    ): array {
        $site ??= SiteHelper::defaultKey();

        $user = $this->_requireUser();
        SectionPolicy::assertAllowed($section, SectionPolicy::CREATE);
        $sectionModel = Craft::$app->getEntries()->getSectionByHandle($section);

        if ($sectionModel === null) {
            throw new ToolCallException(sprintf('Unknown section "%s".', $section));
        }

        $entry = new Entry();
        $entry->sectionId = $sectionModel->id;
        $entry->setTypeId($this->_resolveEntryType($sectionModel, $entryType)->id);
        $entry->siteId = SiteHelper::resolve($site)->id;
        $entry->title = $title;
        $entry->slug = $slug;
        $entry->setAuthorId($user->id);
        $entry->enabled = true;

        if ($parentId !== null) {
            $entry->setParentId($this->_resolveParentId($sectionModel, $parentId, $site));
        }

        FieldValues::apply($entry, $fields);

        if (!Craft::$app->getElements()->canSave($entry, $user)) {
            throw new ToolCallException(sprintf('The API token user is not allowed to create entries in section "%s".', $section));
        }

        if (!Craft::$app->getDrafts()->saveElementAsDraft($entry, $user->id, markAsSaved: false)) {
            throw new ToolCallException('Could not save draft: ' . $this->_formatErrors($entry));
        }

        return $this->_draftResult($entry);
    }

    /**
     * Endrer en eksisterende entry ved å lage et utkast av den.
     *
     * @param int $entryId ID-en til entryen som skal endres
     * @param array $fields Feltverdier per handle (kun feltene som skal endres)
     * @param string|null $title Ny tittel
     * @param string|null $site Språkkode. Utelatt gir standard-siten, se SiteHelper::defaultKey()
     * @param int|null $parentId Ny forelder i structure-seksjoner (0 = flytt til toppnivå)
     * @return array
     * @throws \Throwable
     */
    public function updateEntry(
        int $entryId,
        array $fields = [],
        ?string $title = null,
        ?string $site = null,
        ?int $parentId = null,
    ): array {
        $site ??= SiteHelper::defaultKey();

        $user = $this->_requireUser();

        /** @var Entry|null $entry */
        $entry = Entry::find()
            ->id($entryId)
            ->site(SiteHelper::resolve($site)->handle)
            ->drafts(null)
            ->status(null)
            ->one();

        if ($entry === null) {
            throw new ToolCallException(sprintf('Entry %d not found on site "%s".', $entryId, $site));
        }

        if (!$entry->canCreateDrafts($user)) {
            throw new ToolCallException('The API token user is not allowed to create drafts of this entry.');
        }

        if ($entry->getIsDraft()) {
            // Allerede et utkast — endre det direkte i stedet for utkast-av-utkast.
            $draft = $entry;
            $isNewDraft = false;
        } else {
            /** @var Entry $draft */
            $draft = Craft::$app->getDrafts()->createDraft($entry, $user->id, null, 'Endret via MCP');
            $isNewDraft = true;
        }

        if ($title !== null) {
            $draft->title = $title;
        }

        if ($parentId !== null) {
            $section = $draft->getSection();

            if ($section === null) {
                throw new ToolCallException('Entry has no section, so it cannot be placed in a structure.');
            }

            // 0 betyr toppnivå — `null` kan ikke skille «flytt opp» fra
            // «ikke rør plasseringen», siden utelatt parameter også er null.
            $draft->setParentId($parentId === 0 ? null : $this->_resolveParentId($section, $parentId, $site));
        }

        FieldValues::apply($draft, $fields);

        if (!Craft::$app->getElements()->saveElement($draft)) {
            if ($isNewDraft) {
                Craft::$app->getElements()->deleteElement($draft, true);
            }
            throw new ToolCallException('Could not save draft: ' . $this->_formatErrors($draft));
        }

        return $this->_draftResult($draft);
    }

    /**
     * Publiserer et utkast.
     *
     * @param int $draftId Utkast-ID fra create_entry/update_entry
     * @param string|null $site Språkkode. Utelatt gir standard-siten, se SiteHelper::defaultKey()
     * @return array
     * @throws \Throwable
     */
    public function publishDraft(
        int $draftId,
        ?string $site = null,
    ): array {
        $site ??= SiteHelper::defaultKey();

        $user = $this->_requireUser();

        /** @var Entry|null $draft */
        $draft = Entry::find()
            ->draftId($draftId)
            ->site(SiteHelper::resolve($site)->handle)
            ->status(null)
            ->one();

        if ($draft === null) {
            throw new ToolCallException(sprintf('Draft %d not found on site "%s".', $draftId, $site));
        }

        $canonical = $draft->getIsUnpublishedDraft() ? $draft : $draft->getCanonical();

        if (!Craft::$app->getElements()->canSave($canonical, $user)) {
            throw new ToolCallException('The API token user is not allowed to publish this entry.');
        }

        $draft->enabled = true;
        $draft->setScenario(Element::SCENARIO_LIVE);

        if (!$draft->validate()) {
            throw new ToolCallException('The draft is not ready to publish: ' . $this->_formatErrors($draft));
        }

        /** @var Entry $entry */
        $entry = Craft::$app->getDrafts()->applyDraft($draft);

        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'status' => $entry->getStatus(),
            'url' => $entry->getUrl(),
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Returnerer den autentiserte brukeren.
     *
     * @return User
     * @throws ToolCallException hvis ingen bruker er autentisert
     */
    private function _requireUser(): User
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            throw new ToolCallException('Not authenticated.');
        }

        return $user;
    }

    /**
     * Finner entry-typen en ny entry skal få.
     *
     * Uten eksplisitt handle brukes seksjonens første type. Det er entydig for
     * seksjoner med bare én type, men et gjett for seksjoner med flere — der
     * kreves handlen.
     *
     * @param Section $section
     * @param string|null $handle Ønsket entry-type-handle
     * @return EntryType
     * @throws ToolCallException ved ukjent eller utelatt nødvendig handle
     */
    private function _resolveEntryType(Section $section, ?string $handle): EntryType
    {
        $types = $section->getEntryTypes();

        if ($handle === null) {
            if (count($types) > 1) {
                throw new ToolCallException(sprintf(
                    'Section "%s" has more than one entry type — pass "entryType". Valid handles: %s.',
                    $section->handle,
                    implode(', ', array_map(static fn(EntryType $type) => $type->handle, $types)),
                ));
            }

            return $types[0];
        }

        foreach ($types as $type) {
            if ($type->handle === $handle) {
                return $type;
            }
        }

        throw new ToolCallException(sprintf(
            'Unknown entry type "%s" in section "%s". Valid handles: %s.',
            $handle,
            $section->handle,
            implode(', ', array_map(static fn(EntryType $type) => $type->handle, $types)),
        ));
    }

    /**
     * Validerer en forelder-ID for en structure-seksjon.
     *
     * @param Section $section Seksjonen entryen hører til
     * @param int $parentId Ønsket forelder
     * @param string $site Språkkode
     * @return int Den validerte forelder-ID-en
     * @throws ToolCallException hvis seksjonen ikke er en structure, eller forelderen ikke finnes i den
     * @since 1.1.0
     */
    private function _resolveParentId(Section $section, int $parentId, string $site): int
    {
        if ($section->type !== Section::TYPE_STRUCTURE) {
            throw new ToolCallException(sprintf(
                'Section "%s" is a %s section and has no hierarchy, so parentId cannot be set. Only sections with "isStructure": true in get_content_model accept a parent.',
                $section->handle,
                $section->type,
            ));
        }

        /** @var Entry|null $parent */
        $parent = Entry::find()
            ->id($parentId)
            ->section($section->handle)
            ->site(SiteHelper::resolve($site)->handle)
            ->drafts(null)
            ->status(null)
            ->one();

        if ($parent === null) {
            throw new ToolCallException(sprintf(
                'Parent entry %d not found in section "%s" on site "%s". The parent must be an existing entry in the same section.',
                $parentId,
                $section->handle,
                $site,
            ));
        }

        if ($parent->getIsDraft()) {
            throw new ToolCallException(sprintf(
                'Entry %d is an unpublished draft and cannot be used as a parent. Publish it first.',
                $parentId,
            ));
        }

        return $parentId;
    }

    /**
     * Formaterer valideringsfeil per felt.
     *
     * @param Entry $entry
     * @return string
     */
    private function _formatErrors(Entry $entry): string
    {
        $messages = [];

        foreach ($entry->getErrors() as $attribute => $errors) {
            $messages[] = $attribute . ': ' . implode(' ', $errors);
        }

        return $messages !== [] ? implode('; ', $messages) : 'unknown error';
    }

    /**
     * Standard-resultat for utkast.
     *
     * @param Entry $draft
     * @return array
     */
    private function _draftResult(Entry $draft): array
    {
        return [
            'draftId' => $draft->draftId,
            'elementId' => $draft->id,
            'canonicalId' => $draft->getIsUnpublishedDraft() ? null : $draft->getCanonicalId(),
            'title' => $draft->title,
            'cpEditUrl' => $draft->getCpEditUrl(),
            'note' => 'Draft saved. Call publish_draft with draftId to make it live, or review it in the control panel.',
        ];
    }
}
