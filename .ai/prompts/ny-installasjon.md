# Oppgavebrief: ta pakken i bruk i et nytt prosjekt

## 1. Installer

```sh
composer config repositories.mustasj-mcp vcs git@github.com:mustasj-as/craft-mcp-server.git
composer require mustasj/craft-mcp-server:^1.0
```

Privat repo — byggmiljøet trenger lesetilgang. Se
[../security-guidelines.md](../security-guidelines.md).

## 2. Kartlegg prosjektet før du konfigurerer

Ikke gjett. Finn:

- **Sites:** hvilke finnes, hvilke er `enabled`, hvilken er primær?
  `config/project/sites/*.yaml`.
- **Seksjoner:** hvilke er redaksjonelle, og finnes det noen som eies av en
  import, en feed eller en integrasjon? De skal ha `create => false`.
- **Kategorigrupper** som er verdt å eksponere.
- **Felt der lagret verdi ikke er det som vises** — koder som oversettes ved
  rendering. De hører i `fieldNotes`; en assistent kan ikke utlede dem.
- Prosjektets `.ai/business-rules.md`, hvis den finnes. Det er der
  asymmetriene står.

## 3. Konfigurer i `config/app.php`

```php
'mcp-api' => [
    'class' => \Mustasj\CraftMcp\Module::class,
    'serverName' => '<Prosjekt> Content API',
    'tokenPrefix' => '<kort>_',          // unikt per prosjekt
    'sites' => ['nb' => '<handle>', 'en' => '<handle>'],
    'sections' => [ /* … */ ],
    'categoryGroups' => [ /* … */ ],
    'instructionsPath' => '@root/.ai/mcp/instructions.md',
],
```

Husk `'bootstrap' => [… , 'mcp-api']`.

Ikke rør `config/routes.php` eller `migrations/` — modulen registrerer ruta og
oppretter tabellen selv.

## 4. Skriv instruksjonsfila

`.ai/mcp/instructions.md`, kort. Den sendes i **hver** `initialize` og koster
kontekst hos alle klienter. Ta med de tre–fire tingene som faktisk går galt:

- Hvilke sites finnes, og hva som skjer om `site` utelates
- Hvilke seksjoner som er feed-eide, og hva som aldri skal settes
- Om felthandles er på et annet språk enn innholdet
- At skriving går via utkast

Ikke lim inn ordlista eller domenereglene. De er for lange, og de hører i
resources — som ikke er implementert ennå.

## 5. Verifiser

Kjør røyktesten i [../testing-guidelines.md](../testing-guidelines.md).
Test minst ett negativt tilfelle: en seksjon med `create => false` skal avvise
oppretting og tillate lesing.

## 6. Skriv det ned i prosjektet

Prosjektets egen `.ai/` skal si at MCP finnes, hvor konfigurasjonen bor, og
hvorfor policyen er som den er. En ADR hvis prosjektet har `decisions/`.
