# Endringslogg

Formatet følger [Keep a Changelog](https://keepachangelog.com/), og pakken
bruker [semver](https://semver.org/). Konfigurasjonsnøklene i `config/app.php`
er en del av det offentlige API-et.

## Planlagt

- **`fields: {}` avvises av SDK-valideringen.** PHP dekoder det tomme
  objektet til en array, og skjemaet krever `object`. Funnet ved røyktest
  2026-11-10; utelatt `fields` virker.

## 1.6.1 — 2026-11-11

### Rettet

- **Nye kladder fra `create_entry` vises i kontrollpanelet.** Kladden ble
  lagret med `markAsSaved: false`, så `drafts.saved` ble 0 og Craft skjulte
  den i entry-oversikten, også under filteret «Utkast». Redaktørene fant den
  bare via `cpEditUrl`. Nå lagres den som en vanlig lagret kladd, og
  `note`-teksten sier ikke lenger at den bare finnes via filteret.

## 1.6.0 — 2026-11-10

### Lagt til

- **`update_entry` tar `slug`.** Slugs er per site, men en oversatt versjon
  arvet slugen fra siten entryen ble opprettet på, og verktøyet hadde ingen
  måte å endre den på.
- **Advarsel om lik slug.** `create_entry`, `update_entry` og `publish_draft`
  gir `warning` når slugen på en ikke-primær site er lik primærsitens.
  Blokkerer ikke — noen slugs er med vilje like.

### Endret

- **`mcp/sdk ^0.8 || ^0.9`**, ikke lenger `^0.7`. 0.9 er det
  `stimmt/craft-mcp` 1.8.x krever, så prosjekter med begge kan nå oppgradere
  den. Å slippe 0.7 retter også protokollforhandlingen: 0.7 svarte på
  `initialize` med sin nyeste versjon uansett hva klienten ba om.
  Prosjekter som står på `stimmt/craft-mcp` < 1.7.1 må oppgradere den først.
- **Fremmed `Origin` gir 403.** Nytt vern i SDK 0.9: en request med
  `Origin`-header må være samme origin som endepunktet. MCP-klienter sender
  normalt ikke `Origin`; en nettleserbasert inspektør på et annet domene
  blir avvist.
- **`DnsRebindingProtectionMiddleware` får fabrikkene som navngitte
  argumenter.** SDK 0.9 satte inn `$allowedOrigins` som andre parameter, så
  det posisjonelle kallet ville gitt TypeError på hver request.
- **HTML-felt (CKEditor) leses rått.** `get_entry` gir referansetagger som
  `{entry:984:url||https://…}` i stedet for ferdige URL-er. Før så klienten
  bare absolutte lenker, trodde de var hardkodet, og gjorde dem faktisk
  hardkodet hvis den skrev HTML-en tilbake med `update_entry`. Feltet er
  fortsatt en streng, så formen på svaret er uendret.
  `FIELDS_DESCRIPTION` ber klienten beholde taggene og bruke dem for nye
  interne lenker.

## 1.5.0 — 2026-11-02

### Lagt til

- **`get_entry_history`**: de siste revisjonene av en entry, med dato, fullt
  navn på den som lagret, notater og om entryen var slått på. Svarer på «når
  og av hvem ble denne slått av?». Sier fra i `note` når statusen er endret
  uten revisjon (resave, migreringer). ADR 0009.
- **`get_entry`** gir `dateCreated`, `dateUpdated` og `expiryDate`;
  **`list_entries`** gir `dateUpdated`.

### Endret

- **`serverVersion` er `null` som standard**, og klienten får da den
  installerte pakkeversjonen fra Composer (`1.5.0`, eller
  `dev-develop@<commit>`) i stedet for alltid `1.0.0`. Satt i `config/app.php`
  vinner den fortsatt.

## 1.4.0 — 2026-10-14

### Før du oppdaterer

- **`mcp/sdk` er løftet fra `^0.4` til `^0.7 || ^0.8`.** Krever prosjektet ditt
  `mcp/sdk ^0.4` direkte, eller har det en annen pakke som gjør det, stopper
  Composer oppdateringen. Det gjelder blant annet **`stimmt/craft-mcp` før
  1.4.0**: oppgrader den til `^1.4` i samme `composer update`. Den krever
  `^0.7`, så Composer velger da SDK 0.7.x.
- **Prosjektegne verktøy (`toolPaths`)** kjører nå på SDK 0.7/0.8.
  `#[McpTool]` og `#[Schema]` har samme konstruktør som før, men verktøy som
  bruker andre deler av SDK-en direkte bør sjekkes.
- **Tokentabellen har nytt navn**, se under. Ruller du tilbake til 1.3 etter
  å ha kjørt 1.4, ser alle tokens ut til å være borte: 1.3 finner ikke
  `mcp_tokens` og lager en ny, tom tabell. Tokenene ligger urørt i
  `craft_mcp_tokens` og kan flyttes tilbake for hånd.

### Endret

- **Tokentabellen heter `craft_mcp_tokens`** (var `mcp_tokens`). `stimmt/craft-mcp`
  bruker `mcp_tokens` med en annen struktur. Med begge pakkene installert
  feilet tokenopprettelsen, og pakkene kunne lest hverandres rader. Den gamle
  tabellen flyttes automatisk første gang tokens brukes, men bare hvis den er
  pakkens egen (`expiresAt` finnes, `scope` mangler). stimmts tabell blir
  aldri rørt.
- **DNS rebinding-vernet i SDK-en er på.** Fra 0.7 avviser SDK-en `Host`- og
  `Origin`-verdier den ikke kjenner, og som standard kjenner den bare localhost.
  Pakken godtar i tillegg vertsnavnene fra alle Craft-sitenes base-URL-er.
  Uten det ville endepunktet svart 403 på ethvert ekte domene.
- **Request-body over 4 MB avvises** av SDK-ens transport.

### Lagt til

- **`allowedHosts`** (standard `[]`): ekstra vertsnavn som godtas i
  `Host`/`Origin`. Bruk den bak en proxy, eller når sitenes base-URL er relativ
  (`@web`) og ikke gir noe vertsnavn. Er ingen vertsnavn kjent, logges en
  advarsel, og bare localhost slipper gjennom.
- **`list_entries(drafts: true)`** lister upubliserte utkast i stedet for
  publiserte entries.
- **`draftId` på `get_entry` og `update_entry`.** Element-ID og utkast-ID er
  nå egne parametere. Før måtte `entryId` dekke begge, og agenter blandet dem.

### Rettet

- **`get_entry` fant ikke utkast.** En agent kunne ikke lese tilbake utkastet
  den nettopp hadde laget med `create_entry` eller `update_entry`.
- **Utkast av publiserte entries ble behandlet som publiserte i
  lesetilgangen.** Et slikt utkast arver `postDate` og rapporterer status
  `live`. Da `get_entry` begynte å finne utkast, ville et token uten rett til
  å se utkast fått se upublisert innhold. Rettet før det ble utgitt.
- **Duplisert `Host`-header.** PSR-7-requesten fikk `Host` både fra URI-en og
  fra headerne, så SDK-ens vern leste «a, a» og avviste alt. Like verdier slås
  nå sammen, mens ulike verdier fortsatt avvises.
- **`setSession()` kalles uten `ttl`.** Argumentet finnes ikke fra SDK 0.7.
  Levetiden settes på `FileSessionStore`.

### Merk for klienter

`update_entry` sitt `entryId` er nå en element-ID og **ikke** en utkast-ID.
Utkast adresseres med `draftId`. Skjemaet og beskrivelsene sier dette, og
MCP-klienter leser dem på nytt ved hver tilkobling.
