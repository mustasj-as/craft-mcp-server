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
     * Beskrivelsene er lange med vilje. De er det eneste modellen vet om
     * verktøyet før den kaller det, og da pakken ble trukket ut av sitt første
     * prosjekt, der de sto i `#[McpTool]`/`#[Schema]`-attributter, ble de
     * kortet ned til én setning — og asset-verktøyene ble merkbart dårligere
     * brukt. Ikke kort dem inn igjen.
     *
     * @param Server\Builder $builder
     * @return void
     */
    private static function _addPackageTools(Server\Builder $builder): void
    {
        $sites = SiteHelper::keys();
        $defaultSite = SiteHelper::defaultKey();
        $readable = SectionPolicy::allowing(SectionPolicy::READ);
        $creatable = SectionPolicy::allowing(SectionPolicy::CREATE);
        $groups = Module::getInstance()->categoryGroups;

        $site = static fn(string $extra = ''): array => [
            'type' => 'string',
            'enum' => $sites,
            'description' => trim(sprintf('Language/site code. Omit for the default site ("%s"). %s', $defaultSite, $extra)),
        ];

        self::_addTool($builder, [ArticleTools::class, 'listArticles'], 'list_entries',
            'List entries in a section, newest first. Returns compact summaries (id, title, slug, status, url, postDate) and the total count; use get_entry for full content. Use limit/offset to page through large sections.',
            [
                'section' => ['type' => 'string', 'enum' => $readable],
                'query' => ['type' => ['string', 'null'], 'description' => 'Free-text search across the entry\'s searchable content.'],
                'site' => $site(),
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Maximum number of results (1-100). Default 20.'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Number of results to skip, for paging.'],
            ],
            ['section'],
        );

        self::_addTool($builder, [ArticleTools::class, 'getArticle'], 'get_entry',
            'Get one entry in full, by id or by slug, including all fields and content blocks. Pass either id or slug.',
            [
                'section' => ['type' => 'string', 'enum' => $readable],
                'id' => ['type' => ['integer', 'null'], 'description' => 'Entry id (from list_entries).'],
                'slug' => ['type' => ['string', 'null'], 'description' => 'Entry slug. Slugs can differ per site.'],
                'site' => $site(),
            ],
            ['section'],
        );

        self::_addTool($builder, [WriteTools::class, 'createEntry'], 'create_entry',
            'Create a new entry as an unpublished draft. Nothing goes live until publish_draft is called. Call get_content_model first to learn the section\'s entry types and field handles. Returns the draft id and a control panel edit URL for human review.',
            [
                'section' => ['type' => 'string', 'enum' => $creatable],
                'title' => ['type' => 'string'],
                'fields' => ['type' => 'object', 'additionalProperties' => true, 'description' => WriteTools::FIELDS_DESCRIPTION],
                'site' => $site(),
                'slug' => ['type' => ['string', 'null'], 'description' => 'Omit to let Craft generate it from the title.'],
                'entryType' => ['type' => ['string', 'null'], 'description' => 'Entry type handle (see get_content_model). Defaults to the section\'s first entry type — required for sections that have more than one.'],
                'parentId' => ['type' => ['integer', 'null'], 'description' => 'Place the entry under this parent entry, in sections where "isStructure" is true (see get_content_model). Omit for top level.'],
            ],
            ['section', 'title'],
        );

        self::_addTool($builder, [WriteTools::class, 'updateEntry'], 'update_entry',
            'Update an existing entry by creating a draft of it — the live entry is never modified directly. Accepts both canonical entry ids and draft ids (from create_entry); editing a draft modifies it in place. Only the arguments you pass are changed. Returns the draft id; call publish_draft to apply.',
            [
                'entryId' => ['type' => 'integer', 'description' => 'Entry id or draft id.'],
                'fields' => ['type' => 'object', 'additionalProperties' => true, 'description' => WriteTools::FIELDS_DESCRIPTION],
                'title' => ['type' => ['string', 'null']],
                'site' => $site(),
                'parentId' => ['type' => ['integer', 'null'], 'description' => 'Move the entry under this parent, in sections where "isStructure" is true. Pass 0 to move it to the top level. Omit to leave the position unchanged. The move takes effect when the draft is published.'],
            ],
            ['entryId'],
        );

        self::_addTool($builder, [WriteTools::class, 'publishDraft'], 'publish_draft',
            'Publish a draft created by create_entry or update_entry, making it live. Validates against the full ("live") validation rules and reports per-field errors if it fails — fix them with update_entry on the same draft id and try again.',
            [
                'draftId' => ['type' => 'integer'],
                'site' => $site(),
            ],
            ['draftId'],
        );

        self::_addTool($builder, [CategoryTools::class, 'listCategories'], 'list_categories',
            'List the categories in a group. Use the returned slugs as values for category fields when writing content.',
            [
                'group' => ['type' => 'string', 'enum' => $groups],
                'site' => $site(),
            ],
            ['group'],
        );

        self::_addTool($builder, [ContentModelTools::class, 'getContentModel'], 'get_content_model',
            'Describe the content model: sections, entry types, field handles and types (including Matrix block types), category groups, asset volumes and sites. Call this before creating or updating content so you know the exact shape of each section. Pass a section handle to describe only that one — the full model can be large.',
            [
                'section' => ['type' => ['string', 'null'], 'enum' => [...$readable, null]],
            ],
        );

        self::_addTool($builder, [AssetTools::class, 'searchAssets'], 'search_assets',
            'Find images and files that are already uploaded to Craft, by filename or title. Returns the asset id needed for asset fields in create_entry and update_entry. Matching is a case-insensitive substring of either the filename or the title, so a partial name is enough. Deleted assets in the trash are never returned. Whether alt text can differ per language is reported by get_content_model under "assets" — check it before writing alt text on a non-default site. This server cannot upload files; uploading is done in the Craft control panel.',
            [
                'query' => ['type' => ['string', 'null'], 'description' => 'Substring of the filename or title. Omit to list everything matching the other filters, newest first.'],
                'folder' => ['type' => ['string', 'null'], 'description' => 'Restrict to a folder, by name or path (see list_asset_folders). Includes subfolders.'],
                'kind' => ['type' => 'string', 'enum' => ['image', 'pdf', 'video', 'audio', 'any'], 'description' => 'Default "image".'],
                'missingAlt' => ['type' => 'boolean', 'description' => 'Only return assets that have no alt text.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Maximum number of results (1-100). Default 25.'],
                'site' => $site(),
            ],
        );

        self::_addTool($builder, [AssetTools::class, 'listAssetFolders'], 'list_asset_folders',
            'List asset folders one level at a time. Every response starts with "summary": the total number of folders and assets per volume — check it before browsing, since some sites have thousands of folders. Without arguments it lists the volume root folders; pass "parent" to list a folder\'s direct subfolders, or "query" to search folder names anywhere in the tree. Each folder reports assetCount (assets directly in it) and subfolderCount. Use a folder name or path with search_assets to narrow a search.',
            [
                'parent' => ['type' => ['string', 'null'], 'description' => 'Folder name or path whose direct subfolders to list. With "query", restricts the search to this folder\'s subtree.'],
                'query' => ['type' => ['string', 'null'], 'description' => 'Case-insensitive substring of the folder name.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Maximum number of folders (1-100). Default 50.'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Number of folders to skip, for paging.'],
            ],
        );

        self::_addTool($builder, [AssetTools::class, 'updateAsset'], 'update_asset',
            'Update the metadata of an existing image or file: alt text, title and custom fields. The file itself is never touched and cannot be replaced here. Unlike entries there is no draft step — this writes straight to the live asset. Only the arguments you pass are changed. Check get_content_model under "assets" first: when altTranslatable is true, alt text is written only to the site you pass in "site"; when it is false, writing it on one site overwrites the others.',
            [
                'assetId' => ['type' => 'integer', 'description' => 'Asset id (from search_assets).'],
                'alt' => ['type' => ['string', 'null'], 'description' => 'Alt text describing the image, in the language of the chosen site. Describe what is actually visible; do not guess.'],
                'title' => ['type' => ['string', 'null']],
                'fields' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Custom field values keyed by field handle (see get_content_model).'],
                'site' => $site(),
            ],
            ['assetId'],
        );
    }

    /**
     * Registrerer ett verktøy med et ferdig skjema, og sjekker at skjemaet
     * passer metoden.
     *
     * Tre feil har sluppet gjennom her før, alle stille:
     *
     * 1. **Skjemanøkler som ikke er parameternavn.** SDK-en kobler argumenter
     *    til parametere ETTER NAVN. `update_entry` sendte `id` til en metode
     *    som tok `$entryId` og feilet på hvert kall; `get_content_model`
     *    sendte `section` til `$only`, og filteret ble ignorert. Derfor
     *    sammenlignes skjemaet mot metoden med refleksjon, og et avvik kaster —
     *    det er kode i pakken, ikke konfigurasjon, så første request på et
     *    hvilket som helst miljø avslører det.
     * 2. **Tom `properties` som `[]`.** json_encode gjør en tom PHP-array til en
     *    JSON-liste; klienter som validerer tools/list (Claude Code) forkaster
     *    da HELE lista. SDK-en retter dette for attributt-verktøy, men ikke for
     *    skjemaer gitt til `addTool()`.
     * 3. **Tom `enum`.** Et prosjekt uten kategorigrupper ga `"enum": []`, som
     *    er ugyldig JSON Schema (minst ett element) med samme følge som 2.
     *    Tom enum fjernes; verdien valideres uansett i verktøyet.
     *
     * @param Server\Builder $builder
     * @param array{0: class-string, 1: string} $handler
     * @param string $name
     * @param string $description
     * @param array<string, array> $properties
     * @param string[] $required
     * @return void
     * @throws \LogicException hvis skjemaet ikke passer metoden
     */
    private static function _addTool(
        Server\Builder $builder,
        array $handler,
        string $name,
        string $description,
        array $properties,
        array $required = [],
    ): void {
        $parameters = [];

        foreach ((new \ReflectionMethod($handler[0], $handler[1]))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        foreach (array_keys($properties) as $key) {
            if (!isset($parameters[$key])) {
                throw new \LogicException("MCP-verktøyet {$name}: skjemaet har «{$key}», men {$handler[0]}::{$handler[1]}() har ingen slik parameter.");
            }
        }

        foreach ($parameters as $key => $parameter) {
            if (!$parameter->isOptional() && !in_array($key, $required, true)) {
                throw new \LogicException("MCP-verktøyet {$name}: {$handler[0]}::{$handler[1]}() krever «{$key}», men skjemaet har den ikke som required.");
            }
        }

        foreach ($properties as $key => $property) {
            if (!isset($property['enum'])) {
                continue;
            }

            // `[null]` alene (en nullbar enum uten verdier) er like tom.
            if (array_filter($property['enum'], static fn($value) => $value !== null) === []) {
                unset($properties[$key]['enum']);
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $builder->addTool($handler, name: $name, description: $description, inputSchema: $schema);
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
