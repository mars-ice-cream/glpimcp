<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp;

use CommonDBTM;
use CommonGLPI;
use Html;
use Preference;
use Profile_User;
use Session;
use User;

/**
 * Jeton personnel d'accès MCP.
 *
 * Le jeton n'est jamais stocké en clair : seul son SHA-256 est conservé.
 * Chaque appel MCP effectué avec ce jeton agit EXACTEMENT avec les droits
 * GLPI de son propriétaire (profil + entités), rien de plus.
 */
class Token extends CommonDBTM
{
    public const PREFIX = 'gmcp_';

    public static $rightname = '';

    public static function getTypeName($nb = 0)
    {
        return _n('MCP token', 'MCP tokens', $nb, 'glpimcp');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_glpimcp_tokens';
    }

    public static function canCreate(): bool
    {
        return Session::getLoginUserID() !== false;
    }

    public static function canView(): bool
    {
        return Session::getLoginUserID() !== false;
    }

    /**
     * Génère un jeton pour l'utilisateur et renvoie la valeur en clair (affichée une seule fois).
     */
    public static function generate(int $users_id, string $name, int $profiles_id = 0, ?int $entities_id = null, int $days = 0, bool $recursive = true): string
    {
        $profiles_id = \GlpiPlugin\Glpimcp\Mcp\TokenScope::resolveProfile($users_id, $profiles_id);
        if ($profiles_id <= 0) {
            throw new \RuntimeException('Aucun profil utilisable pour ce jeton.');
        }
        $plain = self::PREFIX . bin2hex(random_bytes(24));
        $token = new self();
        $token->add([
            'users_id'        => $users_id,
            'name'            => $name !== '' ? $name : 'Assistant IA',
            'token_hash'      => hash('sha256', $plain),
            'token_hint'      => substr($plain, 0, 9) . '…',
            'profiles_id'     => $profiles_id,
            'entities_id'     => $entities_id,
            'is_recursive'    => $recursive ? 1 : 0,
            'is_active'       => 1,
            'date_creation'   => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'date_expiration' => $days > 0 ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null,
        ]);
        return $plain;
    }

