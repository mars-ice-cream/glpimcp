<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use CommonDBTM;
use Config;
use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\Form;
use GlpiPlugin\Glpimcp\Forms\FormBuilder;
use Profile;
use ProfileRight;
use Session;
use Throwable;

/**
 * Moteur d'opérations d'écriture.
 *
 * Une action IA = une liste d'opérations. Chaque opération est :
 *  - vérifiée à la préparation (droits + aperçu lisible),
 *  - re-vérifiée à l'exécution (les droits peuvent avoir changé entre-temps),
 *  - exécutée dans une transaction unique (tout ou rien).
 *
 * Références : une opération « add » peut porter un `ref` ; toute valeur "@ref:<nom>"
 * dans les opérations suivantes est remplacée par l'id créé.
 */
final class Operations
{
    /** Types dont la modification est considérée « sensible » (confirmation toujours exigée). */
    /** Ce que la transaction garantit réellement en cas d'échec */
    public const ROLLBACK_SCOPE = "Les écritures en base de ce lot ont été annulées (transaction) : aucun élément n'a été créé, modifié ou supprimé "
        . "dans GLPI. Limite : un effet hors base déjà déclenché par GLPI ou un autre plugin pendant l'exécution (e-mail envoyé "
        . "immédiatement, appel à un service externe, fichier écrit) n'est pas annulé par la transaction.";

    public const SENSITIVE_TYPES = [
        'Entity', 'Profile', 'Profile_User', 'User', 'Group_User', 'RuleTicket', 'RuleRight', 'RuleAsset',
        'RuleMailCollector', 'Rule', 'RuleCriteria', 'RuleAction', 'MailCollector', 'Notification',
        'NotificationTemplate', 'SLM', 'SLA', 'OLA', 'Calendar', 'CronTask', 'Glpi\\Form\\AccessControl\\FormAccessControl',
    ];

