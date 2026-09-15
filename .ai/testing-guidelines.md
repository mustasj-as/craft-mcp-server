# Verifisering

## Sannheten først

**Det finnes ingen testsuite, ingen linter, ingen static analysis og ingen CI
i dette repoet.** Ingen `.github/workflows/`, ingen `phpunit.xml`, ingen
`ecs.php`, ingen `phpstan.neon`. `composer.json` har ingen `scripts`-seksjon
og ingen `require-dev`.

Ingenting kjører automatisk. Ikke skriv «testene passerer», og ikke foreslå
`composer test`.

**Opphavsprosjektet har derimot ECS og PHPStan** (`ddev composer check-cs`,
`ddev composer phpstan`, level 5, `modules/` only), og denne koden er skrevet
etter den konvensjonen. Til de portene finnes her, er det prosjektet det
nærmeste en kvalitetsport: kjør dem der etter at pakken er tatt i bruk.

**Åpen oppgave:** flytt ECS og PHPStan inn i denne pakken. Den er 3 600 linjer
PHP uten noen automatisert sjekk, og den skal kjøre i flere prosjekter.

## Slik verifiseres arbeid i praksis

Alt manuelt, mot en ekte Craft-installasjon. Fremgangsmåten som ble brukt da
pakken ble skrevet:

### 1. Syntaks

```sh
php -l src/<fil>.php
```

### 2. Installer i et prosjekt

Path-repo mens du utvikler:

```sh
composer config repositories.mustasj-mcp path ../craft-mcp-server
composer require mustasj/craft-mcp-server:@dev
```

I DDEV må pakkemappa monteres inn i web-containeren — Composer inne i
containeren ser ikke vertens hjemmemappe. Legg en
`.ddev/docker-compose.mcp-pakke.yaml` i prosjektet som monterer den inn.

### 3. Røyktest endepunktet med curl

```sh
T=$(php craft mcp-api/tokens/create <bruker> | grep '^<prefiks>')

# initialize — skal gi 200 og en Mcp-Session-Id
curl -sk -D/tmp/h -X POST https://<site>/mcp \
  -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'

# så: notifications/initialized, tools/list, tools/call
```

**Sesjons-ID-en er påkrevd** på alle kall etter `initialize`, og
`notifications/initialized` må sendes før første `tools/call`.

### 4. Skriv ned målte tall

Vanen fra begge prosjektrepoene: et verifisert tall er dokumentasjon, «virker»
er det ikke. Fra da pakken erstattet kopien i stresstestprosjektet:

- `list_entries(realEstate)` → 93 treff (var: avvist)
- `create_entry(realEstate)` → avvist (var: opprettet utkast 2603)
- `get_content_model()` 51 770 tegn → `only: "faq"` 3 439 tegn
- `initialize` leste 1 277 tegn instruksjoner fra `.ai/mcp/instructions.md`

### 5. Rydd opp etter deg

Skrivetester lager ekte utkast. Slett dem, og kjør `php craft gc`.

## Hva som må testes ved endringer

| Endring | Verifiser minst |
|---|---|
| `SectionPolicy` | En seksjon med `create => false`: lesing virker, oppretting avvises |
| `SiteHelper` | Ukjent kode avvises; en **deaktivert** site avvises med riktig melding |
| Nye/endrede verktøy | `tools/list` viser dem, og ett ekte kall mot ekte data |
| `Module`-konfigurasjon | Manglende `sites`/`sections` gir `InvalidConfigException` ved oppstart |
| Ruter | Fjern MCP-ruter fra prosjektets `routes.php` — endepunktet skal fortsatt svare |
| Tokens | Slett tabellen, kjør en token-kommando: den skal opprettes på nytt |

## Ikke dekket i det hele tatt

Vær ærlig om dette i stedet for å antyde noe annet:

- **`WriteTools` og `EntrySerializer`** er portert uendret fra
  opphavsprosjektet og har ikke fått nye øyne. De er tyngdepunktet.
- **Nested entries** (`section === null` i Craft 5) er ikke kjørt gjennom
  serialiseringen.
- **Matrix / innholdsbyggere** med mange blokktyper er ikke testet.
- **En ikke-admin bruker.** Alt er kjørt som superadmin, så
  permission-oppførselen er uprøvd i praksis.
- **Samtidige sesjoner** mot `FileSessionStore`.
- **Prosjektegne verktøy** mot `toolPaths` — sømmet er skrevet, men aldri
  kjørt med et ekte prosjektverktøy.
