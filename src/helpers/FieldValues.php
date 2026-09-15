<?php

namespace Mustasj\CraftMcp\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\fields\BaseOptionsField;
use craft\fields\Categories;
use craft\fields\Matrix;
use craft\helpers\ArrayHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use Mcp\Exception\ToolCallException;

/**
 * Normaliserer og validerer feltverdier fra MCP-verktøy mot en field layout.
 *
 * Verktøyene tar imot enkle JSON-verdier (kategori-slugs, Matrix-blokker som
 * lister, nedtrekksverdier som strenger) og denne helperen oversetter dem til
 * Crafts interne format — og avviser det som ikke er gyldig.
 *
 * Delt mellom entry- og asset-skriving, slik at valideringen er den samme
 * uansett elementtype.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.1.0
 */
class FieldValues
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Setter feltverdier på en entry, med normalisering per felttype.
     *
     * @param ElementInterface $element Elementet verdiene skrives til
     * @param array $fields Feltverdier per handle
     * @return void
     * @throws ToolCallException ved ukjent felthandle eller ugyldige verdier
     */
    public static function apply(ElementInterface $element, array $fields): void
    {
        $normalized = self::_normalizeFieldValues($element->getFieldLayout(), $fields, '');

        foreach ($normalized as $handle => $value) {
            $element->setFieldValue($handle, $value);
        }
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Normaliserer feltverdier mot en field layout: Matrix-lister → Crafts
     * blokk-format, kategori-slugs → ID-er.
     *
     * Rekursiv, fordi Matrix-blokker kan inneholde egne Matrix-felt (her:
     * `liste` → `ingrediens` og `stegliste` → `steg`). Uten rekursjon havner
     * nøstede blokker i Crafts input i feil format og forsvinner uten feil.
     *
     * @param FieldLayout|null $layout Layouten verdiene hører til
     * @param array $fields Feltverdier per handle
     * @param string $path Felt-sti til feilmeldinger («» på toppnivå)
     * @return array
     * @throws ToolCallException ved ukjent felthandle eller ugyldige verdier
     */
    private static function _normalizeFieldValues(?FieldLayout $layout, array $fields, string $path): array
    {
        $normalized = [];

        foreach ($fields as $handle => $value) {
            $field = $layout?->getFieldByHandle($handle);

            if ($field === null) {
                $validHandles = array_map(
                    static fn($layoutField) => $layoutField->handle,
                    $layout?->getCustomFields() ?? [],
                );
                throw new ToolCallException(sprintf(
                    'Unknown field "%s"%s. Valid handles: %s.',
                    $handle,
                    $path !== '' ? sprintf(' in "%s"', $path) : '',
                    implode(', ', $validHandles),
                ));
            }

            if ($field instanceof Matrix && is_array($value)) {
                $value = self::_normalizeMatrixValue($field, $value, self::_appendPath($path, $handle));
            } elseif ($field instanceof Categories && is_array($value)) {
                $value = self::_resolveCategorySlugs($field, $value);
            } elseif ($field instanceof BaseOptionsField) {
                self::_validateOptionValue($field, $value, self::_appendPath($path, $handle));
            }

            $normalized[$handle] = $value;
        }

        return $normalized;
    }

    /**
     * Sjekker at en verdi til et felt med lukket verdisett (Dropdown,
     * Checkboxes, Radio, MultiSelect) faktisk er en av de gyldige verdiene.
     *
     * Craft lagrer en ukjent verdi rått uten å klage, og feltet rendres da
     * tomt. `update_entry` returnerte suksess og `publish_draft` validerte
     * uten innsigelser — en feil på den formen nådde produksjon 2026-08-31.
     *
     * @param BaseOptionsField $field Feltet verdien skrives til
     * @param mixed $value Verdien som skrives
     * @param string $path Felt-sti til feilmeldinger
     * @return void
     * @throws ToolCallException ved ugyldig verdi
     * @since 1.1.0
     */
    private static function _validateOptionValue(BaseOptionsField $field, mixed $value, string $path): void
    {
        $valid = array_column($field->options, 'value');
        $given = is_array($value) ? $value : [$value];

        foreach ($given as $item) {
            // Tom verdi tømmer feltet — det er en legitim operasjon.
            if ($item === null || $item === '') {
                continue;
            }

            if (!in_array((string)$item, $valid, true)) {
                throw new ToolCallException(sprintf(
                    'Invalid value "%s" for field "%s". Valid values: %s.%s',
                    is_scalar($item) ? (string)$item : gettype($item),
                    $path,
                    implode(', ', array_filter($valid, static fn($option) => $option !== '')),
                    $field->handle === 'enheter' || $field->handle === 'porsjonsbenevning'
                        ? ' These are codes, not display text — they stay Norwegian on the English site and are translated when rendered.'
                        : '',
                ));
            }
        }
    }

    /**
     * Konverterer en liste av blokker til Crafts Matrix-inputformat, og
     * normaliserer feltverdiene i hver blokk mot blokktypens field layout.
     *
     * @param Matrix $field Matrix-feltet blokkene hører til
     * @param array $blocks Blokker som [{'type': ..., 'fields': {...}}, ...]
     * @param string $path Felt-sti til feilmeldinger
     * @return array
     * @throws ToolCallException ved manglende eller ukjent blokktype, eller ugyldige feltverdier
     */
    private static function _normalizeMatrixValue(Matrix $field, array $blocks, string $path): array
    {
        /** @var EntryType[] $blockTypes */
        $blockTypes = ArrayHelper::index($field->getEntryTypes(), 'handle');
        $normalized = [];

        foreach (array_values($blocks) as $i => $block) {
            if (!is_array($block) || !isset($block['type'])) {
                throw new ToolCallException(sprintf('Each block in Matrix field "%s" must be an object with a "type" key (see get_content_model for block types).', $path));
            }

            $blockType = $blockTypes[$block['type']] ?? null;

            // Craft dropper blokker med ukjent type uten å si fra — bedre å
            // avbryte enn å skrive halvt innhold.
            if ($blockType === null) {
                throw new ToolCallException(sprintf(
                    'Unknown block type "%s" in Matrix field "%s". Valid types: %s.',
                    $block['type'],
                    $path,
                    implode(', ', array_keys($blockTypes)),
                ));
            }

            $data = ['type' => $blockType->handle];

            if (isset($block['title'])) {
                if (!$blockType->hasTitleField) {
                    throw new ToolCallException(sprintf('Block type "%s" in Matrix field "%s" has no title field.', $blockType->handle, $path));
                }

                $data['title'] = $block['title'];
            }

            if (isset($block['enabled'])) {
                $data['enabled'] = (bool)$block['enabled'];
            }

            $data['fields'] = self::_normalizeFieldValues(
                $blockType->getFieldLayout(),
                $block['fields'] ?? [],
                self::_appendPath($path, $blockType->handle),
            );

            $key = isset($block['id']) ? (string)$block['id'] : 'new' . ($i + 1);
            $normalized[$key] = $data;
        }

        return $normalized;
    }

    /**
     * Legger et ledd til en felt-sti brukt i feilmeldinger.
     *
     * @param string $path Sti så langt («» på toppnivå)
     * @param string $segment Neste ledd
     * @return string
     */
    private static function _appendPath(string $path, string $segment): string
    {
        return $path !== '' ? $path . '.' . $segment : $segment;
    }

    /**
     * Slår opp kategori-slugs og returnerer ID-ene, avgrenset til feltets
     * kildegruppe.
     *
     * @param Categories $field
     * @param array $slugs Kategori-slugs (ID-er slippes gjennom urørt)
     * @return int[]
     * @throws ToolCallException hvis en slug ikke finnes
     */
    private static function _resolveCategorySlugs(Categories $field, array $slugs): array
    {
        $group = null;

        if (is_string($field->source) && str_starts_with($field->source, 'group:')) {
            $group = Craft::$app->getCategories()->getGroupByUid(substr($field->source, 6));
        }

        $ids = [];

        foreach ($slugs as $slug) {
            if (is_int($slug) || ctype_digit((string)$slug)) {
                $ids[] = (int)$slug;
                continue;
            }

            $query = Category::find()->slug($slug);

            if ($group !== null) {
                $query->group($group->handle);
            }

            $category = $query->one();

            if ($category === null) {
                throw new ToolCallException(sprintf('No category with slug "%s"%s. Use list_categories to see valid slugs.', $slug, $group !== null ? " in group \"{$group->handle}\"" : ''));
            }

            $ids[] = $category->id;
        }

        return $ids;
    }
}