    /**
     * Vérifie une liste d'opérations SANS rien modifier et renvoie l'aperçu.
     *
     * @return array{preview: array, warnings: array, sensitive: bool}
     */
    public static function check(array $ops): array
    {
        $preview   = [];
        $warnings  = [];
        $sensitive = false;
        $refs      = [];

        foreach ($ops as $i => $op) {
            $n = $i + 1;
            $kind = $op['op'] ?? '';
            try {
                switch ($kind) {
                    case 'add':
                        $item  = Items::newItem($op['itemtype']);
                        $input = $op['input'] ?? [];
                        $has_refs = self::containsRefs($input);
                        if (!$item::canCreate()) {
                            throw new ToolException(sprintf("Ton profil ne permet pas de créer : %s.", $item::getTypeName(1)));
                        }
                        if (!$has_refs) {
                            $probe = Items::cleanInput($item, $input);
                            if (!$item->can(-1, CREATE, $probe)) {
                                throw new ToolException(sprintf("Création de %s refusée dans l'entité visée (droits ou entité hors de ton périmètre).", $item::getTypeName(1)));
                            }
                        }
                        $ignored = [];
                        $clean = Items::cleanInput($item, $input, $ignored);
                        if ($ignored) {
                            $warnings[] = "Opération {$n} : champs ignorés → " . implode(', ', $ignored);
                        }
                        $preview[] = [
                            'n'        => $n,
                            'action'   => __('create', 'glpimcp'),
                            'type'     => Items::typeLabel($item),
                            'itemtype' => $item::class,
                            'ref'      => $op['ref'] ?? null,
                            'valeurs'  => self::humanize($item, $clean, $op['rule_class'] ?? null),
                        ];
                        if (!empty($op['ref'])) {
                            $refs[$op['ref']] = true;
                        }
                        $sensitive = $sensitive || in_array($item::class, self::SENSITIVE_TYPES, true);
                        break;

                    case 'update':
                        $item = Items::load($op['itemtype'], (int) $op['id'], UPDATE);
                        self::guardTarget($item);
                        $ignored = [];
                        $clean = Items::cleanInput($item, $op['input'] ?? [], $ignored);
                        if ($ignored) {
                            $warnings[] = "Opération {$n} : champs ignorés → " . implode(', ', $ignored);
                        }
                        $changes = [];
                        foreach ($clean as $k => $v) {
                            $old = $item->fields[$k] ?? null;
                            if (!str_starts_with((string) $k, '_') && (string) $old === (string) (is_array($v) ? json_encode($v) : $v)) {
                                continue;
                            }
                            $changes[$k] = [
                                'avant' => self::label($k, $old),
                                'après' => self::label($k, $v),
                            ];
                        }
                        if ($changes === []) {
                            $warnings[] = "Opération {$n} : aucune modification réelle (valeurs identiques).";
                        }
                        $preview[] = [
                            'n'       => $n,
                            'action'  => __('update', 'glpimcp'),
                            'type'    => Items::typeLabel($item),
                            'objet'   => self::display($item),
                            'changements' => $changes,
                        ];
                        $sensitive = $sensitive || in_array($item::class, self::SENSITIVE_TYPES, true);
                        break;

                    case 'delete':
                        $purge = !empty($op['purge']) || !Items::newItem($op['itemtype'])->maybeDeleted();
                        $item  = Items::load($op['itemtype'], (int) $op['id'], $purge ? PURGE : DELETE);
                        self::guardTarget($item);
                        $preview[] = [
                            'n'      => $n,
                            'action' => $item instanceof \CommonDBRelation ? __('remove the link', 'glpimcp') : ($purge ? __('DELETE PERMANENTLY', 'glpimcp') : __('move to the trash', 'glpimcp')),
                            'type'   => Items::typeLabel($item),
                            'objet'  => self::display($item),
                        ];
                        $sensitive = true;
                        break;

                    case 'restore':
                        $item = Items::load($op['itemtype'], (int) $op['id'], DELETE);
                        $preview[] = ['n' => $n, 'action' => __('restore from the trash', 'glpimcp'), 'type' => Items::typeLabel($item), 'objet' => self::display($item)];
                        break;

                    case 'config':
                        if (!Session::haveRight('config', UPDATE)) {
                            throw new ToolException("Modifier la configuration générale exige le droit « Configuration : modifier » (profil Super-Admin en général).");
                        }
                        $context = $op['context'] ?? 'core';
                        self::guardConfigContext((string) $context);
                        $current = Config::getConfigurationValues($context, array_keys($op['values'] ?? []));
                        $changes = [];
                        foreach ($op['values'] ?? [] as $k => $v) {
                            if (Items::isSecretConfigKey((string) $k)) {
                                throw new ToolException("Le paramètre « {$k} » est sensible : à saisir directement dans GLPI, pas via l'IA.");
                            }
                            $changes[$k] = ['avant' => $current[$k] ?? null, 'après' => $v];
                        }
                        $preview[] = ['n' => $n, 'action' => __('change the setup', 'glpimcp'), 'contexte' => $context, 'changements' => $changes];
                        $sensitive = true;
                        break;

                    case 'profile_rights':
                        $profile = Items::load('Profile', (int) $op['profiles_id'], UPDATE);
                        $current = ProfileRight::getProfileRights((int) $op['profiles_id'], array_keys($op['rights'] ?? []));
                        $changes = [];
                        foreach ($op['rights'] ?? [] as $k => $v) {
                            $changes[$k] = ['avant' => (int) ($current[$k] ?? 0), 'après' => (int) $v];
                        }
                        $preview[] = ['n' => $n, 'action' => __('change the profile rights', 'glpimcp'), 'objet' => $profile->getName(), 'changements' => $changes];
                        $sensitive = true;
                        break;

                    case 'form_build':
                        $preview[] = ['n' => $n] + FormBuilder::preview($op['spec'] ?? []);
                        if (!empty($op['ref'])) {
                            $refs[$op['ref']] = true;
                        }
                        break;

                    case 'form_destination_add':
                    case 'form_destination_update':
                        $form = Items::load(Form::class, (int) $op['forms_id'], UPDATE);
                        $norm = FormBuilder::normalizeDestinationList([$op['spec'] ?? []]);
                        if ($kind === 'form_destination_update') {
                            Items::load(\Glpi\Form\Destination\FormDestination::class, (int) $op['destination_id'], READ);
                        }
                        $preview[] = [
                            'n'          => $n,
                            'action'     => $kind === 'form_destination_add' ? __('add a destination', 'glpimcp') : sprintf(__('update destination #%d', 'glpimcp'), (int) $op['destination_id']),
                            'formulaire' => $form->getName(),
                        ] + FormBuilder::previewDestinations($norm)[0];
                        break;

                    case 'software_merge':
                        $preview[] = ['n' => $n] + \GlpiPlugin\Glpimcp\Software\SoftwareMerge::preview((int) $op['target_id'], (array) $op['source_ids']);
                        $sensitive = true;
                        break;

                    case 'software_unmerge':
                        $preview[] = ['n' => $n, 'action' => __('undo a software merge', 'glpimcp'), 'logiciel' => '#' . (int) $op['target_id'], 'à_séparer' => count($op['sources'] ?? []) . ' logiciel(s)'];
                        break;

                    case 'form_submit':
                        $form = self::loadAnswerableForm((int) $op['forms_id']);
                        $answers = $op['answers'] ?? [];
                        $result = AnswersHandler::getInstance()->validateAnswers($form, $answers);
                        if (!$result->isValid()) {
                            throw new ToolException('Réponses invalides : ' . json_encode(self::validationErrors($result), JSON_UNESCAPED_UNICODE));
                        }
                        $preview[] = [
                            'n'        => $n,
                            'action'   => __('submit the form', 'glpimcp'),
                            'objet'    => $form->getName(),
                            'réponses' => FormBuilder::humanAnswers($form, $answers),
                        ];
                        break;

                    default:
                        throw new ToolException("Opération inconnue : {$kind}");
                }
            } catch (ToolException $e) {
                throw new ToolException("Opération {$n} impossible : " . $e->getMessage(), $e->getDetails());
            }
        }

        Items::flushMessages();

        foreach ($preview as $k => $p) {
            $i = (int) ($p['n'] ?? $k + 1) - 1;
            if (isset($ops[$i]) && !isset($preview[$k]['annulable'])) {
                $preview[$k]['annulable'] = self::undoabilityOf($ops[$i]);
            }
        }
        return ['preview' => $preview, 'warnings' => $warnings, 'sensitive' => $sensitive];
    }

