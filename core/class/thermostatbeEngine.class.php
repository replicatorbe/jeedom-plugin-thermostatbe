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
 * Le cerveau du thermostat, et rien d'autre.
 *
 * Cette classe ne connaît pas Jeedom : ni commande, ni cache, ni base. Elle
 * reçoit des réglages, des mesures et l'état laissé par le passage précédent,
 * et rend une décision, le nouvel état et la raison de la décision, en toutes
 * lettres. C'est ce qui permet de rejouer hors ligne, dans tests/run.php, les
 * situations qui coûtent cher quand elles se trompent : la clim qui refroidit
 * en janvier la chaleur que la chaudière vient de produire, une chaudière
 * allumée et éteinte toutes les deux minutes, la clim qui passe de chaud à
 * froid sans s'arrêter.
 *
 * Trois questions, dans cet ordre, et c'est tout le plugin :
 *
 *   1. La saison : a-t-on le droit de chauffer, ou de refroidir ? Elle se lit
 *      dehors, sur une moyenne lissée d'environ un jour, jamais dans la
 *      maison : 25 °C au salon un après-midi de janvier ne sont pas l'été,
 *      c'est le soleil dans la baie vitrée.
 *   2. Le besoin : faut-il chauffer ou refroidir maintenant ? Une consigne
 *      par côté, un écart minimum entre les deux, et une hystérésis autour de
 *      chacune.
 *   3. L'appareil : chaudière ou clim pour chauffer, choisi par l'utilisateur
 *      ou, en automatique, au coût du kWh de chaleur.
 *
 * Puis les garde-fous, qui peuvent retarder une décision mais jamais en
 * inventer une : durée minimale de marche et d'arrêt de chaque appareil,
 * arrêt de la clim avant de lui faire changer de mode, verrou de plusieurs
 * heures entre chauffer et refroidir.
 */
class thermostatbeEngine {

    /* Les modes du thermostat, tels que l'utilisateur les choisit. */
    const MODE_OFF   = 'off';
    const MODE_FROST = 'frost';
    const MODE_AUTO  = 'auto';
    const MODE_HEAT  = 'heat';
    const MODE_COOL  = 'cool';
    const MODES = array('off', 'frost', 'auto', 'heat', 'cool');

    /* L'appareil qui chauffe : imposé, ou choisi par le plugin. */
    const SOURCE_AUTO   = 'auto';
    const SOURCE_BOILER = 'boiler';
    const SOURCE_AC     = 'ac';
    const SOURCES = array('auto', 'boiler', 'ac');

    /*
     * Ce que le thermostat fait, à un instant donné. Un seul état à la fois :
     * chaudière et clim ne peuvent pas tourner ensemble, et la clim ne peut
     * pas être à la fois en chaud et en froid, par construction et non par
     * vérification.
     */
    const IDLE        = 'idle';
    const HEAT_BOILER = 'heat_boiler';
    const HEAT_AC     = 'heat_ac';
    const COOL_AC     = 'cool_ac';

    const SEASON_HEAT = 'heat';
    const SEASON_COOL = 'cool';

    /*
     * La constante de temps de la moyenne extérieure, en secondes.
     *
     * Une moyenne exponentielle et non une moyenne sur l'historique : elle ne
     * demande ni que la sonde soit historisée, ni de relire des centaines de
     * valeurs chaque minute. Avec douze heures, un après-midi à 22 °C au
     * milieu d'une semaine à 8 °C ne suffit pas à faire basculer la saison,
     * et une vraie vague de chaleur la fait basculer en un à deux jours.
     */
    const OUTDOOR_TAU = 43200;

    /* Les réglages par défaut, et leurs bornes. Tout ce qui arrive d'un
     * formulaire ou d'un scénario passe par cleanSettings(). */
    const DEFAULTS = array(
        'mode'                => 'auto',
        'source'              => 'auto',
        'heat_setpoint'       => 20.0,
        'cool_setpoint'       => 25.0,
        'frost_setpoint'      => 7.0,
        'min_gap'             => 2.0,
        'hysteresis'          => 0.3,
        'ac_hysteresis'       => 0.5,
        'season_heat_below'   => 15.0,
        'season_cool_above'   => 20.0,
        'changeover_lock'     => 12,
        'boiler_min_on'       => 5,
        'boiler_min_off'      => 5,
        'ac_min_on'           => 15,
        'ac_min_off'          => 10,
        'ac_mode_delay'       => 10,
        'ac_heat_offset'      => 1.0,
        'ac_cool_offset'      => -1.0,
        'ac_setpoint_min'     => 16.0,
        'ac_setpoint_max'     => 30.0,
        'ac_heat_min_outdoor' => -5.0,
        'auto_ac_above'       => 5.0,
        'boiler_efficiency'   => 90,
        'cop_at_7'            => 3.5,
        'cop_at_minus7'       => 2.0,
        'cost_margin'         => 10,
        'cool_min_outdoor'    => null,
        'window_delay'        => 60,
    );

