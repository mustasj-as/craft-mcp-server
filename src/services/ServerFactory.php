<?php

namespace Mustasj\CraftMcp\services;

use Craft;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Registry\Loader\DiscoveryLoader;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mustasj\CraftMcp\helpers\SectionPolicy;
use Mustasj\CraftMcp\helpers\SiteHelper;
use Mustasj\CraftMcp\Module;
use Mustasj\CraftMcp\tools\ArticleTools;
use Mustasj\CraftMcp\tools\AssetTools;
use Mustasj\CraftMcp\tools\CategoryTools;
use Mustasj\CraftMcp\tools\ContentModelTools;
use Mustasj\CraftMcp\tools\WriteTools;
use yii\base\Component;

/**
 * Bygger MCP-serverinstansen.
 *
 * ## Hvorfor verktøyene registreres to ulike veier
 *
 * **Pakkens egne verktøy** registreres med `addTool()` og et eksplisitt
 * `inputSchema` bygget her, ved kjøring. Det er ikke en stilpreferanse:
 * SDK-ens attributt-variant (`#[Schema(enum: …)]`) krever konstante uttrykk,
 * og et PHP-attributt kan ikke slå opp i modulkonfigurasjonen. Så lenge
 * seksjonslista og språkkodene bodde i attributter, MÅTTE de være
 * klassekonstanter — altså måtte hvert prosjekt endre filer i pakken, som er
 * definisjonen på en fork. `Builder::addTool()` tar imot et ferdig skjema
 * (`ArrayLoader`: `$data['inputSchema'] ?? $schemaGenerator->generate(...)`),
 * og det er det som gjør konfigurasjonsdrevne enum-er mulige.
 *
 * **Prosjektets egne verktøy** oppdages fra `toolPaths` med vanlige
 * `#[McpTool]`-attributter. Der er hardkodede konstanter helt riktig: en
 * `PropertyTools` i en eiendomsportal eller en `RecipeTools` i en
 * oppskriftsbase er prosjektkode, og skal se ut som prosjektkode.
 *
 * Sesjoner (streamable HTTP, `Mcp-Session-Id`) lagres som filer under
 * `storage/runtime/mcp-sessions` og overlever dermed php-fpm-requests.
 *
 * @author Mustasj AS
 * @since 1.0.0
 */
