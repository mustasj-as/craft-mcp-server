<?php

namespace Mustasj\CraftMcp\controllers;

use Carbon\Carbon;
use Craft;
use craft\controllers\EditUserTrait;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Mustasj\CraftMcp\helpers\McpAccess;
use Mustasj\CraftMcp\Module;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * «MCP-tokens»-fanen på brukersiden i kontrollpanelet.
 *
 * Lar admins (for alle) og brukere med MCP-permission (for seg selv)
 * generere og trekke tilbake API-tokens. Tokenet vises i klartekst én gang
 * rett etter generering, via en session-flash.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class TokensController extends Controller
{
    use EditUserTrait;

    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string Flash-nøkkel for nygenerert token (vises kun én gang)
     */
    private const FLASH_NEW_TOKEN = 'mcpNewToken';

    /**
     * @var array<int, string> Gyldighet i dager → etikett, lengst først
     *
     * Fast liste, ikke fritt tall, og ingen «aldri». Et token som aldri utløper
     * blir liggende på en maskin noen har glemt; med en frist må det i verste
     * fall fornyes, og det er den billige feilen av de to. Tolv måneder som
     * tak følger Laravel-varianten av samme skjerm.
     */
    public const EXPIRY_OPTIONS = [
        365 => '12 mnd',
        182 => '6 mnd',
        91 => '3 mnd',
        30 => '1 mnd',
        7 => '1 uke',
    ];

    /**
     * @var int Forhåndsvalgt gyldighet
     */
    public const EXPIRY_DEFAULT = 91;

    /**
     * @var int Maks lengde på et tokennavn
     */
    private const NAME_MAX = 40;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \Throwable
     */
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();

        return parent::beforeAction($action);
    }

    /**
     * Viser token-fanen for en bruker.
     *
     * @param int|null $userId Bruker-ID, eller null for «Min konto»
     * @return Response
     * @throws ForbiddenHttpException
     */
    public function actionIndex(?int $userId = null): Response
    {
        $user = $this->editedUser($userId);
        $this->_requireTokenAccess($user);

        /** @var Module $module */
        $module = $this->module;

        /** @var Response|\craft\web\CpScreenResponseBehavior $response */
        $response = $this->asEditUserScreen($user, Module::USER_SCREEN);

        $currentUser = static::currentUser();
        $isCurrentUser = $user->getIsCurrent();
        $newToken = Craft::$app->getSession()->getFlash(self::FLASH_NEW_TOKEN);

        return $response
            ->contentTemplate("{$module->id}/tokens-screen", [
                'user' => $user,
                'tokens' => $module->getTokens()->getTokensForUser($user->id),
                // Klarteksten skal bare vises til den som eier tokenet.
                'newToken' => $isCurrentUser ? $newToken : null,
                'isCurrentUser' => $isCurrentUser,
                'accessSource' => McpAccess::source($user),
                // Admin på andres fane. På sin egen er admin alltid admin, og
                // en bryter der ville ikke gjort noe.
                'canManageAccess' => $currentUser->admin && !$isCurrentUser && McpAccess::canToggle(),
                'expiryOptions' => self::EXPIRY_OPTIONS,
                'expiryDefault' => self::EXPIRY_DEFAULT,
                'screenUrl' => $this->_screenUrl($user),
                // Malen skal ikke kjenne ÉN installasjon. Modul-ID-en er fri
                // (`app.php` bestemmer den), ruta er konfigurerbar, og
                // `serverSlug` er navnet brukeren registrerer klienten under —
                // tokenPrefikset uten understrek, som allerede er validert
                // unikt per prosjekt og dermed trygt i en JSON-nøkkel.
                'moduleId' => $module->id,
                'mcpUrl' => UrlHelper::siteUrl($module->routePath),
                'serverSlug' => rtrim($module->tokenPrefix, '_'),
                'serverName' => $module->serverName,
                'tokenInPath' => $module->tokenInPath,
            ]);
    }

    /**
     * Genererer et nytt token og legger klarteksten i en flash.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws \yii\db\Exception
     */
    public function actionCreate(): Response
    {
        $this->requirePostRequest();

        $user = $this->editedUser($this->request->getBodyParam('userId') ?: null);
        $this->_requireTokenAccess($user);

        // Bare egne tokens. En admin som laget et token på en annens fane,
        // fikk se klarteksten — og kunne da opptre som den brukeren overfor
        // enhver AI-klient. Admin kan fortsatt trekke tilbake andres tokens.
        if (!$user->getIsCurrent()) {
            throw new ForbiddenHttpException('Tokens kan bare lages av brukeren selv.');
        }

        if (McpAccess::source($user) === null) {
            throw new ForbiddenHttpException('Du har ikke MCP-tilgang.');
        }

        /** @var Module $module */
        $module = $this->module;
        $tokens = $module->getTokens();

        $name = trim((string)$this->request->getRequiredBodyParam('name'));
        $days = (int)$this->request->getBodyParam('expiresDays');
        $error = match (true) {
            $name === '' => 'Gi tokenet et navn.',
            mb_strlen($name) > self::NAME_MAX => sprintf('Navnet kan være maks %d tegn.', self::NAME_MAX),
            $tokens->nameExists($user->id, $name) => 'Du har allerede et token med det navnet.',
            !isset(self::EXPIRY_OPTIONS[$days]) => 'Velg hvor lenge tokenet skal gjelde.',
            default => null,
        };

        if ($error !== null) {
            $this->setFailFlash($error);

            return $this->redirect($this->_screenUrl($user));
        }

        $token = $tokens->createToken($user, $name, Carbon::now()->addDays($days)->toDateTime());

        Craft::$app->getSession()->setFlash(self::FLASH_NEW_TOKEN, $token);
        $this->setSuccessFlash('Token opprettet. Kopier det nå — det vises bare én gang.');

        return $this->redirect($this->_screenUrl($user));
    }

    /**
     * Gir eller fjerner MCP-tilgangen på brukernivå. Kun admin, kun på andres
     * fane.
     *
     * Skrus tilgangen av, trekkes tokenene tilbake av lytteren i
     * {@see Module} — på slutten av requesten, samme vei som når tilgangen
     * fjernes i Crafts egen permissions-fane.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws \yii\base\Exception
     */
    public function actionSetAccess(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);

        $user = $this->editedUser((int)$this->request->getRequiredBodyParam('userId'));
        $enabled = (bool)$this->request->getBodyParam('enabled');

        if ($user->getIsCurrent() || !McpAccess::canToggle()) {
            throw new ForbiddenHttpException('Tilgangen kan ikke endres her.');
        }

        $source = McpAccess::source($user);

        // Tilgang fra admin-rollen eller en gruppe ville stått på igjen etter
        // lagring. Fanen skjuler bryteren da, men en manipulert POST skal ikke
        // få det til å se ut som om noe ble skrudd av.
        if (!$enabled && in_array($source, [McpAccess::VIA_ADMIN, McpAccess::VIA_GROUP], true)) {
            $this->setFailFlash('Tilgangen kommer fra admin-rollen eller en brukergruppe, og må fjernes der.');

            return $this->redirect($this->_screenUrl($user));
        }

        McpAccess::setUserLevel($user, $enabled);
        $this->setSuccessFlash($enabled
            ? 'MCP-tilgang gitt.'
            : 'MCP-tilgang fjernet. Brukerens tokens er trukket tilbake.');

        return $this->redirect($this->_screenUrl($user));
    }

    /**
     * Trekker tilbake et token.
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws \yii\db\Exception
     */
    public function actionRevoke(): Response
    {
        $this->requirePostRequest();

        $user = $this->editedUser($this->request->getBodyParam('userId') ?: null);
        $this->_requireTokenAccess($user);

        /** @var Module $module */
        $module = $this->module;
        $tokens = $module->getTokens();
        $id = (int)$this->request->getRequiredBodyParam('id');

        // Tokenet må tilhøre brukeren fanen gjelder — hindrer at en bruker
        // trekker tilbake andres tokens via manipulert id.
        if ($tokens->getTokenOwnerId($id) !== $user->id) {
            throw new ForbiddenHttpException('Tokenet tilhører ikke denne brukeren.');
        }

        $tokens->revokeToken($id);
        $this->setSuccessFlash('Token trukket tilbake.');

        return $this->redirect($this->_screenUrl($user));
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Kaster ForbiddenHttpException hvis innlogget bruker ikke kan
     * administrere tokens for den gitte brukeren.
     *
     * @param \craft\elements\User $user
     * @return void
     * @throws ForbiddenHttpException
     */
    private function _requireTokenAccess(\craft\elements\User $user): void
    {
        $currentUser = static::currentUser();

        if (!Module::canManageTokensFor($currentUser, $user)) {
            throw new ForbiddenHttpException('Du har ikke tilgang til MCP-tokens for denne brukeren.');
        }
    }

    /**
     * URL-en til token-fanen for en bruker.
     *
     * @param \craft\elements\User $user
     * @return string
     */
    private function _screenUrl(\craft\elements\User $user): string
    {
        $path = $user->getIsCurrent()
            ? 'myaccount/' . Module::USER_SCREEN
            : "users/$user->id/" . Module::USER_SCREEN;

        return UrlHelper::cpUrl($path);
    }
}