    /* [minimum, maximum] de chaque réglage numérique. */
    const BOUNDS = array(
        'heat_setpoint'       => array(5, 30),
        'cool_setpoint'       => array(15, 35),
        'frost_setpoint'      => array(4, 12),
        'min_gap'             => array(0.5, 10),
        'hysteresis'          => array(0.1, 2),
        'ac_hysteresis'       => array(0.1, 3),
        'season_heat_below'   => array(-10, 30),
        'season_cool_above'   => array(0, 40),
        'changeover_lock'     => array(0, 168),
        'boiler_min_on'       => array(0, 60),
        'boiler_min_off'      => array(0, 60),
        'ac_min_on'           => array(0, 60),
        'ac_min_off'          => array(0, 60),
        'ac_mode_delay'       => array(0, 60),
        'ac_heat_offset'      => array(-5, 5),
        'ac_cool_offset'      => array(-5, 5),
        'ac_setpoint_min'     => array(5, 25),
        'ac_setpoint_max'     => array(20, 35),
        'ac_heat_min_outdoor' => array(-30, 20),
        'auto_ac_above'       => array(-20, 25),
        'boiler_efficiency'   => array(50, 110),
        'cop_at_7'            => array(1, 8),
        'cop_at_minus7'       => array(1, 8),
        'cost_margin'         => array(0, 50),
        'cool_min_outdoor'    => array(-10, 40),
        'window_delay'        => array(0, 3600),
    );

    /* ============================================================ RÉGLAGES */

    /*
     * Relit un nombre saisi à la main. « 20,5 » vaut 20.5 : un navigateur en
     * français tape la virgule, et (float) '20,5' donnerait 20 sans rien dire.
     * Rend null pour un champ vide ou illisible.
     */
    public static function number($_value) {
        if ($_value === null || is_bool($_value) || is_array($_value)) {
            return null;
        }
        if (is_int($_value) || is_float($_value)) {
            return (float) $_value;
        }
        /* Le signe moins typographique (−) aussi : c'est celui des exemples
         * de la page, et un copier-coller ne doit pas donner un champ vide. */
        $text = str_replace(array(',', ' ', "\xc2\xa0", "\xe2\x88\x92"), array('.', '', '', '-'), trim((string) $_value));
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        return (float) $text;
    }

    /*
     * Impose leur forme aux réglages : défaut pour ce qui manque, bornes pour
     * ce qui dépasse, et l'écart entre les deux consignes.
     */
    public static function cleanSettings($_settings) {
        $settings = is_array($_settings) ? $_settings : array();
        $clean = array();
        foreach (self::DEFAULTS as $key => $default) {
            $value = isset($settings[$key]) ? $settings[$key] : null;
            if ($key == 'mode') {
                $clean[$key] = in_array($value, self::MODES, true) ? $value : $default;
                continue;
            }
            if ($key == 'source') {
                $clean[$key] = in_array($value, self::SOURCES, true) ? $value : $default;
                continue;
            }
            $number = self::number($value);
            if ($number === null) {
                $clean[$key] = $default;
                continue;
            }
            if (isset(self::BOUNDS[$key])) {
                $number = max(self::BOUNDS[$key][0], min(self::BOUNDS[$key][1], $number));
            }
            $clean[$key] = $number;
        }

        /* Les seuils de saison se croisent : « chauffe sous 18 °C, froid
         * au-dessus de 16 °C » laisserait les deux vrais à la fois entre 16 et
         * 18. On garde au moins un degré entre eux. */
        if ($clean['season_cool_above'] < $clean['season_heat_below'] + 1) {
            $clean['season_cool_above'] = $clean['season_heat_below'] + 1;
        }
        if ($clean['ac_setpoint_max'] < $clean['ac_setpoint_min']) {
            $clean['ac_setpoint_max'] = $clean['ac_setpoint_min'];
        }
        $pair = self::fitSetpoints($clean['heat_setpoint'], $clean['cool_setpoint'], $clean['min_gap']);
        $clean['heat_setpoint'] = $pair[0];
        $clean['cool_setpoint'] = $pair[1];

        /* Les prix ne sont pas des réglages bornés : ils viennent du
         * formulaire ou d'une commande de tarif, et le plugin Jeedom les
         * résout avant d'appeler le moteur. */
        foreach (array('gas_price', 'elec_price') as $key) {
            $price = isset($settings[$key]) ? self::number($settings[$key]) : null;
            $clean[$key] = ($price !== null && $price > 0) ? $price : null;
        }
        foreach (array('has_boiler', 'has_ac_heat', 'has_ac_cool') as $key) {
            $clean[$key] = !empty($settings[$key]);
        }
        return $clean;
    }

