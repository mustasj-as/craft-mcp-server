<?php

namespace Mustasj\CraftMcp\controllers;

use Carbon\Carbon;
use Craft;
use craft\controllers\EditUserTrait;
use craft\helpers\UrlHelper;
use craft\web\Controller;
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

        return $response
            ->contentTemplate("{$module->id}/tokens-screen", [
                'user' => $user,
                'tokens' => $module->getTokens()->getTokensForUser($user->id),
                'newToken' => Craft::$app->getSession()->getFlash(self::FLASH_NEW_TOKEN),
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

        $name = trim((string)$this->request->getRequiredBodyParam('name')) ?: 'mcp';
        $expiresDays = $this->request->getBodyParam('expiresDays');
        $expiresAt = is_numeric($expiresDays) && (int)$expiresDays > 0
            ? Carbon::now()->addDays((int)$expiresDays)->toDateTime()
            : null;

        /** @var Module $module */
        $module = $this->module;
        $token = $module->getTokens()->createToken($user, $name, $expiresAt);

        Craft::$app->getSession()->setFlash(self::FLASH_NEW_TOKEN, $token);
        $this->setSuccessFlash('Token generert.');

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
