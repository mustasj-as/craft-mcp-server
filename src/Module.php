<?php

namespace Mustasj\CraftMcp;

use Craft;
use craft\controllers\UsersController;
use craft\events\DefineEditUserScreensEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use Mustasj\CraftMcp\services\Tokens;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\Module as BaseModule;

/**
 * MCP-modul — eksponerer et Craft-nettsteds innhold over Model Context Protocol.
 *
 * Web: streamable HTTP-endepunkt (default `/mcp`) med bearer-autentisering mot
 * tokens knyttet til Craft-brukere. Tilgang krever permission
 * «Tilgang til MCP» ({@see PERMISSION_ACCESS}).
 *
 * CP: egen «MCP-tokens»-fane på brukersiden.
 *
 * Console-kommandoer (`<modul-id>` er det du kaller modulen i `app.php`):
 *   ddev exec php craft <modul-id>/tokens/create <bruker>
 *   ddev exec php craft <modul-id>/tokens/list
 *   ddev exec php craft <modul-id>/tokens/revoke <id>
 *
 * ## Oppsett
 *
 * Modulen konfigureres i `config/app.php`. Alt prosjektspesifikt er
 * konfigurasjon; ingen fil i pakken skal endres per installasjon.
 *
 * ```php
 * 'modules' => [
 *     'mcp-api' => [
 *         'class' => \Mustasj\CraftMcp\Module::class,
 *         'serverName' => 'Mitt nettsted Content API',
 *         'tokenPrefix' => 'mitt_',
 *         'sites' => ['nb' => 'default', 'en' => 'english'],
 *         'sections' => [
 *             'pages' => true,
 *             'produkter' => ['read' => true, 'create' => false, 'update' => true],
 *         ],
 *         'categoryGroups' => ['hovedkategori'],
 *         'instructionsPath' => '@root/.ai/mcp/instructions.md',
 *         'toolPaths' => ['@root/modules/mcpverktoy'],
 *     ],
 * ],
 * 'bootstrap' => ['mcp-api'],
 * ```
 *
 * Modul-ID-en er fri, men `mcp` er opptatt av `stimmt/craft-mcp` i prosjekter
 * som har den installert som dev-verktøy — `mcp-api` er vanen.
 *
 * @property-read Tokens $tokens
 * @author Mustasj AS
 * @since 1.0.0
 */