    /*
     * Garde l'écart minimum entre la consigne de chauffe et celle de froid.
     *
     * C'est la zone neutre : entre 20 et 25 °C, personne ne fait rien. Sans
     * elle, une consigne unique ferait chauffer à 23,9 °C et refroidir à
     * 24,1 °C. $_moved dit laquelle l'utilisateur vient de toucher : c'est
     * l'autre qu'on pousse, jamais celle qu'il a choisie.
     */
    public static function fitSetpoints($_heat, $_cool, $_gap, $_moved = 'heat') {
        $heat = (float) $_heat;
        $cool = (float) $_cool;
        if ($cool - $heat < $_gap) {
            if ($_moved == 'cool') {
                $heat = $cool - $_gap;
            } else {
                $cool = $heat + $_gap;
            }
        }
        return array(round($heat, 2), round($cool, 2));
    }

    /* ============================================================== ÉTAT */

    /* L'état entre deux passages. Tout ce qui manque vaut « jamais ». */
    public static function cleanState($_state) {
        $state = is_array($_state) ? $_state : array();
        $clean = array(
            'target'         => self::IDLE,
            'since'          => null,
            'boiler_on_at'   => null,
            'boiler_off_at'  => null,
            'ac_on_at'       => null,
            'ac_off_at'      => null,
            'ac_mode'        => null,
            'last_heat_at'   => null,
            'last_cool_at'   => null,
            'season'         => null,
            'outdoor_avg'    => null,
            'outdoor_avg_at' => null,
            'heat_source'    => null,
        );
        foreach ($clean as $key => $default) {
            if (!isset($state[$key]) || $state[$key] === '') {
                continue;
            }
            $clean[$key] = $state[$key];
        }
        if (!in_array($clean['target'], array(self::IDLE, self::HEAT_BOILER, self::HEAT_AC, self::COOL_AC), true)) {
            $clean['target'] = self::IDLE;
        }
        return $clean;
    }

    /* L'appareil qu'un état fait tourner : 'boiler', 'ac' ou null. */
    public static function device($_target) {
        switch ($_target) {
            case self::HEAT_BOILER: return 'boiler';
            case self::HEAT_AC:     return 'ac';
            case self::COOL_AC:     return 'ac';
        }
        return null;
    }

    /* Le mode demandé à la clim : 'heat', 'cool' ou null. */
    public static function acMode($_target) {
        switch ($_target) {
            case self::HEAT_AC: return 'heat';
            case self::COOL_AC: return 'cool';
        }
        return null;
    }

    public static function isHeating($_target) {
        return ($_target == self::HEAT_BOILER || $_target == self::HEAT_AC);
    }

    /* ================================================= MOYENNE EXTÉRIEURE */

    /*
     * Fait avancer la moyenne extérieure lissée.
     *
     * $_seed sert au tout premier passage : la moyenne des dernières 24 h,
     * lue dans l'historique par le plugin s'il en a un. Sans elle, la moyenne
     * partirait de la mesure de l'instant, et un plugin installé un après-midi
     * doux d'octobre démarrerait en saison de froid.
     */
    public static function updateOutdoorAverage($_state, $_outdoor, $_now, $_seed = null) {
        $state = $_state;
        if ($_outdoor === null) {
            return $state;
        }
        if ($state['outdoor_avg'] === null) {
            $state['outdoor_avg'] = ($_seed !== null) ? (float) $_seed : (float) $_outdoor;
            $state['outdoor_avg_at'] = $_now;
            return $state;
        }
        $elapsed = max(0, $_now - (int) $state['outdoor_avg_at']);
        $alpha = min(1.0, $elapsed / self::OUTDOOR_TAU);
        $state['outdoor_avg'] = round($state['outdoor_avg'] + $alpha * ($_outdoor - $state['outdoor_avg']), 3);
        $state['outdoor_avg_at'] = $_now;
        return $state;
    }

