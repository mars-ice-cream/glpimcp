# GLPI MCP — piloter GLPI avec une IA, sans jamais dépasser ses droits

Plugin **natif GLPI 11**, open source (GPL-3.0-or-later), qui transforme GLPI en serveur
[MCP](https://modelcontextprotocol.io) (Model Context Protocol). Un assistant IA (Claude, ChatGPT,
Le Chat, Copilot ou tout client MCP) peut alors utiliser GLPI au quotidien : rechercher, consulter,
créer et modifier des éléments, traiter des tickets, remplir les formulaires du catalogue.

Quatre principes :

1. **L'IA a au plus les droits du compte GLPI, limités au périmètre du jeton.** Le profil et les
   entités choisis à la création du jeton (ou au consentement OAuth) sont une limite : ni un en-tête
   `X-GLPI-Profile` / `X-GLPI-Entity`, ni `glpi_switch_context`, ni un relais ne peuvent l'élargir.
   L'IA passe par les classes GLPI (`can()`, règles métier, contrôles d'accès des formulaires), jamais
   par du SQL d'écriture. Garde-fou supplémentaire : impossible de modifier un compte qui a plus de
   droits que soi.
2. **Rien n'est modifié sans aperçu, et rien de critique sans approbation humaine vérifiée.**
   Chaque écriture renvoie d'abord un *aperçu* (avant → après, champ par champ, annulabilité de
   chaque opération) et un `confirmation_id`. Le second appel `glpi_confirm_action` est fait par
   l'assistant : **il ne prouve pas qu'un humain a vu l'aperçu**. C'est pourquoi les opérations
   critiques (suppression définitive, comptes et groupes, droits des profils, configuration,
   annulation forcée) exigent en plus que l'utilisateur **approuve lui-même dans GLPI**, connecté
   avec son compte — le jeton MCP et le `confirmation_id` n'y suffisent jamais (voir plus bas).