class Module extends BaseModule
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string Permission som kreves for å bruke MCP-endepunktet
     */
    public const PERMISSION_ACCESS = 'accessMcpApi';

    /**
     * @var string Screen-nøkkelen for token-fanen på brukersiden
     */
    public const USER_SCREEN = 'mcp-tokens';

    // =========================================================================
    // Public Properties — konfigurasjon
    // =========================================================================

    /**
     * @var string Navnet klienten ser i `initialize`
     */
    public string $serverName = 'Craft Content API';

    /**
     * @var string Én setning om hva serveren eksponerer
     */
    public string $serverDescription = 'Read and write content in Craft CMS';

    /**
     * @var string Versjonen som rapporteres til klienten
     */
    public string $serverVersion = '1.0.0';

    /**
     * @var string|null Sti til en markdown-fil med `instructions` for klienten
     *
     * Craft-alias tillatt (`@root/.ai/mcp/instructions.md`). Peker den på
     * `.ai/`, arver serveren vedlikeholdsregelen som alt gjelder der:
     * instruksjonene kan ikke råtne uten at dokumentasjonen også gjør det.
     * Er den null, sendes ingen instruksjoner.
     */
    public ?string $instructionsPath = null;

    /**
     * @var string Prefiks på utstedte tokens. MÅ være unikt per prosjekt, og
     *      MÅ matche regexen i sti-varianten av ruta hvis den er i bruk.
     */
    public string $tokenPrefix = 'craft_';

    /**
     * @var string URL-stien endepunktet svarer på
     */
    public string $routePath = 'mcp';

    /**
     * @var bool Om `/<routePath>/t/<token>` skal registreres i tillegg
     *
     * Finnes fordi Claude Desktops «Add custom connector» bare tar imot en URL
     * og ikke har noe felt for headere. Tokenet havner da i access-logger —
     * en bevisst avveiing, ikke en forglemmelse. Slå den av i prosjekter der
     * loggene er mindre beskyttet enn tokenet.
     */
    public bool $tokenInPath = true;

    /**
     * @var array<string, string> Språkkode → Craft site-handle
     */
    public array $sites = [];

    /**
     * @var array<string, array<string, bool>|bool> Seksjonshandle → skrivepolicy
     *
     * Enten `true` (alt lov) eller `['read' => bool, 'create' => bool,
     * 'update' => bool]`. Se {@see helpers\SectionPolicy}.
     */
    public array $sections = [];

    /**
     * @var string[] Kategorigrupper som eksponeres
     */
    public array $categoryGroups = [];

    /**
     * @var array<string, string> Feltmerknader, feltets handle → én setning
     *
     * For felt der den lagrede verdien ikke er det som vises. Ett prosjekt
     * har enhetskoder som er norske på alle sites og oversettes ved rendering;
     * en assistent som oversatte dem skrev ugyldige verdier. Slikt kan ikke
     * utledes av felttypen, og hører derfor i konfigurasjonen.
     */
    public array $fieldNotes = [];

    /**
     * @var string[] Mapper med prosjektets EGNE verktøyklasser
     *
     * Craft-alias tillatt. Klassene der oppdages via `#[McpTool]`-attributter,
     * på samme vis som pakkens egne. Dette er sømmet som gjør at et prosjekt
     * kan legge til domenespesifikke verktøy (en `PropertyTools`, en
     * `RecipeTools`) uten å forke pakken.
     */
    public array $toolPaths = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws InvalidConfigException hvis påkrevd konfigurasjon mangler
     */
    public function init(): void
    {
        // Aliaset MÅ hete akkurat dette. Yiis `Module::getControllerPath()`
        // utleder stien fra `controllerNamespace` med
        // `'@' . str_replace('\\', '/', $ns)` — altså `@Mustasj/CraftMcp/console`,
        // med store forbokstaver. Et alias med annen skrivemåte teller ikke:
        // aliaser er case-sensitive. Sto her som `@mustasj/craft-mcp`, og da
        // krasjet `php craft` — hele kommandolista — med «Invalid path alias»
        // i ethvert prosjekt som installerte pakken, fordi HelpController
        // lister kommandoene til hver modul.
        Craft::setAlias('@Mustasj/CraftMcp', __DIR__);

        $this->setComponents([
            'tokens' => Tokens::class,
        ]);

        $this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
            ? 'Mustasj\\CraftMcp\\console'
            : 'Mustasj\\CraftMcp\\controllers';

        parent::init();

        $this->_validateConfig();
        $this->_registerPermissions();
        $this->_registerSiteRoutes();

        if (!Craft::$app->getRequest()->getIsConsoleRequest() && Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpHooks();
        }
    }

    /**
     * Returnerer token-tjenesten.
     *
     * @return Tokens
     */
    public function getTokens(): Tokens
    {
        /** @var Tokens $tokens */
        $tokens = $this->get('tokens');
        $tokens->ensureTable();

        return $tokens;
    }

    /**
     * Avgjør om en CP-bruker kan administrere MCP-tokens for en gitt bruker:
     * admins for alle, ellers kun egne tokens — og bare med MCP-permission.
     *
     * @param \craft\elements\User $current Innlogget CP-bruker
     * @param \craft\elements\User $edited Brukeren tokens gjelder for
     * @return bool
     */
    public static function canManageTokensFor(\craft\elements\User $current, \craft\elements\User $edited): bool
    {
        if ($current->admin) {
            return true;
        }

        return $current->id === $edited->id && $current->can(self::PERMISSION_ACCESS);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Feiler tidlig på konfigurasjon som ellers ville gitt en uforståelig feil
     * langt inne i et verktøykall.
     *
     * @return void
     * @throws InvalidConfigException
     */
    private function _validateConfig(): void
    {
        if ($this->sites === []) {
            throw new InvalidConfigException(sprintf(
                '%s: "sites" må settes, f.eks. [\'nb\' => \'norwegian\'].',
                static::class,
            ));
        }

        if ($this->sections === []) {
            throw new InvalidConfigException(sprintf(
                '%s: "sections" må settes — uten den eksponerer serveren ingenting.',
                static::class,
            ));
        }

        // Prefikset går inn i en regex i sti-ruta. Et prefiks med regex-tegn
        // ville gitt en rute som matcher noe annet enn den ser ut til å gjøre.
        if (!preg_match('/^[a-z0-9]+_$/', $this->tokenPrefix)) {
            throw new InvalidConfigException(sprintf(
                '%s: "tokenPrefix" må være små bokstaver/tall etterfulgt av understrek, f.eks. "sm1_". Fikk "%s".',
                static::class,
                $this->tokenPrefix,
            ));
        }
    }

    /**
     * Registrerer permissionen «Tilgang til MCP» — for alle request-typer,
     * slik at både permissions-UI-et, `can()`-sjekker og project-config-synk
     * kjenner den.
     *
     * @return void
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => 'MCP',
                    'permissions' => [
                        self::PERMISSION_ACCESS => [
                            'label' => 'Tilgang til MCP',
                            'info' => 'Kan koble til MCP-endepunktet med personlig token og generere egne tokens.',
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Registrerer site-URL-reglene selv.
     *
     * Lå tidligere i prosjektets `config/routes.php`, sammen med en
     * håndskrevet regex på tokenprefikset. Det er én av tre filer hver
     * installasjon måtte røre; her er det null.
     *
     * @return void
     */
    private function _registerSiteRoutes(): void
    {
        $path = trim($this->routePath, '/');
        $id = $this->id;
        $prefix = $this->tokenPrefix;
        $withToken = $this->tokenInPath;

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) use ($path, $id, $prefix, $withToken): void {
                $event->rules[$path] = "$id/server/handle";

                if ($withToken) {
                    $event->rules["$path/t/<token:{$prefix}[0-9a-f]{48}>"] = "$id/server/handle";
                }
            },
        );
    }

    /**
     * Registrerer brukerside-fane, CP-routes og template-rot.
     *
     * @return void
     */
    private function _registerCpHooks(): void
    {
        $id = $this->id;

        Event::on(
            UsersController::class,
            UsersController::EVENT_DEFINE_EDIT_SCREENS,
            static function(DefineEditUserScreensEvent $event): void {
                if (self::canManageTokensFor($event->currentUser, $event->editedUser)) {
                    $event->screens[self::USER_SCREEN] = ['label' => 'MCP-tokens'];
                }
            },
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) use ($id): void {
                $event->rules['users/<userId:\d+>/' . self::USER_SCREEN] = "$id/tokens/index";
                $event->rules['myaccount/' . self::USER_SCREEN] = "$id/tokens/index";
            },
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            static function(RegisterTemplateRootsEvent $event) use ($id): void {
                $event->roots[$id] = __DIR__ . '/templates';
            },
        );
    }
}