    /**
     * Exécute réellement les opérations (après confirmation). Tout ou rien.
     */
    public static function execute(array $ops, ?array &$journal = null, ?callable $before_commit = null): array
    {
        global $DB;

        $refs    = [];
        $results = [];
        // Journal d'annulation : opérations inverses + état après exécution (détection de conflits)
        $journal = ['undo' => [], 'irréversible' => [], 'état' => []];

        $DB->beginTransaction();
        try {
            foreach ($ops as $i => $op) {
                $n  = $i + 1;
                $op = self::resolveRefs($op, $refs);
                $kind = $op['op'];

                switch ($kind) {
                    case 'add':
                        $item  = Items::newItem($op['itemtype']);
                        $input = Items::cleanInput($item, $op['input'] ?? []);
                        if (!$item->can(-1, CREATE, $input)) {
                            throw new ToolException(sprintf("Opération %d : création de %s refusée par les droits GLPI.", $n, $item::getTypeName(1)));
                        }
                        $id = $item->add($input);
                        $messages = Items::flushMessages();
                        if (!$id) {
                            throw new ToolException(sprintf("Opération %d : GLPI a refusé la création de %s.", $n, $item::getTypeName(1)), ['messages_glpi' => $messages]);
                        }
                        if (!empty($op['ref'])) {
                            $refs[$op['ref']] = (int) $id;
                        }
                        $item->getFromDB((int) $id);
                        $journal['undo'][] = ['op' => 'delete', 'itemtype' => $item::class, 'id' => (int) $id, 'purge' => true, '_label' => 'supprimer ' . self::display($item)];
                        if (self::notifies($item)) {
                            $journal['irréversible'][] = 'Notifications e-mail éventuellement générées pour ' . self::display($item) . ' (une annulation ne les rappelle pas)';
                        }
                        self::recordState($journal, $item);
                        $results[] = ['n' => $n, 'résultat' => 'créé', 'type' => Items::typeLabel($item), 'id' => (int) $id, 'nom' => self::display($item), 'url' => Items::url($item), 'messages_glpi' => $messages];
                        break;

                    case 'update':
                        $item  = Items::load($op['itemtype'], (int) $op['id'], UPDATE);
                        self::guardTarget($item);
                        $input = Items::cleanInput($item, $op['input'] ?? []);
                        $input['id'] = $item->getID();
                        if (!$item->can($item->getID(), UPDATE, $input)) {
                            throw new ToolException("Opération {$n} : modification refusée par les droits GLPI.");
                        }
                        $old = [];
                        foreach ($input as $k => $v) {
                            if ($k === 'id') {
                                continue;
                            }
                            if (array_key_exists($k, $item->fields)) {
                                $old[$k] = $item->fields[$k];
                            } else {
                                $journal['irréversible'][] = "Champ spécial « {$k} » sur " . self::display($item) . ' (à reprendre à la main si besoin)';
                            }
                        }
                        $ok = $item->update($input);
                        $messages = Items::flushMessages();
                        if (!$ok) {
                            throw new ToolException("Opération {$n} : GLPI a refusé la modification.", ['messages_glpi' => $messages]);
                        }
                        if ($old !== []) {
                            $journal['undo'][] = ['op' => 'update', 'itemtype' => $item::class, 'id' => $item->getID(), 'input' => $old, '_label' => 'remettre les anciennes valeurs de ' . self::display($item)];
                        }
                        $item->getFromDB($item->getID());
                        self::recordState($journal, $item);
                        $results[] = ['n' => $n, 'résultat' => 'modifié', 'type' => Items::typeLabel($item), 'id' => $item->getID(), 'nom' => self::display($item), 'url' => Items::url($item), 'messages_glpi' => $messages]
                            + self::reread($item, $input);
                        break;

                    case 'delete':
                        $probe = Items::newItem($op['itemtype']);
                        $purge = !empty($op['purge']) || !$probe->maybeDeleted();
                        $item  = Items::load($op['itemtype'], (int) $op['id'], $purge ? PURGE : DELETE);
                        self::guardTarget($item);
                        $name  = self::display($item);
                        $ok    = $item->delete(['id' => $item->getID()], $purge);
                        $messages = Items::flushMessages();
                        if (!$ok) {
                            throw new ToolException("Opération {$n} : suppression refusée par GLPI.", ['messages_glpi' => $messages]);
                        }
                        if ($purge && $item instanceof \CommonDBRelation) {
                            // Retrait d'un lien : on garde de quoi le recréer
                            $relink = array_diff_key($item->fields, array_flip(['id', 'date_creation', 'date_mod']));
                            $journal['undo'][] = ['op' => 'add', 'itemtype' => $item::class, 'input' => $relink, '_label' => 'recréer le lien ' . $name];
                        } elseif ($purge) {
                            $journal['irréversible'][] = "Suppression définitive de {$name} (impossible à restaurer)";
                        } else {
                            $journal['undo'][] = ['op' => 'restore', 'itemtype' => $item::class, 'id' => (int) $op['id'], '_label' => 'restaurer ' . $name . ' depuis la corbeille'];
                        }
                        $results[] = ['n' => $n, 'résultat' => $item instanceof \CommonDBRelation ? 'lien retiré' : ($purge ? 'supprimé définitivement' : 'mis à la corbeille'), 'type' => Items::typeLabel($item), 'id' => (int) $op['id'], 'nom' => $name];
                        break;

                    case 'restore':
                        $item = Items::load($op['itemtype'], (int) $op['id'], DELETE);
                        if (!$item->isDeleted()) {
                            throw new ToolException('Opération ' . $n . ' : ' . self::display($item) . " n'est pas dans la corbeille.");
                        }
                        if (!$item->restore(['id' => $item->getID()])) {
                            throw new ToolException("Opération {$n} : restauration refusée par GLPI.", ['messages_glpi' => Items::flushMessages()]);
                        }
                        $journal['undo'][] = ['op' => 'delete', 'itemtype' => $item::class, 'id' => $item->getID(), 'purge' => false, '_label' => 'remettre ' . self::display($item) . ' à la corbeille'];
                        $results[] = ['n' => $n, 'résultat' => 'restauré', 'type' => Items::typeLabel($item), 'id' => $item->getID(), 'nom' => self::display($item)];
                        break;

                    case 'config':
                        if (!Session::haveRight('config', UPDATE)) {
                            throw new ToolException("Opération {$n} : droit de configuration manquant.");
                        }
                        self::guardConfigContext((string) ($op['context'] ?? 'core'));
                        $previous = Config::getConfigurationValues($op['context'] ?? 'core', array_keys($op['values'] ?? []));
                        Config::setConfigurationValues($op['context'] ?? 'core', $op['values'] ?? []);
                        $journal['undo'][] = ['op' => 'config', 'context' => $op['context'] ?? 'core', 'values' => $previous, '_label' => 'remettre la configuration précédente (' . implode(', ', array_keys($previous)) . ')'];
                        $results[] = ['n' => $n, 'résultat' => 'configuration mise à jour', 'paramètres' => array_keys($op['values'] ?? [])];
                        break;

                    case 'profile_rights':
                        $prof = Items::load('Profile', (int) $op['profiles_id'], UPDATE);
                        $previous = ProfileRight::getProfileRights((int) $op['profiles_id'], array_keys($op['rights'] ?? []));
                        foreach (array_keys($op['rights'] ?? []) as $rk) {
                            $previous[$rk] = (int) ($previous[$rk] ?? 0);
                        }
                        $journal['undo'][] = ['op' => 'profile_rights', 'profiles_id' => (int) $op['profiles_id'], 'rights' => $previous, '_label' => 'remettre les anciens droits du profil ' . $prof->getName()];
                        ProfileRight::updateProfileRights((int) $op['profiles_id'], array_map('intval', $op['rights'] ?? []));
                        $results[] = ['n' => $n, 'résultat' => 'droits du profil mis à jour', 'profil' => (int) $op['profiles_id'], 'droits' => array_keys($op['rights'] ?? [])];
                        break;

                    case 'form_build':
                        $built = FormBuilder::build($op['spec'] ?? []);
                        $journal['undo'][] = ['op' => 'delete', 'itemtype' => Form::class, 'id' => $built['id'], 'purge' => true, '_label' => 'supprimer le formulaire ' . $built['nom']];
                        if (!empty($op['ref'])) {
                            $refs[$op['ref']] = $built['id'];
                        }
                        $results[] = ['n' => $n, 'résultat' => 'formulaire créé'] + $built;
                        break;

                    case 'form_destination_add':
                        $form = Items::load(Form::class, (int) $op['forms_id'], UPDATE);
                        $d = FormBuilder::normalizeDestinationList([$op['spec'] ?? []])[0];
                        $did = FormBuilder::addDestination($form, $d);
                        $journal['undo'][] = ['op' => 'delete', 'itemtype' => \Glpi\Form\Destination\FormDestination::class, 'id' => $did, 'purge' => true, '_label' => 'supprimer la destination « ' . $d['name'] . ' » du formulaire ' . $form->getName()];
                        $results[] = ['n' => $n, 'résultat' => 'destination ajoutée', 'formulaire' => $form->getName(), 'id' => $did, 'nom' => $d['name']];
                        break;

                    case 'form_destination_update':
                        $form = Items::load(Form::class, (int) $op['forms_id'], UPDATE);
                        $dest = Items::load(\Glpi\Form\Destination\FormDestination::class, (int) $op['destination_id'], READ);
                        $old = array_intersect_key($dest->fields, array_flip(['name', 'creation_strategy', 'conditions', 'config']));
                        $d = FormBuilder::normalizeDestinationList([$op['spec'] ?? []])[0];
                        FormBuilder::updateDestination($form, $dest->getID(), $d);
                        $journal['undo'][] = ['op' => 'update', 'itemtype' => \Glpi\Form\Destination\FormDestination::class, 'id' => $dest->getID(), 'input' => $old, '_label' => 'remettre la destination « ' . $old['name'] . ' » dans son état précédent'];
                        $results[] = ['n' => $n, 'résultat' => 'destination modifiée', 'formulaire' => $form->getName(), 'id' => $dest->getID(), 'nom' => $d['name']];
                        break;

                    case 'software_merge':
                        $m = \GlpiPlugin\Glpimcp\Software\SoftwareMerge::execute((int) $op['target_id'], (array) $op['source_ids']);
                        $journal['undo'][] = $m['undo'];
                        $target = new \Software();
                        $target->getFromDB((int) $op['target_id']);
                        self::recordState($journal, $target);
                        $results[] = ['n' => $n] + $m['résultat'];
                        break;

                    case 'software_unmerge':
                        $results[] = ['n' => $n] + \GlpiPlugin\Glpimcp\Software\SoftwareMerge::unmerge($op);
                        $journal['irréversible'][] = 'Annulation de fusion (pour refusionner, relancer la fusion)';
                        break;

                    case 'form_submit':
                        $form = self::loadAnswerableForm((int) $op['forms_id']);
                        $answers = $op['answers'] ?? [];
                        $validation = AnswersHandler::getInstance()->validateAnswers($form, $answers);
                        if (!$validation->isValid()) {
                            throw new ToolException("Opération {$n} : réponses invalides.", self::validationErrors($validation));
                        }
                        $answers = AnswersHandler::getInstance()->removeUnusedAnswers($form, $answers);
                        $set = AnswersHandler::getInstance()->saveAnswers($form, $answers, (int) Session::getLoginUserID());
                        $created = [];
                        foreach ($set->getCreatedItems() as $created_item) {
                            $created[] = ['type' => $created_item::getTypeName(1), 'itemtype' => $created_item::class, 'id' => $created_item->getID(), 'nom' => $created_item->getName(), 'url' => Items::url($created_item)];
                            $journal['undo'][] = ['op' => 'delete', 'itemtype' => $created_item::class, 'id' => $created_item->getID(), 'purge' => false, '_label' => 'mettre à la corbeille ' . $created_item->getNameID()];
                            self::recordState($journal, $created_item);
                        }
                        $journal['irréversible'][] = 'Notifications e-mail générées par la soumission du formulaire « ' . $form->getName() . ' » (mises en file d\'envoi : une annulation ne les rappelle pas)';
                        $results[] = ['n' => $n, 'résultat' => 'formulaire soumis', 'formulaire' => $form->getName(), 'objets_créés' => $created, 'messages_glpi' => Items::flushMessages()];
                        break;

                    default:
                        throw new ToolException("Opération inconnue : {$kind}");
                }
            }
            if ($before_commit !== null) {
                // Ex. marquage « confirmation utilisée » : validé avec les écritures, ou annulé avec elles
                $before_commit($results);
            }
            $DB->commit();
            $journal['undo'] = array_reverse($journal['undo']);
        } catch (Throwable $e) {
            try {
                $DB->rollBack();
            } catch (Throwable) {
            }
            Items::flushMessages();
            $scope = ' ' . self::ROLLBACK_SCOPE;
            if ($e instanceof ToolException) {
                throw new ToolException($e->getMessage() . $scope, $e->getDetails() + ['transaction' => 'annulée']);
            }
            throw new ToolException('Erreur GLPI pendant l\'exécution : ' . $e->getMessage() . '.' . $scope, ['transaction' => 'annulée']);
        }

        return $results;
    }