    /*
     * La saison, d'après la moyenne extérieure.
     *
     * Entre les deux seuils, on garde la saison en cours : c'est ce qui évite
     * de changer d'avis chaque jour de mi-saison. Sans aucune mesure
     * extérieure, on reste en chauffe : une maison qu'on oublie de refroidir
     * est inconfortable, une maison qu'on oublie de chauffer gèle.
     */
    public static function season($_settings, $_average, $_previous) {
        if ($_average === null) {
            return ($_previous === self::SEASON_COOL) ? self::SEASON_COOL : self::SEASON_HEAT;
        }
        if ($_average <= $_settings['season_heat_below']) {
            return self::SEASON_HEAT;
        }
        if ($_average >= $_settings['season_cool_above']) {
            return self::SEASON_COOL;
        }
        if ($_previous === self::SEASON_HEAT || $_previous === self::SEASON_COOL) {
            return $_previous;
        }
        $middle = ($_settings['season_heat_below'] + $_settings['season_cool_above']) / 2;
        return ($_average < $middle) ? self::SEASON_HEAT : self::SEASON_COOL;
    }

    /* ======================================================= COÛT DU KWH */

    /*
     * Le rendement de la clim en chauffage selon la température extérieure.
     *
     * Une droite entre deux points que la fiche technique donne presque
     * toujours, à 7 °C et à −7 °C. C'est une approximation, mais la bonne :
     * c'est précisément la chute du rendement par grand froid qui décide
     * entre la clim et la chaudière.
     */
    public static function cop($_settings, $_outdoor) {
        $high = $_settings['cop_at_7'];
        $low = $_settings['cop_at_minus7'];
        $cop = $low + ($high - $low) * (($_outdoor + 7) / 14);
        return round(max(1.0, min(max($high, $low) * 1.3, $cop)), 2);
    }

    /*
     * Le prix d'un kWh de chaleur produit par chaque appareil, ou null si un
     * prix manque. Le gaz est compté sur le rendement de la chaudière, la clim
     * sur son COP à la température extérieure du moment.
     */
    public static function heatCosts($_settings, $_outdoor) {
        if ($_settings['gas_price'] === null || $_settings['elec_price'] === null || $_outdoor === null) {
            return null;
        }
        $cop = self::cop($_settings, $_outdoor);
        return array(
            'boiler' => round($_settings['gas_price'] / ($_settings['boiler_efficiency'] / 100), 4),
            'ac'     => round($_settings['elec_price'] / $cop, 4),
            'cop'    => $cop,
        );
    }

    /*
     * L'appareil qui chauffera, et pourquoi.
     *
     * En automatique, on part toujours de l'appareil retenu la dernière fois
     * et on n'en change que si l'autre est nettement meilleur : sans cette
     * marge, deux coûts voisins feraient alterner chaudière et clim d'une
     * minute à l'autre au gré de la sonde extérieure.
     */
    public static function chooseSource($_settings, $_outdoor, $_previous) {
        $hasBoiler = $_settings['has_boiler'];
        $hasAc = $_settings['has_ac_heat'];

        if ($_settings['source'] == self::SOURCE_BOILER) {
            return $hasBoiler
                ? array('source' => 'boiler', 'why' => 'chaudière imposée')
                : array('source' => null, 'why' => 'chaudière imposée, mais aucune chaudière configurée');
        }
        if ($_settings['source'] == self::SOURCE_AC) {
            return $hasAc
                ? array('source' => 'ac', 'why' => 'clim imposée')
                : array('source' => null, 'why' => 'clim imposée, mais aucune clim configurée pour chauffer');
        }

        if (!$hasBoiler && !$hasAc) {
            return array('source' => null, 'why' => 'aucun appareil de chauffage configuré');
        }
        if (!$hasAc) {
            return array('source' => 'boiler', 'why' => 'seule la chaudière est configurée');
        }
        if (!$hasBoiler) {
            return array('source' => 'ac', 'why' => 'seule la clim est configurée');
        }
        if ($_outdoor === null) {
            return array('source' => 'boiler', 'why' => 'température extérieure inconnue, la chaudière est le choix sûr');
        }
        if ($_outdoor < $_settings['ac_heat_min_outdoor']) {
            return array('source' => 'boiler', 'why' => 'trop froid dehors pour la clim ('
                . self::formatTemperature($_outdoor) . ' < ' . self::formatTemperature($_settings['ac_heat_min_outdoor']) . ')');
        }

        $previous = ($_previous === 'boiler' || $_previous === 'ac') ? $_previous : null;
        $costs = self::heatCosts($_settings, $_outdoor);
        if ($costs !== null) {
            $margin = 1 + $_settings['cost_margin'] / 100;
            $cheaper = ($costs['ac'] < $costs['boiler']) ? 'ac' : 'boiler';
            $other = ($cheaper == 'ac') ? 'boiler' : 'ac';
            if ($previous !== null && $previous != $cheaper && $costs[$previous] <= $costs[$cheaper] * $margin) {
                $cheaper = $previous;
                $other = ($cheaper == 'ac') ? 'boiler' : 'ac';
            }
            return array('source' => $cheaper, 'why' => sprintf(
                'kWh de chaleur : chaudière %s, clim %s (COP %s)',
                self::formatPrice($costs['boiler']), self::formatPrice($costs['ac']),
                str_replace('.', ',', (string) $costs['cop'])));
        }

        /* Sans prix, une règle de température : la clim au-dessus du seuil,
         * avec un degré d'hystérésis autour de l'appareil en cours. */
        $threshold = $_settings['auto_ac_above'];
        if ($previous == 'ac') {
            $threshold -= 1;
        } elseif ($previous == 'boiler') {
            $threshold += 1;
        }
        return ($_outdoor >= $threshold)
            ? array('source' => 'ac', 'why' => 'dehors ' . self::formatTemperature($_outdoor) . ', la clim chauffe au meilleur rendement')
            : array('source' => 'boiler', 'why' => 'dehors ' . self::formatTemperature($_outdoor) . ', la chaudière est plus efficace');
    }

