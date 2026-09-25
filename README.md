# mustasj/craft-mcp-server

MCP-server for Craft CMS 5. Eksponerer innholdet i en Craft-installasjon over
Model Context Protocol (streamable HTTP), med personlige bearer-tokens knyttet
til Craft-brukere.

Den er en Yii-modul, ikke en plugin — se «Hvorfor modul» nederst.

## Installasjon

```sh
composer require mustasj/craft-mcp-server
```

Så i `config/app.php`:

```php
'modules' => [
    'mcp-api' => [
        'class' => \Mustasj\CraftMcp\Module::class,
        'serverName' => 'Mitt nettsted Content API',
        'tokenPrefix' => 'mitt_',
        'sites' => ['nb' => 'default', 'en' => 'english'],
        'sections' => [
            'artikler' => true,
            'produkter' => ['read' => true, 'create' => false, 'update' => true],
        ],
        'categoryGroups' => ['hovedkategori'],
        'instructionsPath' => '@root/.ai/mcp/instructions.md',
        'toolPaths' => ['@root/modules/mcpverktoy'],
    ],
],
'bootstrap' => ['mcp-api'],
```

Det er hele oppsettet. Modulen registrerer selv ruta (`/mcp`), permissionen,
CP-fanen og tokens-tabellen — ingen filer i prosjektet skal røres utover
`app.php`.

Utsted et token:

```sh
php craft mcp-api/tokens/create <brukernavn>
```

## Konfigurasjon

| Nøkkel | Default | Hva den gjør |
|---|---|---|
| `serverName` | `Craft Content API` | Navnet klienten ser i `initialize` |
| `serverDescription` | generisk | Én setning om hva serveren er |
| `serverVersion` | `1.0.0` | Rapporteres til klienten |
| `instructionsPath` | `null` | Markdown-fil med `instructions`. Craft-alias tillatt |
| `tokenPrefix` | `craft_` | **Må være unikt per prosjekt.** Små bokstaver/tall + `_` |
| `routePath` | `mcp` | URL-stien endepunktet svarer på |
| `tokenInPath` | `true` | Om `/mcp/t/<token>` også registreres. Token-fanen viser den ikke lenger — tokenet sendes som header — men den virker for oppkoblinger som alt bruker den. Sett `false` når ingen gjør det |
| `sites` | — | **Påkrevd.** Språkkode → Craft site-handle |
| `defaultSite` | `null` | Språkkoden som brukes når `site` utelates. Tomt gir koden for Craft-installasjonens **primære** site — ikke første nøkkel i `sites`, og ikke siteId 1 |
| `sections` | — | **Påkrevd.** Seksjonshandle → policy |
| `categoryGroups` | `[]` | Kategorigrupper som eksponeres |
| `fieldNotes` | `[]` | Felthandle → merknad, for felt der lagret verdi ≠ vist verdi |
| `toolPaths` | `[]` | Mapper med prosjektets egne verktøyklasser |

### `sections` — policy per operasjon, ikke per seksjon

```php
'sections' => [
    'artikler' => true,                                              // alt lov
    'produkter' => ['read' => true, 'create' => false, 'update' => true],
],
```

Formen er ikke overkonstruert; den er målt. Med en flat `string[]` — som
modulen hadde før — styrte samme liste både lesing og skriving, og i ett
prosjekt ga det stikk motsatt resultat av domenereglene: eiendommer eid av en
ekstern feed kunne **opprettes**, men ikke **leses**. Redaksjonen trenger det
omvendte.

Allowlisten er ikke en sikkerhetsgrense — Crafts permissions avgjør hva
tokenets bruker faktisk får gjøre. Den avgrenser hva som er *ment* for
maskinell redigering, som er nødvendig fordi `canSave()` alltid er sann for
superadmins.

### `instructionsPath` — pek den på `.ai/`

MCP har to mekanismer for kontekst, og de er ikke like:

- **`instructions`** sendes i hver `initialize` og koster kontekst hos alle
  klienter. Hold den kort: de tre–fire tingene som faktisk går galt.
- **Resources** hentes ved behov, og er rett sted for stilguide og ordbok.
  (Ikke implementert ennå.)

