<?php

namespace Mustasj\CraftMcp\helpers;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use yii\base\Arrayable;

/**
 * Serialiserer entries (og relaterte elementer) til rene arrays for
 * MCP-verktøyenes JSON-svar.
 *
 * Serialiseringen er generisk: den går gjennom entryens field layout og
 * håndterer verditypene per felttype (Matrix → nestede entries rekursivt,
 * relasjoner → kompakte referanser, CKEditor → HTML-streng, datoer → ISO
 * 8601). Ukjente objekttyper utelates fremfor å feile.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class EntrySerializer
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string[] Felthandles som utelates fra serialisering
     */
    private const EXCLUDED_FIELDS = ['seoSettings'];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Serialiserer en entry med alle feltverdier.
     *
     * @param Entry $entry
     * @param int $depth Hvor dypt nestede elementer (Matrix/relasjoner) følges
     * @return array
     */
    public static function serialize(Entry $entry, int $depth = 3): array
    {
        $data = [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->getStatus(),
            'url' => $entry->getUrl(),
            'site' => SiteHelper::key($entry->getSite()),
            'section' => $entry->getSection()?->handle,
            'entryType' => $entry->getType()->handle,
            'postDate' => $entry->postDate?->format(DateTimeInterface::ATOM),
        ];

        if ($entry->getIsDraft()) {
            $data['isDraft'] = true;
            $data['draftId'] = $entry->draftId;
            $data['canonicalId'] = $entry->getIsUnpublishedDraft() ? null : $entry->getCanonicalId();
        }

        $data['fields'] = self::_serializeFields($entry, $depth);

        return $data;
    }

    /**
     * Serialiserer en kompakt oppsummering av en entry (for listeresultater).
     *
     * @param Entry $entry
     * @return array
     */
    public static function summarize(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->getStatus(),
            'url' => $entry->getUrl(),
            'postDate' => $entry->postDate?->format(DateTimeInterface::ATOM),
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Serialiserer alle custom fields på en entry.
     *
     * @param Entry $entry
     * @param int $depth
     * @return array
     */
    private static function _serializeFields(Entry $entry, int $depth): array
    {
        $fields = [];

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (in_array($field->handle, self::EXCLUDED_FIELDS, true)) {
                continue;
            }

            $fields[$field->handle] = self::_serializeValue($entry->getFieldValue($field->handle), $depth);
        }

        return $fields;
    }

    /**
     * Serialiserer én feltverdi rekursivt.
     *
     * @param mixed $value
     * @param int $depth
     * @return mixed
     */
    private static function _serializeValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof ElementQueryInterface) {
            if ($depth <= 0) {
                return null;
            }

            return array_map(
                static fn(ElementInterface $element) => self::_serializeElement($element, $depth - 1),
                $value->all(),
            );
        }

        if ($value instanceof Collection) {
            if ($depth <= 0) {
                return null;
            }

            return $value
                ->map(static fn(mixed $item) => $item instanceof ElementInterface
                    ? self::_serializeElement($item, $depth - 1)
                    : self::_serializeValue($item, $depth - 1))
                ->values()
                ->all();
        }

        if ($value instanceof ElementInterface) {
            return $depth > 0 ? self::_serializeElement($value, $depth - 1) : null;
        }

        if (is_array($value)) {
            return array_map(static fn(mixed $item) => self::_serializeValue($item, $depth), $value);
        }

        if ($value instanceof \Stringable) {
            return (string)$value;
        }

        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        return null;
    }

    /**
     * Serialiserer et relatert/nestet element til en kompakt representasjon.
     *
     * @param ElementInterface $element
     * @param int $depth
     * @return array
     */
    private static function _serializeElement(ElementInterface $element, int $depth): array
    {
        if ($element instanceof Entry) {
            // Nestede entries (Matrix-blokker) serialiseres med feltinnhold.
            return [
                'id' => $element->id,
                'type' => $element->getType()->handle,
                'title' => $element->title,
                'fields' => $depth >= 0 ? self::_serializeFields($element, $depth) : [],
            ];
        }

        if ($element instanceof Asset) {
            return [
                'id' => $element->id,
                'kind' => $element->kind,
                'filename' => $element->getFilename(),
                'url' => $element->getUrl(),
                'alt' => $element->alt,
            ];
        }

        if ($element instanceof Category) {
            return [
                'id' => $element->id,
                'group' => $element->getGroup()->handle,
                'title' => $element->title,
                'slug' => $element->slug,
            ];
        }

        if ($element instanceof User) {
            return [
                'id' => $element->id,
                'name' => $element->getName(),
            ];
        }

        return [
            'id' => $element->id,
            'title' => (string)$element,
        ];
    }
}
