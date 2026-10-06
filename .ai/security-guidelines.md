# Sikkerhet

Denne pakken er et **autentisert skrive-endepunkt mot et CMS, styrt av en
språkmodell.** Trusselbildet er ikke det samme som for en vanlig Craft-modul.

## Tokens

- Lagres **kun som sha256-hash** i `{{%mcp_tokens}}`. Klartekst vises én gang.
- Formen er `<prefiks>` + 48 hex-tegn (24 tilfeldige byte fra
  `random_bytes()`).
- `tokenPrefix` **må være unikt per prosjekt.** Ikke bare kosmetikk: prefikset
  går inn i regexen for `/mcp/t/<token>`-ruta, og gjør at et token som lekker
  kan spores til riktig installasjon. `Module::_validateConfig()` avviser
  prefikser som ikke matcher `/^[a-z0-9]+_$/`, fordi regex-tegn ellers ville
  gitt en rute som matcher noe annet enn den ser ut til.
- `lastUsedAt` oppdateres strupet (maks hvert 60. sekund).
- Ingen tokens i git, i dokumentasjon eller i eksempler. Bruk `<token>`.

## Tokenet i URL-en — finnes fortsatt, vises ikke

`tokenInPath` (standard `true`) registrerer `/<routePath>/t/<token>`. Den kom
fordi Claude Desktop en gang bare tok imot en URL. Det gjør den ikke lenger,
og token-fanen viser bare header-oppskrifter (ADR 0006).

**Tokenet havner da i access-logger, proxy-logger og nettleserhistorikk.** Det
er akseptert for et internt verktøy, men det er en reell eksponering:

- Slå `tokenInPath => false` av i prosjekter der loggene er mindre beskyttet
  enn tokenet.
- Erstattes av OAuth når det er på plass. Et utkast ligger i
  opphavsprosjektets `.ai/prompts/oauth-for-mcp.md`.

Headeren vinner når begge er oppgitt.

## Autentisering og autorisasjon

`ServerController` har `$allowAnonymous = true` og
`$enableCsrfValidation = false`. **Det er riktig for dette endepunktet, ikke en
forglemmelse:** MCP-klienter er ikke nettlesere, har ingen Craft-sesjon og kan
ikke skaffe et CSRF-token. Autentiseringen skjer i `beforeAction()`:

1. Ingen/ugyldig token → **401** med `WWW-Authenticate: Bearer`
2. Token uten `accessMcpApi` → **403**
3. Ellers: `Craft::$app->getUser()->setIdentity($user)` — **uten sesjon eller
   cookie**, kun for denne requesten

Fordi identiteten settes, gjelder Crafts egne permissions inne i verktøyene
(`canView()`, `canSave()`, `canCreateDrafts()`). **Ikke skriv et verktøy som
omgår dem** med direkte SQL eller `SCENARIO_ESSENTIALS` uten en skrevet
begrunnelse.

CORS-preflight (`OPTIONS`) slippes gjennom uautentisert; transporten svarer
204 og eksponerer ingenting.

## `sections` er ikke en sikkerhetsgrense

`SectionPolicy` avgrenser hva som er **ment** for maskinell redigering.
Sikkerheten er Crafts permissions. Grunnen til at allowlisten likevel må
finnes: `canSave()` er alltid sann for superadmins, og de fleste tokens i
praksis tilhører en admin — uten lista ville et token nådd enhver seksjon i
systemet.

**Håndhev alltid i kode, ikke bare i skjemaet.** Enum-et i `inputSchema`
stopper klienter som validerer; en rå HTTP-klient kan sende hva som helst.
Derfor kaller verktøyene `SectionPolicy::assertAllowed()` i tillegg.

## Prompt injection er en reell vektor her

Innhold som leses ut av CMS-et kan inneholde tekst som forsøker å instruere
modellen som leser det. En redaktør — eller en ekstern feed, som i
stresstestprosjektet — kan plante instruksjoner i et felt.

Konsekvensen for design: **skriveverktøyene lager utkast, aldri live
innhold.** `publish_draft` er et eget, eksplisitt steg. Det er den viktigste
enkeltbeskyttelsen pakken har, og den skal ikke fjernes for å spare et kall.

Nye verktøy skal ikke gjøre noe destruktivt eller irreversibelt. Det finnes
med vilje **ingen `delete_entry`.**

## Data som aldri skal eksponeres

Diskutert og avvist da pakken ble laget: **brukere, brukerdata og
Wishlist-/favorittdata skal ikke over MCP.** Persondata over et AI-endepunkt
er en annen samtale enn innholdsredigering, med andre krav. Utvid ikke scope
hit uten en ADR.

Globals kan vurderes senere; entries og kategorier er dagens scope.

## Distribusjon

**Repoet er offentlig, under MIT** (2026-09-15). Det var privat før, og
denne fila sa «ikke publiser det» — beslutningen er omgjort, ikke glemt.
Vurderingen bak: pakken inneholder ingen hemmeligheter, ingen kundedata og
ingen domenelogikk. Sikkerheten ligger i tokenet og i Crafts permissions, ikke
i at koden er skjult — et autentiseringsopplegg som svekkes av å bli lest, er
uansett ødelagt.

Det følger to regler av at repoet er offentlig:

- **Ingen kundenavn, domener, filstier eller nettstedsspesifikke verdier i
  koden eller i `.ai/`.** Det er samme regel som allerede gjaldt av
  gjenbrukshensyn — nå er den også en konfidensialitetsregel. Overleveringer
  under `.ai/context/handoffs/` er gitignorert av samme grunn.
- **Ingen tokens, nøkler eller dumper.** Selvsagt, men terskelen for et uhell
  er lavere nå: en feilcommit kan ikke trekkes tilbake.

Byggmiljøer trenger ikke lenger auth for `composer install` — det var den
praktiske gevinsten ved å publisere.

**Endepunktet hører ikke på et offentlig nettsted uten at noen har tenkt
gjennom det.** Lokalt og på staging er det udramatisk; i prod er det et
autentisert skrive-API mot innholdet.

## Token-fanen

- **Tokens lages bare av brukeren selv.** En admin som laget et token på en
  annens fane, fikk se klarteksten og kunne opptre som den brukeren. Admin
  kan fortsatt trekke tilbake andres tokens.
- **Gyldighet er påkrevd** fra en fast liste, maks 12 mnd. Tokens uten utløp
  kan bare lages fra konsollen (`tokens/create` uten `--expiresDays`).
- **Mister en bruker tilgangen, trekkes tokenene tilbake** — se Tilgangssynk
  i [architecture.md](architecture.md). Endepunktet avviser dem uansett;
  slettingen hindrer at glemte tokens virker igjen den dagen tilgangen gis
  tilbake.
- Bryteren «Kan bruke MCP» endrer bare permission på brukernivå. Den leser
  brukernivå-lista fra tabellen og skriver den tilbake — ikke fra
  `getPermissionsByUserId()`, som inkluderer gruppenes og ville kopiert dem
  inn på brukeren.
