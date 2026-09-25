<?php

namespace Mustasj\CraftMcp\tools;

use Craft;
use craft\elements\Asset;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\models\VolumeFolder;
use Mcp\Exception\ToolCallException;
use Mustasj\CraftMcp\helpers\FieldValues;
use Mustasj\CraftMcp\helpers\SiteHelper;

/**
 * MCP-verktøy for assets (bilder og filer).
 *
 * Søk, mappeoversikt og redigering av metadata. Opplasting skjer i
 * kontrollpanelet — verktøyene her finner ID-en til et allerede opplastet
 * bilde, slik at den kan brukes i asset-felt via `create_entry`/`update_entry`,
 * som tar element-ID-er.
 *
 * Merk at assets ikke har utkast slik entries har: `update_asset` skriver
 * direkte. Det er derfor begrenset til metadata (alt-tekst, tittel, felter) —
 * selve fila røres aldri.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.1.0
 */
class AssetTools
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int Maks antall treff per søk
     */
    private const MAX_LIMIT = 100;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Søker etter assets på filnavn eller tittel.
     *
     * @param string|null $query Søketekst — delstreng av filnavn eller tittel
     * @param string|null $folder Mappenavn eller -sti å begrense til
     * @param string $kind Filtype («image», «pdf», «video», «any»)
     * @param bool $missingAlt Kun assets uten alt-tekst
     * @param int $limit Maks antall treff
     * @param string|null $site Språkkode. Utelatt gir standard-siten, se SiteHelper::defaultKey()
     * @return array
     * @throws ToolCallException ved ukjent mappe
     */
    public function searchAssets(
        ?string $query = null,
        ?string $folder = null,
        string $kind = 'image',
        bool $missingAlt = false,
        int $limit = 25,
        ?string $site = null,
    ): array {
        $site ??= SiteHelper::defaultKey();

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $siteHandle = SiteHelper::resolve($site)->handle;
        $folderModel = $folder !== null ? $this->_resolveFolder($folder) : null;

        $baseQuery = static function() use ($siteHandle, $folderModel, $kind, $missingAlt) {
            $assets = Asset::find()
                ->site($siteHandle)
                ->limit(null);

            if ($folderModel !== null) {
                $assets->folderId($folderModel->id)->includeSubfolders();
            }

            if ($kind !== 'any') {
                $assets->kind($kind);
            }

            // `alt` er en kolonne på assets-tabellen, ikke en query-metode.
            if ($missingAlt) {
                $assets->andWhere(['or', ['assets.alt' => null], ['assets.alt' => '']]);
            }

            return $assets;
        };

        // Filnavn og tittel kan ikke OR-es i én element-query, så de kjøres
        // hver for seg og slås sammen. Filnavn først — det er det brukeren
        // oppgir, og tittel er ofte bare filnavnet uten endelse.
        $results = [];

        if ($query !== null && $query !== '') {
            $wildcard = '*' . $query . '*';

            foreach ([$baseQuery()->filename($wildcard), $baseQuery()->title($wildcard)] as $assetQuery) {
                foreach ($assetQuery->all() as $asset) {
                    $results[$asset->id] = $asset;
                }
            }
        } else {
            foreach ($baseQuery()->orderBy(['dateCreated' => SORT_DESC])->all() as $asset) {
                $results[$asset->id] = $asset;
            }
        }

        $results = $this->_filterViewable($results);
        $total = count($results);

        return [
            'total' => $total,
            'returned' => min($total, $limit),
            'assets' => array_map(
                fn(Asset $asset) => $this->_serialize($asset),
                array_slice(array_values($results), 0, $limit),
            ),
        ];
    }

    /**
     * Lister asset-mapper, ett nivå om gangen.
     *
     * Svaret starter alltid med `summary` — antall mapper og assets per volum,
     * regnet med én COUNT per volum — slik at klienten ser omfanget før den
     * graver. Det var hele treet i ett svar før, og ett prosjekt har én mappe
     * per eiendom: 5 420 mapper, 425 000 tegn, og en COUNT-spørring per mappe.
     * Nå telles bare mappene som faktisk returneres.
     *
     * Uten `parent` og `query` listes volumenes rotmapper. Med `parent` listes
     * mappens direkte undermapper. Med `query` søkes det på mappenavn i hele
     * treet (eller under `parent`), fordi en mappe som heter etter en
     * referanse ellers bare kan finnes ved å bla.
     *
     * @param string|null $parent Mappenavn eller -sti å liste undermappene til
     * @param string|null $query Delstreng av mappenavnet
     * @param int $limit Maks antall mapper
     * @param int $offset Antall mapper å hoppe over
     * @return array
     * @throws ToolCallException ved ukjent mappe
     */
    public function listAssetFolders(
        ?string $parent = null,
        ?string $query = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $parentModel = $parent !== null ? $this->_resolveFolder($parent) : null;

        $summary = array_map(static fn($volume) => [
            'volume' => $volume->name,
            'folders' => (int)(new Query())
                ->from(Table::VOLUMEFOLDERS)
                ->where(['volumeId' => $volume->id])
                ->count(),
            'assets' => (int)Asset::find()->volumeId($volume->id)->count(),
        ], $volumes);

        $folderQuery = (new Query())
            ->select(['id', 'parentId', 'volumeId', 'name', 'path'])
            ->from(Table::VOLUMEFOLDERS)
            ->where(['volumeId' => array_map(static fn($volume) => $volume->id, $volumes)])
            ->orderBy(['path' => SORT_ASC, 'name' => SORT_ASC]);

        if ($query !== null && $query !== '') {
            // Postgres' LIKE skiller store og små bokstaver, MySQLs gjør det
            // ikke med standard kollasjon — og `ilike` finnes bare i Yiis
            // Postgres-driver. Pakken kjører på begge.
            $like = Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like';
            $folderQuery->andWhere([$like, 'name', $query]);

            if ($parentModel !== null) {
                $folderQuery->andWhere(['volumeId' => $parentModel->volumeId]);

                // Rotmappa har tom sti, og da er hele volumet «under» den.
                if ($parentModel->path) {
                    $folderQuery->andWhere(['like', 'path', addcslashes($parentModel->path, '%_\\') . '%', false]);
                }
            }
        } elseif ($parentModel !== null) {
            $folderQuery->andWhere(['parentId' => $parentModel->id]);
        } else {
            $folderQuery->andWhere(['parentId' => null]);
        }

        $total = (int)(clone $folderQuery)->count();
        $rows = $folderQuery->limit($limit)->offset($offset)->all();
        $ids = array_column($rows, 'id');

        // Én gruppert spørring for undermappene, ikke én per rad.
        $subfolderCounts = $ids === [] ? [] : (new Query())
            ->select(['parentId', 'count' => 'COUNT(*)'])
            ->from(Table::VOLUMEFOLDERS)
            ->where(['parentId' => $ids])
            ->groupBy('parentId')
            ->pairs();

        $volumeNames = [];

        foreach ($volumes as $volume) {
            $volumeNames[$volume->id] = $volume->name;
        }

        $folders = array_map(static fn(array $row) => [
            'name' => $row['name'],
            'path' => $row['path'] ?: '/',
            'volume' => $volumeNames[$row['volumeId']] ?? null,
            // Bare assets direkte i mappa — search_assets med `folder` tar
            // med undermappene.
            'assetCount' => (int)Asset::find()->folderId($row['id'])->count(),
            'subfolderCount' => (int)($subfolderCounts[$row['id']] ?? 0),
        ], $rows);

        $result = [
            'summary' => $summary,
            'total' => $total,
            'returned' => count($folders),
            'offset' => $offset,
            'folders' => $folders,
        ];

        if ($offset + count($folders) < $total) {
            $result['hint'] = sprintf(
                'Showing %d of %d folders. Use offset to page, "query" to search by folder name, or "parent" to go one level down.',
                count($folders),
                $total,
            );
        }

        return $result;
    }

    /**
     * Oppdaterer metadata på en asset.
     *
     * @param int $assetId Asset-ID (se search_assets)
     * @param string|null $alt Alt-tekst
     * @param string|null $title Tittel
     * @param array $fields Egendefinerte feltverdier per handle
     * @param string|null $site Språkkode. Utelatt gir standard-siten, se SiteHelper::defaultKey()
     * @return array
     * @throws \Throwable
     */
    public function updateAsset(
        int $assetId,
        ?string $alt = null,
        ?string $title = null,
        array $fields = [],
        ?string $site = null,
    ): array {
        $site ??= SiteHelper::defaultKey();

        $user = Craft::$app->getUser()->getIdentity();

        if (!$user instanceof User) {
            throw new ToolCallException('No authenticated user.');
        }

        if ($alt === null && $title === null && $fields === []) {
            throw new ToolCallException('Nothing to update — pass at least one of "alt", "title" or "fields".');
        }

        /** @var Asset|null $asset */
        $asset = Asset::find()
            ->id($assetId)
            ->site(SiteHelper::resolve($site)->handle)
            ->one();

        if ($asset === null) {
            throw new ToolCallException(sprintf('Asset %d not found on site "%s". Use search_assets to look up the id.', $assetId, $site));
        }

        if (!Craft::$app->getElements()->canSave($asset, $user)) {
            throw new ToolCallException(sprintf('The API token user is not allowed to edit assets in volume "%s".', $asset->getVolume()->name));
        }

        if ($alt !== null) {
            $asset->alt = $alt;
        }

        if ($title !== null) {
            $asset->title = $title;
        }

        if ($fields !== []) {
            FieldValues::apply($asset, $fields);
        }

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new ToolCallException(sprintf(
                'Could not save asset %d: %s',
                $assetId,
                implode('; ', array_map(
                    static fn(string $handle, array $errors) => $handle . ': ' . implode(', ', $errors),
                    array_keys($asset->getErrors()),
                    $asset->getErrors(),
                )),
            ));
        }

        return [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'site' => $site,
            'alt' => $asset->alt,
            'title' => $asset->title,
            'note' => 'Saved. Assets have no draft step, so this is already live.',
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Finner en mappe på navn eller sti.
     *
     * @param string $folder Mappenavn eller -sti
     * @return VolumeFolder
     * @throws ToolCallException hvis mappen ikke finnes
     */
    private function _resolveFolder(string $folder): VolumeFolder
    {
        $needle = trim($folder, '/');

        // Kun mapper som hører til et volum — Craft har også temp-mapper
        // (opplastingskø, per bruker) uten volumeId, som ikke skal være
        // søkbare eller nevnes i feilmeldinger.
        $candidates = array_filter(
            Craft::$app->getAssets()->findFolders([]),
            static fn(VolumeFolder $candidate) => $candidate->volumeId !== null,
        );

        foreach ($candidates as $candidate) {
            if (strcasecmp($candidate->name, $needle) === 0
                || strcasecmp(trim((string)$candidate->path, '/'), $needle) === 0
            ) {
                return $candidate;
            }
        }

        // Taket er ikke kosmetisk: ett prosjekt har 5 420 mapper, og hele lista
        // i en feilmelding blir en feilmelding ingen kan lese.
        $names = array_values(array_unique(array_map(
            static fn(VolumeFolder $candidate) => $candidate->name,
            $candidates,
        )));
        $shown = array_slice($names, 0, 20);
        $rest = count($names) - count($shown);

        throw new ToolCallException(sprintf(
            'Unknown asset folder "%s". Some available folders: %s%s. Call list_asset_folders with "query" to search by name.',
            $folder,
            implode(', ', $shown),
            $rest > 0 ? sprintf(' (and %d more)', $rest) : '',
        ));
    }

    /**
     * Fjerner assets den autentiserte brukeren ikke har lesetilgang til.
     *
     * Volumtilgang styres per brukergruppe i Craft, så et token med
     * MCP-permission skal ikke automatisk se alle volumer.
     *
     * @param Asset[] $assets Assets indeksert på ID
     * @return Asset[]
     */
    private function _filterViewable(array $assets): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return [];
        }

        $elements = Craft::$app->getElements();

        return array_filter($assets, static fn(Asset $asset) => $elements->canView($asset, $user));
    }

    /**
     * Serialiserer en asset til verktøysvaret.
     *
     * @param Asset $asset
     * @return array
     */
    private function _serialize(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'title' => $asset->title,
            'kind' => $asset->kind,
            'folder' => $asset->getFolder()->name,
            'url' => $asset->getUrl(),
            'width' => $asset->width,
            'height' => $asset->height,
            'size' => $asset->size !== null ? (int)$asset->size : null,
            'alt' => $asset->alt,
        ];
    }
}
