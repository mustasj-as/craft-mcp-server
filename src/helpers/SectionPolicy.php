<?php

namespace Mustasj\CraftMcp\helpers;

use Mcp\Exception\ToolCallException;
use Mustasj\CraftMcp\Module;

/**
 * Hvilke seksjoner MCP eksponerer, og hva som er lov å gjøre med hver av dem.
 *
 * Dette er en bevisst allowlist, ikke en sikkerhetsgrense: Crafts permissions
 * avgjør hva tokenets bruker faktisk får gjøre. Allowlisten avgrenser hvilke
 * seksjoner som i det hele tatt er ment for maskinell redigering — nødvendig
 * fordi `canSave()` alltid er sann for superadmins, og et superadmin-token
 * ellers ville nådd enhver seksjon i systemet.
 *
 * **Policyen er per operasjon, ikke per seksjon.** Grunnen er målt, ikke
 * teoretisk: i ett prosjekt eies eiendomsseksjonen av en ekstern XML-feed, og
 * domenereglene sier at eiendommer aldri skal OPPRETTES fra CMS-siden, mens
 * redaksjonelle RETTELSER er hele poenget. En flat `string[]` — som denne
 * modulen hadde før — kan ikke uttrykke det, og ga i praksis stikk motsatt
 * resultat: oppretting lyktes, lesing ble avvist. Se
 * `2026-09-14-craft-mcp-stresstest.md` i det prosjektet.
 *
 * @author Mustasj AS
 * @since 1.0.0
 */
class SectionPolicy
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    public const READ = 'read';
    public const CREATE = 'create';
    public const UPDATE = 'update';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returnerer seksjonshandlene som tillater en gitt operasjon.
     *
     * Brukes både til å bygge enum-ene i verktøyskjemaene og til å håndheve
     * lista i kode.
     *
     * @param string $operation {@see READ}, {@see CREATE} eller {@see UPDATE}
     * @return string[]
     */
    public static function allowing(string $operation): array
    {
        $handles = [];

        foreach (Module::getInstance()->sections as $handle => $policy) {
            if (self::_permits($policy, $operation)) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * Sjekker at en operasjon er tillatt på en seksjon.
     *
     * Enum-ene i verktøyskjemaene fanger dette for klienter som validerer mot
     * skjemaet, men skjemaet er ikke en grense — en rå HTTP-klient kan sende
     * hva som helst. Derfor håndheves lista også i kode.
     *
     * @param string $handle Seksjonshandle
     * @param string $operation {@see READ}, {@see CREATE} eller {@see UPDATE}
     * @return void
     * @throws ToolCallException hvis operasjonen ikke er tillatt
     */
    public static function assertAllowed(string $handle, string $operation): array|null
    {
        $allowed = self::allowing($operation);

        if (!in_array($handle, $allowed, true)) {
            // Skill mellom «finnes ikke i det hele tatt» og «finnes, men ikke
            // for denne operasjonen». Uten skillet ville en assistent som får
            // nei på create_entry prøve igjen med et annet seksjonsnavn, i
            // stedet for å forstå at seksjonen er lesbar men feed-eid.
            $known = array_key_exists($handle, Module::getInstance()->sections);

            throw new ToolCallException($known
                ? sprintf(
                    'Section "%s" does not allow "%s" over MCP. Allowed for %s: %s.',
                    $handle,
                    $operation,
                    $operation,
                    implode(', ', $allowed),
                )
                : sprintf(
                    'Section "%s" is not exposed over MCP. Available sections: %s.',
                    $handle,
                    implode(', ', $allowed),
                ));
        }

        return null;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Tolker én seksjons policy.
     *
     * Godtar både den fulle formen (`['read' => true, 'create' => false]`) og
     * kortformen `true`, som betyr «alt lov». Kortformen finnes fordi de
     * fleste seksjoner i de fleste prosjekter er helt vanlige, og en
     * konfigurasjon der hver linje er tre nøkler blir uleselig.
     *
     * @param array<string, bool>|bool $policy
     * @param string $operation
     * @return bool
     */
    private static function _permits(array|bool $policy, string $operation): bool
    {
        if (is_bool($policy)) {
            return $policy;
        }

        return (bool)($policy[$operation] ?? false);
    }
}
