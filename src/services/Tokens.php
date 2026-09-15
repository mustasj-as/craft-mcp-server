<?php

namespace Mustasj\CraftMcp\services;

use Carbon\Carbon;
use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use Mustasj\CraftMcp\Module;
use yii\base\Component;

/**
 * Token-tjeneste for MCP-endepunktet.
 *
 * Utsteder og validerer personlige API-tokens knyttet til Craft-brukere.
 * Tokens har prosjektets eget prefiks (modulens `tokenPrefix`) etterfulgt av
 * 48 hex-tegn, og lagres kun som sha256-hash. Klartekst vises én gang ved
 * opprettelse.
 *
 * Tabellen opprettes av {@see ensureTable()}, ikke av en migrering. Grunnen er
 * strukturell: Crafts `MigrateController` kjenner sporene `craft`, `content` og
 * `plugin:<handle>` — det finnes ikke noe `module:`-spor, så en modul kan ikke
 * eie migreringer. Alternativet var å be hver installasjon kopiere en
 * migreringsfil inn i prosjektets `migrations/`, som er nøyaktig den typen
 * kopi-lim pakken finnes for å bli kvitt.
 *
 * @author Mustasj AS <pal@mustasj.no>
 * @since 1.0.0
 */
class Tokens extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string Tabellen tokens lagres i
     */
    public const TABLE = '{{%mcp_tokens}}';

    /**
     * @var int Lengden på hex-delen av et token
     */
    public const SECRET_BYTES = 24;

    /**
     * @var int Minste antall sekunder mellom `lastUsedAt`-oppdateringer
     */
    private const LAST_USED_THROTTLE = 60;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var bool Om tabellsjekken alt er gjort i denne requesten
     */
    private bool $_tableChecked = false;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Oppretter tokens-tabellen hvis den ikke finnes.
     *
     * Idempotent, og kalles fra token-kommandoene og CP-skjermen — altså bare
     * fra flater der noen faktisk skal bruke tokens. Den kalles bevisst IKKE
     * fra `Module::init()`: en `tableExists`-sjekk på hver eneste request,
     * inkludert frontend-sidevisninger, er en pris ingen skal betale for en
     * tabell som opprettes én gang.
     *
     * @return void
     * @throws \yii\base\NotSupportedException
     */
    public function ensureTable(): void
    {
        // Memoisert: getTokens() kalles på hver autentiserte MCP-request, og
        // en tableExists-sjekk per kall er en avgift for en tabell som
        // opprettes én gang i en installasjons levetid.
        if ($this->_tableChecked) {
            return;
        }

        $this->_tableChecked = true;
        $db = Craft::$app->getDb();

        // Indeks- og nøkkelnavn MÅ oppgis. Crafts `Migration::createIndex()`
        // genererer et navn når det utelates, men dette er Yiis
        // `Command::createIndex()`, som ikke gjør det: `null` ble til
        // `CREATE UNIQUE INDEX "" ON …` og feilet med «zero-length delimited
        // identifier» på Postgres. Tabellen var da alt opprettet, og siden
        // metoden returnerte tidlig på `tableExists()` ble indeksen og
        // fremmednøkkelen aldri lagt på — permanent, i stillhet.
        $rawTable = $db->getSchema()->getRawTableName(self::TABLE);
        $indexName = $rawTable . '_tokenHash_unq';
        $fkName = $rawTable . '_userId_fk';

        if ($db->tableExists(self::TABLE)) {
            $this->_repairTable($indexName, $fkName);

            return;
        }

        // Transaksjon: uten den etterlater en feil halvveis en tabell uten
        // indeks, og `tableExists()` over ville meldt «alt i orden» for alltid.
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()->createTable(self::TABLE, [
                'id' => 'pk',
                'userId' => 'integer NOT NULL',
                'name' => 'string NOT NULL',
                'tokenHash' => 'char(64) NOT NULL',
                'expiresAt' => 'datetime NULL',
                'lastUsedAt' => 'datetime NULL',
                'dateCreated' => 'datetime NOT NULL',
                'dateUpdated' => 'datetime NOT NULL',
                'uid' => 'char(36) NOT NULL',
            ])->execute();

            $db->createCommand()->createIndex($indexName, self::TABLE, ['tokenHash'], true)->execute();
            $db->createCommand()->addForeignKey($fkName, self::TABLE, ['userId'], '{{%users}}', ['id'], 'CASCADE', null)->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * Utsteder et nytt token for en bruker og returnerer klarteksten.
     *
     * Klarteksten lagres ikke — kun sha256-hashen.
     *
     * @param User $user Brukeren tokenet knyttes til
     * @param string $name Beskrivende navn (f.eks. «claude-code laptop»)
     * @param DateTime|null $expiresAt Utløpstidspunkt, eller null for evig
     * @return string Tokenet i klartekst
     * @throws \yii\db\Exception
     */
    public function createToken(User $user, string $name, ?DateTime $expiresAt = null): string
    {
        $secret = Module::getInstance()->tokenPrefix . bin2hex(random_bytes(self::SECRET_BYTES));

        Db::insert(self::TABLE, [
            'userId' => $user->id,
            'name' => $name,
            'tokenHash' => hash('sha256', $secret),
            'expiresAt' => Db::prepareDateForDb($expiresAt),
            'uid' => StringHelper::UUID(),
        ]);

        return $secret;
    }

    /**
     * Validerer en `Authorization`-header og returnerer brukeren tokenet
     * tilhører, eller null hvis headeren mangler, tokenet er ukjent, utløpt
     * eller brukeren ikke lenger er aktiv.
     *
     * @param string|null $authHeader Rå `Authorization`-headerverdi
     * @return User|null
     */
    public function authenticate(?string $authHeader): ?User
    {
        if ($authHeader === null || !preg_match('/^Bearer\s+(\S+)$/i', trim($authHeader), $matches)) {
            return null;
        }

        return $this->authenticateToken($matches[1]);
    }

    /**
     * Validerer et token i klartekst og returnerer brukeren det tilhører,
     * eller null hvis tokenet mangler, er ukjent, utløpt eller brukeren ikke
     * lenger er aktiv.
     *
     * Brukes både av bearer-autentiseringen og av path-token-ruten
     * (`/mcp/t/<token>`), se {@see \Mustasj\CraftMcp\controllers\ServerController}.
     *
     * @param string|null $secret Tokenet i klartekst
     * @return User|null
     * @since 1.1.0
     */
    public function authenticateToken(?string $secret): ?User
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        $hash = hash('sha256', $secret);

        $row = (new Query())
            ->select(['id', 'userId', 'tokenHash', 'lastUsedAt', 'expiresAt'])
            ->from(self::TABLE)
            ->where(['tokenHash' => $hash])
            ->one();

        if (!$row || !hash_equals($row['tokenHash'], $hash)) {
            return null;
        }

        if ($row['expiresAt'] !== null && Carbon::parse($row['expiresAt'])->isPast()) {
            return null;
        }

        $user = \Craft::$app->getUsers()->getUserById((int)$row['userId']);

        if ($user === null || $user->getStatus() !== User::STATUS_ACTIVE) {
            return null;
        }

        $this->_touchLastUsed($row);

        return $user;
    }

    /**
     * Returnerer alle tokens med brukerinfo, for konsollvisning.
     *
     * @return array[]
     */
    public function getAllTokens(): array
    {
        return (new Query())
            ->select(['t.id', 't.userId', 't.name', 't.lastUsedAt', 't.expiresAt', 't.dateCreated', 'u.email'])
            ->from(['t' => self::TABLE])
            ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[t.userId]]')
            ->orderBy(['t.dateCreated' => SORT_DESC])
            ->all();
    }

    /**
     * Returnerer en brukers tokens, for CP-visning.
     *
     * @param int $userId
     * @return array[]
     */
    public function getTokensForUser(int $userId): array
    {
        return (new Query())
            ->select(['id', 'name', 'lastUsedAt', 'expiresAt', 'dateCreated'])
            ->from(self::TABLE)
            ->where(['userId' => $userId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
    }

    /**
     * Returnerer bruker-ID-en et token tilhører, eller null hvis ukjent.
     *
     * @param int $id Token-radens ID
     * @return int|null
     */
    public function getTokenOwnerId(int $id): ?int
    {
        $userId = (new Query())
            ->select(['userId'])
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->scalar();

        return $userId !== false ? (int)$userId : null;
    }

    /**
     * Sletter et token.
     *
     * @param int $id Token-radens ID
     * @return bool Om noe ble slettet
     * @throws \yii\db\Exception
     */
    public function revokeToken(int $id): bool
    {
        return Db::delete(self::TABLE, ['id' => $id]) > 0;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Legger på indeksen og fremmednøkkelen hvis tabellen finnes uten dem.
     *
     * Finnes for installasjoner som fikk tabellen opprettet før
     * indeksnavn-buggen i {@see ensureTable()} ble rettet: der står tabellen
     * igjen uten den unike indeksen på `tokenHash` og uten
     * CASCADE-fremmednøkkelen mot `users`, og ingenting ville noen gang lagt
     * dem på.
     *
     * Indeksen sjekkes FØR det skrives noe. Alternativet — å forsøke
     * `CREATE INDEX` og svelge feilen — ville sendt en setning som feiler på
     * hver eneste request i den normale tilstanden, og fylt loggen med den.
     *
     * @param string $indexName
     * @param string $fkName
     * @return void
     */
    private function _repairTable(string $indexName, string $fkName): void
    {
        $db = Craft::$app->getDb();
        $schema = $db->getSchema();
        $tableSchema = $schema->getTableSchema(self::TABLE);

        if ($tableSchema === null) {
            return;
        }

        foreach ($schema->findUniqueIndexes($tableSchema) as $columns) {
            if ($columns === ['tokenHash']) {
                return;
            }
        }

        $db->createCommand()->createIndex($indexName, self::TABLE, ['tokenHash'], true)->execute();

        // Fremmednøkkelen mangler i samme tilfelle, men kan tenkes lagt på for
        // hånd. Den er ikke verdt en egen sjekk.
        try {
            $db->createCommand()->addForeignKey($fkName, self::TABLE, ['userId'], '{{%users}}', ['id'], 'CASCADE', null)->execute();
        } catch (\Throwable) {
            // Fantes fra før.
        }
    }

    /**
     * Oppdaterer `lastUsedAt`, men maks én gang per minutt for å unngå en
     * skriving per request.
     *
     * @param array $row Token-raden fra databasen
     * @return void
     */
    private function _touchLastUsed(array $row): void
    {
        if ($row['lastUsedAt'] !== null && Carbon::parse($row['lastUsedAt'])->diffInSeconds() < self::LAST_USED_THROTTLE) {
            return;
        }

        Db::update(self::TABLE, ['lastUsedAt' => Db::prepareDateForDb(new DateTime())], ['id' => $row['id']]);
    }
}
