# Kontekst — arbeidslogger, planer og overleveringer

> ## ⚠️ Dette er historikk, ikke sannhet.
>
> Filene her er **daterte snapshots** fra én økt. De var riktige da de ble
> skrevet, og kan være utdaterte nå: filer kan ha flyttet, metoder byttet navn,
> planer blitt forkastet.
>
> **Verifiser alltid mot koden før du handler på noe herfra.**
>
> Er informasjonen fortsatt gyldig og generell, hører den ikke her — flytt den
> til `architecture.md`, `business-rules.md`, `coding-standards.md` eller en ADR.

## Innhold

- **`handoffs/`** — overleveringer mellom økter, `YYYY-MM-DD-<tema>.md`.

## Opphavet ligger i et annet repo

Pakken ble til av opphavsprosjektets `modules/mcp/`, og ble stresstestet i et
annet prosjekt. De to dokumentene som forklarer hvorfor pakken ser ut som den
gjør, ligger i de prosjektrepoene og ikke her:

- `2026-09-04-craft-mcp-generalisering.md` i opphavsprosjektet — analysen som
  startet det hele. **Merk at anbefalingen der (plugin) ble forkastet**, se
  [ADR 0002](../decisions/0002-modul-ikke-plugin.md).
- `2026-09-14-craft-mcp-stresstest.md` i stresstestprosjektet — de åtte
  funnene som formet konfigurasjonssnittet, med målte tall.

Begge er historikk på samme vilkår som filene her.