    /** L'entité est-elle atteignable par le compte avec ce profil (habilitation directe ou récursive) ? */
    public static function profileCoversEntity(int $users_id, int $profiles_id, int $entities_id): bool
    {
        global $DB;
        foreach ($DB->request(['FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $users_id, 'profiles_id' => $profiles_id]]) as $pu) {
            if ((int) $pu['entities_id'] === $entities_id) {
                return true;
            }
            if ($pu['is_recursive'] && in_array($entities_id, array_map('intval', getSonsOf('glpi_entities', (int) $pu['entities_id'])), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retrouve un jeton actif et non expiré à partir de sa valeur en clair.
     */
    public static function findActive(string $plain): ?array
    {
        global $DB;

        if (!str_starts_with($plain, self::PREFIX) || strlen($plain) < 20) {
            return null;
        }

        $it = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'token_hash' => hash('sha256', $plain),
                'is_active'  => 1,
            ],
            'LIMIT' => 1,
        ]);
        if (count($it) !== 1) {
            return null;
        }
        $row = $it->current();
        if (!empty($row['date_expiration']) && strtotime($row['date_expiration']) < time()) {
            return null;
        }
        return $row;
    }

    public static function touch(int $id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['last_use' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Preference || $item instanceof User) {
            return self::createTabEntry(__('AI assistant (MCP)', 'glpimcp'), 0, null, 'ti ti-robot');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            self::showForUser((int) Session::getLoginUserID(), true);
        } elseif ($item instanceof User) {
            $is_self = (int) $item->getID() === (int) Session::getLoginUserID();
            if ($is_self || $item->can($item->getID(), UPDATE)) {
                self::showForUser((int) $item->getID(), $is_self);
            }
        }
        return true;
    }

    public static function showForUser(int $users_id, bool $can_create): void
    {
        global $CFG_GLPI, $DB;

        $action   = $CFG_GLPI['root_doc'] . '/plugins/glpimcp/front/token.form.php';
        $endpoint = rtrim($CFG_GLPI['url_base'] ?? '', '/') . '/plugins/glpimcp/mcp';

        echo '<div class="card"><div class="card-body">';
        echo '<h3 class="card-title"><i class="ti ti-robot me-2"></i>' . __('Connect an AI assistant to GLPI', 'glpimcp') . '</h3>';
        echo '<p>' . __('The assistant acts within your GLPI rights, limited to the profile and entity chosen for each token. Changes are previewed (unless the administrator chose immediate application of ordinary changes); critical actions always require your own approval in GLPI.', 'glpimcp') . '</p>';
        echo '<p><strong>' . __('MCP server URL', 'glpimcp') . ' :</strong> <code>' . htmlescape($endpoint) . '</code></p>';

        if (!empty($_SESSION['glpimcp_new_token'])) {
            echo '<div class="alert alert-warning"><strong>' . __('Copy this token now, it will not be shown again:', 'glpimcp') . '</strong><br>';
            echo '<code style="font-size:1.1em;user-select:all">' . htmlescape($_SESSION['glpimcp_new_token']) . '</code><br>';
            echo '<small>' . __('Send it in the HTTP header: Authorization: Bearer &lt;token&gt;', 'glpimcp') . '</small></div>';
            unset($_SESSION['glpimcp_new_token']);
        }

        // Liste des jetons
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id],
            'ORDER' => 'id DESC',
        ]);
        echo '<table class="table table-sm table-hover mt-3"><thead><tr>';
        echo '<th>' . __('Name') . '</th><th>' . __('Token', 'glpimcp') . '</th><th>' . __('Profile') . '</th>';
        echo '<th>' . __('Creation date') . '</th><th>' . __('Expiration date') . '</th><th>' . __('Last use', 'glpimcp') . '</th><th>' . __('Status') . '</th><th></th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $profile = $row['profiles_id'] > 0 ? \Dropdown::getDropdownName('glpi_profiles', $row['profiles_id']) : __('Default profile', 'glpimcp');
            $profile .= ' · ' . ($row['entities_id'] !== null
                ? \Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']) . ($row['is_recursive'] ? ' ' . __('(with sub-entities)', 'glpimcp') : '')
                : __('all entities of the profile', 'glpimcp'));
            echo '<tr>';
            echo '<td>' . htmlescape($row['name']) . '</td>';
            echo '<td><code>' . htmlescape($row['token_hint']) . '</code></td>';
            echo '<td>' . htmlescape($profile) . '</td>';
            echo '<td>' . Html::convDateTime($row['date_creation']) . '</td>';
            echo '<td>' . ($row['date_expiration'] ? Html::convDateTime($row['date_expiration']) : __('Never')) . '</td>';
            echo '<td>' . ($row['last_use'] ? Html::convDateTime($row['last_use']) : '—') . '</td>';
            echo '<td>' . ($row['is_active'] ? '<span class="badge bg-green-lt">' . __('Active') . '</span>' : '<span class="badge bg-red-lt">' . __('Revoked', 'glpimcp') . '</span>') . '</td>';
            echo '<td>';
            if ($row['is_active']) {
                echo '<form method="post" action="' . htmlescape($action) . '" class="d-inline">';
                echo Html::hidden('id', ['value' => $row['id']]);
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo '<button type="submit" name="revoke" class="btn btn-sm btn-outline-danger">' . __('Revoke', 'glpimcp') . '</button>';
                echo '</form>';
            }
            echo '</td></tr>';
        }
        if (count($rows) === 0) {
            echo '<tr><td colspan="8" class="text-muted">' . __('No token yet.', 'glpimcp') . '</td></tr>';
        }
        echo '</tbody></table>';