    /**
     * Annulabilité d'une action exécutée, d'après son journal : totale, partielle ou aucune,
     * et effets hors base connus (jamais défaits par une annulation).
     *
     * @return array{niveau: string, effets_externes: string[]}
     */
    public static function undoability(?array $journal): array
    {
        $undo = $journal['undo'] ?? [];
        $irreversible = $journal['irréversible'] ?? [];
        $external = array_values(array_filter($irreversible, fn($l) => str_contains((string) $l, 'Notification') || str_contains((string) $l, 'e-mail')));
        $niveau = $undo === [] ? 'aucune' : ($irreversible === [] ? 'totale' : 'partielle');
        return ['niveau' => $niveau, 'effets_externes' => $external];
    }

    /** Les réglages de l'assistant IA (interrupteurs, approbation, OAuth) ne se changent jamais par l'IA */
    private static function guardConfigContext(string $context): void
    {
        if (str_starts_with($context, 'plugin:glpimcp')) {
            throw new ToolException("Les réglages de GLPI MCP (types d'actions autorisés, approbation, OAuth) se modifient uniquement dans GLPI, pas via l'assistant.");
        }
    }

    /**
     * Types d'objets ramenés à leur classe GLPI (alias « user », « utilisateur »… → User) AVANT toute
     * classification : interrupteurs, approbation humaine et garde-fous voient le même type que l'exécution.
     */
    public static function canonicalize(array $ops): array
    {
        foreach ($ops as $i => $op) {
            if (is_array($op) && isset($op['itemtype']) && is_string($op['itemtype']) && $op['itemtype'] !== '') {
                try {
                    $ops[$i]['itemtype'] = Items::resolveType($op['itemtype']);
                } catch (ToolException) {
                    // Type inconnu ou interdit : l'aperçu le refusera avec un message clair
                }
            }
        }
        return $ops;
    }

