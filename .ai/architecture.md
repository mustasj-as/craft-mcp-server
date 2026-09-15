# Arkitektur

## Stack

| Lag | Teknologi |
|---|---|
| Form | Yii-modul distribuert som Composer-pakke (`type: library`) — **ikke** en Craft-plugin, se [ADR 0002](decisions/0002-modul-ikke-plugin.md) |
| PHP | ≥ 8.2 (`platform` pinnet til 8.2 i `composer.json`) |
| Craft | `craftcms/cms ^5.0` |
| MCP | `mcp/sdk ^0.4` |
| PSR-7 | `nyholm/psr7`, `nyholm/psr7-server` |
| Discovery | `symfony/finder` |
| Namespace | `Mustasj\CraftMcp\` → `src/` |

## Mappestruktur

17 filer, ~3 600 linjer.

```
src/
├── Module.php              konfigurasjon, ruter, permission, CP-hooks
├── controllers/
│   ├── ServerController    /mcp — autentisering + PSR-7-brua til SDK-en
│   └── TokensController    CP-skjermen «MCP-tokens»
├── console/TokensController  create / list / revoke
├── services/
│   ├── ServerFactory       bygger serveren og registrerer verktøyene
│   └── Tokens              utsteding, validering, tabelloppretting
├── helpers/
│   ├── SectionPolicy       hvilke seksjoner, og hva som er lov på hver
│   ├── SiteHelper          språkkode ↔ Craft site, avviser deaktiverte
│   ├── EntryAccess         lesetilgang via Crafts canView()
│   ├── EntrySerializer     entry → array
│   └── FieldValues         feltverdier ut og inn
├── tools/                  de generiske verktøyene
└── templates/              tokens-screen.twig (CP)
```

## Requestflyt

```
POST /mcp
  └── ServerController::beforeAction()
        ├── OPTIONS slippes gjennom (CORS-preflight, transporten svarer 204)
        ├── Tokens::authenticate()  ← Authorization-header
        │     eller authenticateToken() ← /mcp/t/<token>
        ├── 401 uten gyldig token, 403 uten PERMISSION_ACCESS
        └── Craft::$app->getUser()->setIdentity($user)   ← ingen sesjon/cookie
  └── actionHandle()
        ├── PSR-7-request bygget fra Crafts rawBody
        │     (php://input kan alt være konsumert av Yii)
        ├── ServerFactory::create()->run(StreamableHttpTransport)
        └── PSR-7-respons kopiert tilbake til Crafts Response
```

**Identiteten settes uten sesjon.** Den gjelder kun denne requesten, og gjør at
Crafts permissions styrer hva verktøyene får gjøre — `canView()`,
`canSave()`, `canCreateDrafts()`.

## Hvordan verktøy registreres — to veier, med vilje

`ServerFactory::create()` gjør begge deler:

**Pakkens egne verktøy** registreres med `Builder::addTool()` og et eksplisitt
`inputSchema` bygget ved kjøring fra modulkonfigurasjonen.

**Prosjektets egne verktøy** oppdages fra `toolPaths` — én `DiscoveryLoader`
per mappe — med vanlige `#[McpTool]`-attributter.

Grunnen til delingen er strukturell og står i
[ADR 0003](decisions/0003-skjemaer-bygges-ved-kjoring.md): PHP-attributter
krever konstante uttrykk og kan ikke lese konfigurasjon. Se den før du
«forenkler» pakkens verktøy tilbake til attributter.

## Konfigurasjon

Alt prosjektspesifikt settes i prosjektets `config/app.php`. Modulen har ingen
egen konfigurasjonsfil, og **ingen fil i pakken skal endres per installasjon.**

| Nøkkel | Påkrevd | Hva den gjør |
|---|---|---|
| `sites` | ja | Språkkode → Craft site-handle |
| `sections` | ja | Seksjonshandle → `true` eller `['read'/'create'/'update' => bool]` |
| `serverName`, `serverDescription`, `serverVersion` | nei | Det klienten ser i `initialize` |
| `instructionsPath` | nei | Markdown-fil med `instructions`. Craft-alias tillatt |
| `tokenPrefix` | nei (men bør) | Må være unikt per prosjekt. Valideres mot `/^[a-z0-9]+_$/` |
| `routePath`, `tokenInPath` | nei | URL-sti, og om `/mcp/t/<token>` registreres |
| `categoryGroups` | nei | Kategorigrupper som eksponeres |
| `fieldNotes` | nei | Felthandle → merknad for felt der lagret verdi ≠ vist verdi |
| `toolPaths` | nei | Mapper med prosjektets egne verktøyklasser |

`Module::_validateConfig()` feiler tidlig på manglende `sites`/`sections` og
på ugyldig `tokenPrefix` — prefikset går inn i en regex i sti-ruta.

## Hva modulen registrerer selv

- **Site-ruter** på `EVENT_REGISTER_SITE_URL_RULES` — `/<routePath>` og
  eventuelt `/<routePath>/t/<token>`
- **Permission** «Tilgang til MCP» (`accessMcpApi`)
- **CP-fane** «MCP-tokens» på brukersiden, CP-ruter og template-rot
- **Tokens-tabellen** via `Tokens::ensureTable()`

Prosjektet skal altså ikke røre `config/routes.php` eller `migrations/`.

## Kommandoer

`<modul-id>` er det prosjektet kaller modulen i `app.php` — vanen er `mcp-api`.

```sh
php craft <modul-id>/tokens/create <brukernavn>
php craft <modul-id>/tokens/list
php craft <modul-id>/tokens/revoke <id>
```

I DDEV-prosjekter: `ddev craft …`, eller `ddev exec php craft …` der
prosjekttypen er `php` og ikke `craftcms`.
