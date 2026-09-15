# CLAUDE.md

All prosjektkontekst bor i **[.ai/](.ai/)** — verktøy-agnostisk, delt mellom
Claude Code, Cursor, Copilot og Codex. Denne fila har ingen egne regler.

**Start med [.ai/README.md](.ai/README.md)** for kart over mappa og
vedlikeholdsregelen.

## Standing kontekst

@.ai/project-overview.md
@.ai/architecture.md
@.ai/coding-standards.md
@.ai/business-rules.md
@.ai/testing-guidelines.md
@.ai/security-guidelines.md
@.ai/git-workflow.md
@.ai/glossary.md

## Last ved behov

- **[.ai/prompts/](.ai/prompts/)** — oppgavebriefer: nytt verktøy, ny
  installasjon.
- **[.ai/decisions/](.ai/decisions/)** — ADR-er. Slå opp før du foreslår å
  gjøre om på et valg som ser rart ut.
- **[.ai/context/](.ai/context/)** — daterte overleveringer. Historikk, ikke
  sannhet.

## Verktøyspesifikt

- Repoet er norsk: kommentarer, docblocks, commit-meldinger og dokumentasjon
  på norsk. **Unntaket er alt en MCP-klient ser** — verktøynavn, beskrivelser,
  parametere, feilmeldinger og `instructions` er engelske.
- **Pakken har ikke sitt eget kjøremiljø.** Den testes ved å installeres i et
  Craft-prosjekt, som regel via path-repo med DDEV-montering av pakkemappa.
- Ingen testsuite, ingen linter, ingen CI **i dette repoet**. Ikke påstå at
  noe er dekket. Opphavsprosjektet har ECS og PHPStan, og koden er skrevet
  etter den konvensjonen — se `.ai/testing-guidelines.md`.
- Verifiser endringer med `php -l` og ekte `curl`-kall mot et installert
  prosjekt. Skriv ned målte tall.

## Suggested skills

- **`craft-cms`** — før endringer i modul-, controller- eller tjenestekode.
- **`craft-php-guidelines`** — obligatorisk for all PHP her. Seksjonsheadere,
  PHPDoc med `@since`/`@throws`, korte nullable-typer.
- **`craft-content-modeling`** — når et verktøy skal håndtere felttyper,
  `translationMethod` eller field layouts.
