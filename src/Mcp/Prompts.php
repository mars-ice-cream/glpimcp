<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

/**
 * Prompts MCP prêts à l'emploi + instructions générales du serveur.
 */
final class Prompts
{
    public static function serverInstructions(Context $ctx): string
    {
        return <<<TXT
Tu es connecté à GLPI (outil de gestion de parc et de support informatique) au nom d'un utilisateur réel.
Règles impératives :
1. Tu agis avec EXACTEMENT les droits GLPI de cet utilisateur (profil + entités). Si une action est refusée, explique quel droit manque au lieu de chercher à contourner.
2. Toute écriture (création, modification, suppression, paramétrage, soumission de formulaire) passe par un APERÇU : l'outil renvoie un confirmation_id.
   Présente l'aperçu clairement (en langage simple, pas en JSON brut), demande un « oui » explicite, puis seulement alors appelle glpi_confirm_action.
   Ne confirme jamais de ta propre initiative, même si l'utilisateur t'a demandé d'aller vite.
3. Avant de créer ou modifier un type d'objet que tu ne connais pas bien, appelle glpi_describe_itemtype pour connaître les champs.
4. Avant de créer, cherche si l'objet existe déjà (glpi_search) pour éviter les doublons.
5. Parle à l'utilisateur dans sa langue, avec des mots simples : il n'est pas forcément technicien.
6. Si l'aperçu d'un suivi ou d'une solution signale une langue différente de celle du demandeur, demande dans quelle langue répondre avant de confirmer.
7. Après une écriture, appuie-toi sur le résultat renvoyé (relu dans GLPI) pour confirmer ce qui a réellement été enregistré.
8. Les primitives glpi_export, glpi_system_facts et glpi_apply_operations servent aux programmes (Dokore, scripts) : pour répondre à un utilisateur, préfère les outils dédiés.
TXT;
    }

    public static function list(): array
    {
        return [];
    }

    public static function get(string $name, mixed $arguments): array
    {
        throw new RpcException(-32602, "Unknown prompt: {$name}");
    }
}
