<?php

/**
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Glpimcp\Token;

Session::checkLoginUser();

$users_id = (int) Session::getLoginUserID();

if (isset($_POST['generate'])) {
    $profiles_id = (int) ($_POST['profiles_id'] ?? 0);
    // On ne peut choisir qu'un profil qu'on possède réellement
    if ($profiles_id > 0 && !in_array($profiles_id, Profile_User::getUserProfiles($users_id), false)) {
        $profiles_id = 0;
    }
    $days = max(0, min(3650, (int) ($_POST['days'] ?? 90)));
    $name = trim((string) ($_POST['name'] ?? ''));
    $_SESSION['glpimcp_new_token'] = Token::generate($users_id, mb_substr($name, 0, 100), $profiles_id, null, $days);
    Session::addMessageAfterRedirect(__('Token generated. Copy it now.', 'glpimcp'));
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