class ServerFactory extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int Sesjonslevetid i sekunder
     */
    private const SESSION_TTL = 3600;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Bygger en ferdig konfigurert MCP-server.
     *
     * @return Server
     * @throws \Exception
     */
    public static function create(): Server
    {
        $module = Module::getInstance();
        $sessionDir = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'mcp-sessions';

        $builder = Server::builder()
            ->setServerInfo($module->serverName, $module->serverVersion, $module->serverDescription)
            ->setSession(new FileSessionStore($sessionDir, self::SESSION_TTL), ttl: self::SESSION_TTL);

        $instructions = self::_instructions($module);

        if ($instructions !== null) {
            $builder->setInstructions($instructions);
        }

        self::_addPackageTools($builder);
        self::_addProjectTools($builder);

        return $builder->build();
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Leser instruksjonsteksten fra fil.
     *
     * @param Module $module
     * @return string|null
     */
    private static function _instructions(Module $module): ?string
    {
        if ($module->instructionsPath === null) {
            return null;
        }

        $path = Craft::getAlias($module->instructionsPath);

        if (!is_string($path) || !is_file($path)) {
            Craft::warning("MCP: fant ingen instruksjonsfil på \"{$module->instructionsPath}\".", __METHOD__);

            return null;
        }

        return trim((string)file_get_contents($path)) ?: null;
    }

    /**
     * Registrerer pakkens egne verktøy med skjemaer bygget fra konfigurasjonen.
     *
     * @param Server\Builder $builder
     * @return void
     */
    private static function _addPackageTools(Server\Builder $builder): void
    {
        $sites = SiteHelper::keys();
        $readable = SectionPolicy::allowing(SectionPolicy::READ);
        $creatable = SectionPolicy::allowing(SectionPolicy::CREATE);
        $groups = Module::getInstance()->categoryGroups;

        $site = static fn(): array => [
            'type' => 'string',
            'enum' => $sites,
            'description' => 'Language/site code. Omitting this edits the default site.',
        ];

        $builder->addTool(
            [ArticleTools::class, 'listArticles'],
            name: 'list_entries',
            description: 'List entries in a section. Returns compact summaries; use get_entry for full content.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'section' => ['type' => 'string', 'enum' => $readable],
                    'query' => ['type' => ['string', 'null'], 'description' => 'Free-text search.'],
                    'site' => $site(),
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                ],
                'required' => ['section'],
            ],
        );

        $builder->addTool(
            [ArticleTools::class, 'getArticle'],
            name: 'get_entry',
            description: 'Fetch one entry in full, by id or by slug.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => ['integer', 'null']],
                    'slug' => ['type' => ['string', 'null']],
                    'section' => ['type' => 'string', 'enum' => $readable],
                    'site' => $site(),
                ],
            ],
        );

        $builder->addTool(
            [WriteTools::class, 'createEntry'],
            name: 'create_entry',
            description: 'Create a new entry as an unpublished draft. Nothing goes live until publish_draft is called. Returns the draft id and a control panel edit URL for human review.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'section' => ['type' => 'string', 'enum' => $creatable],
                    'title' => ['type' => 'string'],
                    'fields' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Field values keyed by field handle (see get_content_model).'],
                    'site' => $site(),
                    'slug' => ['type' => ['string', 'null']],
                    'entryType' => ['type' => ['string', 'null'], 'description' => 'Entry type handle. Defaults to the section\'s first entry type — required for sections with more than one.'],
                    'parentId' => ['type' => ['integer', 'null'], 'description' => 'Place under this parent entry, in structure sections.'],
                ],
                'required' => ['section', 'title'],
            ],
        );

        $builder->addTool(
            [WriteTools::class, 'updateEntry'],
            name: 'update_entry',
            description: 'Update an existing entry as a draft. Only the arguments you pass are changed. Nothing goes live until publish_draft is called.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => ['string', 'null']],
                    'fields' => ['type' => 'object', 'additionalProperties' => true],
                    'site' => $site(),
                    'slug' => ['type' => ['string', 'null']],
                ],
                'required' => ['id'],
            ],
        );

        $builder->addTool(
            [WriteTools::class, 'publishDraft'],
            name: 'publish_draft',
            description: 'Publish a draft created by create_entry or update_entry, making it live.',
            inputSchema: [
                'type' => 'object',
                'properties' => ['draftId' => ['type' => 'integer']],
                'required' => ['draftId'],
            ],
        );

        $builder->addTool(
            [CategoryTools::class, 'listCategories'],
            name: 'list_categories',
            description: 'List the categories in a group. Use the returned slugs as values for category fields when writing content.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'group' => ['type' => 'string', 'enum' => $groups],
                    'site' => $site(),
                ],
                'required' => ['group'],
            ],
        );

        $builder->addTool(
            [ContentModelTools::class, 'getContentModel'],
            name: 'get_content_model',
            description: 'Describe the sections, entry types, field handles and categories available. Call this before creating or updating content. Pass a section handle to describe only that one — the full model can be large.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'section' => ['type' => ['string', 'null'], 'enum' => [...$readable, null]],
                ],
            ],
        );

        $builder->addTool(
            [AssetTools::class, 'searchAssets'],
            name: 'search_assets',
            description: 'Find images and files already uploaded to Craft, by filename or title. Returns the asset id needed for asset fields. This server cannot upload files.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => ['string', 'null']],
                    'folder' => ['type' => ['string', 'null']],
                    'kind' => ['type' => 'string', 'enum' => ['image', 'pdf', 'video', 'audio', 'any']],
                    'missingAlt' => ['type' => 'boolean'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'site' => $site(),
                ],
            ],
        );

        $builder->addTool(
            [AssetTools::class, 'listAssetFolders'],
            name: 'list_asset_folders',
            description: 'List the asset folders available in Craft, with how many assets each one holds.',
            inputSchema: ['type' => 'object', 'properties' => []],
        );

        $builder->addTool(
            [AssetTools::class, 'updateAsset'],
            name: 'update_asset',
            description: 'Update metadata of an existing image or file: alt text, title and custom fields. The file itself is never touched. Unlike entries there is no draft step — this writes straight to the live asset.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'assetId' => ['type' => 'integer'],
                    'alt' => ['type' => ['string', 'null'], 'description' => 'Describe what is actually visible; do not guess.'],
                    'title' => ['type' => ['string', 'null']],
                    'fields' => ['type' => 'object', 'additionalProperties' => true],
                    'site' => $site(),
                ],
                'required' => ['assetId'],
            ],
        );
    }

    /**
     * Registrerer prosjektets egne verktøymapper som discovery-loadere.
     *
     * Én loader per mappe, fordi `setDiscovery()` bare holder på ett basePath
     * og et nytt kall ville overskrevet det forrige.
     *
     * @param Server\Builder $builder
     * @return void
     */
    private static function _addProjectTools(Server\Builder $builder): void
    {
        foreach (Module::getInstance()->toolPaths as $configured) {
            $path = Craft::getAlias($configured);

            if (!is_string($path) || !is_dir($path)) {
                Craft::warning("MCP: verktøymappa \"{$configured}\" finnes ikke.", __METHOD__);

                continue;
            }

            $builder->addLoader(new DiscoveryLoader($path, ['.'], [], new Discoverer()));
        }
    }
}
