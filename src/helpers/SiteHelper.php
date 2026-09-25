<?php

namespace Mustasj\CraftMcp\helpers;

use Craft;
use craft\models\Site;
use Mcp\Exception\ToolCallException;
use Mustasj\CraftMcp\Module;

/**
 * Oversetter mellom MCP-verktøyenes språkkoder og Crafts site-handles.
 *
 * Kartet kommer fra modulkonfigurasjonen (`sites`), ikke fra en konstant —
 * prosjektene er ulike: ett har «default»/«english», et annet
 * «norwegian»/«english» med engelsk som siteId 1 og norsk som primær.
 *
 * **Deaktiverte sites avvises.** Et prosjekt kan ha sites stående i project
 * config med `enabled: '0'` — ett prosjekt har tre — og en konfigurasjon som
 * ved et uhell nevner en av dem skal ikke gi en stille skriving til en site
 * som ikke serverer noe. Sjekken ligger her fordi kartet er
 * prosjektkonfigurasjon og dermed feilbarlig; den forrige versjonen av denne
 * hjelperen var trygg bare fordi kartet tilfeldigvis hadde to oppføringer.
 *
 * @author Mustasj AS
 * @since 1.0.0
 */
class SiteHelper
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returnerer språkkodene verktøyene godtar.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(Module::getInstance()->sites);
    }

    /**
     * Returnerer språkkoden verktøyene bruker når `site` utelates.
     *
     * `defaultSite` fra konfigurasjonen hvis satt, ellers koden som peker på
     * Craft-installasjonens primære site. Se {@see Module::$defaultSite} for
     * hvorfor det ikke er første nøkkel i kartet.
     *
     * @return string
     */
    public static function defaultKey(): string
    {
        $module = Module::getInstance();

        if ($module->defaultSite !== null) {
            return $module->defaultSite;
        }

        $primary = array_search(Craft::$app->getSites()->getPrimarySite()->handle, $module->sites, true);

        // Primærsiten er ikke eksponert i `sites` — da finnes det ikke noe
        // «riktig» svar, og første nøkkel er bedre enn en feil på hvert kall.
        return $primary !== false ? $primary : (string)array_key_first($module->sites);
    }

    /**
     * Returnerer Craft-siten for en språkkode.
     *
     * @param string $site Språkkode, f.eks. «nb» eller «en»
     * @return Site
     * @throws ToolCallException hvis koden er ukjent, siten mangler eller er deaktivert
     */
    public static function resolve(string $site): Site
    {
        $map = Module::getInstance()->sites;
        $handle = $map[$site] ?? null;

        if ($handle === null) {
            throw new ToolCallException(sprintf(
                'Unknown site "%s". Use one of: %s.',
                $site,
                implode(', ', array_keys($map)),
            ));
        }

        // `withDisabled: true` er nødvendig: uten den returnerer Craft null
        // for en deaktivert site, og feilmeldingen blir «is not configured» —
        // som sender den som feilsøker til project config for å lete etter en
        // site som står der hele tiden. Vi vil skille «finnes ikke» fra
        // «finnes, men er slått av».
        $craftSite = Craft::$app->getSites()->getSiteByHandle($handle, withDisabled: true);

        if ($craftSite === null) {
            throw new ToolCallException(sprintf('Site "%s" is not configured.', $site));
        }

        if (!$craftSite->enabled) {
            throw new ToolCallException(sprintf(
                'Site "%s" (%s) is disabled in this Craft install and cannot be read or written.',
                $site,
                $handle,
            ));
        }

        return $craftSite;
    }

    /**
     * Returnerer språkkoden for en Craft-site (motsatt vei av {@see resolve()}).
     *
     * @param Site $site
     * @return string
     */
    public static function key(Site $site): string
    {
        $key = array_search($site->handle, Module::getInstance()->sites, true);

        return $key !== false ? $key : $site->handle;
    }
}
