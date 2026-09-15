# `.ai/` — felles kontekst for AI-agenter

Alt en agent trenger å vite om denne pakken bor her. Mappa er
verktøy-agnostisk: Claude Code, Cursor, Copilot og Codex leser de samme filene.
`CLAUDE.md` og `AGENTS.md` i rota er tynne inngangsdører uten egne regler.

## Standing kontekst — les alltid

| Fil | Innhold |
|---|---|
| [project-overview.md](project-overview.md) | Hva pakken er, hvem som bruker den, hvor den er installert |
| [architecture.md](architecture.md) | Stack, struktur, requestflyt, konfigurasjonsnøkler |
| [coding-standards.md](coding-standards.md) | Konvensjoner — inkludert regelen om at ingenting prosjektspesifikt hører i `src/` |
| [business-rules.md](business-rules.md) | Regler koden aldri får bryte |
| [testing-guidelines.md](testing-guidelines.md) | Hva som faktisk verifiseres — det finnes ingen testsuite |
| [security-guidelines.md](security-guidelines.md) | Tokens, autentisering, prompt injection, distribusjon |
| [git-workflow.md](git-workflow.md) | Branchmodell, semver, commit-form |
| [glossary.md](glossary.md) | MCP-begreper og pakkens klasser |

## Last ved behov

- **[prompts/](prompts/)** — oppgavebriefer. Les den som matcher.
- **[decisions/](decisions/)** — nummererte ADR-er, **append-only**. Slå opp
  her før du foreslår å gjøre om på et valg som ser rart ut.
- **[context/](context/)** — daterte overleveringer. **Historikk, ikke
  sannhet** — verifiser mot koden.

## Vedlikeholdsregelen

Endrer du en konvensjon, tar en beslutning eller oppdager en domeneregel:
**oppdater den relevante fila under `.ai/` i SAMME commit som koden.** Utdatert
kontekst er verre enn ingen.

- ny konvensjon → `coding-standards.md`
- valg som er dyrt å reversere → ny ADR i `decisions/`
- regel du oppdaget i koden → `business-rules.md`
- ny konfigurasjonsnøkkel → `architecture.md` **og** rotas `README.md`
- nytt begrep eller ny klasse → `glossary.md`

## Regler for agenter

1. **Norsk i koden, engelsk til klienten.** Kommentarer, docblocks og
   commit-meldinger på norsk; alt en MCP-klient ser på engelsk. Se
   `coding-standards.md`.
2. **Ingenting prosjektspesifikt i `src/`.** Ser du en seksjonshandle, en
   site-handle eller et kundenavn der, er det en bug — det hører i
   prosjektets `config/app.php`.
3. **Skriveverktøy lager utkast.** Aldri live innhold, aldri sletting.
4. **Håndhev i kode, ikke bare i skjemaet.** Enum-et i `inputSchema` er en
   hjelp til klienten, ikke en grense.
5. **Konfigurasjonssnittet er det offentlige API-et.** Endrer du en nøkkel på
   `Module`, brekker du hver installasjons `config/app.php` — se
   `git-workflow.md` om semver.
6. **Ingen tester fanger deg.** Verifiser manuelt, og skriv ned målte tall.

## Knowledge graph

Ikke aktuelt. Pakken er 17 filer og ~3 600 linjer — godt under terskelen der
en grafvisning gir mer signal enn støy.