    /* ============================================================ DÉCISION */

    /*
     * Un passage du thermostat.
     *
     * $_inputs :
     *   now              horodatage
     *   indoor           moyenne des sondes intérieures valides, ou null
     *   outdoor          température extérieure, ou null
     *   outdoor_seed     moyenne des dernières 24 h, pour le premier passage
     *   window_open_for  secondes depuis l'ouverture d'une fenêtre, null si
     *                    tout est fermé
     *
     * Rend un tableau : target (l'état à appliquer), wanted (ce que le besoin
     * demandait avant les garde-fous), setpoint, ac_setpoint, season, status,
     * reason, source_why, costs, state (à conserver pour le passage suivant).
     */
    public static function decide($_settings, $_inputs, $_state) {
        $settings = self::cleanSettings($_settings);
        $state = self::cleanState($_state);
        $now = isset($_inputs['now']) ? (int) $_inputs['now'] : time();
        $indoor = isset($_inputs['indoor']) ? self::number($_inputs['indoor']) : null;
        $outdoor = isset($_inputs['outdoor']) ? self::number($_inputs['outdoor']) : null;
        $seed = isset($_inputs['outdoor_seed']) ? self::number($_inputs['outdoor_seed']) : null;
        $windowFor = isset($_inputs['window_open_for']) ? $_inputs['window_open_for'] : null;

        $state = self::updateOutdoorAverage($state, $outdoor, $now, $seed);
        $season = self::season($settings, $state['outdoor_avg'], $state['season']);
        $state['season'] = $season;

        $current = $state['target'];
        $result = array(
            'target'      => self::IDLE,
            'wanted'      => self::IDLE,
            'setpoint'    => null,
            'ac_setpoint' => null,
            'season'      => $season,
            'status'      => 'idle',
            'reason'      => '',
            'source_why'  => '',
            'costs'       => self::heatCosts($settings, $outdoor),
            'outdoor_avg' => $state['outdoor_avg'],
        );

        $need = self::need($settings, $indoor, $outdoor, $windowFor, $season, $state, $now);
        $result['status'] = $need['status'];
        $result['reason'] = $need['reason'];
        $result['setpoint'] = $need['setpoint'];
        $wanted = self::IDLE;

        if ($need['demand'] == 'heat') {
            $choice = self::chooseSource($settings, $outdoor, $state['heat_source']);
            $result['source_why'] = $choice['why'];
            if ($choice['source'] === null) {
                $result['status'] = 'blocked';
                $result['reason'] = 'Chauffage demandé, mais ' . $choice['why'];
            } else {
                $wanted = ($choice['source'] == 'ac') ? self::HEAT_AC : self::HEAT_BOILER;
                $result['reason'] = ($choice['source'] == 'ac' ? 'Chauffe par la clim' : 'Chauffe par la chaudière')
                    . ' : ' . self::formatTemperature($indoor) . ' pour ' . self::formatTemperature($need['setpoint'])
                    . ' (' . $choice['why'] . ')';
                if ($need['frost']) {
                    $result['reason'] = 'Hors-gel. ' . $result['reason'];
                }
            }
        } elseif ($need['demand'] == 'cool') {
            $wanted = self::COOL_AC;
            $result['reason'] = 'Refroidissement par la clim : ' . self::formatTemperature($indoor)
                . ' pour ' . self::formatTemperature($need['setpoint']);
        }
        $result['wanted'] = $wanted;

        /* Les garde-fous : ils peuvent retarder, jamais inventer. */
        $final = $wanted;
        if ($wanted != $current) {
            $guard = self::guard($settings, $state, $current, $wanted, $need['hard'], $now);
            if ($guard !== null) {
                $final = $guard['target'];
                $result['status'] = 'waiting';
                $result['reason'] = $guard['reason'];
            }
        }

        $state = self::transition($state, $current, $final, $now);
        if (self::isHeating($final)) {
            $state['heat_source'] = self::device($final);
        }

        $result['target'] = $final;
        if (self::isHeating($final)) {
            $result['status'] = 'heating';
        } elseif ($final == self::COOL_AC) {
            $result['status'] = 'cooling';
        }
        if (self::device($final) == 'ac') {
            $sp = ($need['setpoint'] !== null) ? $need['setpoint'] : $settings['heat_setpoint'];
            $offset = ($final == self::HEAT_AC) ? $settings['ac_heat_offset'] : $settings['ac_cool_offset'];
            $result['ac_setpoint'] = self::acSetpoint($settings, $sp + $offset);
        }
        $result['state'] = $state;
        return $result;
    }

