# 0006 — Tokenet sendes som header; URL-varianten vises ikke lenger

- **Dato:** 2026-09-25
- **Status:** Vedtatt. Opphever [0005](0005-token-i-url-sti.md) delvis
- **Besluttet av:** Mustasj-teamet, i v1.3.0

## Kontekst

ADR 0005 begrunnet `/<routePath>/t/<token>` med at Claude Desktops «Add custom
connector» bare tok imot en URL. Det stemmer ikke lenger: dialogen har «No
sign-in» og et felt for «Request headers». En Laravel-variant av samme
server kobler Claude Desktop til med URL og `authorization`-header hver for
seg, og det virker.

Tokenet i URL-en havner i access-logger, proxy-logger og nettleserhistorikk.
Den eksponeringen var bare akseptabel så lenge alternativet var at klienten
ikke kunne koble til i det hele tatt.

## Beslutning

- Token-fanen viser **bare** header-oppskrifter. For Claude Desktop er det tre
  felt: URL, header-navn (`authorization`) og header-verdi (`Bearer <token>`).
- `tokenInPath` beholdes som konfigurasjon med standard `true`, så
  installasjoner der noen allerede bruker URL-varianten ikke brekker. Å endre
  standarden er et brudd i konfigurasjonssnittet og hører til en major.
- Nye installasjoner bør sette `tokenInPath => false`.

## Konsekvenser

- URL-varianten kan ikke lenger oppdages fra CP-en. Den som vil bruke den, må
  vite om den.
- Ved neste major bør standarden bli `false`, eller ruta fjernes helt.
- Er OAuth på plass (se 0005), oppheves denne ADR-en også.
