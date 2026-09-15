<?php

namespace Mustasj\CraftMcp\tools;

use Craft;
use craft\base\Field;
use craft\fields\BaseOptionsField;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\Section;
use Mustasj\CraftMcp\helpers\SectionPolicy;
use Mustasj\CraftMcp\Module;

/**
 * MCP-verktøy for introspeksjon av innholdsmodellen.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class ContentModelTools
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Beskriver innholdsmodellen: seksjoner, entry types, felter og
     * kategorigrupper.
     *
     * Kan avgrenses til én seksjon. Grunnen er målt: i et mellomstort
     * prosjekt returnerer det fulle svaret 51,7 kB (~13k tokens), og
     * instruksjonene ber
     * klienten kalle dette FØRST — altså før arbeidet i det hele tatt er
     * begynt. På et lite nettsted er hele modellen grei; på et stort er den
     * en avgift på hver eneste sesjon.
     *
     * @param string|null $only Bare denne seksjonen, eller null for alle
     * @return array
     */
    public function getContentModel(?string $only = null): array
    {
        $sections = [];

        if ($only !== null) {
            SectionPolicy::assertAllowed($only, SectionPolicy::READ);
        }

        $handles = $only !== null ? [$only] : SectionPolicy::allowing(SectionPolicy::READ);

        foreach ($handles as $handle) {
            $section = Craft::$app->getEntries()->getSectionByHandle($handle);

            if ($section === null) {
                continue;
            }

            // `hasUrls` er per site-innstilling; alle sites deler oppsettet
            // her, så den første er representativ.
            $siteSettings = $section->getSiteSettings();
            $firstSiteSetting = reset($siteSettings);
            $hasUrls = $firstSiteSetting !== false && $firstSiteSetting->hasUrls;

            $sections[] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'isStructure' => $section->type === Section::TYPE_STRUCTURE,
                'hasUrls' => $hasUrls,
                'note' => $this->_sectionNote($section, $hasUrls),
                'entryTypes' => array_map(
                    fn(EntryType $entryType) => $this->_describeEntryType($entryType),
                    $section->getEntryTypes(),
                ),
            ];
        }

        // Alt-tekstens oversettbarhet er en volum-innstilling, ikke et felt,
        // så den fanges ikke av felt-introspeksjonen over. Den avgjør om en
        // engelsk alt-tekst overskriver den norske.
        $volumes = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $altTranslatable = $volume->altTranslationMethod !== Field::TRANSLATION_METHOD_NONE;

            $volumes[] = [
                'handle' => $volume->handle,
                'name' => $volume->name,
                'altTranslationMethod' => $volume->altTranslationMethod,
                'altTranslatable' => $altTranslatable,
                'note' => $altTranslatable
                    ? 'Alt text can differ per language — write it on the site you are working on.'
                    : 'Alt text is shared across sites: writing it on one site overwrites the other. Do not translate it.',
            ];
        }

        $categoryGroups = [];

        foreach (Module::getInstance()->categoryGroups as $handle) {
            $group = Craft::$app->getCategories()->getGroupByHandle($handle);

            if ($group === null) {
                continue;
            }

            $categoryGroups[] = [
                'handle' => $group->handle,
                'name' => $group->name,
            ];
        }

        return [
            'sites' => [
                'nb' => 'Norwegian (primary)',
                'en' => 'English',
            ],
            'sections' => $sections,
            'categoryGroups' => $categoryGroups,
            'assets' => $volumes,
            'notes' => [
                'Matrix field values are arrays of blocks: [{"type": "<entryTypeHandle>", "title": "...", "fields": {...}}, ...]. Setting a Matrix field replaces its entire content. Pass "title" only for block types with "hasTitleField": true.',
                'Blocks nest: a Matrix field listed inside a block type takes the same array-of-blocks shape as a top-level Matrix field.',
                'Category fields accept arrays of category slugs.',
                'Asset fields accept arrays of existing asset IDs.',
            ],
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Bygger merknaden på en seksjon: hierarki og manglende URL-er er de to
     * tingene en assistent ellers ikke kan utlede, og begge har ført til
     * bortkastet arbeid tidligere.
     *
     * @param Section $section
     * @param bool $hasUrls Om seksjonen har URL-er
     * @return string|null
     * @since 1.1.0
     */
    private function _sectionNote(Section $section, bool $hasUrls): ?string
    {
        $notes = [];

        if ($section->type === Section::TYPE_STRUCTURE) {
            $notes[] = 'Entries are arranged in a hierarchy — pass parentId to create_entry or update_entry to place one under another.';
        }

        if (!$hasUrls) {
            $notes[] = 'This section has no URLs, so "url" is always null for its entries. That is expected, not a missing value — do not try to link to them.';
        }

        return $notes !== [] ? implode(' ', $notes) : null;
    }

    /**
     * Beskriver en entry type med feltene i field layouten.
     *
     * @param EntryType $entryType
     * @param int $depth Rekursjonsvern for nestede Matrix-typer
     * @return array
     */
    private function _describeEntryType(EntryType $entryType, int $depth = 2): array
    {
        $fields = [];

        foreach ($entryType->getFieldLayout()->getCustomFieldElements() as $layoutElement) {
            $field = $layoutElement->getField();

            $description = [
                'handle' => $field->handle,
                'name' => $field->name,
                'type' => (new \ReflectionClass($field))->getShortName(),
                'required' => (bool)$layoutElement->required,
            ];

            // Felt med `translationMethod: none` deles mellom sitene — en
            // skriving på engelsk endrer også den norske verdien.
            if ($field->translationMethod === Field::TRANSLATION_METHOD_NONE) {
                $description['sharedBetweenSites'] = true;
                $description['note'] = 'This field is not translatable: nb and en share one value, so writing it on one site changes both.';
            }

            // Lukkede verdisett må listes ut — uten dem kan en assistent kun
            // gjette, og ugyldige verdier lagres uten å bli avvist.
            if ($field instanceof BaseOptionsField) {
                $description['validValues'] = array_values(array_filter(array_map(
                    static fn(array $option) => $option['value'] !== '' ? [
                        'value' => $option['value'],
                        'label' => $option['label'],
                    ] : null,
                    $field->options,
                )));
                $description['multiple'] = $field->getIsMultiOptionsField();
            }

            $notes = Module::getInstance()->fieldNotes;

            if (isset($notes[$field->handle])) {
                $description['note'] = $notes[$field->handle];
            }

            if ($field instanceof Matrix && $depth > 0) {
                $description['blockTypes'] = array_map(
                    fn(EntryType $blockType) => $this->_describeEntryType($blockType, $depth - 1),
                    $field->getEntryTypes(),
                );
            }

            $fields[] = $description;
        }

        return [
            'handle' => $entryType->handle,
            'name' => $entryType->name,
            'hasTitleField' => $entryType->hasTitleField,
            'fields' => $fields,
        ];
    }
}
