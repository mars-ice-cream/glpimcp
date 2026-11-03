<?php

/**
 * Jeu de données de test de bout en bout (GLPI de TEST uniquement, jamais en production).
 *
 * N'est PAS livré dans le plugin : la CI le copie dans src/Console/ le temps des tests.
 *   php bin/console plugins:glpimcp:e2e-fixtures --allow-superuser   (exige GLPIMCP_E2E=1)
 *
 * Crée deux entités, un utilisateur à deux profils (Technicien sur E1 ; Admin sur la racine, plus
 * privilégié) et des jetons MCP au périmètre connu ; affiche les jetons en JSON (une seule fois).
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Console;

use Entity;
use Glpi\Console\AbstractCommand;
use GlpiPlugin\Glpimcp\Token;
use Profile_User;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use User;

class FixturesCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:glpimcp:e2e-fixtures');
        $this->setDescription('Données de test de bout en bout de GLPI MCP (instance de test uniquement)');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $DB;

        if (getenv('GLPIMCP_E2E') !== '1') {
            $output->writeln('<error>Refusé : définir GLPIMCP_E2E=1 (instance de test uniquement).</error>');
            return 1;
        }
        $_SESSION['glpiactiveentities'] = [0];
        $_SESSION['glpiactiveentities_string'] = "'0'";
        $_SESSION['glpiactive_entity'] = 0;
        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        // Contexte « tâche automatique » : sans session utilisateur, GLPI filtrerait les champs selon des droits absents
        $_SESSION['glpicronuserrunning'] = 'glpimcp-e2e';

        $entity = function (string $name): int {
            $e = new Entity();
            $found = $e->find(['name' => $name, 'entities_id' => 0]);
            if ($found) {
                return (int) array_key_first($found);
            }
            return (int) $e->add(['name' => $name, 'entities_id' => 0]);
        };
        $e1 = $entity('MCP-E1');
        $e2 = $entity('MCP-E2');

        $profile = function (string $name): int {
            return (int) array_key_first((new \Profile())->find(['name' => $name], [], 1));
        };
        $p_tech  = $profile('Technician');
        $p_admin = $profile('Admin');
        $p_super = $profile('Super-Admin');
        $p_self  = $profile('Self-Service');

        $user = function (string $login, string $password, array $habilitations): int {
            $u = new User();
            if ($u->getFromDBbyName($login)) {
                $id = $u->getID();
            } else {
                $id = (int) $u->add(['name' => $login, 'password' => $password, 'password2' => $password, '_profiles_id' => 0, 'is_active' => 1]);
            }
            $pu = new Profile_User();
            foreach ($pu->find(['users_id' => $id]) as $row) {
                $pu->delete(['id' => $row['id']], true);
            }
            foreach ($habilitations as [$pid, $eid, $rec]) {
                $pu->add(['users_id' => $id, 'profiles_id' => $pid, 'entities_id' => $eid, 'is_recursive' => $rec ? 1 : 0]);
            }
            return $id;
        };
        $dual = $user('mcpdual', 'mcpdual-E2E-1', [[$p_tech, $e1, true], [$p_admin, 0, true]]);
        $glpi = (int) array_key_first((new User())->find(['name' => 'glpi'], [], 1));
        $tech = (int) array_key_first((new User())->find(['name' => 'tech'], [], 1));
        $post = (int) array_key_first((new User())->find(['name' => 'post-only'], [], 1));

        $DB->delete(Token::getTable(), ['name' => ['LIKE', 'E2E %']]);
        $tokens = [
            'TOKEN_ADMIN'        => Token::generate($glpi, 'E2E admin', $p_super),
            'TOKEN_TECH'         => Token::generate($tech, 'E2E tech', $p_tech),
            'TOKEN_SELFSERVICE'  => Token::generate($post, 'E2E self-service', $p_self),
            'TOKEN_DUAL_TECH'    => Token::generate($dual, 'E2E dual technicien', $p_tech),
            'TOKEN_DUAL_ADMIN_E1' => Token::generate($dual, 'E2E dual admin E1', $p_admin, $e1, 0, false),
            // Jeton dédié aux tests de garde-fous (limites basses posées par les tests eux-mêmes)
            'TOKEN_LIMITED'      => Token::generate($tech, 'E2E limité', $p_tech),
        ];
        $output->writeln(json_encode($tokens + [
            'E1' => $e1, 'E2' => $e2, 'DUAL_ID' => $dual, 'P_TECH' => $p_tech, 'P_ADMIN' => $p_admin, 'P_SELF' => $p_self,
        ], JSON_PRETTY_PRINT));
        return 0;
    }
}