    /*
     * La consigne envoyée à la clim, arrondie au demi-degré — la plupart des
     * clims n'en acceptent pas plus fin — et ramenée dans sa plage.
     */
    public static function acSetpoint($_settings, $_value) {
        $value = round($_value * 2) / 2;
        return max($_settings['ac_setpoint_min'], min($_settings['ac_setpoint_max'], $value));
    }

    /*
     * Le besoin thermique, avant tout choix d'appareil.
     *
     * « hard » dit que l'arrêt doit être immédiat, sans attendre la durée
     * minimale de marche : thermostat coupé, fenêtre ouverte, sonde perdue.
     * Un arrêt parce que la consigne est atteinte, lui, peut attendre
     * quelques minutes.
     */
    private static function need($_settings, $_indoor, $_outdoor, $_windowFor, $_season, $_state, $_now) {
        $need = array('demand' => 'none', 'setpoint' => null, 'hard' => false, 'frost' => false,
                      'status' => 'idle', 'reason' => '');
        $mode = $_settings['mode'];
        $current = $_state['target'];

        if ($mode == self::MODE_OFF) {
            $need['hard'] = true;
            $need['status'] = 'off';
            $need['reason'] = 'Thermostat à l\'arrêt';
            return $need;
        }
        if ($_indoor === null) {
            $need['hard'] = true;
            $need['status'] = 'safety';
            $need['reason'] = 'Aucune sonde intérieure valide : tout est coupé par sécurité';
            return $need;
        }
        if ($_windowFor !== null && $_windowFor >= $_settings['window_delay']) {
            $need['hard'] = true;
            $need['status'] = 'window';
            $need['reason'] = 'Fenêtre ouverte : pause';
            return $need;
        }

        if ($mode == self::MODE_HEAT || $mode == self::MODE_FROST) {
            $side = self::SEASON_HEAT;
        } elseif ($mode == self::MODE_COOL) {
            $side = self::SEASON_COOL;
        } else {
            $side = $_season;
        }

        /*
         * Le hors-gel, là où l'on ne chauffe pas d'ordinaire : en mode
         * hors-gel, et du côté froid. Il passe avant la saison et avant le
         * verrou : une maison à 6 °C se chauffe, même un 15 août, même si la
         * clim refroidissait il y a une heure. Du côté chauffe, la consigne
         * normale est de toute façon au-dessus.
         */
        if ($mode == self::MODE_FROST || $side == self::SEASON_COOL) {
            $frost = $_settings['frost_setpoint'];
            $h = $_settings['hysteresis'];
            $heating = self::isHeating($current);
            if ($heating ? ($_indoor < $frost + $h) : ($_indoor <= $frost - $h)) {
                $need['frost'] = true;
                $need['setpoint'] = $frost;
                $need['demand'] = 'heat';
                $need['status'] = 'heating';
                return $need;
            }
            if ($mode == self::MODE_FROST) {
                $need['setpoint'] = $frost;
                $need['reason'] = 'Hors-gel : ' . self::formatTemperature($_indoor) . ', rien à faire au-dessus de '
                    . self::formatTemperature($frost);
                return $need;
            }
        }

        if ($side == self::SEASON_HEAT) {
            $sp = $_settings['heat_setpoint'];
            $need['setpoint'] = $sp;
            $hh = self::hysteresisFor($_settings, $current, 'heat');
            $running = self::isHeating($current);
            $demand = $running ? ($_indoor < $sp + $hh) : ($_indoor <= $sp - $hh);
            if (!$demand) {
                $need['reason'] = self::formatTemperature($_indoor) . ' pour ' . self::formatTemperature($sp) . ' : pas besoin de chauffer';
                if ($_indoor >= $_settings['cool_setpoint'] && $mode == self::MODE_AUTO) {
                    $need['reason'] .= ', et pas de froid en saison de chauffe';
                }
                return $need;
            }
            if ($mode == self::MODE_AUTO && !$running) {
                $blocked = self::lockRemaining($_settings, $_state['last_cool_at'], $_now);
                if ($blocked > 0) {
                    $need['status'] = 'blocked';
                    $need['reason'] = 'Chauffage bloqué : la clim refroidissait encore il y a peu, verrou encore '
                        . self::formatDuration($blocked);
                    return $need;
                }
            }
            $need['demand'] = 'heat';
            $need['status'] = 'heating';
            return $need;
        }

        $sp = $_settings['cool_setpoint'];
        $need['setpoint'] = $sp;
        $hc = self::hysteresisFor($_settings, $current, 'cool');
        $running = ($current == self::COOL_AC);
        $demand = $running ? ($_indoor > $sp - $hc) : ($_indoor >= $sp + $hc);
        if (!$demand) {
            $need['reason'] = self::formatTemperature($_indoor) . ' pour ' . self::formatTemperature($sp) . ' : pas besoin de refroidir';
            if ($_indoor <= $_settings['heat_setpoint'] && $mode == self::MODE_AUTO) {
                $need['reason'] .= ', et pas de chauffe en saison de froid';
            }
            return $need;
        }
        if (!$_settings['has_ac_cool']) {
            $need['status'] = 'blocked';
            $need['reason'] = 'Refroidissement demandé, mais aucune clim configurée pour refroidir';
            return $need;
        }
        if ($mode == self::MODE_AUTO && !$running) {
            $blocked = self::lockRemaining($_settings, $_state['last_heat_at'], $_now);
            if ($blocked > 0) {
                $need['status'] = 'blocked';
                $need['reason'] = 'Froid bloqué : on chauffait encore il y a peu, verrou encore '
                    . self::formatDuration($blocked);
                return $need;
            }
        }
        /* Dehors plus frais que la consigne de froid : ouvrir une fenêtre
         * rafraîchit gratuitement. Le seuil est facultatif. */
        if (!$running && $_settings['cool_min_outdoor'] !== null && $_outdoor !== null
            && $_outdoor < $_settings['cool_min_outdoor']) {
            $need['status'] = 'blocked';
            $need['reason'] = 'Froid non lancé : il fait ' . self::formatTemperature($_outdoor)
                . ' dehors, ouvrir les fenêtres suffit';
            return $need;
        }
        $need['demand'] = 'cool';
        $need['status'] = 'cooling';
        return $need;
    }

