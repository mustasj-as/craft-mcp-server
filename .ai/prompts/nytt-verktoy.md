# Oppgavebrief: nytt MCP-verktøy

Les [../business-rules.md](../business-rules.md) og
[../coding-standards.md](../coding-standards.md) først.

## Avgjør først: hører verktøyet i pakken eller i prosjektet?

| Spørsmål | Svar ja → |
|---|---|
| Ville det gitt mening i et hvilket som helst Craft-nettsted? | **Pakken** |
| Er det avhengig av en bestemt seksjon, felttype eller datakilde? | **Prosjektet**, via `toolPaths` |

Et verktøy som heter noe med prosjektets domene (`searchProperties`,
`searchRecipes`) hører nesten alltid i prosjektet. Er du i tvil, legg det i
prosjektet — det er billig å flytte inn i pakken senere, dyrt å flytte ut.

## Verktøy i prosjektet

Vanlig klasse i mappa `toolPaths` peker på, med `#[McpTool]` og `#[Schema]`.
Konstanter er helt greit der. Ferdig.

## Verktøy i pakken

1. **Metoden** legges i riktig klasse under `src/tools/`. Ingen attributter.
2. **Registrering** i `ServerFactory::_addPackageTools()` med `addTool()`:
   navn, beskrivelse og et eksplisitt `inputSchema`. Enum-er som avhenger av
   prosjektet bygges fra `SectionPolicy::allowing()`, `SiteHelper::keys()`
   eller `Module::getInstance()`.
3. **Håndhev i kode**, ikke bare i skjemaet: `SectionPolicy::assertAllowed()`
   for seksjoner, `SiteHelper::resolve()` for sites, `EntryAccess` for lesing.
4. **Skriver verktøyet noe? Da lager det utkast.** Se business-rules.
5. **Beskrivelsen er engelsk**, og skriver seg til en språkmodell: si hva
   verktøyet gjør, hva som er påkrevd, og hva som IKKE går an. Nevn
   `get_content_model` når verktøyet trenger felthandles.
6. **Feilmeldinger lister gyldige verdier.**

## Sjekkliste før du er ferdig

- [ ] `php -l` på hver endret fil
- [ ] `tools/list` viser verktøyet med riktig skjema
- [ ] Ett ekte `tools/call` mot ekte data, med målt resultat
- [ ] Et negativt tilfelle: ugyldig seksjon/site avvises med en nyttig melding
- [ ] Ryddet opp i utkast eller andre spor testen lagde
- [ ] Er dette en brekkende endring for et konsumerende prosjekt?
      Se [../git-workflow.md](../git-workflow.md) om versjonering
- [ ] Oppdatert verktøylista i rotas `README.md` og i
      [../project-overview.md](../project-overview.md)