    /**
     * État des objets visés par le lot (empreinte de leurs champs) :
     * fait partie de l'empreinte de l'aperçu, pour qu'une modification faite entre l'aperçu et l'exécution
     * (même invisible dans l'aperçu, ex. une suppression) impose un nouvel aperçu.
     */
    public static function basis(array $ops): array
    {
        $out = [];
        foreach ($ops as $op) {
            if (empty($op['itemtype']) || empty($op['id']) || !is_numeric($op['id'])) {
                continue;
            }
            $class = ltrim((string) $op['itemtype'], '\\');
            if (!class_exists($class) || !is_subclass_of($class, CommonDBTM::class)) {
                continue;
            }
            $item = new $class();
            if (!$item->getFromDB((int) $op['id'])) {
                $out[$class . '#' . $op['id']] = 'absent';
                continue;
            }
            // Empreinte de TOUS les champs (une date_mod à la seconde ne voit pas deux modifications rapprochées)
            $out[$class . '#' . $op['id']] = hash('sha256', (string) json_encode($item->fields));
        }
        ksort($out);
        return $out;
    }

    /** Objets dont la création déclenche des notifications GLPI */
    private static function notifies(CommonDBTM $item): bool
    {
        return $item instanceof \CommonITILObject || $item instanceof \ITILFollowup || $item instanceof \CommonITILTask
            || $item instanceof \ITILSolution || $item instanceof \CommonITILValidation;
    }

