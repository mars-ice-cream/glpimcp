<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tickets;

use CommonITILActor;
use Ticket;
use User;

/**
 * Vérification de langue avant d'envoyer un suivi public ou une solution :
 * on répond dans la langue du demandeur.
 */
final class LanguageCheck
{
    private const LANG_WORDS = [
        'fr_FR' => ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'est', 'pour', 'vous', 'nous', 'une', 'un', 'pas', 'avec', 'sur', 'votre', 'bonjour', 'merci', 'cordialement', 'je', 'il', 'que', 'qui', 'ce'],
        'en_GB' => ['the', 'and', 'is', 'for', 'you', 'we', 'your', 'of', 'to', 'with', 'on', 'not', 'hello', 'thanks', 'regards', 'please', 'it', 'this', 'that', 'have'],
        'it_IT' => ['il', 'lo', 'gli', 'di', 'per', 'e', 'che', 'non', 'con', 'una', 'sono', 'del', 'della', 'buongiorno', 'grazie', 'cordiali', 'saluti', 'questo'],
        'es_ES' => ['el', 'los', 'las', 'de', 'para', 'y', 'que', 'no', 'con', 'una', 'es', 'del', 'hola', 'gracias', 'saludos', 'por', 'favor', 'esto'],
        'de_DE' => ['der', 'die', 'das', 'und', 'ist', 'für', 'sie', 'wir', 'nicht', 'mit', 'auf', 'ein', 'eine', 'hallo', 'danke', 'grüße', 'bitte', 'ich'],
        'pt_PT' => ['o', 'os', 'as', 'de', 'para', 'e', 'que', 'não', 'com', 'uma', 'do', 'da', 'olá', 'obrigado', 'cumprimentos'],
    ];

    /**
     * Vérification de langue avant envoi d'un suivi public ou d'une solution (null = rien à signaler).
     */
    public static function languageWarning(\CommonDBTM $parent, string $text): ?string
    {
        if (!$parent instanceof Ticket) {
            return null;
        }
        $expected = self::requesterLanguage($parent->getUsers(CommonITILActor::REQUESTER)) ?? self::guessLanguage(self::plain($parent->fields['content'] ?? ''));
        $l = self::guessLanguage(self::plain($text));
        if ($l === null || $expected === null || $l === $expected) {
            return null;
        }
        return sprintf("Ce texte est en %s alors que le demandeur utilise GLPI en %s. Avant de confirmer, demande à l'utilisateur s'il veut répondre en %s (tu peux proposer une traduction et refaire l'aperçu).",
            self::langName($l), self::langName($expected), self::langName($expected));
    }
    private static function requesterLanguage(array $requesters): ?string
    {
        global $CFG_GLPI;
        foreach ($requesters as $r) {
            $u = new User();
            if ($u->getFromDB((int) $r['users_id'])) {
                $l = $u->fields['language'] ?: ($CFG_GLPI['language'] ?? null);
                return $l ? self::family($l) : null;
            }
        }
        return null;
    }
    /** Langue probable d'un texte (null si trop court ou indécis) */
    public static function guessLanguage(string $text): ?string
    {
        $words = explode(' ', ' ' . self::norm($text) . ' ');
        if (count($words) < 6) {
            return null;
        }
        $counts = array_count_values($words);
        $scores = [];
        foreach (self::LANG_WORDS as $lang => $list) {
            $scores[$lang] = 0;
            foreach ($list as $w) {
                $scores[$lang] += $counts[self::norm($w)] ?? 0;
            }
        }
        arsort($scores);
        $top = array_key_first($scores);
        $vals = array_values($scores);
        return $vals[0] >= 2 && $vals[0] > ($vals[1] ?? 0) ? $top : null;
    }
    private static function family(string $glpi_lang): string
    {
        $p = strtolower(substr($glpi_lang, 0, 2));
        foreach (array_keys(self::LANG_WORDS) as $l) {
            if (str_starts_with(strtolower($l), $p)) {
                return $l;
            }
        }
        return $glpi_lang;
    }
    private static function langName(string $l): string
    {
        return match (substr($l, 0, 2)) {
            'fr' => 'français', 'en' => 'anglais', 'it' => 'italien', 'es' => 'espagnol', 'de' => 'allemand', 'pt' => 'portugais', default => $l,
        };
    }
    private static function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br', '</p>'], [' <br', ' </p>'], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
    private static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s) ?: $s;
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
    }
}
