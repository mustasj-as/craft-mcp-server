# AGENTS.md

All prosjektkontekst bor i **`.ai/`** — verktøy-agnostisk, delt mellom Claude
Code, Cursor, Copilot og Codex. Denne fila har ingen egne regler.

**Start med `.ai/README.md`** for kart over mappa og vedlikeholdsregelen.

## Les alltid

- `.ai/project-overview.md` — hva pakken er, hvor den er installert
- `.ai/architecture.md` — stack, requestflyt, konfigurasjonsnøkler
- `.ai/coding-standards.md` — konvensjoner per lag
- `.ai/business-rules.md` — regler koden aldri får bryte
- `.ai/testing-guidelines.md` — hva som faktisk verifiseres
- `.ai/security-guidelines.md` — tokens, auth, prompt injection
- `.ai/git-workflow.md` — brancher, semver, commit-form
- `.ai/glossary.md` — MCP-begreper og pakkens klasser

## Les ved behov

- `.ai/prompts/` — oppgavebriefer: nytt verktøy, ny installasjon
- `.ai/decisions/` — ADR-er, append-only
- `.ai/context/` — daterte overleveringer. Historikk, ikke sannhet

## Kort oppsummert

Repoet er norsk, men **alt en MCP-klient ser er engelsk**. Ingenting
prosjektspesifikt hører i `src/` — det er konfigurasjon i prosjektets
`config/app.php`. Skriveverktøy lager utkast, aldri live innhold, og
ingenting slettes. Det finnes ingen testsuite og ingen CI i dette repoet;
verifiser manuelt mot et Craft-prosjekt pakken er installert i, og skriv ned
målte tall.