    /** Annulabilité estimée d'une opération, montrée dans l'aperçu */
    public static function undoabilityOf(array $op): string
    {
        $kind = (string) ($op['op'] ?? '');
        $class = ltrim((string) ($op['itemtype'] ?? ''), '\\');
        return match (true) {
            $kind === 'delete' && class_exists($class) && is_subclass_of($class, \CommonDBRelation::class) => __('yes (link recreated)', 'glpimcp'),
            $kind === 'delete' && (!empty($op['purge']) || (class_exists($class) && is_subclass_of($class, CommonDBTM::class) && !(new $class())->maybeDeleted())) => __('NO: permanent deletion', 'glpimcp'),
            $kind === 'delete'      => __('yes (restore from the trash)', 'glpimcp'),
            $kind === 'add'         => __('yes (deletion of the created item; notifications already sent are not recalled)', 'glpimcp'),
            $kind === 'update'      => __('yes (previous values), except special fields reported', 'glpimcp'),
            $kind === 'form_submit' => __('partial: created items can be deleted, notifications sent are not recalled', 'glpimcp'),
            $kind === 'software_merge' => __('yes (separation of the merged software)', 'glpimcp'),
            default                 => __('yes', 'glpimcp'),
        };
    }

    /**
     * Relecture après écriture : valeurs réellement enregistrées par GLPI (une règle métier ou un contrôle
     * peut avoir modifié ou refusé une valeur demandée) ; les écarts sont signalés.
     */
    private static function reread(CommonDBTM $item, array $input): array
    {
        $saved = [];
        $diff  = [];
        foreach ($input as $k => $v) {
            if ($k === 'id' || str_starts_with((string) $k, '_') || !array_key_exists($k, $item->fields) || in_array($k, Items::SECRET_FIELDS, true) || is_array($v)) {
                continue;
            }
            $now = $item->fields[$k];
            $saved[$k] = self::label($k, $now);
            $long = strlen((string) $v) > 200 || str_contains((string) $v, '<');
            if (!$long && (string) $now !== (string) $v && !(is_numeric($now) && is_numeric($v) && (float) $now === (float) $v)) {
                $diff[] = sprintf('%s : demandé « %s », enregistré « %s »', $k, Items::text((string) $v, 80), Items::text((string) $now, 80));
            }
        }
        $out = ['relu_dans_glpi' => array_slice($saved, 0, 20, true)];
        if ($diff !== []) {
            $out['écarts_après_enregistrement'] = $diff;
        }
        return $out;
    }

