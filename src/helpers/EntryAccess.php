<?php

namespace Mustasj\CraftMcp\helpers;

use Craft;
use craft\elements\Entry;
use Mcp\Exception\ToolCallException;

/**
 * Tilgangssjekk for MCP-ets leseverktøy.
 *
 * Leseverktøyene henter entries med `status(null)` slik at et agent-kall kan
 * lese innhold det nettopp har opprettet, før det er publisert. Uten en
 * eksplisitt sjekk ville et hvilket som helst token med MCP-permission dermed
 * kunne lese upublisert og deaktivert innhold — uavhengig av hva brukeren
 * faktisk har lesetilgang til i Craft. Skriveverktøyene sjekker allerede
 * `canSave`/`canCreateDrafts`; dette er motstykket for lesing.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class EntryAccess
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Sjekker at den autentiserte brukeren kan se en entry som ikke er live.
     *
     * Publiserte entries slipper gjennom uten sjekk — de er offentlige på
     * nettstedet uansett.
     *
     * @param Entry $entry
     * @return Entry
     * @throws ToolCallException hvis brukeren ikke har lesetilgang
     */
    public static function requireViewable(Entry $entry): Entry
    {
        if ($entry->getStatus() === Entry::STATUS_LIVE) {
            return $entry;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !Craft::$app->getElements()->canView($entry, $user)) {
            throw new ToolCallException(sprintf(
                'Entry %d is not published, and the API token user does not have permission to view it.',
                $entry->id,
            ));
        }

        return $entry;
    }
}