3. **Usage unique et cohérence.** Une confirmation ne peut produire qu'une exécution, même en cas
   d'appels simultanés. Au moment de l'exécution, droits et réglages sont revérifiés et l'aperçu est
   recalculé : si les données ont changé depuis, un nouvel aperçu est exigé. Les écritures en base
   d'une action s'exécutent dans une transaction unique (tout ou rien en base) ; les effets hors base
   (e-mails, appels de services externes par d'autres plugins) ne sont pas couverts par la transaction.
4. **L'administrateur choisit ce que l'IA a le droit de faire**, par type d'action, en plus des
   droits GLPI ; les actions à risque sont désactivées par défaut.

### Confirmation et approbation humaine

| | Mode « toutes » (défaut) | Mode « sensibles » |
|---|---|---|
| Écriture ordinaire (ticket, suivi, élément…) | aperçu → `glpi_confirm_action` | **exécutée immédiatement** (pas d'aperçu préalable) |
| Écriture sensible (corbeille, règles, entités, SLA, fusions…) | aperçu → `glpi_confirm_action` | aperçu → `glpi_confirm_action` |
| Écriture critique (purge, comptes/groupes/habilitations, droits des profils, configuration, annulation forcée) | aperçu → **approbation dans GLPI** → `glpi_confirm_action` | idem |

- `glpi_confirm_action` est un **second appel de l'assistant**. Il montre que l'assistant a été
  programmé pour demander, pas qu'un humain a validé.
- **L'approbation humaine** se fait sur une page GLPI (`/plugins/glpimcp/front/approval.php`) : session
  GLPI authentifiée du **même** utilisateur, jeton CSRF de GLPI. La page montre l'action, les cibles,
  les valeurs avant/après, ce qui ne pourra pas être défait, le contexte (assistant, profil, entité).
  L'approbation est liée par empreinte à la proposition exacte (contenu du lot, aperçu, état des
  objets visés, jeton, profil et entités actives) ; une proposition modifiée, expirée, déjà utilisée
  ou devenue obsolète est refusée. Le journal enregistre qui a approuvé et quand.
- La portée de l'approbation est réglable : *actions critiques* (défaut) ou *toutes les actions
  sensibles*. Les réglages de GLPI MCP eux-mêmes ne sont jamais modifiables par l'assistant.
- Une nouvelle tentative de confirmation ne rejoue jamais une écriture : déjà exécutée → réponse
  `déjà_exécutée` avec le n° de journal ; échouée ou obsolète → nouvel aperçu exigé ; interrompue
  (processus arrêté) → reprise possible après 15 min, car l'état « exécutée » est validé dans la même
  transaction que les écritures.

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
  corbeille est restauré, un lien retiré est recréé, les droits de profil et la configuration
  reviennent à l'état d'avant ;
- seuls les champs modifiés par l'IA sont remis : une modification faite à la main entre-temps
  sur un autre champ est conservée ;
- si un objet a été modifié depuis l'action IA (comparaison des valeurs), l'annulation s'arrête et
  liste les **conflits** champ par champ. On peut forcer (`force=true`) : l'annulation forcée exige
  alors l'**approbation humaine dans GLPI** ;
- les conflits sont **revérifiés au moment de l'exécution** : si un technicien modifie un élément
  entre l'aperçu de l'annulation et sa confirmation, l'annulation est bloquée sans rien modifier ;
- l'annulation est elle-même une action : aperçu, droits revérifiés, transaction, journalisée ;
- réservée à l'auteur de l'action ou à un administrateur GLPI.

Chaque action exécutée indique son **annulabilité** (aussi dans le journal) :

| Annulabilité | Signification |
|---|---|
| totale | toutes les écritures en base peuvent être défaites |
| partielle | certaines parties ne le peuvent pas : elles sont listées (`non_annulable`) |
| aucune | rien n'est défaisable (ex. suppression définitive) |

Ne peuvent pas être défaits : une suppression définitive (purge) ; les **notifications e-mail**
générées par la création d'un ticket, d'un suivi, d'une tâche, d'une solution, d'une validation ou
par la soumission d'un formulaire (une annulation supprime l'objet, pas l'e-mail) ; les « champs
spéciaux » (acteurs ajoutés via `_users_id_…` lors d'une modification) ; tout effet produit hors de
la base par GLPI ou un autre plugin (appel à un service externe, fichier écrit). Le journal l'indique
pour chaque action.

En cas d'échec pendant l'exécution, les écritures en base du lot sont annulées par la transaction ;
le message d'erreur le dit, et rappelle que les effets hors base éventuellement déjà déclenchés ne
sont pas couverts.

---

### Ce que l'IA ne fera jamais

- lire ou écrire un mot de passe, un jeton, une clé (SMTP, LDAP, collecteur, API…) ;
- toucher aux plugins, aux journaux, à la clé de chiffrement GLPI ;
- approuver une action critique : seule une session GLPI de l'utilisateur le peut ;
- modifier les réglages de GLPI MCP (types d'actions autorisés, approbation, OAuth) ;
- utiliser un autre profil ou d'autres entités que ceux accordés à son jeton.


---

## Installation

Prérequis : GLPI 11.0 → 11.9, PHP ≥ 8.2.

```bash
cd /var/www/glpi/plugins            # ou marketplace/
unzip glpimcp-1.6.0.zip             # crée le dossier glpimcp/
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

### Réglages (Administration > Assistant IA (MCP))

Accessible aussi par *Configuration > Plugins > GLPI MCP*. Réservé aux comptes ayant le droit de modifier
la configuration de GLPI.


- **Confirmation** : toutes les écritures (recommandé) ou seulement les sensibles ; en mode
  « sensibles », les écritures ordinaires sont exécutées sans aperçu préalable.
- **Approbation humaine dans GLPI** : actions critiques (défaut) ou toutes les actions sensibles.
  Chaque changement de ces deux réglages est inscrit au journal IA.
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
- **Outils interdits pour tout le monde** : un outil coché est masqué pour tous les assistants et
  refusé, quel que soit le profil. Passe avant tout le reste.
- **Restrictions par profil** : sur chaque fiche profil (*Administration > Profils*), un onglet
  *Assistant IA (MCP)* permet de décocher les outils que ce profil ne doit pas utiliser et de lui retirer
  des types d'action (y compris par les outils génériques de création et de modification). Exemple :
  un profil Self-Service limité à la recherche, aux tickets et à la base de connaissances.

  Ces trois couches (outils interdits, types d'action globaux, politique du profil) ne font que
  restreindre : les droits GLPI du compte s'appliquent toujours. Trois outils restent toujours
  disponibles (qui suis-je, actions en attente, annuler une action en attente). Tout est revérifié au
  moment de la confirmation : une action préparée avec un outil retiré depuis est refusée. Chaque
  modification est inscrite au journal IA.
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

Dans GLPI : **Mes préférences > Assistant IA (MCP) > Générer un jeton** (profil, entité, sous-entités,
durée). Le jeton n'est affiché qu'une fois. Les actions qui attendent votre approbation sont listées sur
cette même page.

### Périmètre d'un jeton

| Élément | Règle |
|---|---|
| Profil | Celui choisi à la création (ou au consentement OAuth) ; « profil par défaut » est résolu à la création et figé. `X-GLPI-Profile` n'est accepté que s'il désigne ce profil (sinon HTTP 403) ; `glpi_switch_context` ne change plus de profil. |
| Entités | L'entité choisie (avec ou sans sous-entités), ou toutes celles que le profil donne au compte. `X-GLPI-Entity` et `glpi_switch_context` choisissent une entité de travail **dans** cette limite ; au-delà : refus explicite (HTTP 403 / erreur). |
| Droits retirés | Si le compte perd le profil ou l'entité du jeton, le jeton cesse de fonctionner ; s'il perd un droit, l'exécution est refusée à la confirmation. Le périmètre ne s'élargit jamais. |
| Proposition | Liée au jeton, au profil et aux entités actives de son aperçu : elle ne peut être confirmée qu'avec le même jeton, dans le même contexte. |

**Mise à jour depuis une version antérieure à 1.4.0** : les jetons créés avec « profil par défaut »
sont figés sur le profil par défaut actuel du compte (ou son unique profil) ; si aucun profil ne peut
être déterminé, le jeton est désactivé et doit être recréé. Les jetons dont l'entité était vide gardent
« toutes les entités du profil ». Les propositions en attente au moment de la mise à jour sont retirées
(il suffit de refaire la demande). Un assistant qui envoyait `X-GLPI-Profile` vers un autre profil
reçoit désormais une erreur 403 : créer un jeton par profil.

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

## Déployer en entreprise

Trois façons de connecter des assistants, selon qui utilise le plugin. Aucune ne donne plus de droits que
le compte GLPI ; toutes passent par les mêmes garde-fous (aperçu, approbation, quotas).

| Cas | Qui | Comment | Ce que voit l'utilisateur |
|---|---|---|---|
| **Individuel** | techniciens, administrateurs, scripts | jeton personnel, ou OAuth avec choix du profil | il génère son jeton ou clique *Autoriser* en choisissant son profil |
| **Assistant de l'entreprise** (Copilot, Claude Team/Enterprise, ChatGPT Enterprise…) | tous les salariés | OAuth + client IA **géré** par l'administrateur, SSO de GLPI | la 1re fois, *Se connecter* ; avec le SSO AD, aucun mot de passe ni page de consentement |
| **Relais** (Dokore…) | outils qui ajoutent leurs propres fonctions | OAuth via un serveur relais autorisé | comme l'assistant qui passe par le relais |

**Changer la politique d'un client géré** prend effet tout de suite sur les accès déjà accordés : retirer
le statut « géré » ou le consentement implicite, changer le profil imposé ou raccourcir la durée coupe les
accès qui ne la respectent plus (requêtes, rafraîchissement et codes en attente refusés). Les salariés
concernés se reconnectent ; sans consentement implicite, ils voient alors la page de consentement.

Selon l'annuaire de l'entreprise :

- **SSO (AD / Entra) branché sur GLPI** : connexion transparente, aucun mot de passe à taper.
- **Pas de SSO, comptes GLPI locaux ou LDAP** : le salarié tape son identifiant GLPI une seule fois ;
  l'accès dure ensuite le temps choisi pour le client IA (jusqu'à 365 jours). Aucun compte global.
- **Salariés sans compte GLPI** : non pris en charge volontairement. Déposer des tickets au nom d'une
  adresse e-mail non vérifiée permettrait d'usurper n'importe qui (« de la part du directeur ») et de
  faire envoyer des notifications vers n'importe quelle adresse. Ces salariés passent par les canaux
  habituels de GLPI (collecteur de mails, formulaires).

### Assistant de l'entreprise : exemple avec Microsoft Copilot (Copilot Studio)

1. **GLPI** : URL publique en HTTPS (*Configuration > Générale*), SSO actif (SAML, CAS ou OIDC vers l'AD /
   Entra ID), OAuth et enregistrement automatique des clients activés dans *GLPI MCP*.
2. **Copilot Studio** : dans l'agent, *Outils > Ajouter un outil > Model Context Protocol*, URL
   `https://votre-glpi/plugins/glpimcp/mcp`, authentification **OAuth 2.0 – découverte dynamique**. Copilot
   s'enregistre tout seul auprès de GLPI ; faites une première connexion avec votre compte pour tester.
3. **GLPI > Configuration > Plugins > GLPI MCP > Clients IA** : sur le client « Copilot » qui vient
   d'apparaître, *Politique d'entreprise* :
   - **Géré par l'entreprise**, **profil imposé** : `Self-Service` (ou un profil dédié) ;
   - **Sans page de consentement** : après la connexion SSO, l'accès est accordé directement ;
   - durée des accès, **IP autorisées** (plages de sortie de Microsoft si vous voulez restreindre ;
     derrière un reverse proxy, déclarez-le dans *Reverse proxies de confiance*) ;
   - quotas propres au client (par compte, et pour l'ensemble du client).
4. **Publiez l'agent** dans Teams / Microsoft 365 Copilot. Chaque salarié, à sa première question qui
   touche GLPI, clique *Se connecter* : le SSO l'identifie, il obtient un accès **à son nom**, limité au
   profil imposé (ses tickets, le catalogue de services, la base de connaissances). Un compte qui n'a pas
   ce profil est refusé.

Pourquoi pas une clé unique partagée ? Une clé partagée ne sait pas qui parle : pour « mes tickets », il
faudrait croire l'identité envoyée avec la clé, et une clé volée donnerait accès aux tickets de tout le
monde. Avec OAuth + SSO, chaque accès est nominatif, révocable individuellement, et il n'y a aucune clé à
distribuer.

### Garde-fous contre l'abus d'une clé (tous les jetons)

| Garde-fou | Défaut | Effet |
|---|---|---|
| Requêtes par minute et par jeton | 600 | HTTP 429 + `Retry-After` |
| Écritures par heure et par compte (aperçus compris) | 200 | écriture refusée, message clair |
| Tickets créés par heure et par compte (directs ou via formulaire) | 20 | création refusée |
| Écritures par heure pour tout un client IA géré | illimité (réglable par client) | refus + alerte à l'administrateur |
| Refus répétés (N en 10 minutes) | 50 | **jeton suspendu** + e-mail d'alerte + entrée au journal ; réactivation par un administrateur |
| IP autorisées par client IA | aucune restriction | 403 hors des plages déclarées |

Limites effectives : celles du jeton (si définies) > celles du client IA > réglages globaux ; 0 = illimité.
Les e-mails d'alerte partent vers *E-mails d'alerte* (à défaut, l'adresse administrateur de GLPI) via la
file de notifications de GLPI. Un jeton volé reste donc borné : quelques tickets, puis suspension.

## Tests

- **Unitaires** (sans GLPI) : `php tests/unit/run.php`.
- **De bout en bout**, via le protocole MCP comme un vrai client, contre un GLPI **jetable** :

  ```bash
  # GLPI 11.0.12 téléchargé, base dédiée (recréée), plugin, données de test, serveur sur :8080
  DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=root WORK=/tmp/glpimcp-e2e bash tests/e2e/ci_setup.sh
  WORK=/tmp/glpimcp-e2e bash tests/e2e/ci_run.sh          # -k motif pour un sous-ensemble
  ```

  `ci_setup.sh` crée deux entités, un compte à deux profils (Technicien sur E1, Admin plus privilégié)
  et des jetons au périmètre connu (commande de test `tests/e2e/FixturesCommand.php`, copiée seulement
  dans l'instance de test). 29 scénarios, avec vérifications en base (`DB_CLI`) en plus des réponses MCP :
  authentification, filtrage des outils, droits GLPI, anti-escalade, usage unique, **confirmations
  simultanées (un seul effet)**, **confirmation et annulation simultanées**, **aperçu obsolète refusé sans
  effet**, **transaction en cas d'échec en cours d'exécution**, **approbation humaine obligatoire** (sans
  CSRF, par un autre compte, cible modifiée après approbation), **profil fixé au jeton**, **isolation
  entre entités**, **droits retirés entre aperçu et exécution**, **conflit apparu pendant l'aperçu d'une
  annulation**, **débit (429) et suspension automatique**, **quota de tickets**, **client géré (profil imposé,
  sans consentement, IP)**, annulation forcée, interrupteurs, formulaires, langue, logiciels, projets, SecIncident
  (si actif), primitives, OAuth. Tout ce qui est créé est préfixé `TEST-MCP` puis annulé via le journal.
- **Intégration continue** (`.github/workflows/tests.yml`) : syntaxe PHP 8.2 → 8.4, tests unitaires,
  traductions, XML du catalogue, puis le job *Bout en bout* (GLPI 11.0.12 + MariaDB 11.4 jetables).

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
