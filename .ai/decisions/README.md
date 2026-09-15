# Beslutninger (ADR)

Nummererte, **append-only**. En beslutning oppheves ikke ved å redigeres — den
oppheves av en NY ADR som refererer den gamle.

Slå opp her før du foreslår å gjøre om på et valg som ser rart ut.
Sannsynligvis er det begrunnet, og som oftest med et målt tall.

| # | Tittel | Status |
|---|---|---|
| [0001](0001-ai-mappa.md) | All AI-kontekst bor i `.ai/` | Vedtatt |
| [0002](0002-modul-ikke-plugin.md) | Pakken er en Yii-modul, ikke en Craft-plugin | Vedtatt |
| [0003](0003-skjemaer-bygges-ved-kjoring.md) | Pakkens verktøyskjemaer bygges ved kjøring, ikke med attributter | Vedtatt |
| [0004](0004-skrivepolicy-per-operasjon.md) | Skrivepolicy settes per operasjon, ikke per seksjon | Vedtatt |
| [0005](0005-token-i-url-sti.md) | Tokenet kan stå i URL-stien, med åpne øyne | Vedtatt, midlertidig |

Neste ledige nummer: **0006**.

## Format

`NNNN-kebab-tittel.md`, med Dato, Status, Besluttet av, Kontekst, Beslutning,
Konsekvenser. Er beslutningen tatt før den ble skrevet ned, si det, og datér
omtrentlig heller enn å gjette presist.