    /* La clim a son hystérésis à elle, plus large : un compresseur inverter
     * ne gagne rien à être relancé pour un demi-degré. */
    private static function hysteresisFor($_settings, $_current, $_side) {
        if ($_side == 'cool') {
            return $_settings['ac_hysteresis'];
        }
        if ($_current == self::HEAT_AC) {
            return $_settings['ac_hysteresis'];
        }
        if ($_current == self::IDLE && $_settings['source'] == self::SOURCE_AC) {
            return $_settings['ac_hysteresis'];
        }
        return $_settings['hysteresis'];
    }

    /* Secondes restantes du verrou entre chauffer et refroidir, 0 s'il est
     * levé. */
    private static function lockRemaining($_settings, $_lastOpposite, $_now) {
        if ($_lastOpposite === null || $_settings['changeover_lock'] <= 0) {
            return 0;
        }
        $end = (int) $_lastOpposite + (int) round($_settings['changeover_lock'] * 3600);
        return max(0, $end - $_now);
    }

    /*
     * Les garde-fous d'une transition. Rend null si elle peut se faire, sinon
     * l'état à tenir en attendant et la raison.
     */
    private static function guard($_settings, $_state, $_current, $_wanted, $_hard, $_now) {
        $from = self::device($_current);
        $to = self::device($_wanted);
        $changesDevice = ($from !== null && ($from !== $to || self::acMode($_current) !== self::acMode($_wanted)));

        /* 1. Arrêter ce qui tourne, pas avant sa durée minimale de marche. */
        if ($changesDevice && !$_hard) {
            $minOn = self::minutes($_settings[$from . '_min_on']);
            $onAt = $_state[$from . '_on_at'];
            if ($onAt !== null && $_now - $onAt < $minOn) {
                return array('target' => $_current, 'reason' => self::deviceName($from)
                    . ' maintenue : durée minimale de marche, encore ' . self::formatDuration($minOn - ($_now - $onAt)));
            }
        }
        if ($to === null) {
            return null;
        }

        /* 2. Démarrer, pas avant la durée minimale d'arrêt. L'appareil qu'on
         *    arrête à l'instant a « été arrêté maintenant ». */
        $offAt = ($from === $to) ? $_now : $_state[$to . '_off_at'];
        $minOff = self::minutes($_settings[$to . '_min_off']);
        $modeSwitch = ($to == 'ac' && $_state['ac_mode'] !== null && self::acMode($_wanted) !== $_state['ac_mode']);
        if ($modeSwitch) {
            $minOff = max($minOff, self::minutes($_settings['ac_mode_delay']));
        }
        if ($offAt !== null && $_now - $offAt < $minOff) {
            /* Passer de la chaudière à la clim : la chaudière continue en
             * attendant que la clim puisse démarrer, plutôt que de laisser la
             * maison sans chauffage. Passer de chaud à froid, au contraire,
             * commence par tout arrêter. */
            $hold = (self::isHeating($_current) && self::isHeating($_wanted)) ? $_current : self::IDLE;
            $what = $modeSwitch ? ' en attente avant de changer de mode, encore '
                                : ' en attente : durée minimale d\'arrêt, encore ';
            return array('target' => $hold, 'reason' => self::deviceName($to) . $what
                . self::formatDuration($minOff - ($_now - $offAt)));
        }
        return null;
    }

