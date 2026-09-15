# Domeneregler

Regler koden ikke får bryte, og som ikke kan leses ut av mappestrukturen.
Endres en av dem, oppdater denne fila i samme commit.

## Skriving går alltid via utkast

`create_entry` og `update_entry` lager **utkast**. Ingenting blir live før
`publish_draft` kalles eksplisitt.

Dette er kontrakten `instructions` lover klienten, og den viktigste
beskyttelsen mot at en språkmodell publiserer noe ingen har sett — inkludert
tilfeller der innholdet den leste inneholdt instruksjoner. **Et nytt
skriveverktøy som går rett på live innhold bryter denne regelen.**

Unntaket er `update_asset`, som skriver direkte: assets har ikke noe
utkast-begrep i Craft. Det står i verktøyets egen beskrivelse, slik at
klienten vet det.

## Ingenting slettes

Det finnes **ingen `delete_entry`, ingen `delete_asset` og ingen
`revoke`-verktøy.** Sletting er irreversibelt og hører hjemme i CP-en, hos et
menneske. Ikke legg det til «for symmetri».

## Allowlisten er intensjon, ikke sikkerhet

`sections` sier hva som er **ment** for maskinell redigering. Crafts
permissions avgjør hva brukeren faktisk får. Begge må være på plass:
permissions alene holder ikke, fordi `canSave()` alltid er sann for
superadmins og de fleste tokens tilhører en admin.

**Policy er per operasjon.** `read`, `create` og `update` settes hver for seg,
fordi virkelige prosjekter trenger asymmetrien: en seksjon eid av en ekstern
feed skal kunne leses og rettes, aldri opprettes. Se
[ADR 0004](decisions/0004-skrivepolicy-per-operasjon.md).

## Håndhev i kode, ikke bare i skjemaet

Hvert verktøy som tar en seksjon kaller `SectionPolicy::assertAllowed()`, og
hvert verktøy som tar en site kaller `SiteHelper::resolve()` — selv om
`inputSchema` har et enum. Skjemaet er en hjelp til klienter som validerer,
ikke en grense: en rå HTTP-klient kan sende hva som helst.

## Deaktiverte sites avvises

`SiteHelper::resolve()` slår opp med `withDisabled: true` og nekter selv, med
en melding som sier at siten er deaktivert. Et prosjekt kan ha sites stående i
project config som ikke serverer noe, og en konfigurasjon som ved et uhell
nevner en av dem skal ikke gi en stille skriving dit.

## Feilmeldinger skal si hva som ER lov

Klienten er en språkmodell som prøver igjen. Et generist «not allowed» fører
til at den gjetter på et annet navn; en melding som lister de gyldige verdiene
fører til at den velger riktig. `SectionPolicy::assertAllowed()` skiller
derfor mellom «seksjonen finnes ikke» og «seksjonen finnes, men ikke for
denne operasjonen».

## Persondata er utenfor scope

**Brukere og Wishlist-/favorittdata skal ikke eksponeres.** Besluttet da
pakken ble laget. Entries og kategorier er scope; globals kan vurderes senere.
Utvidelse hit krever en ADR, ikke en commit.

## Pakken kjenner ikke prosjektet

Ingen seksjonshandle, site-handle, tokenprefiks, kundenavn eller domenetekst
skal finnes i `src/`. Alt slikt er konfigurasjon i prosjektets
`config/app.php`, eller en fil `instructionsPath` peker på.

Dette er ikke en stilregel. Pakken finnes fordi to installasjoner med
hardkodede verdier divergerer til to ulike moduler i løpet av få måneder.
