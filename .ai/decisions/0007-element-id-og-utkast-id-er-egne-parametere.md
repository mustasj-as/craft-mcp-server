# 0007 — Element-ID og utkast-ID er egne parametere, og utkast er aldri offentlige

- **Dato:** 2026-09-29
- **Status:** Foreslått. Skrevet av Claude (Opus 5.5) sammen med Erlend, og venter på teamet.
- **Besluttet av:** —

## Kontekst

Craft har to tallrekker for utkast: **element-ID** (`elements.id`) og
**utkast-ID** (`drafts.id`). `create_entry` returnerer begge (`elementId`,
`draftId`). Tallrekkene overlapper. I HEM var utkast 1765 element 66344, mens
element 1765 var en nestet Matrix-blokk (`articleEditor`) i en helt annen
entry.

`update_entry` tok ett parameter, `entryId`, og beskrivelsen sa «Entry id or
draft id». Koden slo opp `Entry::find()->id($entryId)` uten seksjonsfilter, og
det ga tre feil samtidig:

1. **Et utkast-ID traff et annet element.** `update_entry(entryId: 1765)` ga
   `Unknown field "ingress". Valid handles: storrelse, bakgrunn, innhold`,
   som er feltene til Matrix-blokken. Hadde feltnavnene passet, ville
   feil innhold blitt endret.
2. **Nestede entries (Matrix-blokker) kunne redigeres direkte.** De har ingen
   seksjon, og filteret manglet.
3. **`SectionPolicy::UPDATE` ble aldri sjekket.** Et token kunne endre entries
   i seksjoner som ikke er eksponert, eller som er satt til `update: false`.

`get_entry` hadde motsatt feil: uten `->drafts(null)` fant den ikke utkast i
det hele tatt, så en agent kunne ikke lese tilbake det den nettopp hadde laget.
Det samme ble rapportert fra en annen installasjon samme dag (overlevering
under `context/handoffs/`, punkt 2).

`EntryAccess::requireViewable()` slapp i tillegg gjennom alt med status
`live`. **Et utkast av en publisert entry arver `postDate` og rapporterer
`live`.** Sjekken ville dermed vist upublisert utkastinnhold til et token
uten rett til å se utkast, så snart `get_entry` begynte å finne utkast.

## Beslutning

- **Ett parameter, én betydning.** `entryId` og `id` er alltid element-ID
  (også `elementId` fra `create_entry`). `draftId` er et eget, valgfritt
  parameter på `get_entry` og `update_entry`. `update_entry` krever nøyaktig
  ett av dem.
- **Alle oppslag fra skriveverktøyene filtreres på seksjoner som tillater
  operasjonen**, `->section(SectionPolicy::allowing(...))`. Det håndhever
  policyen og utelukker nestede entries i samme grep.
- **`get_entry` tar med utkast ved `id` og `draftId`, men ikke ved `slug`.**
  Element-ID-er er unike. En slug er det ikke, og et utkast med samme slug
  kunne ellers skygge for den live versjonen.
- **`list_entries(drafts: true)`** lister utkast av entries som aldri er
  publisert (`drafts()->draftOf(false)`). Uten `viewPeerEntryDrafts` for
  seksjonen ser brukeren bare sine egne.
- **Utkast er aldri offentlige** i `EntryAccess`. Status `live` slipper bare
  gjennom når entryen ikke er et utkast.
- Feilmeldingen fra `update_entry` foreslår `draftId` når et `entryId` ikke
  finnes, fordi det er den vanligste forvekslingen.

## Konsekvenser

- **Minor-bump.** `entryId` går fra påkrevd til valgfritt, og `draftId` og
  `drafts` er nye valgfrie parametere. Eksisterende kall med ekte entry-ID-er
  virker som før. Kall som sendte utkast-ID i `entryId` virket aldri
  pålitelig, og får nå en forklarende feil i stedet for å treffe feil element.
- `update_entry` kan ikke lenger endre entries i seksjoner med
  `update: false`. Det er en innstramming, men det er slik policyen alltid
  var ment (ADR 0004).
- `publish_draft` slår fortsatt opp på `draftId` uten seksjonsfilter. Det er
  entydig, men sjekker ikke `SectionPolicy`. Det er ikke rettet her (se
  overleveringen).