    /** Champs qui changent sans modification « métier » (caches, horodatages) : ignorés pour les conflits */
    public const VOLATILE_FIELDS = ['date_mod', 'date_creation', 'ancestors_cache', 'sons_cache', 'last_login', 'date_sync', 'ranking', 'usage_count', 'uuid'];

    private static function recordState(array &$journal, CommonDBTM $item): void
    {
        $snapshot = [];
        foreach ($item->fields as $k => $v) {
            if (in_array($k, self::VOLATILE_FIELDS, true) || in_array($k, Items::SECRET_FIELDS, true) || (is_string($v) && strlen($v) > 2000)) {
                continue;
            }
            $snapshot[$k] = $v;
        }
        $journal['état'][$item::class . '#' . $item->getID()] = $snapshot;
    }

    /**
     * Garde-fou anti-escalade : on ne modifie / supprime pas un compte qui possède
     * plus de droits que soi (ex : un technicien ne désactive pas le Super-Admin).
     */
    private static function guardTarget(CommonDBTM $item): void
    {
        if ($item instanceof \User && $item->getID() !== (int) Session::getLoginUserID()) {
            $profiles = \Profile_User::getUserProfiles($item->getID());
            if ($profiles !== [] && !Profile::currentUserHaveMoreRightThan($profiles)) {
                throw new ToolException(sprintf(
                    "Le compte « %s » a des droits supérieurs ou différents des tiens : seul un administrateur de niveau au moins équivalent peut le modifier.",
                    $item->getName()
                ));
            }
        }
    }