    /* Note dans l'état ce qui démarre et ce qui s'arrête. */
    private static function transition($_state, $_current, $_final, $_now) {
        $state = $_state;
        if ($_final != $_current) {
            $from = self::device($_current);
            $to = self::device($_final);
            $modeChange = (self::acMode($_current) !== self::acMode($_final));
            if ($from !== null && ($from !== $to || $modeChange)) {
                $state[$from . '_off_at'] = $_now;
            }
            if ($to !== null && ($from !== $to || $modeChange)) {
                $state[$to . '_on_at'] = $_now;
            }
            if ($to == 'ac') {
                $state['ac_mode'] = self::acMode($_final);
            }
            $state['target'] = $_final;
            $state['since'] = $_now;
        }
        if (self::isHeating($_final)) {
            $state['last_heat_at'] = $_now;
        } elseif ($_final == self::COOL_AC) {
            $state['last_cool_at'] = $_now;
        }
        return $state;
    }

    /* ============================================================ TEXTES */

    private static function minutes($_value) {
        return (int) round($_value * 60);
    }

    public static function deviceName($_device) {
        return ($_device == 'ac') ? 'Clim' : 'Chaudière';
    }

    public static function formatTemperature($_value) {
        if ($_value === null) {
            return '—';
        }
        return str_replace('.', ',', (string) round($_value, 1)) . ' °C';
    }

    public static function formatPrice($_value) {
        return str_replace('.', ',', sprintf('%.3f', $_value)) . ' €';
    }

    public static function formatDuration($_seconds) {
        $seconds = max(0, (int) $_seconds);
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        $minutes = (int) ceil($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        return $hours . ' h' . ($rest > 0 ? ' ' . sprintf('%02d', $rest) : '');
    }

    /* Les libellés des états, pour l'affichage et les commandes. */
    public static function targetLabel($_target) {
        switch ($_target) {
            case self::HEAT_BOILER: return 'Chauffe (chaudière)';
            case self::HEAT_AC:     return 'Chauffe (clim)';
            case self::COOL_AC:     return 'Refroidissement (clim)';
        }
        return 'Repos';
    }

    public static function modeLabel($_mode) {
        switch ($_mode) {
            case self::MODE_OFF:   return 'Arrêt';
            case self::MODE_FROST: return 'Hors-gel';
            case self::MODE_HEAT:  return 'Chauffage';
            case self::MODE_COOL:  return 'Refroidissement';
        }
        return 'Auto';
    }

    public static function sourceLabel($_source) {
        switch ($_source) {
            case self::SOURCE_BOILER: return 'Chaudière';
            case self::SOURCE_AC:     return 'Clim';
        }
        return 'Auto';
    }

    public static function seasonLabel($_season) {
        return ($_season == self::SEASON_COOL) ? 'Froid' : 'Chauffe';
    }
}
