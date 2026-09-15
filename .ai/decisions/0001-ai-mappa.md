# 0001 — All AI-kontekst bor i `.ai/`

- **Dato:** 2026-09-14
- **Status:** Vedtatt
- **Besluttet av:** Mustasj-teamet

## Kontekst

Repoet ble opprettet samme dag og hadde bare en `README.md`. Konteksten en
agent trenger — hvorfor pakken er en modul og ikke en plugin, hvorfor
verktøyskjemaene bygges ved kjøring, hva som aldri skal eksponeres — lå spredt
i commit-meldinger og i et handoff-dokument i et annet repo.

Begge prosjektene som bruker pakken har allerede en `.ai/`-mappe med samme
struktur.

## Beslutning

All AI-rettet kontekst bor i `.ai/`, verktøy-agnostisk. `CLAUDE.md` og
`AGENTS.md` i rota er tynne inngangsdører uten egne regler.

Samme struktur som i de to prosjektrepoene, så en agent som har lest ett av
dem kjenner seg igjen.

## Konsekvenser

- **Vedlikeholdsregelen gjelder:** endrer du en konvensjon, tar en beslutning
  eller oppdager en domeneregel, oppdater riktig `.ai/`-fil i **samme commit**
  som koden.
- ADR-er er append-only. En beslutning oppheves med en ny ADR som refererer
  den gamle.
- `context/` er historikk, ikke sannhet — verifiser mot koden.
- `.ai/` er utviklerkontekst og har ingenting å gjøre på en server. Pakken har
  ingen `.deployignore`; den installeres via Composer, som ikke sender
  `.ai/`-mappa videre til noe miljø uansett.
