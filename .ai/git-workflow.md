# Git-arbeidsflyt

## Hva repoet faktisk gjør

**Trunk-basert, med tagger.** Én langlevd branch, `main`, som til enhver tid er
det installasjonene henter. Ingen `develop`.

Dette **avviker fra gitflow-standarden** i teamets `.ai`-mal, og det er
bevisst: pakken er liten (17 filer), har få bidragsytere, og har ingen
release-kandidat-fase å koordinere. En `develop` uten integrasjonsbehov gir
bare et ekstra merge-steg. Vokser pakken eller får flere samtidige
arbeidsstrømmer, er gitflow riktig — da hører det en ny ADR.

## Brancher

Arbeid som er større enn en commit går på `feature/<kort-kebab-beskrivelse>`
fra `main`, og merges tilbake dit.

```
feature/oauth-stoette
feature/resources-fra-ai-mappa
bugfix/sesjonsfil-laases-ved-parallelle-kall
```

Ingen issue-nøkler — teamet sporer ikke oppgaver i dette repoet.

## Versjonering

**Semver, med tagger på `main`.** Taggene er det installasjonene binder seg
til (`composer require mustasj/craft-mcp-server:^1.0`), så de er en kontrakt:

| Endring | Bump |
|---|---|
| Nytt verktøy, ny konfigurasjonsnøkkel med default | minor |
| Endret/fjernet konfigurasjonsnøkkel, endret verktøysignatur, ny påkrevd nøkkel | **major** |
| Feilretting uten endret grensesnitt | patch |

Husk at **konfigurasjonssnittet er det offentlige API-et her**, ikke bare
PHP-klassene. Fjerner du en nøkkel fra `Module`, brekker du hver
installasjons `config/app.php`.

Tagg med melding:

```sh
git tag -a v1.1.0 -m "Resources fra .ai/, og list_drafts"
git push origin v1.1.0
```

## Commit-meldinger

Norsk, imperativ, én linje som sier hva endringen gjør. Scope foran kolon når
det hjelper:

```
SectionPolicy: skill mellom ukjent seksjon og ikke-tillatt operasjon
Tokens: memoiser tabellsjekken, den kalles på hver request
README, og skill deaktivert site fra manglende site
```

Utdypende forklaring i brødteksten når endringen trenger en begrunnelse.

## Etter en endring som treffer installasjonene

1. Tagg en ny versjon.
2. `composer update mustasj/craft-mcp-server` i hvert prosjekt som bruker den.
3. Kjør røyktesten i [testing-guidelines.md](testing-guidelines.md) der.

Det finnes ingen CI som fanger en brekkende endring for deg.
