<?php

namespace Mustasj\CraftMcp\controllers;

use Craft;
use craft\web\Controller;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
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

        // `fromGlobals()` legger inn Host både fra URI-en og fra headerne.
        // SDK-ens DNS rebinding-vern leser `getHeaderLine('Host')`, og «a, a»
        // matcher ingen vert. Ulike verdier blir stående og avvises.
        $psrRequest = $psrRequest->withHeader('Host', array_values(array_unique($psrRequest->getHeader('Host'))));

        $transport = new StreamableHttpTransport($psrRequest, $psr17, $psr17, middleware: $this->_middleware($psr17));

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

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * SDK-ens standard-middleware, med DNS rebinding-vernet utvidet til
     * sitenes egne vertsnavn.
     *
     * Standardvernet godtar bare localhost, så uten dette svarer endepunktet
     * 403 på ethvert ekte domene. Resten av standardstakken (CORS, og i 0.7
     * protokollversjon) beholdes som SDK-en definerer den.
     *
     * @param Psr17Factory $psr17
     * @return array<\Psr\Http\Server\MiddlewareInterface>
     */
    private function _middleware(Psr17Factory $psr17): array
    {
        $hosts = ['localhost', '127.0.0.1', '[::1]', ...Module::getInstance()->allowedHosts];
        $siteHosts = 0;

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $host = parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
                $siteHosts++;
            }
        }

        // En base-URL som `@web` er relativ i web-requests og gir ingen vert.
        // Uten denne meldingen ser det bare ut som SDK-en avviser alt.
        if ($siteHosts === 0 && Module::getInstance()->allowedHosts === []) {
            Craft::warning('MCP: ingen site har absolutt base-URL, og `allowedHosts` er tom. Bare localhost slipper gjennom.', __METHOD__);
        }

        $hosts = array_values(array_unique(array_map('strtolower', $hosts)));

        return array_map(
            static fn($middleware) => $middleware instanceof DnsRebindingProtectionMiddleware
                ? new DnsRebindingProtectionMiddleware($hosts, $psr17, $psr17)
                : $middleware,
            StreamableHttpTransport::defaultMiddleware(),
        );
    }
}
