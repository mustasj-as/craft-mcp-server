# 0005 — Tokenet kan stå i URL-stien, med åpne øyne

- **Dato:** 2026-07 (omtrentlig — nedtegnet retroaktivt 2026-09-14)
- **Status:** Vedtatt, midlertidig
- **Besluttet av:** Mustasj-teamet, under arbeidet med opphavsprosjektet

## Kontekst

Nedtegnet i ettertid: beslutningen ble tatt i opphavsprosjektet og fulgte med
da modulen ble en pakke. Datoen er omtrentlig, tatt fra migreringen som opprettet
tokens-tabellen (`m260707_090000`).

Claude Desktops «Add custom connector» tar imot **kun en URL**. Den har ikke
noe felt for HTTP-headere, og kan derfor ikke sende
`Authorization: Bearer <token>`.

## Beslutning

Modulen registrerer `/<routePath>/t/<token>` i tillegg til `/<routePath>`, styrt
av `tokenInPath` (standard `true`). Headeren vinner når begge er oppgitt.

Ruta matcher bare tokenprefikset etterfulgt av nøyaktig 48 hex-tegn, så den
fanger ikke vilkårlige stier.

## Konsekvenser

**Tokenet havner i access-logger, proxy-logger og nettleserhistorikk.** Det er
en reell eksponering, ikke en teoretisk. Avveiingen er akseptert fordi
alternativet er at klienten ikke kan koble til i det hele tatt.

- Slå `tokenInPath => false` i prosjekter der loggene er mindre beskyttet enn
  tokenet.
- Tokens er per bruker og kan trekkes tilbake enkeltvis
  (`tokens/revoke <id>`), så skadeomfanget er avgrenset.
- `tokenPrefix` er unikt per prosjekt, så et token som dukker opp i en logg
  kan spores til riktig installasjon.

**Erstattes av OAuth.** Et utkast ligger i opphavsprosjektets
`.ai/prompts/oauth-for-mcp.md`. Når OAuth er på plass, bør denne ADR-en
oppheves av en ny.
