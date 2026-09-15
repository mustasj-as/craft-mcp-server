# 0002 — Pakken er en Yii-modul, ikke en Craft-plugin

- **Dato:** 2026-09-14
- **Status:** Vedtatt
- **Besluttet av:** Mustasj-teamet

## Kontekst

MCP-serveren var en modul i opphavsprosjektet (`modules/mcp/`, 15 filer). Da
den skulle gjenbrukes, anbefalte en analyse å gjøre den om til en plugin, med
begrunnelsen at «et Yii-modul kan ikke distribueres via Composer på en
fornuftig måte». Analysen ligger i det prosjektets eget `.ai/context/`.

## Beslutning

**Pakken forblir en Yii-modul**, distribuert som en Composer-pakke
(`type: library`) fra privat VCS, bootstrappet i prosjektets `config/app.php`.

Premisset holdt ikke: en modul distribueres helt fint via Composer — det er
PSR-4 og én linje i `app.php`. Det plugin-formen faktisk gir, er
migreringsstøtte og et innstillings-UI. Prisen er plugin-lifecycle,
`Settings`-modell, project config-oppføringer og installasjonsløp. For et
internt verktøy med en håndfull installasjoner er det overhead uten gevinst.

## Konsekvenser

**Tokens-tabellen kan ikke være en migrering.** Verifisert i
`vendor/craftcms/cms/src/console/controllers/MigrateController.php`: sporene er
`craft`, `content` og `plugin:<handle>`. Det finnes ikke noe `module:`-spor.
Tabellen opprettes derfor av `Tokens::ensureTable()`, idempotent og memoisert
per request. **Dette er den ene tingen plugin-formen hadde løst**, og det er en
bevisst pris.

**Ingen innstillings-UI.** Konfigurasjon er PHP i `config/app.php`. For
utviklere er det greit; for en redaktør ville det ikke vært det. Skal pakken
en dag konfigureres av ikke-utviklere, er plugin riktig.

**Konfigurasjonssnittet oversettes rett til en `Settings`-modell** hvis pakken
senere skal ut av huset. Beslutningen er ikke dyr å reversere.

## Alternativer forkastet

- **Plugin.** Se over.
- **Kopi per prosjekt.** Prøvd med vilje som stresstest i et annet prosjekt.
  Gir divergerende kopier fra dag én.
- **Git submodule.** Ingen versjonering, og en ekstra ting å forklare.