    public static function loadAnswerableForm(int $forms_id): Form
    {
        $form = new Form();
        if (!$form->getFromDB($forms_id)) {
            throw new ToolException("Formulaire #{$forms_id} introuvable.");
        }
        $params = new FormAccessParameters(Session::getCurrentSessionInfo());
        if (!FormAccessControlManager::getInstance()->canAnswerForm($form, $params)) {
            throw new ToolException("Tu n'as pas accès au formulaire « " . $form->getName() . " » (inactif ou réservé à d'autres utilisateurs).");
        }
        return $form;
    }

    private static function validationErrors(object $result): array
    {
        $errors = [];
        foreach ($result->getErrors() as $error) {
            $errors[] = is_array($error) ? $error : (string) $error;
        }
        return $errors;
    }

    private static function containsRefs(mixed $v): bool
    {
        if (is_string($v)) {
            return str_starts_with($v, '@ref:');
        }
        if (is_array($v)) {
            foreach ($v as $x) {
                if (self::containsRefs($x)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function resolveRefs(mixed $v, array $refs): mixed
    {
        if (is_string($v) && str_starts_with($v, '@ref:')) {
            $key = substr($v, 5);
            if (!isset($refs[$key])) {
                throw new ToolException("Référence inconnue ou pas encore créée : {$v}");
            }
            return $refs[$key];
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::resolveRefs($x, $refs);
            }
        }
        return $v;
    }

    public static function display(CommonDBTM $item): string
    {
        $name = $item->fields['completename'] ?? $item->fields['name'] ?? '';
        return trim(sprintf('%s #%d', $name !== '' ? $name : $item::getTypeName(1), $item->getID()));
    }

    private static function label(string $field, mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '@ref:')) {
            return '(objet créé à l\'étape « ' . substr($value, 5) . ' »)';
        }
        $l = Items::fkLabel($field, $value);
        if ($l !== null) {
            return $value . ' (' . $l . ')';
        }
        if (is_string($value) && strlen($value) > 300) {
            return Items::text($value, 300);
        }
        return $value;
    }

    private static function humanize(CommonDBTM $item, array $input, ?string $rule_class = null): array
    {
        $out = [];
        $rule_class = $rule_class !== null && is_a($rule_class, \Rule::class, true) ? $rule_class : \RuleTicket::class;
        $operators = \GlpiPlugin\Glpimcp\Rules\RuleDescriber::OPERATORS;
        if ($item instanceof \RuleCriteria && isset($input['criteria'])) {
            $def = (new $rule_class())->getCriteria($input['criteria']);
            $op = array_search((int) ($input['condition'] ?? 0), $operators, true) ?: $input['condition'];
            $val = $input['pattern'] ?? '';
            $lab = is_numeric($val) ? (match ($input['criteria']) {
                'entities_id' => \Dropdown::getDropdownName('glpi_entities', (int) $val),
                'itilcategories_id' => \Dropdown::getDropdownName('glpi_itilcategories', (int) $val),
                '_groups_id_of_requester' => \Dropdown::getDropdownName('glpi_groups', (int) $val),
                default => (string) $val,
            }) : (string) $val;
            $out['signification'] = sprintf('%s %s « %s »', $def['name'] ?? $input['criteria'], str_replace('_', ' ', (string) $op), $lab);
        }
        if ($item instanceof \RuleAction && isset($input['field'])) {
            $def = (new $rule_class())->getAction($input['field']);
            $val = self::label(str_contains($input['field'], 'groups_id') ? 'groups_id' : (str_contains($input['field'], 'users_id') ? 'users_id' : $input['field']), $input['value'] ?? '');
            $out['signification'] = sprintf('%s → %s', $def['name'] ?? $input['field'], is_array($val) ? json_encode($val) : $val);
        }
        foreach ($input as $k => $v) {
            $out[$k] = self::label($k, $v);
        }
        return $out;
    }
}
