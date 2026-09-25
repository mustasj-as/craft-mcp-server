<?php

namespace Mustasj\CraftMcp\helpers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\services\UserPermissions;
use Mustasj\CraftMcp\Module;

/**
 * Hvem har MCP-tilgang, og hvor kommer den fra.
 *
 * Tilgangen er Craft-permissionen {@see Module::PERMISSION_ACCESS}, som kan
 * komme fra tre steder: admin-rollen, en brukergruppe eller brukeren selv.
 * Bryteren på token-fanen kan bare endre den siste — de to andre styres der de
 * er satt, og fanen sier hvor. Å skru av en tilgang man ikke kan skru av, og
 * se bryteren stå på igjen etter lagring, er verre enn å ikke ha bryteren.
 *
 * @author Mustasj AS
 * @since 1.3.0
 */
class McpAccess
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string Tilgang via admin-rollen
     */
    public const VIA_ADMIN = 'admin';

    /**
     * @var string Tilgang via en brukergruppe
     */
    public const VIA_GROUP = 'group';

    /**
     * @var string Tilgang gitt direkte på brukeren
     */
    public const VIA_USER = 'user';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returnerer hvor brukerens MCP-tilgang kommer fra, eller null uten tilgang.
     *
     * Leser ferskt fra databasen og ikke gjennom `$user->can()`: Crafts
     * UserPermissions cacher per bruker for hele requesten, og denne kalles
     * nettopp etter at tilgangen er endret i samme request.
     *
     * @param User $user
     * @return string|null En av VIA_*-konstantene
     */
    public static function source(User $user): ?string
    {
        if ($user->admin) {
            return self::VIA_ADMIN;
        }

        $permission = strtolower(Module::PERMISSION_ACCESS);

        // Ny instans = tom cache. `reset()` hadde gjort samme nytten, men
        // finnes først fra Craft 5.8.13, og pakken krever bare ^5.0.
        $fresh = new UserPermissions();

        if (in_array($permission, $fresh->getGroupPermissionsByUserId($user->id), true)) {
            return self::VIA_GROUP;
        }

        return in_array($permission, self::userLevelPermissions($user->id), true) ? self::VIA_USER : null;
    }

    /**
     * Om bryteren kan brukes: permissions på brukernivå krever Craft Pro.
     *
     * @return bool
     */
    public static function canToggle(): bool
    {
        return Craft::$app->edition->value >= CmsEdition::Pro->value;
    }

    /**
     * Gir eller fjerner MCP-tilgangen på brukernivå, og lar brukerens øvrige
     * permissions stå.
     *
     * `saveUserPermissions()` erstatter HELE lista, så den må leses først. Den
     * leses fra tabellen for brukernivå, ikke fra `getPermissionsByUserId()` —
     * den siste inkluderer gruppenes permissions, og da ville hver lagring her
     * kopiert gruppetilgangene inn på brukeren.
     *
     * @param User $user
     * @param bool $enabled
     * @return void
     * @throws \yii\base\Exception
     */
    public static function setUserLevel(User $user, bool $enabled): void
    {
        $permission = strtolower(Module::PERMISSION_ACCESS);
        $permissions = array_values(array_diff(self::userLevelPermissions($user->id), [$permission]));

        if ($enabled) {
            $permissions[] = $permission;
        }

        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Permissions satt direkte på brukeren, uten gruppenes.
     *
     * @param int $userId
     * @return string[] Små bokstaver, slik Craft lagrer dem
     */
    private static function userLevelPermissions(int $userId): array
    {
        return (new Query())
            ->select(['p.name'])
            ->from(['p' => Table::USERPERMISSIONS])
            ->innerJoin(['p_u' => Table::USERPERMISSIONS_USERS], '[[p_u.permissionId]] = [[p.id]]')
            ->where(['p_u.userId' => $userId])
            ->column();
    }
}
