# Kodekonvensjoner

Ingen linter og ingen static analysis er konfigurert i **dette** repoet ennå —
se [testing-guidelines.md](testing-guidelines.md). Konvensjonene under er lest
ut av koden som står der, og følger Pixel & Tonics modulmal, som
opphavsprosjektet (der koden kommer fra) håndhever med ECS og PHPStan.

## Språk

- **Kommentarer, docblocks og commit-meldinger: norsk.**
- **All utdata til MCP-klienten: engelsk.** Verktøynavn, beskrivelser,
  parameternavn, feilmeldinger og `instructions` leses av en språkmodell som
  jobber på engelsk, og av utviklere i flere prosjekter. Dette er den ene
  flata der norsk ikke gjelder.
- **CP-tekster: norsk** — permission-etiketten «Tilgang til MCP», fanen
  «MCP-tokens», `tokens-screen.twig`.
- Identifikatorer er engelske (`SectionPolicy`, `ensureTable`, `toolPaths`).

## PHP

- PHP 8.2, Craft 5 / Yii 2-konvensjoner.
- **Seksjonsoverskrifter** med `// ====…`-rammer: `Const Properties`,
  `Public Properties`, `Public Methods`, `Private Methods`. Behold formen.
- **Docblocks på alt offentlig**, med `@author Mustasj AS`, `@since`,
  `@param`, `@return` og `@throws`.
- **Private metoder prefikses med understrek** (`_validateConfig`,
  `_registerSiteRoutes`).
- Typede parametere og returtyper overalt. Korte nullable-typer (`?string`).
- Bruk `Craft::$app`-getterne (`getSites()`, `getEntries()`), ikke direkte
  komponent-oppslag.

## Kommentarer: forklar hvorfor, med tall

Vanen er arvet fra de to prosjektene pakken kommer fra, og er verdt å holde:
kommentarer
forklarer **hvorfor koden ser rar ut**, ofte med et målt tall eller det
forkastede alternativet. Eksempler som står i koden nå:

```php
// `withDisabled: true` er nødvendig: uten den returnerer Craft null
// for en deaktivert site, og feilmeldingen blir «is not configured» —
// som sender den som feilsøker til project config for å lete etter en
// site som står der hele tiden.
```

```php
// Memoisert: getTokens() kalles på hver autentiserte MCP-request, og
// en tableExists-sjekk per kall er en avgift for en tabell som
// opprettes én gang i en installasjons levetid.
```

Ikke skriv kommentarer som gjentar koden.

## Ingenting prosjektspesifikt i pakken

Den viktigste regelen. Ser du en seksjonshandle, en site-handle, et
tokenprefiks eller et kundenavn i `src/`, er det en bug — det hører i
prosjektets `config/app.php`. Pakken ble skrevet nettopp fordi to
installasjoner ellers divergerer.

Gjelder også tekst: `serverName` og `instructions` er konfigurasjon.

## Verktøy

- **Pakkens verktøy registreres i `ServerFactory::_addPackageTools()`** med
  `addTool()` og et eksplisitt skjema. Ikke legg `#[McpTool]` på dem —
  se [ADR 0003](decisions/0003-skjemaer-bygges-ved-kjoring.md).
- **Feilmeldinger til klienten skal si hva som ER lov**, ikke bare at noe er
  galt. `SectionPolicy::assertAllowed()` skiller mellom «seksjonen finnes
  ikke» og «seksjonen finnes, men ikke for denne operasjonen», fordi en
  assistent som får et generisk nei prøver igjen med et gjettet navn.
- **Skriveverktøy lager utkast.** Et nytt skriveverktøy som går rett på live
  innhold bryter kontrakten `instructions` lover klienten.
- Kast `ToolCallException` for feil klienten skal se og kunne rette.

## Nye konfigurasjonsnøkler

Legges som en `public`-property på `Module` med docblock som sier hva den gjør
**og hvorfor den er konfigurasjon og ikke en konstant**. Er den påkrevd, valider
den i `_validateConfig()` — en uforståelig feil midt i et verktøykall er verre
enn en tydelig feil ved oppstart.

Oppdater samtidig: tabellen i [architecture.md](architecture.md), tabellen i
rotas `README.md`, og eksempelet i `Module`-docblocken.
