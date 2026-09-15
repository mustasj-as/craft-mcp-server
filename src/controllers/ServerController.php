<?php

namespace Mustasj\CraftMcp\controllers;

use Craft;
use craft\web\Controller;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mustasj\CraftMcp\Module;
use Mustasj\CraftMcp\services\ServerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use yii\base\Action;
use yii\web\Response;

/**
 * MCP-endepunktet (/mcp) — streamable HTTP-transport for Model Context
 * Protocol.
 *
 * Alle requests autentiseres mot tokens fra {@see \modules\mcp\services\Tokens} —
 * enten via `Authorization: Bearer <token>`, eller via `token`-segmentet på
 * `/mcp/t/<token>` for klienter som kun kan oppgi en URL (Claude Desktop sin
 * «Add custom connector»).
 * Den autentiserte brukeren settes som Crafts identity (uten sesjon/cookie),
 * slik at Crafts permissions styrer hva verktøyene får gjøre.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class ServerController extends Controller
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // =========================================================================
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = true;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @param Action $action
     * @throws \Throwable
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // CORS-preflight skal ikke autentiseres — transporten svarer 204.
        if ($this->request->getIsOptions()) {
            return true;
        }

        /** @var Module $module */
        $module = $this->module;
        $tokens = $module->getTokens();

        // Path-tokenet (`/mcp/t/<token>`) er et alternativ til headeren, ikke
        // et tillegg — headeren vinner når begge er oppgitt. Det leses fra
        // route-paramene og ikke via `getParam()`, siden Craft ikke slår
        // route-params sammen med query-paramene.
        $pathToken = Craft::$app->getUrlManager()->getRouteParams()['token'] ?? null;
        $user = $tokens->authenticate($this->request->getHeaders()->get('Authorization'))
            ?? (is_string($pathToken) ? $tokens->authenticateToken($pathToken) : null);

        if ($user === null) {
            $this->response->format = Response::FORMAT_JSON;
            $this->response->setStatusCode(401);
            $this->response->getHeaders()->set('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
            $this->response->data = ['error' => 'unauthorized'];
            return false;
        }

        if (!$user->can(Module::PERMISSION_ACCESS)) {
            $this->response->format = Response::FORMAT_JSON;
            $this->response->setStatusCode(403);
            $this->response->data = ['error' => 'forbidden', 'message' => 'The token user does not have the "Tilgang til MCP" permission.'];
            return false;
        }

        // Identity uten sesjon/cookie — gjelder kun denne requesten.
        Craft::$app->getUser()->setIdentity($user);

        return true;
    }

    /**
     * Håndterer alle MCP-requests (POST/GET/DELETE/OPTIONS) via SDK-ens
     * streamable HTTP-transport.
     *
     * @return Response
     * @throws \Throwable
     */
    public function actionHandle(): Response
    {
        $psr17 = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);

        // Bygg body fra Crafts rawBody — php://input kan allerede være
        // konsumert av Yii på dette tidspunktet.
        $psrRequest = $creator->fromGlobals()
            ->withBody($psr17->createStream($this->request->getRawBody()));

        $transport = new StreamableHttpTransport($psrRequest, $psr17, $psr17);

        /** @var \Psr\Http\Message\ResponseInterface $psrResponse */
        $psrResponse = ServerFactory::create()->run($transport);

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->setStatusCode($psrResponse->getStatusCode());

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $response->getHeaders()->set($name, implode(', ', $values));
        }

        // 202/204-svar har ingen body — ikke la Crafts default text/html lekke.
        if (!$psrResponse->hasHeader('Content-Type')) {
            $response->getHeaders()->remove('Content-Type');
        }

        $response->content = (string)$psrResponse->getBody();

        return $response;
    }
}