        if ($can_create) {
            echo '<form method="post" action="' . htmlescape($action) . '" class="row g-2 align-items-end mt-2">';
            echo '<div class="col-auto"><label class="form-label">' . __('Name') . '</label>';
            echo '<input type="text" name="name" class="form-control" placeholder="Claude, Copilot…" maxlength="100"></div>';

            echo '<div class="col-auto"><label class="form-label">' . __('Profile used by the assistant', 'glpimcp') . '</label>';
            $user = new User();
            $default_pid = $user->getFromDB($users_id) ? (int) $user->fields['profiles_id'] : 0;
            echo '<select name="profiles_id" class="form-select">';
            foreach (Profile_User::getUserProfiles($users_id) as $pid) {
                echo '<option value="' . (int) $pid . '"' . ((int) $pid === $default_pid ? ' selected' : '') . '>' . htmlescape(\Dropdown::getDropdownName('glpi_profiles', $pid)) . '</option>';
            }
            echo '</select><small class="text-muted">' . __('Fixed limit: the assistant can never use another profile with this token.', 'glpimcp') . '</small></div>';

            echo '<div class="col-auto"><label class="form-label">' . __('Entity', 'glpimcp') . '</label>';
            echo '<select name="entities_id" class="form-select"><option value="">' . __('All entities of the profile', 'glpimcp') . '</option>';
            $seen = [];
            foreach ($DB->request(['SELECT' => ['entities_id'], 'DISTINCT' => true, 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $users_id]]) as $pu) {
                $eid = (int) $pu['entities_id'];
                if (!isset($seen[$eid])) {
                    $seen[$eid] = true;
                    echo '<option value="' . $eid . '">' . htmlescape(\Dropdown::getDropdownName('glpi_entities', $eid)) . '</option>';
                }
            }
            echo '</select><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="is_recursive" value="1" id="glpimcp_rec" checked>'
                . '<label class="form-check-label" for="glpimcp_rec">' . __('Include sub-entities', 'glpimcp') . '</label></div></div>';

            echo '<div class="col-auto"><label class="form-label">' . __('Validity', 'glpimcp') . '</label>';
            echo '<select name="days" class="form-select">';
            foreach ([30 => '30 j', 90 => '90 j', 365 => '1 an', 0 => __('Never expires', 'glpimcp')] as $d => $label) {
                echo '<option value="' . $d . '"' . ($d === 90 ? ' selected' : '') . '>' . htmlescape($label) . '</option>';
            }
            echo '</select></div>';
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo '<div class="col-auto"><button type="submit" name="generate" class="btn btn-primary"><i class="ti ti-key me-1"></i>' . __('Generate a token', 'glpimcp') . '</button></div>';
            echo '</form>';
        }
        echo '</div></div>';

        $pending = $DB->request(['FROM' => 'glpi_plugin_glpimcp_pendingactions', 'WHERE' => [
            'users_id' => $users_id, 'status' => 'pending', 'requires_approval' => 1, 'approved_by' => 0,
            'date_expiration' => ['>', date('Y-m-d H:i:s')],
        ], 'ORDER' => 'id DESC']);
        if (count($pending) > 0) {
            echo '<div class="card mt-3 border-warning"><div class="card-header"><h3 class="card-title"><i class="ti ti-shield-check me-2"></i>' . __('Actions waiting for your approval', 'glpimcp') . '</h3></div><div class="list-group list-group-flush">';
            foreach ($pending as $p) {
                echo '<a class="list-group-item list-group-item-action" href="' . htmlescape($CFG_GLPI['root_doc'] . '/plugins/glpimcp/front/approval.php?uuid=' . rawurlencode($p['uuid'])) . '">'
                    . htmlescape((string) $p['summary']) . ' <small class="text-muted">— ' . Html::convDateTime($p['date_creation']) . '</small></a>';
            }
            echo '</div></div>';
        }

        echo '<div class="card mt-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-history me-2"></i>' . __('AI actions made with this account', 'glpimcp') . '</h3></div><div class="card-body p-0">';
        LogView::renderTable(['users_id' => $users_id], 20, false);
        echo '</div></div>';
    }
}
