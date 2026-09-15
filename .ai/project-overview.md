# Prosjektoversikt

## Hva det er

**`mustasj/craft-mcp-server`** — en Craft CMS 5-modul som eksponerer et
nettsteds innhold over **Model Context Protocol** (MCP), slik at AI-klienter
kan lese og skrive innhold gjennom et autentisert HTTP-endepunkt.

Pakken er **intern** i Mustasj AS og distribueres fra privat VCS
(`mustasj-as/craft-mcp-server`). Den er ikke publisert på Packagist, og er
ikke skrevet for bruk utenfor huset.

## Hvem bruker den

| Gruppe | Bruker den til |
|---|---|
| Redaktør med AI-klient | Lese og redigere innhold fra Claude Desktop, claude.ai eller Claude Code |
| Utvikler | Slå opp innholdsmodellen (`get_content_model`) uten å åpne CP-en |
| Prosjektet selv | Legge til domenespesifikke verktøy via `toolPaths` |

Tilgang krever et personlig token **og** Craft-permissionen «Tilgang til MCP».
Hva brukeren faktisk får gjøre, styres av Crafts egne permissions — tokenet
gir ikke rettigheter i seg selv.

## Verktøy pakken leverer

`list_entries` · `get_entry` · `create_entry` · `update_entry` ·
`publish_draft` · `list_categories` · `get_content_model` · `search_assets` ·
`list_asset_folders` · `update_asset`

Skriveverktøyene lager **utkast**. Ingenting går live uten `publish_draft`.

## Installasjoner

To Craft-prosjekter kjører den i dag. De er ikke navngitt her — dette repoet
er offentlig, og hvilke nettsteder som eksponerer et skriveendepunkt er ikke
noe repoet trenger å fortelle. Se [glossary.md](glossary.md) for hva
«opphavsprosjektet» og «stresstestprosjektet» viser til.

| Prosjekt | Status |
|---|---|
| **Opphavsprosjektet** | Opphavet. Kjører fortsatt sin egen kopi i `modules/mcp/` — **skal over på pakken**, og er akseptansetesten for `toolPaths` (det har et eget prosjektverktøy) |
| **Stresstestprosjektet** | Kjører pakken lokalt via path-repo. Ikke i prod |

## Historikk

Modulen ble skrevet for opphavsprosjektet. Da den skulle gjenbrukes, ble den
stresstestet i et annet prosjekt — en installasjon med fem sites (to aktive),
en ekstern feed som eier en seksjon, og en langt større innholdsmodell. Åtte
funn derfra formet konfigurasjonssnittet som finnes i dag. Se
[decisions/](decisions/); handoffen med de målte tallene ligger i det
prosjektets eget repo.
