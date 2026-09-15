# Ordliste

| Begrep | Betyr |
|---|---|
| **MCP** | Model Context Protocol. Protokollen AI-klienter bruker for å nå verktøy og data |
| **streamable HTTP** | MCP-transporten pakken bruker. Sesjonsbasert over vanlig HTTP, med `Mcp-Session-Id`-header |
| **verktøy / tool** | En funksjon klienten kan kalle. `tools/list` lister dem, `tools/call` kjører ett |
| **`inputSchema`** | JSON Schema for et verktøys parametere. Bygges ved kjøring for pakkens verktøy |
| **discovery** | SDK-ens skanning etter `#[McpTool]`-attributter i en mappe. Brukes for prosjektets egne verktøy |
| **`instructions`** | Tekst som sendes i hver `initialize`. Koster kontekst hos klienten — hold den kort |
| **resources** | MCP-mekanismen for innhold som hentes ved behov. **Ikke implementert ennå** |
| **token** | Personlig bearer-token knyttet til en Craft-bruker. `<prefiks>` + 48 hex-tegn |
| **allowlist / policy** | `sections`-konfigurasjonen. Intensjon, ikke sikkerhetsgrense |
| **god node** | Fra graphify. Ikke relevant her |

## Klasser verdt å kjenne

| Klasse | Ansvar |
|---|---|
| `Module` | All konfigurasjon, selvregistrering av ruter/permission/CP |
| `ServerFactory` | Bygger serveren, registrerer begge slags verktøy |
| `SectionPolicy` | Hvilke seksjoner, og hva som er lov per operasjon |
| `SiteHelper` | Språkkode ↔ Craft site, avviser deaktiverte |
| `EntryAccess` | Lesetilgang via Crafts `canView()` |
| `Tokens` | Utsteding, validering, `ensureTable()` |

## Konvensjoner om språk

- **Norsk:** kommentarer, docblocks, commit-meldinger, CP-tekster.
- **Engelsk:** alt klienten ser — verktøynavn, beskrivelser, parametere,
  feilmeldinger, `instructions`.

Ikke «rett» det ene til det andre. Se
[coding-standards.md](coding-standards.md).

## Modul-ID

Prosjektene kaller modulen **`mcp-api`**, ikke `mcp`. Grunnen er at
`stimmt/craft-mcp` — et uavhengig dev-verktøy noen prosjekter har installert —
okkuperer handleen `mcp`. ID-en er fri, men følg vanen.

## «Opphavsprosjektet» og «stresstestprosjektet»

Dokumentasjonen her viser gjennomgående til to Craft-prosjekter uten å navngi
dem, fordi repoet er offentlig:

| Begrep | Hva det viser til |
|---|---|
| **opphavsprosjektet** | Prosjektet modulen ble skrevet for, som `modules/mcp/`. Kjører fortsatt sin egen kopi, og er akseptansetesten for `toolPaths`. Har ECS og PHPStan, som denne koden er skrevet etter |
| **stresstestprosjektet** | Prosjektet pakken ble generalisert mot: fem sites (to aktive), en ekstern XML-feed som eier en seksjon, en langt større innholdsmodell. De åtte funnene derfra formet konfigurasjonssnittet |

Hvilke kunder det er, står ikke i repoet. Trenger du det, spør noen i teamet.
