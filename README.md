# GLPI MCP — piloter GLPI avec une IA, sans jamais dépasser ses droits

Plugin **natif GLPI 11**, open source (GPL-3.0-or-later), qui transforme GLPI en serveur
[MCP](https://modelcontextprotocol.io) (Model Context Protocol). Un assistant IA (Claude, ChatGPT,
Le Chat, Copilot ou tout client MCP) peut alors utiliser GLPI au quotidien : rechercher, consulter,
créer et modifier des éléments, traiter des tickets, remplir les formulaires du catalogue.

Quatre principes :

1. **L'IA a exactement les droits du compte GLPI** qui a généré le jeton (profil + entités).
   Elle passe par les classes GLPI (`can()`, règles métier, contrôles d'accès des formulaires),
   jamais par du SQL d'écriture. Garde-fou supplémentaire : impossible de modifier un compte
   qui a plus de droits que soi.
2. **Rien n'est modifié sans validation humaine.** Chaque écriture renvoie d'abord un
   *aperçu* (avant → après, champ par champ) et un `confirmation_id`. Seul un second appel
   explicite (`glpi_confirm_action`) exécute, après une nouvelle vérification des droits.
3. **Tout ou rien.** Une action, même de plusieurs dizaines d'opérations, s'exécute dans une
   transaction unique. Après l'écriture, les valeurs sont relues dans GLPI et les écarts éventuels
   (règle métier, contrôle GLPI) sont signalés.
4. **L'administrateur choisit ce que l'IA a le droit de faire**, par type d'action, en plus des
   droits GLPI ; les actions à risque sont désactivées par défaut.

## Outils (45)

| Domaine | Outils |
|---|---|
| Session | `glpi_whoami`, `glpi_switch_context` (changer de profil/entité parmi les siens) |
| Découverte | `glpi_list_itemtypes`, `glpi_describe_itemtype` (champs, libellés, valeurs possibles) |
| Lecture | `glpi_search` (tout type, filtres, « mes tickets ouverts »…), `glpi_get_item`, `glpi_asset_summary` |
| Écriture | `glpi_create_items` (en lot, avec références), `glpi_update_items`, `glpi_delete_items` |
| Confirmation | `glpi_confirm_action`, `glpi_cancel_action`, `glpi_list_pending_actions` |
| Journal | `glpi_ai_history`, `glpi_rollback_action` |
| Assistance | `glpi_ticket_create`, `glpi_ticket_get` (chronologie), `glpi_ticket_add_followup`, `glpi_ticket_add_task`, `glpi_ticket_add_solution`, `glpi_ticket_add_actor`, `glpi_validation_answer`, `glpi_kb_search` |
| Projets | `glpi_project_list`, `glpi_project_get`, `glpi_project_my_tasks`, `glpi_project_task_add`, `glpi_project_task_update` |
| Incidents de sécurité* | `glpi_secincident_list`, `glpi_secincident_get`, `glpi_secincident_declare`, `glpi_secincident_add_event`, `glpi_secincident_add_iocs`, `glpi_secincident_task_update` |
| Formulaires | `glpi_form_list`, `glpi_form_get`, `glpi_form_question_types`, `glpi_form_submit` (remplissage conversationnel) |
| Configuration | `glpi_config_get`, `glpi_config_set`, `glpi_profile_rights`, `glpi_rule_list` |
| Primitives | `glpi_export`, `glpi_system_facts`, `glpi_apply_operations` (voir ci-dessous) |

**Vérification de langue** : un suivi public ou une solution rédigés dans une autre langue que celle
du demandeur sont signalés dans l'aperçu ; l'assistant demande alors dans quelle langue répondre.

Les outils affichés dépendent du profil (un *Self-Service* ne voit ni la configuration ni les primitives)
et des types d'actions activés par l'administrateur.

\* Seulement si le plugin [SecIncident](https://github.com/mars-ice-cream/secincident) est installé et actif.

### Incidents de sécurité (plugin SecIncident)

Quand SecIncident est actif, l'assistant peut suivre les incidents de sécurité avec **exactement les contrôles
de l'interface** : droits du plugin, cloisonnement « mes incidents » (responsable, rédacteur, groupe, équipe),
restriction optionnelle au groupe sécurité (appliquée aussi aux sessions de l'assistant).

- **Lecture** : liste (phase, gravité, échéances CNIL 72 h / ANSSI 24 h-72 h-1 mois dépassées ou proches),
  fiche complète (qualification, impacts, MITRE ATT&CK, RGPD, NIS2, équipe, éléments liés, plan de remédiation,
  chronologie, IOC). Chaque consultation est tracée dans le journal d'audit de SecIncident.
- **Écriture limitée**, désactivée par défaut (*Types d'actions autorisés > Incidents de sécurité*) : déclarer un
  incident (avec éléments touchés et premiers IOC), alimenter la chronologie, ajouter des IOC (doublons écartés),
  avancer une tâche de remédiation. Aperçu, confirmation et annulation comme partout.
- **Jamais via l'IA**, même avec les outils génériques : suppression ou restauration, clôture d'un incident,
  modification de l'équipe (qui y a accès), procédures et catégories, journal d'audit et registre des preuves.
- **Traçabilité** : en plus du journal IA de GLPI MCP, chaque action de l'assistant ajoute une entrée
  `ai_assistant` (canal, client IA, n° du journal IA) dans le journal d'audit chaîné de SecIncident ; les
  événements et IOC créés portent la source `mcp`.
- **Injection de consignes** : tout texte issu d'un incident (mail de phishing collé, commande, données EDR) est
  renvoyé encadré `«««DONNÉE NON FIABLE»»» … «««FIN»»»`, avec la consigne de l'analyser sans jamais l'exécuter.

### Primitives pour les programmes

Pour construire des outils plus avancés (rapports, analyses, assistants spécialisés) **sans rien
contourner** :

- `glpi_export` : lecture en masse paginée de n'importe quel type (y compris les tables de liaison),
  avec les mêmes contrôles que la recherche (profil, entités, droits fins objet par objet, champs secrets
  exclus) ; `group_by` pour des décomptes ;
- `glpi_system_facts` : version, langues, inventaire, types de l'analyse d'impact, comptes au mot de
  passe d'origine (noms seulement), tâches cron, décomptes par type ;
- `glpi_apply_operations` : un lot d'opérations (`add`, `update`, `delete`, `restore`, `config`,
  `profile_rights`, `form_build`, `form_destination_add/update`, `form_submit`, `software_merge`) exécuté
  avec exactement les mêmes garanties que les autres outils : aperçu, confirmation, interrupteurs,
  transaction, journal, annulation.

C'est sur ces primitives que s'appuie **Dokore pour GLPI** (offre commerciale, programme distinct) :
premier paramétrage guidé, création et fusion de formulaires, nettoyage du catalogue logiciel,
analyse d'impact et faiblesses de l'infrastructure, contrôle qualité des tickets.

## Traçabilité et annulation (rollback)

Chaque action passée par l'IA est journalisée : **qui** (compte GLPI et profil utilisé),
**avec quel assistant** (nom du jeton), **quoi** (outil, opérations, objets créés/modifiés),
**quand**, et le **statut** : `exécuté`, `échec`, `refusé` (droits insuffisants) ou `annulé`
(l'utilisateur a dit non à l'aperçu).

Dans l'**historique natif GLPI** de chaque objet, les modifications faites par l'IA
apparaissent ainsi : `Marie Martin (8) [via IA : Claude Desktop — journal #42]`.

**Annuler une action** depuis le journal (bouton *Annuler…*) ou en demandant à l'IA
(`glpi_rollback_action`) :
- ce qui a été créé est supprimé, les anciennes valeurs sont remises, ce qui a été mis à la
  corbeille est restauré, les droits de profil et la configuration reviennent à l'état d'avant ;
- seuls les champs modifiés par l'IA sont remis : une modification faite à la main entre-temps
  sur un autre champ est conservée ;
- si un objet a été modifié depuis l'action IA (comparaison des valeurs, pas des dates : un
  objet modifié puis remis en l'état n'est pas un conflit), l'annulation s'arrête et liste les
  **conflits** champ par champ (on peut forcer, en connaissance de cause) ;
- l'annulation est elle-même une action : aperçu, droits revérifiés, tout ou rien, journalisée ;
- réservée à l'auteur de l'action ou à un administrateur GLPI.

Ne peut pas être défait : une suppression définitive (purge), les e-mails de notification
déjà envoyés, les « champs spéciaux » (acteurs ajoutés via `_users_id_…` lors d'une modification).
Le journal l'indique explicitement pour chaque action.

---

### Ce que l'IA ne fera jamais

- lire ou écrire un mot de passe, un jeton, une clé (SMTP, LDAP, collecteur, API…) ;
- toucher aux plugins, aux journaux, à la clé de chiffrement GLPI ;
- confirmer une action à la place de l'utilisateur (consigne dans chaque aperçu).


---

## Installation

Prérequis : GLPI 11.0 → 11.9, PHP ≥ 8.2.

```bash
cd /var/www/glpi/plugins            # ou marketplace/
unzip glpimcp-1.2.0.zip             # crée le dossier glpimcp/
chown -R www-data:www-data glpimcp
php /var/www/glpi/bin/console plugin:install glpimcp --username=glpi
php /var/www/glpi/bin/console plugin:activate glpimcp
```

(ou *Configuration > Plugins* → Installer → Activer).

Vérifier que l'URL de l'application est correcte (*Configuration > Générale*), elle sert à
afficher l'adresse du serveur MCP : `https://votre-glpi/plugins/glpimcp/mcp`.

> **Reverse proxy / Apache :** l'en-tête `Authorization` doit être transmis à PHP.
> Avec Apache + PHP-FPM, ajouter si besoin :
> `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`

### Réglages (Configuration > Plugins > GLPI MCP)

- **Confirmation** : toutes les écritures (recommandé) ou seulement les sensibles
  (suppressions, droits, utilisateurs, entités, règles, configuration, SLA…).
- Durée de validité d'un aperçu, nombre max de résultats.
- **Types d'actions autorisés pour l'IA**, en plus des droits GLPI (une action doit être
  permise par le profil ET activée ici) ; les outils correspondants sont masqués quand un type
  est désactivé, et le réglage est revérifié au moment de la confirmation :

  | Activé par défaut | Désactivé par défaut (à risque) |
  |---|---|
  | Assistance (tickets, suivis, solutions…), remplir des formulaires, créer/modifier des éléments, corbeille, concevoir des formulaires, règles, fusion de logiciels, annulation depuis le journal | Suppression définitive, utilisateurs et groupes, droits des profils, configuration générale |

  Pour un premier paramétrage complet (création de comptes, réglages e-mail…), activer
  temporairement « Utilisateurs et groupes » et « Configuration générale ». Chaque changement
  de ces interrupteurs est inscrit au journal IA.
- Liste des jetons actifs (révocation) et journal des actions avec annulation.

Chaque utilisateur retrouve aussi ses propres actions IA dans *Mes préférences > Assistant IA (MCP)*.

---

## Connecter un assistant

Deux façons de se connecter, qui donnent exactement les mêmes droits :

### 1. OAuth — claude.ai, ChatGPT, Le Chat (Mistral), et tout client MCP moderne

L'utilisateur ajoute un connecteur avec l'URL `https://votre-glpi/plugins/glpimcp/mcp`.
Le client découvre tout seul le serveur d'autorisation, s'enregistre, puis ouvre une page
GLPI : l'utilisateur se connecte (SSO/LDAP compris), voit **quel assistant** demande l'accès,
choisit **le profil** avec lequel l'IA agira et la durée, puis clique *Autoriser*.

- **claude.ai / Claude Desktop / Claude mobile** : Paramètres > Connecteurs > *Ajouter un
  connecteur personnalisé* → coller l'URL (sur les offres Team/Enterprise, c'est un
  propriétaire de l'organisation qui l'ajoute).
- **ChatGPT** : Paramètres > Applications et connecteurs > activer le *mode développeur*
  (paramètres avancés) → *Créer* un connecteur, URL ci-dessus, authentification OAuth.
- **Le Chat (Mistral)** : Connecteurs > *Ajouter un connecteur* > MCP personnalisé → URL.
- Les menus exacts évoluent selon les versions de ces outils ; seule l'URL compte.

Prérequis côté GLPI :
- GLPI accessible depuis Internet en **HTTPS** (les serveurs de Claude/OpenAI/Mistral
  appellent GLPI) — par exemple derrière un reverse proxy avec certificat Let's Encrypt ;
- l'**URL de l'application** (Configuration > Générale) doit être cette URL publique : c'est
  elle qui est annoncée aux clients ;
- si GLPI est installé dans un sous-dossier (`https://exemple.fr/glpi`), ajouter au serveur
  web une redirection de `/.well-known/oauth-*` vers `/glpi/.well-known/oauth-*`.
  À la racine d'un domaine/sous-domaine (`https://glpi.exemple.fr`), il n'y a rien à faire.

Connexion : si l'utilisateur n'est pas encore connecté à GLPI, le plugin l'envoie sur la page
de connexion GLPI en conservant intégralement la demande d'autorisation (PKCE, `state`,
`resource`), puis affiche la page de consentement — aucun correctif du cœur de GLPI n'est
nécessaire.

**Serveurs relais** (ex. Dokore) : un service qui expose ses propres outils au-dessus de GLPI peut
utiliser l'OAuth de GLPI. L'administrateur déclare son URL MCP dans *Serveurs relais autorisés* ; un client
peut alors demander un jeton pour ce relais, la page de consentement l'indique à l'utilisateur, et le relais
transmet le jeton à GLPI, qui reste seul juge des droits. Liste vide par défaut.

Sécurité OAuth : code d'autorisation + PKCE S256 obligatoire, code à usage unique (2 min),
jeton d'accès d'une heure (réglable), jeton de rafraîchissement tournant (l'ancien est
invalidé à chaque usage), `redirect_uri` vérifié strictement. Chaque accès accordé apparaît
dans *Mes préférences > Assistant IA (MCP)* (révocable), et l'administrateur peut désactiver
un client IA entier (ce qui coupe immédiatement tous ses accès). L'enregistrement automatique
des clients et OAuth lui-même peuvent être désactivés dans la configuration du plugin.

### 2. Jeton personnel — Claude Code, scripts, clients sans OAuth

Dans GLPI : **Mes préférences > Assistant IA (MCP) > Générer un jeton** (profil + durée).
Le jeton n'est affiché qu'une fois.

**Claude Code**

```bash
claude mcp add --transport http glpi https://votre-glpi/plugins/glpimcp/mcp \
  --header "Authorization: Bearer gmcp_xxxxxxxx"
```

**Tout client MCP « Streamable HTTP »** : URL ci-dessus + en-tête
`Authorization: Bearer <jeton>`. En-têtes optionnels : `X-GLPI-Profile: <id>`,
`X-GLPI-Entity: <id>`.

## Exemples de demandes

- « Quels tickets urgents sont ouverts dans le groupe Support ? Résume le 1245. »
- « Ajoute une tâche de 30 min sur le ticket 1245 et résous-le avec cette explication… »
- « Crée l'ordinateur PC-COMPTA-02, statut En service, au Bureau Le Teil. »
- « Quelles garanties expirent dans les 3 mois ? »
- « Remplis pour moi le formulaire de demande de matériel : un écran 27 pouces pour la compta. »
- « Le profil Technicien peut-il supprimer des ordinateurs ? Retire-lui ce droit. »
- « Annule ce que tu as fait tout à l'heure sur les catégories. »

---

## Tests

- **Unitaires** (sans GLPI) : `php tests/unit/run.php`.
- **De bout en bout**, via le protocole MCP comme un vrai client, contre un GLPI de **test** :

  ```bash
  pip install requests
  export MCP_URL=https://glpi-test/plugins/glpimcp/mcp
  export TOKEN_ADMIN=gmcp_… TOKEN_TECH=gmcp_… TOKEN_SELFSERVICE=gmcp_…
  export GLPI_LOGIN=… GLPI_PASSWORD=…        # optionnel : parcours OAuth avec connexion
  python3 tests/e2e/run_tests.py
  ```

  17 scénarios : authentification, filtrage des outils par profil, droits GLPI, anti-escalade,
  aperçu/confirmation à usage unique, transaction tout ou rien (échec en cours d'exécution), annulation
  avec détection de conflit, interrupteurs, moteur de formulaires (titre dynamique, destination
  conditionnelle), vérification de langue, fusion de logiciels et annulation, projets (tâches, sous-tâches, équipe, annulation), incidents de sécurité si SecIncident est actif, primitives (export paginé
  sans champ secret, faits, liens d'impact), OAuth (découverte, enregistrement, PKCE, connexion d'un
  utilisateur déconnecté). Tout ce qui est créé est préfixé `TEST-MCP` puis annulé via le journal.
- **Intégration continue** : `.github/workflows/tests.yml`.

## Publication dans le catalogue des plugins GLPI

Le dépôt contient ce qu'attend [plugins.glpi-project.org](https://plugins.glpi-project.org) :
`glpimcp.xml` (description FR/EN, versions, compatibilité), `logo.png`, `LICENSE` (GPLv3),
`CHANGELOG.md`. Dépôt : https://github.com/mars-ice-cream/glpimcp. Chaque version publiée a sa *release*
avec l'archive `glpimcp-<version>.tar.bz2` ; le catalogue lit
`https://raw.githubusercontent.com/mars-ice-cream/glpimcp/main/glpimcp.xml`.

## Architecture

```
setup.php / hook.php        déclaration, tables (jetons hachés, actions en attente, journal, OAuth)
src/Controller/McpController.php   POST /plugins/glpimcp/mcp — auth Bearer → session GLPI en mémoire
src/Controller/OAuthController.php page de consentement GLPI, /oauth/token, /oauth/register
src/OAuth/                  OAuth 2.1 : découverte, enregistrement dynamique, PKCE, jetons tournants
src/Mcp/Server.php          JSON-RPC 2.0 (initialize, tools/*, prompts/*)
src/Mcp/Operations.php      moteur d'écriture : vérification → aperçu → exécution transactionnelle → relecture
src/Mcp/PendingActions.php  cycle aperçu → confirmation (usage unique, expiration, par utilisateur)
src/Mcp/Permissions.php     types d'actions autorisés (interrupteurs administrateur)
src/Mcp/AuditLog.php        journal + marquage « via IA » dans l'historique GLPI
src/Mcp/Rollback.php        annulation : opérations inverses, détection de conflits
src/Mcp/Items.php           résolution des types, filtrage des champs sensibles, export lisible
src/Forms/                  moteur de formulaires GLPI 11 natifs (questions, conditions, traductions, destinations)
src/Software/SoftwareMerge.php  fusion de logiciels annulable
src/Tickets/LanguageCheck.php   vérification de langue avant réponse
src/Tools/*                 un fichier par outil
tests/                      tests unitaires et de bout en bout
```

Le point d'entrée est sans état (pas de cookie, pas de CSRF) : chaque requête ouvre une
session GLPI temporaire pour le propriétaire du jeton, exactement comme l'API REST de GLPI.

Licence GPL-3.0-or-later.
