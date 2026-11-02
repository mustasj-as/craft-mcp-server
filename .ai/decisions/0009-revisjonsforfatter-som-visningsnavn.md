# 0009 — Revisjonshistorikk med forfatterens fulle navn

- **Dato:** 2026-11-02
- **Status:** Vedtatt. Skrevet av Claude (Opus 5.5) sammen med Erlend.
- **Besluttet av:** Erlend

## Kontekst

En agent kunne ikke svare på «hvem slo av disse sidene, og når?». `get_entry`
ga innhold og `status`, men ingen datoer utover `postDate` og ingenting om
revisjoner. Et menneske måtte åpne revisjonsmenyen i CP-en side for side.

`dateUpdated` alene holder ikke: den flyttes av alt som lagrer elementet, også
resave og migreringer. I prosjektet som utløste behovet hadde alle sidene
`dateUpdated` fra samme sekund i en migrering.

Hvem som lagret en revisjon er brukerdata, og `security-guidelines.md` sa at
brukerdata ikke skal over MCP uten en ADR. Dette er den ADR-en.

## Beslutning

- **Nytt verktøy `get_entry_history`**, ikke et felt i `get_entry`, så
  `get_entry` holdes slank. Gir de siste revisjonene (standard 5, maks 20),
  nyeste først: `num`, `date`, `creator`, `notes` og `enabled` (revisjonens
  status på siten).
- **`creator` er bare `fullName`.** Aldri e-post, brukernavn eller id. Ikke
  `getName()`, som faller tilbake til brukernavnet, og brukernavnet er ofte
  e-postadressen. Mangler fullt navn, er `creator` `null`.
- **Samme tilgang som entryen** (`EntryAccess::requireViewable()`). Den som
  kan lese entryen, kan lese historikken. Ingen ny permission.
- **`get_entry` og `list_entries`** får `dateUpdated` (og `get_entry` også
  `dateCreated` og `expiryDate`). Det er ikke brukerdata.
- **`enabled` er `$entry->enabled && getEnabledForSite()`.** Med én site setter
  Craft `enabled` på elementet, og per-site-verdien blir stående `true`. Bare
  `getEnabledForSite()` ga `true` for en entry med status `disabled`.
- **`note` i svaret** når entryen og siste revisjon har ulik status, så agenten
  ikke tolker et hull i historikken som «alltid av».

## Hva som lager revisjon (verifisert 2026-11-02, Craft 5.9)

`Entry::_shouldSaveRevision()` krever blant annet `!$this->resaving`.

| Hvordan statusen endres | Revisjon? |
|---|---|
| Lagring i CP-en | Ja |
| Bulk-handlingen «Status» i indeksen (`SetStatus` → `saveElement`) | Ja (lest i koden, ikke kjørt) |
| `saveElement()` fra et skript uten `resaving` | Ja, med innlogget bruker som forfatter, ellers `creator: null` |
| `php craft resave/entries --setEnabledForSite=0` | Nei, og `dateUpdated` flyttes heller ikke |

Overleveringen gjettet at bulk-handlingen ikke lager revisjon. Det stemmer ikke.

## Konsekvenser

- **Minor-bump**: nytt verktøy og nye felt i svarene, ingen endret signatur.
- Fullt navn på redaktører går nå over MCP til den som har et token med
  lesetilgang. E-post, brukernavn og id gjør det fortsatt ikke, og resten av
  regelen om brukerdata står.
- Revisjonsnotater (`notes`) er fritekst skrevet av redaktører og kan i
  prinsippet inneholde hva som helst. De er en del av innholdshistorikken og
  tas med.
