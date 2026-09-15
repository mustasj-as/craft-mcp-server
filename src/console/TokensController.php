<?php

namespace Mustasj\CraftMcp\console;

use Carbon\Carbon;
use craft\console\Controller;
use craft\helpers\Console;
use Mustasj\CraftMcp\Module;
use yii\console\ExitCode;

/**
 * Håndterer MCP API-tokens.
 *
 * Eksempler:
 *   ddev craft mcp-api/tokens/create redaktor@example.com --name="claude-code"
 *   ddev craft mcp-api/tokens/create redaktor@example.com --expires-days=90
 *   ddev craft mcp-api/tokens/list
 *   ddev craft mcp-api/tokens/revoke 3
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class TokensController extends Controller
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var string Beskrivende navn på tokenet
     */
    public string $name = 'mcp';

    /**
     * @var int|null Antall dager til tokenet utløper (null = aldri)
     */
    public ?int $expiresDays = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'create') {
            $options[] = 'name';
            $options[] = 'expiresDays';
        }

        return $options;
    }

    /**
     * Utsteder et nytt token for en bruker. Tokenet vises kun én gang.
     *
     * @param string $usernameOrEmail Brukernavn eller e-post
     * @return int
     * @throws \yii\db\Exception
     */
    public function actionCreate(string $usernameOrEmail): int
    {
        $user = \Craft::$app->getUsers()->getUserByUsernameOrEmail($usernameOrEmail);

        if ($user === null) {
            $this->stderr("Fant ingen bruker: $usernameOrEmail" . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $expiresAt = $this->expiresDays !== null
            ? Carbon::now()->addDays($this->expiresDays)->toDateTime()
            : null;

        if (!$user->can(Module::PERMISSION_ACCESS)) {
            $this->stdout('NB: Brukeren mangler permissionen «Tilgang til MCP» og vil få 403 fra endepunktet.' . PHP_EOL, Console::FG_YELLOW);
        }

        $token = $this->_getModule()->getTokens()->createToken($user, $this->name, $expiresAt);

        $this->stdout(PHP_EOL . "Token for {$user->email}" . ($expiresAt !== null ? ' (utløper ' . $expiresAt->format('Y-m-d') . ')' : '') . ':' . PHP_EOL);
        $this->stdout($token . PHP_EOL . PHP_EOL, Console::FG_GREEN);
        $this->stdout('Lagre det nå — det vises ikke igjen.' . PHP_EOL, Console::FG_YELLOW);

        return ExitCode::OK;
    }

    /**
     * Lister alle tokens.
     *
     * @return int
     */
    public function actionList(): int
    {
        $rows = $this->_getModule()->getTokens()->getAllTokens();

        if ($rows === []) {
            $this->stdout('Ingen tokens.' . PHP_EOL);
            return ExitCode::OK;
        }

        $this->table(
            ['ID', 'Bruker', 'Navn', 'Sist brukt', 'Utløper', 'Opprettet'],
            array_map(static fn(array $row) => [
                $row['id'],
                $row['email'] ?? '(slettet bruker)',
                $row['name'],
                $row['lastUsedAt'] ?? '-',
                $row['expiresAt'] ?? 'aldri',
                $row['dateCreated'],
            ], $rows),
        );

        return ExitCode::OK;
    }

    /**
     * Trekker tilbake et token.
     *
     * @param int $id Token-ID (se `list`)
     * @return int
     * @throws \yii\db\Exception
     */
    public function actionRevoke(int $id): int
    {
        if (!$this->_getModule()->getTokens()->revokeToken($id)) {
            $this->stderr("Fant ikke token #$id." . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Token #$id trukket tilbake." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Returnerer MCP-modulen.
     *
     * @return Module
     */
    private function _getModule(): Module
    {
        /** @var Module */
        return $this->module;
    }
}
