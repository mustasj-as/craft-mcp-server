# 0008 — `develop` som integrasjonsbranch, `main` er publisert

- **Dato:** 2026-10-06
- **Status:** Vedtatt
- **Besluttet av:** Mustasj-teamet

## Kontekst

`git-workflow.md` valgte trunk-basert flyt med bare `main`, og sa at gitflow
krever en ny ADR hvis forutsetningene endret seg. Det har de:

- Repoet er offentlig og publisert på Packagist. En push til `main` oppdaterer
  `dev-main` for alle, og en tagg der er en utgivelse.
- Det finnes nå samtidige arbeidsstrømmer: verktøyendringer på én maskin og
  SDK-oppgradering (`mcp/sdk` 0.7/0.8) på en annen, som berører de samme
  filene.
- Det finnes ingen CI. Det eneste som fanger en brekkende endring er en
  røyktest mot et installert prosjekt, og den må kunne kjøres på den samlede
  koden *før* den publiseres.

## Beslutning

- `develop` er integrasjonsbranchen. `feature/*` og `bugfix/*` lages fra og
  merges til `develop`.
- `main` får bare merger fra `develop`, etter røyktest i minst ett prosjekt
  (installer `dev-develop` via path-repo eller VCS).
- Tagger settes fortsatt bare på `main`.

## Konsekvenser

- Ett ekstra merge-steg per utgivelse.
- Installasjonene kan teste uutgitt kode med `mustasj/craft-mcp-server:dev-develop`.
- Opphever trunk-delen av `git-workflow.md`; semver-reglene står.
