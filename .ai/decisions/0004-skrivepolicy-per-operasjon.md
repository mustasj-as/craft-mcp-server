# 0004 — Skrivepolicy settes per operasjon, ikke per seksjon

- **Dato:** 2026-09-14
- **Status:** Vedtatt
- **Besluttet av:** Mustasj-teamet

## Kontekst

Modulen hadde to flate lister: `Sections::ALL` styrte skriving,
`Sections::ARTICLE_LIKE` styrte lesing.

Stresstesten i det andre prosjektet viste hva det gir i et prosjekt med en
ekstern datakilde. Der eies seksjonen `realEstate` av en XML-feed: eiendommer skal
**aldri opprettes** fra CMS-siden, men redaksjonelle **rettelser** er hele
poenget med tilgangen. Med `realEstate` i `ALL` ble resultatet stikk motsatt,
og det ble målt:

- `list_entries(section: "realEstate")` → **avvist** (enumet kom fra
  `ARTICLE_LIKE`)
- `create_entry(section: "realEstate")` → **lyktes**, utkast 2603 opprettet

## Beslutning

`sections` er et kart fra handle til policy:

```php
'sections' => [
    'pages' => true,                                                  // alt lov
    'realEstate' => ['read' => true, 'create' => false, 'update' => true],
],
```

`SectionPolicy::allowing($operation)` bygger enum-ene i verktøyskjemaene, og
`SectionPolicy::assertAllowed($handle, $operation)` håndhever i kode.

Kortformen `true` finnes fordi de fleste seksjoner i de fleste prosjekter er
helt vanlige, og en konfigurasjon der hver linje er tre nøkler blir uleselig.

## Konsekvenser

- Konfigurasjonen kan uttrykke redaksjonell intensjon som faktisk finnes.
- Feilmeldingen skiller mellom «seksjonen finnes ikke» og «seksjonen finnes,
  men ikke for denne operasjonen» — en assistent som får et generisk nei
  prøver igjen med et gjettet navn.
- **Grensen går ved seksjonsnivå.** Feltnivåregler, valideringsuttrykk eller
  betingelser i konfigurasjonen er en glidebane mot et DSL i en PHP-array. Alt
  mer nyansert hører i et prosjektspesifikt verktøy, der det er vanlig PHP som
  kan leses og feilsøkes.

## Verifisert etter endringen

Samme to kall, mot pakken: `list_entries(realEstate)` → 93 treff.
`create_entry(realEstate)` → avvist med «allowed values: pages, projects, faq».
