# Changelog

## 1.5.0 — 2026-11-03
- **Déploiement en entreprise** : un client IA enregistré (ex. l'agent Copilot Studio) peut être passé
  « géré par l'entreprise » : profil imposé (ex. Self-Service), consentement implicite après la connexion
  GLPI / SSO, durée des accès, IP autorisées, quotas propres. Seuls les comptes ayant le profil imposé se
  connectent ; changer de profil imposé coupe les accès accordés avec l'ancien.
- **Garde-fous contre l'abus d'un jeton** (tous les jetons) : requêtes par minute (429 + Retry-After),
  écritures et tickets par heure et par compte, écritures par heure pour un client géré, suspension
  automatique après des refus répétés avec alerte e-mail et journal, réactivation par un administrateur.
  Limites réglables globalement, par client IA et par jeton.
- Reverse proxies de confiance : l'IP réelle du client (X-Forwarded-For) n'est lue que derrière eux.
- Tests : 3 scénarios de bout en bout (débit et suspension, quota de tickets, client géré), tests
  unitaires des plages d'IP.

## 1.4.0 — 2026-11-03 (sécurité)
- **Approbation humaine vérifiée dans GLPI** pour les actions critiques (suppression définitive, comptes,
  groupes et habilitations, droits des profils, configuration, annulation forcée) : page GLPI avec session
  authentifiée du même utilisateur et CSRF ; approbation liée par empreinte à la proposition exacte (lot,
  aperçu, état des objets visés, jeton, profil, entités) ; le journal enregistre qui a approuvé et quand.
  Portée réglable (critiques / toutes les sensibles). Le jeton MCP et le `confirmation_id` ne suffisent plus.
- **Confirmation à usage unique atomique** : réservation par UPDATE conditionnel, statut « exécuté » validé
  dans la transaction des écritures ; confirmations simultanées et confirmation/annulation simultanées
  gérées ; une nouvelle tentative ne rejoue jamais une écriture (`déjà_exécutée`, ou nouvel aperçu exigé).
- **Aperçu revérifié à l'exécution** : droits, interrupteurs, aperçu et état des objets visés recalculés ;
  si les données ont changé, la confirmation est refusée et un nouvel aperçu est demandé.
- **Périmètre du jeton imposé** : le profil accordé est une limite (`X-GLPI-Profile` différent → 403,
  `glpi_switch_context` ne change plus de profil) ; entité accordée et sous-entités respectées
  (`X-GLPI-Entity` hors périmètre → 403) ; proposition liée au jeton et au contexte de son aperçu.
  Choix de l'entité à la création d'un jeton.
- **Annulation** : conflits revérifiés au moment de l'exécution ; annulabilité « totale / partielle / aucune »
  affichée et journalisée ; notifications liées aux créations ITIL signalées comme non rappelables.
- Messages d'échec exacts : la transaction annule les écritures en base, pas les effets hors base.
- Les réglages de GLPI MCP ne sont plus modifiables via l'assistant (`glpi_apply_operations` compris).
- Tests de bout en bout : 8 nouveaux scénarios ; job CI sur un GLPI 11.0.12 + MariaDB jetables.

**Compatibilité / mise à jour** : jetons « profil par défaut » figés sur le profil par défaut actuel du
compte (ou désactivés si indéterminable) ; propositions en attente retirées ; en mode « sensibles », les
écritures ordinaires restent immédiates ; un client qui changeait de profil par en-tête reçoit une 403.

## 1.3.0 — 2026-11-03
- **Intégration SecIncident** (si le plugin est actif) : `glpi_secincident_list`, `glpi_secincident_get`,
  `glpi_secincident_declare`, `glpi_secincident_add_event`, `glpi_secincident_add_iocs`,
  `glpi_secincident_task_update`. Mêmes contrôles que l'interface de SecIncident ; écriture limitée et désactivée
  par défaut (nouvel interrupteur « Incidents de sécurité ») ; suppression, clôture et équipe interdites à l'IA,
  y compris via les outils génériques ; entrée `ai_assistant` dans le journal d'audit chaîné de SecIncident ;
  contenu des incidents renvoyé comme donnée non fiable.
- Les sessions de l'assistant déclenchent le crochet `change_profile` : les plugins qui ajustent les droits du
  profil actif (ex. restriction de SecIncident au groupe sécurité) s'appliquent aussi à l'IA.

## 1.2.0 — 2026-11-02
- **Projets** : `glpi_project_list` (état, avancement, retard, « mes projets »), `glpi_project_get` (synthèse, équipe,
  tâches en arbre, jalons, sous-projets, tickets et éléments liés), `glpi_project_my_tasks` (ma charge, retards),
  `glpi_project_task_add` (une ou plusieurs tâches, sous-tâches, équipe, tickets liés) et `glpi_project_task_update`
  (avancement, état, dates, membres ajoutés/retirés, tickets liés). Écritures avec aperçu, confirmation, journal et annulation.
- Nouvel interrupteur « Projets » dans les types d'actions autorisés (activé par défaut).
- Retirer un lien (membre d'équipe, ticket lié…) n'est plus traité comme une suppression définitive : l'aperçu
  affiche « retirer le lien » et l'annulation recrée le lien.
- Dates refusées au-delà du 31/12/2037 (limite des colonnes de GLPI) avec un message clair.

## 1.1.0 — 2026-10-30
- OAuth : **serveurs relais autorisés** (réglage administrateur, vide par défaut). Un client IA connecté à un relais
  (ex. Dokore) peut obtenir un jeton pour ce relais ; la page de consentement le mentionne ; les droits GLPI
  s'appliquent toujours.

## 1.0.0 — 2026-10-30
Version « socle » : le plugin se concentre sur l'accès sûr à GLPI (droits, aperçu, confirmation, journal,
annulation, OAuth, interrupteurs). Les outils d'analyse et de paramétrage avancés deviennent l'offre
**Dokore pour GLPI**, programme distinct qui s'appuie sur les primitives ci-dessous.
- Nouvelles primitives : `glpi_export` (lecture en masse paginée, droits fins, décomptes), `glpi_system_facts`,
  `glpi_apply_operations` (lot d'opérations avec toutes les garanties habituelles).
- Retirés du plugin (désormais dans Dokore) : premier paramétrage guidé, création/audit/fusion de formulaires et
  gestion des destinations, règles en langage simple, audit et normalisation des logiciels, analyse d'impact et
  faiblesses, contrôle qualité des tickets, et les prompts associés. Le moteur (formulaires, fusion de logiciels,
  opérations) reste dans le plugin et reste accessible via `glpi_apply_operations`.
- Conservé : vérification de langue dans l'aperçu des suivis et solutions.

## 0.9.0 — 2026-10-29
- `glpi_impact_risks` : faiblesses de l'infrastructure — éléments les plus critiques (pondérés), points uniques de défaillance probables, maillons faibles (garantie, contrat, OS en fin de support, sauvegarde, statut, incidents, problèmes), applications aux dépendances inconnues, plan d'actions, schéma avec critiques en rouge, questions de redondance.
- Analyse d'impact : provenance enregistrée sur chaque lien (inventaire, Notion, hyperviseur, utilisateur…) ; éléments retrouvés par UUID / n° de série avant création (pas de doublon avec l'inventaire) ; éléments créés marqués « à confirmer par l'inventaire ».
- Prompts `audit_infrastructure` et `cartographier_dependances` enrichi (recherche dans les autres connecteurs avant de poser des questions).

## 0.8.0 — 2026-10-29
- **Graphe de dépendances** : `glpi_impact_build` remplit l'« Analyse d'impact » native de GLPI à partir des liens connus (ports réseau et câbles, périphériques, hôtes de VM par UUID, PDU/baies, instances de bases, applications), avec schéma Mermaid, aperçu, confirmation et annulation ; détection des points manquants transformés en questions (branchement réseau par lieu, switch en amont, services d'un serveur, composants d'une application), réponses via `add_links` (création possible des éléments manquants).
- `glpi_impact_query` : « qui est touché si… », « de quoi dépend… », avec schéma et lien vers GLPI.
- Prompt `cartographier_dependances` ; test de bout en bout ajouté (15 scénarios).

## 0.7.0 — 2026-10-29
- **OAuth** : connexion d'un utilisateur pas encore authentifié dans GLPI — le plugin redirige lui-même vers la page de connexion en conservant tous les paramètres (PKCE, state, resource), sans passer par l'erreur « session expirée » de GLPI ; aucun correctif du cœur de GLPI n'est nécessaire.
- **Types d'actions autorisés** : interrupteurs par type d'action (assistance, formulaires, éléments, corbeille, suppression définitive, règles, logiciels, utilisateurs et groupes, droits, configuration, annulation). Suppression définitive, utilisateurs/groupes, droits et configuration générale désactivés par défaut. Revérifiés à la confirmation ; chaque changement est inscrit au journal IA. Outils masqués quand leur type d'action est désactivé.
- **`glpi_ticket_check`** : contrôle qualité ITIL (type incident/demande, catégorie absente ou invalide pour l'entité ou le type, demandeur absent, non attribué, délais dépassés) avec correction type + catégorie proposée ; revue des tickets ouverts.
- **Langue** : un suivi public ou une solution dans une autre langue que celle du demandeur est signalé à l'aperçu (aperçu forcé même en mode « sensibles seulement »).
- **Relecture après écriture** : les valeurs réellement enregistrées par GLPI sont renvoyées, avec les écarts éventuels (règle métier, contrôle GLPI).
- **Tests** : suite de bout en bout via MCP (`tests/e2e/run_tests.py`, 14 scénarios, nettoyage automatique par rollback) et tests unitaires (`tests/unit/run.php`) ; intégration continue GitHub Actions.
- Fichiers du catalogue des plugins GLPI : `glpimcp.xml`, logo, licence, changelog.

## 0.6.0 — 2026-10-29
- `glpi_software_audit` et `glpi_software_normalize` : doublons, bruit d'inventaire, catégories, fusion annulable, règles du dictionnaire et de catégorie ; prompt `nettoyer_logiciels`.

## 0.5.1 — 2026-10-29
- Titres de destination : `{{Intitulé de question}}` remplacé par la réponse (balise native GLPI).

## 0.5.0
- Destinations conditionnelles (ticket, changement, problème ; toujours / si / sauf si).

## 0.4.0
- Fusion des formulaires dupliqués par site/langue, traductions, règles de validation par entité.

## 0.3.0
- OAuth 2.1 (découverte, enregistrement dynamique, PKCE, jetons tournants) pour claude.ai, ChatGPT…

## 0.2.0
- Journal des actions IA et annulation (rollback) avec détection des conflits.

## 0.1.0
- Première version : serveur MCP natif GLPI 11, jetons personnels, aperçu → confirmation, droits GLPI.
