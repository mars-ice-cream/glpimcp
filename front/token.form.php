<?php

/**
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Glpimcp\Token;

Session::checkLoginUser();

$users_id = (int) Session::getLoginUserID();

if (isset($_POST['generate'])) {
    // Le profil choisi devient la LIMITE du jeton ; « profil par défaut » est résolu maintenant et figé
    $profiles_id = \GlpiPlugin\Glpimcp\Mcp\TokenScope::resolveProfile($users_id, (int) ($_POST['profiles_id'] ?? 0));
    $entities_id = isset($_POST['entities_id']) && $_POST['entities_id'] !== '' && (int) $_POST['entities_id'] >= 0 ? (int) $_POST['entities_id'] : null;
    $recursive   = !empty($_POST['is_recursive']);
    if ($profiles_id <= 0) {
        Session::addMessageAfterRedirect(__('Choose the profile the assistant will be limited to.', 'glpimcp'), false, ERROR);
    } elseif ($entities_id !== null && !Token::profileCoversEntity($users_id, $profiles_id, $entities_id)) {
        Session::addMessageAfterRedirect(__('This entity is not reachable with the chosen profile.', 'glpimcp'), false, ERROR);
    } else {
        $days = max(0, min(3650, (int) ($_POST['days'] ?? 90)));
        $name = trim((string) ($_POST['name'] ?? ''));
        $_SESSION['glpimcp_new_token'] = Token::generate($users_id, mb_substr($name, 0, 100), $profiles_id, $entities_id, $days, $recursive);
        Session::addMessageAfterRedirect(__('Token generated. Copy it now.', 'glpimcp'));
    }
} elseif (isset($_POST['revoke'])) {
    $token = new Token();
    if ($token->getFromDB((int) $_POST['id'])) {
        $owner = (int) $token->fields['users_id'];
        $user  = new User();
        $can   = $owner === $users_id
            || ($user->getFromDB($owner) && $user->can($owner, UPDATE));
        if ($can) {
            $token->update(['id' => $token->getID(), 'is_active' => 0]);
            Session::addMessageAfterRedirect(__('Token revoked.', 'glpimcp'));
        } else {
            Session::addMessageAfterRedirect(__('You cannot revoke this token.', 'glpimcp'), false, ERROR);
        }
    }
}

Html::back();