Peker `instructionsPath` inn i prosjektets `.ai/`-mappe, arver serveren
vedlikeholdsregelen som alt gjelder der — instruksjonene kan ikke råtne uten at
dokumentasjonen også gjør det.

### `toolPaths` — prosjektets egne verktøy

Pakken eier de generiske verktøyene. Domenespesifikke verktøy (en
`RecipeTools`, en `PropertyTools`) hører i prosjektet, og legges i en mappe som
nevnes her. De skrives med vanlige `#[McpTool]`-attributter og oppdages
automatisk.

Dette er sømmet som avgjør om pakken holder: uten det må hvert prosjekt som
trenger ett eget verktøy forke pakken.

## Verktøy

`list_entries`, `get_entry`, `create_entry`, `update_entry`, `publish_draft`,
`list_categories`, `get_content_model`, `search_assets`, `list_asset_folders`,
`update_asset`.

Skriveverktøyene lager **utkast**. Ingenting går live uten `publish_draft`.

## Token-fanen

Fanen «MCP-tokens» på brukersiden kommer fra pakken. Flyten:

1. **Opprett** — navn (unikt per bruker, maks 40 tegn) og gyldighet fra en
   fast liste, 1 uke til 12 mnd, 3 mnd forhåndsvalgt. Tokens som aldri
   utløper kan bare lages fra konsollen.
2. **Klarteksten** vises én gang, i stedet for skjemaet.
3. **«Koble til din egen AI»** — oppskrifter for Claude Desktop (URL og
   header hver for seg, ikke tokenet i URL-en), Claude Code, Copilot, Codex
   og Gemini. Skjult uten tokens, lukket til vanlig, åpen og ferdig utfylt
   rett etter opprettelse.
4. **Tokenlista.**

Tokens lages bare av brukeren selv. Admin ser andres faner med en bryter
«Kan bruke MCP» (permission på brukernivå; Craft Pro), og kan trekke
tilbake andres tokens. Kommer tilgangen fra admin-rollen eller en gruppe,
sier fanen det i stedet for å vise bryteren.

**Mister en bruker tilgangen — hvor som helst i CP-en — trekkes tokenene
tilbake** på slutten av requesten. Endepunktet avviser dem uansett; dette
hindrer at glemte tokens virker igjen den dagen tilgangen gis tilbake.

## To designvalg verdt å kjenne

**Pakkens verktøy registreres med `addTool()` og eksplisitte skjemaer, ikke med
attributter.** SDK-ens attributt-variant (`#[Schema(enum: …)]`) krever
konstante uttrykk, og et PHP-attributt kan ikke lese modulkonfigurasjon. Så
lenge seksjonslista lå i et attributt, måtte den være en klassekonstant — og da
måtte hvert prosjekt endre filer i pakken. Prosjektets egne verktøy bruker
attributter, fordi der er konstanter riktig.

**Tokens-tabellen opprettes av `Tokens::ensureTable()`, ikke av en migrering.**
Crafts `MigrateController` kjenner sporene `craft`, `content` og
`plugin:<handle>`; det finnes ikke noe `module:`-spor, så en modul kan ikke eie
migreringer. Alternativet var å be hver installasjon kopiere en migreringsfil
inn i `migrations/`.

## Hvorfor modul, ikke plugin

En plugin ville løst migreringspunktet over, og gitt et innstillings-UI. Prisen
er plugin-lifecycle, `Settings`-modell, project config-oppføring og
installasjons-/avinstallasjonsløp — for et internt verktøy med en håndfull
installasjoner er det overhead uten gevinst. Composer-distribusjon krever ikke
plugin-formen; den krever bare et repo.

Blir pakken en dag noe som skal ut av huset, er plugin riktig, og
konfigurasjonssnittet her oversettes rett til en `Settings`-modell.

## For utviklere og AI-agenter

Konvensjoner, arkitektur, domeneregler og beslutningslogg ligger i
**[`.ai/`](.ai/)**. Ny dokumentasjon rettet mot utviklere eller AI-agenter
hører der, ikke i denne fila — start med [`.ai/README.md`](.ai/README.md).
