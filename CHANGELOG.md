# Endringslogg

Formatet følger [Keep a Changelog](https://keepachangelog.com/), og pakken
bruker [semver](https://semver.org/). Konfigurasjonsnøklene i `config/app.php`
er en del av det offentlige API-et.

## 1.4.0 — ikke utgitt

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
