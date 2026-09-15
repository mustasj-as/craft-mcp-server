# 0003 — Pakkens verktøyskjemaer bygges ved kjøring, ikke med attributter

- **Dato:** 2026-09-14
- **Status:** Vedtatt
- **Besluttet av:** Mustasj-teamet

## Kontekst

Verktøyene i den opprinnelige modulen brukte SDK-ens attributter:

```php
#[McpTool(name: 'list_articles', description: '…')]
public function listArticles(
    #[Schema(enum: Sections::ARTICLE_LIKE)] string $section = 'artikler',
    #[Schema(enum: ['nb', 'en'])] string $site = 'nb',
): array
```

**PHP-attributter krever konstante uttrykk.** De kan ikke slå opp i
modulkonfigurasjon ved kjøring. Derfor måtte seksjonslista og språkkodene være
klassekonstanter — og derfor måtte hver installasjon endre filer inne i
modulen. Det er definisjonen på en fork, og det er nøyaktig problemet pakken
finnes for å løse.

Dette var funn 5 av åtte i stresstesten, og det eneste som var strukturelt
og ikke bare kosmetisk.

## Beslutning

**Pakkens egne verktøy registreres med `Builder::addTool()`** og et eksplisitt
`inputSchema` bygget ved kjøring fra konfigurasjonen, i
`ServerFactory::_addPackageTools()`.

**Prosjektets egne verktøy oppdages fra `toolPaths`** med vanlige
`#[McpTool]`-attributter, én `DiscoveryLoader` per mappe.

SDK-en støtter begge: `ArrayLoader` gjør
`$data['inputSchema'] ?? $schemaGenerator->generate($reflection)`, altså vinner
et eksplisitt skjema over refleksjon.

## Konsekvenser

- **Et prosjekt trenger null PHP** for det vanlige tilfellet. Seksjoner,
  sites og kategorigrupper er konfigurasjon, og enum-ene i skjemaene følger
  med.
- **Verktøybeskrivelser og skjema står nå et annet sted enn koden de
  beskriver** — i `ServerFactory` i stedet for over metoden. Det er prisen.
  Endrer du en verktøysignatur, må skjemaet endres i samme commit; ingenting
  fanger avviket for deg.
- **Prosjektverktøy beholder attributter**, og det er riktig: der er
  konstanter prosjektkode, og nærheten mellom signatur og skjema er en fordel.
- `toolPaths`-sømmet er det som avgjør om pakken holder. Uten det må hvert
  prosjekt som trenger ett eget verktøy forke.

## Alternativ forkastet

**La prosjektet levere alle verktøyklassene**, og la pakken bare eksponere
server og basisklasser. Ville løst det samme, men flyttet ti generiske verktøy
inn i hvert prosjekt — altså kopi-lim av det pakken skulle dele.
