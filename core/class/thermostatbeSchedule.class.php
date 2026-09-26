<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * La programmation horaire : à telle heure, tels jours, tel préréglage.
 *
 * Comme sur un thermostat programmable mural, une plage est un changement et
 * non un état : à 6 h 30 on passe en Confort, à 22 h 30 en Éco, et entre les
 * deux ce que l'utilisateur règle à la main tient jusqu'au changement suivant.
 * Un état imposé en continu écraserait chaque minute la consigne qu'on vient
 * de monter d'un degré parce qu'on a froid ce soir.
 *
 * Classe sans Jeedom, comme le moteur : tests/run.php la rejoue.
 */
class thermostatbeSchedule {

    /* Ce qu'une plage peut faire. */
    const PRESETS = array('comfort', 'eco', 'away');

    /* Retard au-delà duquel une plage manquée n'est plus jouée : Jeedom
     * redémarré à 9 h ne doit pas remettre le Confort de 6 h 30 si l'on a
     * déjà baissé à la main entre-temps. */
    const GRACE = 900;

    /* Normalise une heure « 6:30 », « 06h30 » en « 06:30 », ou ''. */
    public static function cleanTime($_value) {
        if (!preg_match('/^\s*(\d{1,2})\s*[:hH]\s*(\d{2})\s*$/', (string) $_value, $match)) {
            return '';
        }
        $hour = (int) $match[1];
        $minute = (int) $match[2];
        if ($hour > 23 || $minute > 59) {
            return '';
        }
        return sprintf('%02d:%02d', $hour, $minute);
    }

    public static function cleanSlots($_slots) {
        $clean = array();
        if (!is_array($_slots)) {
            return $clean;
        }
        foreach ($_slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $time = self::cleanTime(isset($slot['time']) ? $slot['time'] : '');
            if ($time === '') {
                continue;
            }
            $days = array();
            foreach ((isset($slot['days']) && is_array($slot['days'])) ? $slot['days'] : array() as $day) {
                $day = (int) $day;
                if ($day >= 1 && $day <= 7) {
                    $days[$day] = $day;
                }
            }
            ksort($days);
            $preset = isset($slot['preset']) ? $slot['preset'] : '';
            $clean[] = array(
                'enable' => (isset($slot['enable']) && $slot['enable'] == 0) ? 0 : 1,
                'time'   => $time,
                'days'   => array_values($days),
                'preset' => in_array($preset, self::PRESETS, true) ? $preset : 'comfort',
            );
        }
        usort($clean, function ($_a, $_b) {
            return strcmp($_a['time'], $_b['time']);
        });
        return $clean;
    }

    /*
     * La dernière occurrence d'une plage à l'instant $_now ou avant, sur les
     * huit derniers jours, ou null si elle n'a aucun jour coché.
     */
    public static function lastOccurrence($_slot, $_now) {
        if (count($_slot['days']) == 0) {
            return null;
        }
        list($hour, $minute) = array_map('intval', explode(':', $_slot['time']));
        for ($back = 0; $back <= 7; $back++) {
            $day = strtotime('-' . $back . ' day', $_now);
            $at = mktime($hour, $minute, 0, (int) date('n', $day), (int) date('j', $day), (int) date('Y', $day));
            if ($at > $_now) {
                continue;
            }
            if (in_array((int) date('N', $at), $_slot['days'], true)) {
                return $at;
            }
        }
        return null;
    }

    /*
     * La plage à jouer maintenant, ou null.
     *
     * $_last est l'instant de la dernière plage jouée : une plage n'est jouée
     * qu'une fois, et seulement si elle est plus récente que la dernière et
     * pas en retard de plus de GRACE. Rend array('slot' => …, 'at' => …).
     */
    public static function due($_slots, $_last, $_now) {
        $best = null;
        foreach ($_slots as $slot) {
            if ($slot['enable'] != 1) {
                continue;
            }
            $at = self::lastOccurrence($slot, $_now);
            if ($at === null || $at <= (int) $_last || $_now - $at > self::GRACE) {
                continue;
            }
            if ($best === null || $at > $best['at']) {
                $best = array('slot' => $slot, 'at' => $at);
            }
        }
        return $best;
    }

    /* La prochaine plage après $_now, pour l'affichage, ou null. */
    public static function next($_slots, $_now) {
        $best = null;
        foreach ($_slots as $slot) {
            if ($slot['enable'] != 1 || count($slot['days']) == 0) {
                continue;
            }
            list($hour, $minute) = array_map('intval', explode(':', $slot['time']));
            for ($ahead = 0; $ahead <= 7; $ahead++) {
                $day = strtotime('+' . $ahead . ' day', $_now);
                $at = mktime($hour, $minute, 0, (int) date('n', $day), (int) date('j', $day), (int) date('Y', $day));
                if ($at <= $_now || !in_array((int) date('N', $at), $slot['days'], true)) {
                    continue;
                }
                if ($best === null || $at < $best['at']) {
                    $best = array('slot' => $slot, 'at' => $at);
                }
                break;
            }
        }
        return $best;
    }
}
