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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/thermostatbeEngine.class.php';

/*
 * Un équipement = le thermostat d'une zone : ses sondes, sa chaudière, sa clim.
 *
 * Cette classe fait le lien avec Jeedom et rien d'autre : elle lit les sondes,
 * appelle le moteur (thermostatbeEngine), envoie les ordres et tient les
 * commandes à jour. Toute la décision est dans le moteur, et c'est voulu : elle
 * s'éprouve hors ligne, pas ici.
 *
 * Les appareils ne sont pas pilotés par un protocole à nous : chaudière et clim
 * sont des commandes d'action d'autres plugins, choisies dans la page de
 * l'équipement. Le relais Shelly de la chaudière, la clim Airton arrivée par
 * Tuya, ou tout autre appareil demain : le thermostat n'a pas à le savoir.
 *
 * Aucun démon : le cron du coeur passe chaque minute, et un écouteur sur les
 * sondes et les fenêtres fait réagir le thermostat sans attendre la minute.
 */
class thermostatbe extends eqLogic {

    /* Les préréglages : consigne de chauffe et consigne de froid. */
    const PRESETS = array(
        'comfort' => array('heat' => 20.0, 'cool' => 25.0),
        'eco'     => array('heat' => 18.0, 'cool' => 27.0),
        'away'    => array('heat' => 16.0, 'cool' => 29.0),
    );

    /*
     * Ce qu'une condition de l'utilisateur peut faire au thermostat, dans
     * l'ordre de la liste déroulante. Les deux premiers changent le mode, les
     * deux suivants les consignes, les autres posent un interdit que le
     * moteur respecte. Le hors-gel, lui, passe toujours.
     */
    const EFFECTS = array('off', 'frost', 'eco', 'away', 'no_heat', 'no_cool', 'no_ac', 'no_boiler');

    /*
     * L'âge au-delà duquel une sonde intérieure est tenue pour muette, en
     * minutes. Une sonde à la pile vide ne se tait pas : Jeedom garde sa
     * dernière valeur indéfiniment, et le thermostat chaufferait tout l'hiver
     * sur une mesure de novembre.
     *
     * Trois heures et non moins : beaucoup de sondes ne publient qu'au
     * changement, et une nuit calme peut les laisser muettes plus d'une heure.
     * Trop court, le délai couperait le chauffage à 4 h du matin sur une sonde
     * en parfait état.
     */
    const DEFAULT_SENSOR_MAX_AGE = 180;

    /*
     * Tous les combien la chaudière reçoit à nouveau son ordre, en minutes.
     *
     * C'est la moitié d'une sécurité, l'autre est sur le Shelly : réglé pour
     * s'éteindre seul au bout de 15 minutes (« Auto OFF »), il ne reste allumé
     * que tant que Jeedom le lui redemande. Si Jeedom tombe, la chaudière
     * s'arrête d'elle-même au lieu de chauffer jusqu'au retour de quelqu'un.
     */
    const DEFAULT_BOILER_RESEND = 5;

    /* La clim, elle, n'est relancée que si son état remonté contredit l'ordre :
     * elle bipe à chaque commande reçue. Et pas plus d'une fois par 5 min. */
    const AC_RESEND_MIN = 300;

    /* ==================================================================== CRON */

    public static function cron() {
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->evaluate('cron');
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* L'écouteur : une sonde intérieure ou une fenêtre a changé. */
    public static function pull($_options) {
        $eqLogic = self::byId($_options['eqLogic_id']);
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
            return;
        }
        try {
            $eqLogic->evaluate('event');
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    public static function sensorMaxAge() {
        $minutes = config::byKey('sensor_max_age', __CLASS__, self::DEFAULT_SENSOR_MAX_AGE);
        if ($minutes === '' || !is_numeric($minutes)) {
            $minutes = self::DEFAULT_SENSOR_MAX_AGE;
        }
        return max(0, (int) $minutes) * 60;
    }

    public static function boilerResend() {
        $minutes = config::byKey('boiler_resend', __CLASS__, self::DEFAULT_BOILER_RESEND);
        if ($minutes === '' || !is_numeric($minutes)) {
            $minutes = self::DEFAULT_BOILER_RESEND;
        }
        return max(0, (int) $minutes) * 60;
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        if ($this->getConfiguration('ac_enforce', '') === '') {
            $this->setConfiguration('ac_enforce', 1);
        }
        /* Les réglages passent par le moteur, qui leur impose défauts et
         * bornes : une consigne tapée « 20,5 » vaut 20.5, et l'écart entre les
         * deux consignes est tenu dès l'enregistrement. */
        $raw = array();
        foreach (array_keys(thermostatbeEngine::DEFAULTS) as $key) {
            $raw[$key] = $this->getConfiguration($key, null);
        }
        $clean = thermostatbeEngine::cleanSettings($raw);
        foreach (array_keys(thermostatbeEngine::DEFAULTS) as $key) {
            $this->setConfiguration($key, $clean[$key]);
        }
        foreach (self::PRESETS as $preset => $values) {
            foreach (array('heat', 'cool') as $side) {
                $key = 'preset_' . $preset . '_' . $side;
                $value = thermostatbeEngine::number($this->getConfiguration($key, ''));
                $this->setConfiguration($key, ($value === null) ? $values[$side] : $value);
            }
        }
        if ($this->getConfiguration('preset', '') == '') {
            $this->setConfiguration('preset', 'manual');
        }
        $this->setConfiguration('conditions', self::cleanConditions($this->getConfiguration('conditions', array())));
        if ($this->getDisplay('width') == '') {
            $this->setDisplay('width', '300px');
        }
    }

    public function postSave() {
        $this->createCommands();
        $this->updateListener();
        if ($this->getIsEnable() != 1) {
            /* Un thermostat désactivé ne passe plus au cron : s'il laissait la
             * chaudière allumée, elle le resterait. On coupe ce qu'il faisait
             * tourner avant de se taire. */
            $this->shutdown(__('équipement désactivé', __FILE__));
            return;
        }
        try {
            $this->evaluate('save');
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    public function preRemove() {
        $this->shutdown(__('équipement supprimé', __FILE__));
        $listener = listener::byClassAndFunction(__CLASS__, 'pull', array('eqLogic_id' => intval($this->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }
        cache::delete($this->stateKey());
        cache::delete($this->decisionKey());
        cache::delete($this->sentKey());
        cache::delete($this->stateKey() . '::saved');
        config::remove($this->stateKey(), __CLASS__);
        message::removeAll(__CLASS__, 'safety' . $this->getId());
    }

    /* L'écouteur suit la liste des sondes et des fenêtres : reconstruit à
     * chaque enregistrement, il ne garde pas une sonde retirée. */
    private function updateListener() {
        $listener = listener::byClassAndFunction(__CLASS__, 'pull', array('eqLogic_id' => intval($this->getId())));
        /* Les commandes citées par les conditions aussi : armer l'alarme doit
         * couper le chauffage tout de suite, pas à la minute suivante. */
        $ids = array_values(array_unique(array_merge($this->cmdIds('indoor_sensors'), $this->cmdIds('windows'),
                                                     $this->conditionCmdIds())));
        if ($this->getIsEnable() != 1 || count($ids) == 0) {
            if (is_object($listener)) {
                $listener->remove();
            }
            return;
        }
        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass(__CLASS__);
            $listener->setFunction('pull');
            $listener->setOption(array('eqLogic_id' => intval($this->getId())));
        }
        $listener->emptyEvent();
        foreach ($ids as $id) {
            $listener->addEvent($id);
        }
        $listener->save();
    }

    /* ============================================================== COMMANDES */

    public function createCommands() {
        $modes = array();
        foreach (thermostatbeEngine::MODES as $mode) {
            $modes[] = $mode . '|' . thermostatbeEngine::modeLabel($mode);
        }
        $sources = array();
        foreach (thermostatbeEngine::SOURCES as $source) {
            $sources[] = $source . '|' . thermostatbeEngine::sourceLabel($source);
        }
        $presets = array();
        foreach (array_keys(self::PRESETS) as $preset) {
            $presets[] = $preset . '|' . self::presetLabel($preset);
        }

        $definitions = array(
            array('logicalId' => 'temperature', 'name' => __('Température', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => 'THERMOSTAT_TEMPERATURE',
                  'visible' => 1, 'historized' => 1, 'unite' => '°C'),
            array('logicalId' => 'heat_setpoint', 'name' => __('Consigne chauffe', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => 'THERMOSTAT_SETPOINT',
                  'visible' => 0, 'historized' => 1, 'unite' => '°C'),
            array('logicalId' => 'set_heat_setpoint', 'name' => __('Régler consigne chauffe', __FILE__),
                  'type' => 'action', 'subType' => 'slider', 'generic' => 'THERMOSTAT_SET_SETPOINT',
                  'visible' => 1, 'value' => 'heat_setpoint', 'min' => 5, 'max' => 30, 'step' => 0.5, 'unite' => '°C'),
            array('logicalId' => 'cool_setpoint', 'name' => __('Consigne froid', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 0, 'historized' => 1, 'unite' => '°C'),
            array('logicalId' => 'set_cool_setpoint', 'name' => __('Régler consigne froid', __FILE__),
                  'type' => 'action', 'subType' => 'slider', 'generic' => '',
                  'visible' => 1, 'value' => 'cool_setpoint', 'min' => 15, 'max' => 35, 'step' => 0.5, 'unite' => '°C'),
            array('logicalId' => 'mode', 'name' => __('Mode', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => 'THERMOSTAT_MODE', 'visible' => 0),
            array('logicalId' => 'set_mode', 'name' => __('Choisir le mode', __FILE__),
                  'type' => 'action', 'subType' => 'select', 'generic' => 'THERMOSTAT_SET_MODE',
                  'visible' => 1, 'value' => 'mode', 'list' => implode(';', $modes)),
            array('logicalId' => 'source', 'name' => __('Source de chauffe', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '', 'visible' => 0),
            array('logicalId' => 'set_source', 'name' => __('Choisir la source', __FILE__),
                  'type' => 'action', 'subType' => 'select', 'generic' => '',
                  'visible' => 1, 'value' => 'source', 'list' => implode(';', $sources)),
            array('logicalId' => 'preset', 'name' => __('Préréglage', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '', 'visible' => 0),
            array('logicalId' => 'set_preset', 'name' => __('Choisir le préréglage', __FILE__),
                  'type' => 'action', 'subType' => 'select', 'generic' => '',
                  'visible' => 1, 'value' => 'preset', 'list' => implode(';', $presets)),
            array('logicalId' => 'state', 'name' => __('État', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => 'THERMOSTAT_STATE_NAME', 'visible' => 1),
            array('logicalId' => 'reason', 'name' => __('Raison', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '', 'visible' => 1),
            array('logicalId' => 'active', 'name' => __('En marche', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => 'THERMOSTAT_STATE',
                  'visible' => 0, 'historized' => 1),
            array('logicalId' => 'heating', 'name' => __('Chauffe', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => '', 'visible' => 0, 'historized' => 1),
            array('logicalId' => 'cooling', 'name' => __('Refroidit', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => '', 'visible' => 0, 'historized' => 1),
            array('logicalId' => 'device', 'name' => __('Appareil en marche', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '', 'visible' => 0),
            array('logicalId' => 'season', 'name' => __('Saison', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '', 'visible' => 1),
            array('logicalId' => 'outdoor', 'name' => __('Température extérieure', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => 'THERMOSTAT_TEMPERATURE_OUTDOOR',
                  'visible' => 0, 'unite' => '°C'),
            array('logicalId' => 'outdoor_avg', 'name' => __('Moyenne extérieure', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 0, 'historized' => 1, 'unite' => '°C'),
            array('logicalId' => 'window', 'name' => __('Fenêtre ouverte', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => '', 'visible' => 0),
            array('logicalId' => 'refresh', 'name' => __('Rafraîchir', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '', 'visible' => 0),
        );

        $order = 0;
        foreach ($definitions as $definition) {
            $cmd = $this->getCmd(null, $definition['logicalId']);
            if (!is_object($cmd)) {
                $cmd = new thermostatbeCmd();
                $cmd->setLogicalId($definition['logicalId']);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($definition['name']);
                $cmd->setIsVisible($definition['visible']);
                $cmd->setIsHistorized(isset($definition['historized']) ? $definition['historized'] : 0);
                if (isset($definition['unite'])) {
                    $cmd->setUnite($definition['unite']);
                }
                if (isset($definition['min'])) {
                    $cmd->setConfiguration('minValue', $definition['min']);
                    $cmd->setConfiguration('maxValue', $definition['max']);
                    $cmd->setDisplay('parameters', array('step' => $definition['step']));
                }
            }
            $cmd->setType($definition['type']);
            $cmd->setSubType($definition['subType']);
            $cmd->setGeneric_type($definition['generic']);
            if (isset($definition['list'])) {
                /* Les listes sont reposées à chaque fois : un mode ajouté par
                 * une mise à jour doit apparaître dans la liste déroulante. */
                $cmd->setConfiguration('listValue', $definition['list']);
            }
            $cmd->setOrder($order++);
            $cmd->save();
        }

        /* Le lien action → info : sans lui, le curseur revient sous le doigt à
         * l'ancienne consigne, et la liste des modes n'affiche pas le mode en
         * cours. */
        foreach ($definitions as $definition) {
            if (!isset($definition['value'])) {
                continue;
            }
            $cmd = $this->getCmd(null, $definition['logicalId']);
            $info = $this->getCmd(null, $definition['value']);
            if (is_object($cmd) && is_object($info) && $cmd->getValue() != $info->getId()) {
                $cmd->setValue($info->getId());
                $cmd->save();
            }
        }
    }

    public static function presetLabel($_preset) {
        switch ($_preset) {
            case 'comfort': return __('Confort', __FILE__);
            case 'eco':     return __('Éco', __FILE__);
            case 'away':    return __('Absent', __FILE__);
        }
        return __('Manuel', __FILE__);
    }

    /* ======================================================= RÉGLAGES EN DIRECT */

    /*
     * Change un réglage de marche : mode, source, consigne, préréglage.
     *
     * Enregistré en base et non dans le cache : un cache vidé ne doit pas
     * rendre la maison au mode par défaut. L'enregistrement est direct — sans
     * preSave ni postSave — pour ne pas reconstruire toutes les commandes à
     * chaque cran du curseur ; le moteur borne de toute façon ce qu'il lit.
     */
    public function setRuntime($_key, $_value) {
        $settings = $this->settings();
        switch ($_key) {
            case 'mode':
                if (!in_array($_value, thermostatbeEngine::MODES, true)) {
                    throw new Exception(__('Mode inconnu :', __FILE__) . ' ' . $_value);
                }
                $this->setConfiguration('mode', $_value);
                break;
            case 'source':
                if (!in_array($_value, thermostatbeEngine::SOURCES, true)) {
                    throw new Exception(__('Source inconnue :', __FILE__) . ' ' . $_value);
                }
                $this->setConfiguration('source', $_value);
                break;
            case 'heat_setpoint':
            case 'cool_setpoint':
                $value = thermostatbeEngine::number($_value);
                if ($value === null) {
                    throw new Exception(__('Consigne illisible :', __FILE__) . ' ' . $_value);
                }
                $heat = ($_key == 'heat_setpoint') ? $value : $settings['heat_setpoint'];
                $cool = ($_key == 'cool_setpoint') ? $value : $settings['cool_setpoint'];
                $pair = thermostatbeEngine::fitSetpoints($heat, $cool, $settings['min_gap'],
                                                         ($_key == 'cool_setpoint') ? 'cool' : 'heat');
                $this->setConfiguration('heat_setpoint', $pair[0]);
                $this->setConfiguration('cool_setpoint', $pair[1]);
                $this->setConfiguration('preset', 'manual');
                break;
            case 'preset':
                if (!isset(self::PRESETS[$_value])) {
                    throw new Exception(__('Préréglage inconnu :', __FILE__) . ' ' . $_value);
                }
                $pair = $this->presetValues($_value);
                $this->setConfiguration('heat_setpoint', $pair[0]);
                $this->setConfiguration('cool_setpoint', $pair[1]);
                $this->setConfiguration('preset', $_value);
                break;
            default:
                return;
        }
        $this->save(true);
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $_key . ' = ' . $_value);
        $this->evaluate('command');
    }

    /* Les réglages tels que le moteur les attend, prix et appareils résolus. */
    public function settings() {
        $raw = array();
        foreach (array_keys(thermostatbeEngine::DEFAULTS) as $key) {
            $raw[$key] = $this->getConfiguration($key, null);
        }
        $raw['gas_price'] = self::resolveNumber($this->getConfiguration('gas_price', ''));
        $raw['elec_price'] = self::resolveNumber($this->getConfiguration('elec_price', ''));
        $raw['has_boiler'] = $this->hasBoiler();
        $acBase = $this->cmdId('ac_on') !== null && $this->cmdId('ac_off') !== null;
        $raw['has_ac_heat'] = $acBase && $this->cmdId('ac_mode_heat') !== null;
        $raw['has_ac_cool'] = $acBase && $this->cmdId('ac_mode_cool') !== null;
        return thermostatbeEngine::cleanSettings($raw);
    }

    /* ============================================================ CONDITIONS */

    /*
     * Impose leur forme aux conditions : une expression, un effet connu, un
     * nom. Une ligne sans expression est une ligne vide, retirée.
     */
    public static function cleanConditions($_conditions) {
        $clean = array();
        if (!is_array($_conditions)) {
            return $clean;
        }
        foreach ($_conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $expression = isset($condition['expression']) ? trim((string) $condition['expression']) : '';
            if ($expression === '') {
                continue;
            }
            $effect = isset($condition['effect']) ? $condition['effect'] : '';
            $clean[] = array(
                'enable'     => (isset($condition['enable']) && $condition['enable'] == 0) ? 0 : 1,
                'name'       => isset($condition['name']) ? trim((string) $condition['name']) : '',
                'expression' => $expression,
                'effect'     => in_array($effect, self::EFFECTS, true) ? $effect : 'off',
            );
        }
        return $clean;
    }

    public function conditionCmdIds() {
        $ids = array();
        foreach (self::cleanConditions($this->getConfiguration('conditions', array())) as $condition) {
            if ($condition['enable'] != 1) {
                continue;
            }
            preg_match_all('/#(\d+)#/', $condition['expression'], $matches);
            foreach ($matches[1] as $id) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function effectLabel($_effect) {
        switch ($_effect) {
            case 'off':       return __('Tout arrêter', __FILE__);
            case 'frost':     return __('Hors-gel seulement', __FILE__);
            case 'eco':       return __('Consignes Éco', __FILE__);
            case 'away':      return __('Consignes Absent', __FILE__);
            case 'no_heat':   return __('Ne pas chauffer', __FILE__);
            case 'no_cool':   return __('Ne pas refroidir', __FILE__);
            case 'no_ac':     return __('Ne pas utiliser la clim', __FILE__);
            case 'no_boiler': return __('Ne pas utiliser la chaudière', __FILE__);
        }
        return $_effect;
    }

    /*
     * Évalue une condition.
     *
     * Seuls un booléen et un nombre font foi. Une expression que le coeur ne
     * sait pas calculer lui revient sous forme de texte — les valeurs des
     * commandes déjà substituées, « 24.9 >>> faux » — et ce texte non vide
     * passerait pour « vrai » : une faute de frappe couperait le chauffage.
     * Tout ce qui n'est ni booléen ni nombre est donc tenu pour faux, et le
     * journal le dit. Une commande texte se compare : « #[…][Tarif]# == "HP" ».
     */
    private function conditionIsTrue($_condition) {
        try {
            $result = jeedom::evaluateExpression($_condition['expression']);
        } catch (Throwable $e) {
            $result = null;
        }
        if (is_bool($result)) {
            return $result;
        }
        if (is_numeric($result)) {
            return ((float) $result) != 0;
        }
        log::add(__CLASS__, 'warning', $this->getHumanName() . ' : '
            . __('condition impossible à évaluer, ignorée :', __FILE__) . ' '
            . jeedom::toHumanReadable($_condition['expression']));
        return false;
    }

    /*
     * Les conditions vraies en ce moment, avec leur nom lisible. Les
     * évaluations sont faites une fois par passage : settings() et
     * l'affichage lisent le même résultat.
     */
    public function activeConditions() {
        $active = array();
        foreach (self::cleanConditions($this->getConfiguration('conditions', array())) as $condition) {
            if ($condition['enable'] != 1 || !$this->conditionIsTrue($condition)) {
                continue;
            }
            if ($condition['name'] === '') {
                $condition['name'] = jeedom::toHumanReadable($condition['expression']);
            }
            $active[] = $condition;
        }
        return $active;
    }

    /*
     * Applique les conditions vraies aux réglages. L'arrêt l'emporte sur le
     * hors-gel, le hors-gel sur les consignes ; entre Éco et Absent, on prend
     * pour chaque côté la consigne la plus sobre. Les interdits s'ajoutent.
     */
    public function applyConditions($_settings, $_active) {
        $settings = $_settings;
        $names = array();
        foreach ($_active as $condition) {
            $names[$condition['effect']][] = $condition['name'];
        }
        $label = function ($_effect) use ($names) {
            return implode(', ', $names[$_effect]);
        };
        if (isset($names['off'])) {
            $settings['mode'] = thermostatbeEngine::MODE_OFF;
            $settings['mode_reason'] = sprintf(__('Thermostat coupé par la condition « %s »', __FILE__), $label('off'));
        } elseif (isset($names['frost']) && $settings['mode'] != thermostatbeEngine::MODE_OFF) {
            $settings['mode'] = thermostatbeEngine::MODE_FROST;
            $settings['mode_reason'] = sprintf(__('Hors-gel imposé par la condition « %s »', __FILE__), $label('frost'));
        }
        foreach (array('eco', 'away') as $preset) {
            if (!isset($names[$preset])) {
                continue;
            }
            $values = $this->presetValues($preset);
            $settings['heat_setpoint'] = min($settings['heat_setpoint'], $values[0]);
            $settings['cool_setpoint'] = max($settings['cool_setpoint'], $values[1]);
        }
        foreach (thermostatbeEngine::RESTRICTIONS as $key) {
            if (isset($names[$key])) {
                $settings[$key] = sprintf(__('condition « %s »', __FILE__), $label($key));
            }
        }
        return thermostatbeEngine::cleanSettings($settings);
    }

    /* Les consignes d'un préréglage, écart minimum tenu. */
    public function presetValues($_preset) {
        $heat = thermostatbeEngine::number($this->getConfiguration('preset_' . $_preset . '_heat', ''));
        $cool = thermostatbeEngine::number($this->getConfiguration('preset_' . $_preset . '_cool', ''));
        $gap = thermostatbeEngine::number($this->getConfiguration('min_gap', ''));
        return thermostatbeEngine::fitSetpoints(
            ($heat === null) ? self::PRESETS[$_preset]['heat'] : $heat,
            ($cool === null) ? self::PRESETS[$_preset]['cool'] : $cool,
            ($gap === null) ? thermostatbeEngine::DEFAULTS['min_gap'] : $gap);
    }

    /*
     * Un prix : un nombre tapé, ou une commande info (#123#) — un tarif
     * dynamique, un prix du gaz mis à jour par un scénario.
     */
    public static function resolveNumber($_value) {
        $value = trim((string) $_value);
        if (preg_match('/^#(\d+)#$/', $value, $match)) {
            $cmd = cmd::byId($match[1]);
            if (!is_object($cmd) || $cmd->getType() != 'info') {
                return null;
            }
            return thermostatbeEngine::number($cmd->execCmd());
        }
        return thermostatbeEngine::number($value);
    }

    /* ================================================================ LECTURES */

    /* L'identifiant d'une commande choisie dans la page, ou null. */
    public function cmdId($_key) {
        $ids = $this->cmdIds($_key);
        return (count($ids) > 0) ? $ids[0] : null;
    }

    /* Les identifiants de toutes les commandes d'un champ : un champ de
     * sondes en contient plusieurs, écrits « #12# #34# ». */
    public function cmdIds($_key) {
        preg_match_all('/#(\d+)#/', (string) $this->getConfiguration($_key, ''), $matches);
        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /*
     * Lit une sonde : sa valeur, ou null si elle est absente, illisible ou
     * muette depuis trop longtemps.
     */
    public static function readSensor($_cmdId, $_maxAge = 0) {
        $state = array('value' => null, 'stale' => false, 'age' => null, 'name' => '');
        try {
            $cmd = cmd::byId($_cmdId);
            if (!is_object($cmd) || $cmd->getType() != 'info') {
                return $state;
            }
            $state['name'] = $cmd->getHumanName();
            $value = $cmd->execCmd();
            if ($value === '' || $value === null || !is_numeric($value)) {
                return $state;
            }
            $collected = strtotime((string) $cmd->getCollectDate());
            if ($collected !== false && $collected > 0) {
                $state['age'] = max(0, time() - $collected);
            }
            if ($_maxAge > 0 && $state['age'] !== null && $state['age'] > $_maxAge) {
                $state['stale'] = true;
                return $state;
            }
            $state['value'] = (float) $value;
        } catch (Throwable $e) {
            $state['value'] = null;
        }
        return $state;
    }

    /*
     * La température intérieure : la moyenne des sondes qui répondent.
     *
     * Une sonde muette est écartée, pas remplacée par zéro : trois sondes à
     * 20 °C et une pile morte donnent 20 °C, pas 15. Il faut qu'aucune ne
     * réponde pour que le thermostat se coupe.
     */
    public function indoor() {
        $maxAge = self::sensorMaxAge();
        $sensors = array();
        $values = array();
        foreach ($this->cmdIds('indoor_sensors') as $id) {
            $sensor = self::readSensor($id, $maxAge);
            $sensor['id'] = $id;
            $sensors[] = $sensor;
            if ($sensor['value'] !== null) {
                $values[] = $sensor['value'];
            }
        }
        $average = (count($values) > 0) ? round(array_sum($values) / count($values), 2) : null;
        return array('value' => $average, 'sensors' => $sensors);
    }

    public function outdoor() {
        $id = $this->cmdId('outdoor_sensor');
        if ($id === null) {
            return null;
        }
        /* Dehors, on tolère plus longtemps : une station météo publie parfois
         * une fois l'heure, et une moyenne sur un jour n'en souffre pas. */
        $sensor = self::readSensor($id, max(self::sensorMaxAge(), 3 * 3600));
        return $sensor['value'];
    }

    /* La moyenne extérieure des dernières 24 h, si la sonde est historisée :
     * elle amorce la moyenne lissée au tout premier passage. */
    public function outdoorSeed() {
        $id = $this->cmdId('outdoor_sensor');
        if ($id === null) {
            return null;
        }
        try {
            $stats = history::getStatistique($id, date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s'));
            if (is_array($stats) && isset($stats['avg']) && is_numeric($stats['avg'])) {
                return (float) $stats['avg'];
            }
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
        }
        return null;
    }

    /*
     * Depuis combien de secondes une fenêtre est ouverte, null si toutes sont
     * fermées. La date du dernier changement de valeur dit quand elle s'est
     * ouverte : rien à retenir de notre côté.
     */
    public function windowOpenFor() {
        $invert = ($this->getConfiguration('window_invert', 0) == 1);
        $longest = null;
        foreach ($this->cmdIds('windows') as $id) {
            $cmd = cmd::byId($id);
            if (!is_object($cmd) || $cmd->getType() != 'info') {
                continue;
            }
            $open = ((int) $cmd->execCmd() == 1);
            if ($invert) {
                $open = !$open;
            }
            if (!$open) {
                continue;
            }
            $since = strtotime((string) $cmd->getValueDate());
            $for = ($since !== false && $since > 0) ? max(0, time() - $since) : 0;
            $longest = ($longest === null) ? $for : max($longest, $for);
        }
        return $longest;
    }

    /* ================================================================ ÉTAT */

    public function stateKey() {
        return 'thermostatbe::state::' . $this->getId();
    }

    public function decisionKey() {
        return 'thermostatbe::decision::' . $this->getId();
    }

    public function sentKey() {
        return 'thermostatbe::sent::' . $this->getId();
    }

    /*
     * L'état du moteur : dans le cache pour aller vite, et recopié en base à
     * chaque changement d'état. Un cache vidé ferait oublier quand on a
     * chauffé pour la dernière fois, et avec lui le verrou qui empêche de
     * refroidir juste après.
     *
     * En base, dans la configuration du plugin et non dans celle de
     * l'équipement : le cron travaille sur un équipement chargé au début de
     * son passage, et l'enregistrer effacerait un réglage que l'utilisateur
     * aurait sauvegardé depuis la page pendant ce temps.
     */
    private function loadState() {
        $state = cache::byKey($this->stateKey())->getValue(null);
        if (!is_array($state)) {
            $state = json_decode((string) config::byKey($this->stateKey(), __CLASS__, ''), true);
        }
        return is_array($state) ? $state : array();
    }

    private function storeState($_state, $_persist) {
        cache::set($this->stateKey(), $_state);
        if ($_persist) {
            config::save($this->stateKey(), json_encode($_state), __CLASS__);
        }
    }

    /* ============================================================ ÉVALUATION */

    /*
     * Un passage du thermostat : lire, décider, agir, afficher.
     *
     * Protégé par un verrou de fichier : le cron et l'écouteur peuvent tomber
     * dans la même seconde, et deux passages simultanés enverraient deux fois
     * le même ordre — ou pire, deux ordres contraires.
     */
    public function evaluate($_origin = 'cron') {
        if ($this->getIsEnable() != 1) {
            return null;
        }
        /* Sans verrou, on évalue quand même : un fichier de verrou illisible
         * ne doit pas arrêter le thermostat en silence, la maison avec. */
        $lock = @fopen(jeedom::getTmpFolder(__CLASS__) . '/eq' . $this->getId() . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : '
                . __('verrou indisponible, évaluation sans verrou', __FILE__));
            if ($lock !== false) {
                fclose($lock);
            }
            return $this->evaluateLocked($_origin);
        }
        try {
            return $this->evaluateLocked($_origin);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function evaluateLocked($_origin) {
        $active = $this->activeConditions();
        $settings = $this->applyConditions($this->settings(), $active);
        $indoor = $this->indoor();
        $outdoor = $this->outdoor();
        $state = $this->loadState();
        $previous = thermostatbeEngine::cleanState($state);
        $windowFor = $this->windowOpenFor();

        $inputs = array(
            'now'             => time(),
            'indoor'          => $indoor['value'],
            'outdoor'         => $outdoor,
            /* L'historique n'est relu que si la moyenne manque ou s'est
             * interrompue : pas à chaque passage. */
            'outdoor_seed'    => ($previous['outdoor_avg'] === null
                                  || time() - (int) $previous['outdoor_avg_at'] > thermostatbeEngine::OUTDOOR_GAP)
                                 ? $this->outdoorSeed() : null,
            'window_open_for' => $windowFor,
        );
        $decision = thermostatbeEngine::decide($settings, $inputs, $state);
        /* Une condition qui change les consignes ne se voit pas dans la raison
         * du moteur : « 19 °C pour 16 °C » sans dire pourquoi 16. */
        $stopped = in_array($settings['mode'], array(thermostatbeEngine::MODE_OFF, thermostatbeEngine::MODE_FROST), true);
        foreach ($active as $condition) {
            if (!$stopped && in_array($condition['effect'], array('eco', 'away'), true)) {
                $decision['reason'] = sprintf(__('%s (condition « %s »)', __FILE__),
                    $decision['reason'], $condition['name']);
            }
        }
        $changed = ($decision['target'] != $previous['target']);
        /* En base à chaque changement d'état, et au moins toutes les heures :
         * la moyenne extérieure survit ainsi à un redémarrage. */
        $persistedAt = (int) cache::byKey($this->stateKey() . '::saved')->getValue(0);
        $persist = $changed || time() - $persistedAt >= 3600;
        $this->storeState($decision['state'], $persist);
        if ($persist) {
            cache::set($this->stateKey() . '::saved', time());
        }
        if (count($this->cmdIds('indoor_sensors')) == 0) {
            /* Rien de perdu : rien n'a encore été choisi. Le dire autrement
             * qu'une panne, et sans alerte dans le centre de messages. */
            $decision['status'] = 'blocked';
            $decision['reason'] = __('Choisissez au moins une sonde intérieure (onglet Thermostat)', __FILE__);
        }

        if ($changed) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : '
                . thermostatbeEngine::targetLabel($previous['target']) . ' → '
                . thermostatbeEngine::targetLabel($decision['target']) . ' — ' . $decision['reason']);
        } else {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' [' . $_origin . '] ' . $decision['reason']);
        }

        $this->apply($previous['target'], $decision);
        $this->notifySafety($decision);

        $decision['inputs'] = $inputs;
        $decision['sensors'] = $indoor['sensors'];
        $decision['settings'] = $settings;
        $decision['conditions'] = array_map(function ($_condition) {
            return array('name' => $_condition['name'], 'effect' => self::effectLabel($_condition['effect']));
        }, $active);
        $decision['at'] = time();
        cache::set($this->decisionKey(), $decision);
        $this->refreshInfo($decision, $settings, $indoor['value'], $outdoor, $windowFor);
        return $decision;
    }

    private function refreshInfo($_decision, $_settings, $_indoor, $_outdoor, $_windowFor) {
        $target = $_decision['target'];
        if ($_indoor !== null) {
            $this->checkAndUpdateCmd('temperature', $_indoor);
        }
        if ($_outdoor !== null) {
            $this->checkAndUpdateCmd('outdoor', $_outdoor);
        }
        if ($_decision['outdoor_avg'] !== null) {
            $this->checkAndUpdateCmd('outdoor_avg', round($_decision['outdoor_avg'], 1));
        }
        /* Les consignes et le mode affichés sont ceux de l'utilisateur, pas
         * ceux qu'une condition impose pour un temps : le curseur du tableau
         * de bord ne doit pas sauter à 16 °C quand l'alarme s'arme, ni y
         * rester quand elle se désarme. La raison dit ce qui s'applique. */
        $runtime = $this->runtime();
        $this->checkAndUpdateCmd('heat_setpoint', $runtime['heat_setpoint']);
        $this->checkAndUpdateCmd('cool_setpoint', $runtime['cool_setpoint']);
        $this->checkAndUpdateCmd('mode', $runtime['mode']);
        $this->checkAndUpdateCmd('source', $_settings['source']);
        $this->checkAndUpdateCmd('preset', $this->getConfiguration('preset', 'manual'));
        $this->checkAndUpdateCmd('state', thermostatbeEngine::targetLabel($target));
        $this->checkAndUpdateCmd('reason', $_decision['reason']);
        $this->checkAndUpdateCmd('active', ($target != thermostatbeEngine::IDLE) ? 1 : 0);
        $this->checkAndUpdateCmd('heating', thermostatbeEngine::isHeating($target) ? 1 : 0);
        $this->checkAndUpdateCmd('cooling', ($target == thermostatbeEngine::COOL_AC) ? 1 : 0);
        $device = thermostatbeEngine::device($target);
        $this->checkAndUpdateCmd('device', ($device === null) ? __('Aucun', __FILE__) : thermostatbeEngine::deviceName($device));
        $this->checkAndUpdateCmd('season', thermostatbeEngine::seasonLabel($_decision['season']));
        $this->checkAndUpdateCmd('window', ($_windowFor !== null) ? 1 : 0);
    }

    /*
     * Plus aucune sonde intérieure : le thermostat est coupé, et il faut que
     * quelqu'un le sache. Un message dans le centre de messages, retiré dès
     * que les sondes reviennent.
     */
    private function notifySafety($_decision) {
        $key = 'safety' . $this->getId();
        if ($_decision['status'] == 'safety') {
            if (cache::byKey('thermostatbe::safety::' . $this->getId())->getValue(0) == 0) {
                message::add(__CLASS__, $this->getHumanName() . ' : ' . $_decision['reason'], '', $key);
                cache::set('thermostatbe::safety::' . $this->getId(), 1);
            }
            return;
        }
        if (cache::byKey('thermostatbe::safety::' . $this->getId())->getValue(0) == 1) {
            message::removeAll(__CLASS__, $key);
            cache::set('thermostatbe::safety::' . $this->getId(), 0);
        }
    }

    /* ================================================================ ORDRES */

    /*
     * Applique une décision aux appareils.
     *
     * Toujours arrêter avant de démarrer : même une fraction de seconde,
     * chaudière et clim ne tournent pas ensemble, et la clim ne reçoit pas
     * « froid » tant qu'elle tourne en chaud.
     */
    private function apply($_previous, $_decision) {
        $target = $_decision['target'];
        $sent = cache::byKey($this->sentKey())->getValue(array());
        if (!is_array($sent)) {
            $sent = array();
        }
        $now = time();

        $boilerOn = ($target == thermostatbeEngine::HEAT_BOILER);
        $acMode = thermostatbeEngine::acMode($target);
        $acOn = ($acMode !== null);
        $prevAcMode = thermostatbeEngine::acMode($_previous);

        /* 1. Les arrêts. */
        if (thermostatbeEngine::device($_previous) == 'boiler' && !$boilerOn) {
            $this->sendBoiler(false, $sent, $now);
        }
        if ($prevAcMode !== null && $prevAcMode !== $acMode) {
            $this->sendAc(false, null, null, $sent, $now);
        }

        /* 2. Les démarrages. */
        if ($boilerOn && thermostatbeEngine::device($_previous) != 'boiler') {
            $this->sendBoiler(true, $sent, $now);
        }
        if ($acOn && $prevAcMode !== $acMode) {
            $this->sendAc(true, $acMode, $_decision['ac_setpoint'], $sent, $now);
        } elseif ($acOn && isset($sent['ac_setpoint']) && $sent['ac_setpoint'] != $_decision['ac_setpoint']) {
            /* La consigne a changé en cours de marche. */
            $this->execAction($this->cmdId('ac_setpoint'), $_decision['ac_setpoint']);
            $sent['ac_setpoint'] = $_decision['ac_setpoint'];
        }

        /* 3. Les rappels : l'ordre en cours, renvoyé si l'appareil ne l'a pas
         *    suivi — ou, pour la chaudière, à intervalle régulier. */
        $this->remindBoiler($boilerOn, $sent, $now);
        $this->remindAc($acOn, $acMode, $_decision['ac_setpoint'], $sent, $now);

        cache::set($this->sentKey(), $sent);
    }

    private function hasBoiler() {
        return $this->cmdId('boiler_on') !== null && $this->cmdId('boiler_off') !== null;
    }

    private function sendBoiler($_on, &$_sent, $_now) {
        if (!$this->hasBoiler()) {
            return;
        }
        $this->execAction($this->cmdId($_on ? 'boiler_on' : 'boiler_off'));
        $_sent['boiler'] = $_on ? 1 : 0;
        $_sent['boiler_at'] = $_now;
    }

    private function sendAc($_on, $_mode, $_setpoint, &$_sent, $_now) {
        if ($this->cmdId('ac_on') === null || $this->cmdId('ac_off') === null) {
            return;
        }
        if (!$_on) {
            $this->execAction($this->cmdId('ac_off'));
            $_sent['ac'] = 0;
            $_sent['ac_at'] = $_now;
            return;
        }
        $this->execAction($this->cmdId('ac_on'));
        $modeKey = ($_mode == 'cool') ? 'ac_mode_cool' : 'ac_mode_heat';
        $this->execAction($this->cmdId($modeKey), $this->getConfiguration($modeKey . '_value', ''));
        if ($_setpoint !== null && $this->cmdId('ac_setpoint') !== null) {
            $this->execAction($this->cmdId('ac_setpoint'), $_setpoint);
        }
        $_sent['ac'] = 1;
        $_sent['ac_mode'] = $_mode;
        $_sent['ac_setpoint'] = $_setpoint;
        $_sent['ac_at'] = $_now;
    }

    private function remindBoiler($_on, &$_sent, $_now) {
        if (!$this->hasBoiler()) {
            return;
        }
        $last = isset($_sent['boiler_at']) ? (int) $_sent['boiler_at'] : 0;
        /* Jamais rien envoyé depuis le démarrage de Jeedom : on ne sait pas
         * dans quel état est le relais, on le lui dit. */
        $due = !isset($_sent['boiler']) || $_sent['boiler'] != ($_on ? 1 : 0);
        $resend = self::boilerResend();
        if (!$due && $resend > 0 && $_now - $last >= $resend) {
            $due = true;
        }
        $stateId = $this->cmdId('boiler_state');
        if (!$due && $stateId !== null && $_now - $last >= 60) {
            $state = self::readSensor($stateId);
            if ($state['value'] !== null && ((int) $state['value'] == 1) != $_on) {
                log::add(__CLASS__, 'warning', $this->getHumanName() . ' : '
                    . __('le relais de la chaudière ne suit pas l\'ordre, renvoi', __FILE__));
                $due = true;
            }
        }
        if ($due) {
            $this->sendBoiler($_on, $_sent, $_now);
        }
    }

    private function remindAc($_on, $_mode, $_setpoint, &$_sent, $_now) {
        if ($this->cmdId('ac_on') === null || $this->cmdId('ac_off') === null) {
            return;
        }
        $last = isset($_sent['ac_at']) ? (int) $_sent['ac_at'] : 0;
        if (!isset($_sent['ac'])) {
            /* Premier passage depuis le démarrage : même raisonnement que pour
             * la chaudière, mais une seule fois — la clim bipe. */
            $this->sendAc($_on, $_mode, $_setpoint, $_sent, $_now);
            return;
        }
        $stateId = $this->cmdId('ac_state');
        if ($stateId === null || $_now - $last < self::AC_RESEND_MIN) {
            return;
        }
        $state = self::readSensor($stateId);
        if ($state['value'] !== null && ((int) $state['value'] == 1) != $_on) {
            /* Un ordre perdu, ou quelqu'un qui a pris la télécommande : on ne
             * peut pas les distinguer. Le choix revient à l'utilisateur. */
            if ($this->getConfiguration('ac_enforce', 1) != 1) {
                log::add(__CLASS__, 'info', $this->getHumanName() . ' : '
                    . __('la clim ne suit pas l\'ordre (télécommande ?), laissée telle quelle', __FILE__));
                $_sent['ac_at'] = $_now;
                return;
            }
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : '
                . __('la clim ne suit pas l\'ordre, renvoi', __FILE__));
            $this->sendAc($_on, $_mode, $_setpoint, $_sent, $_now);
        }
    }

    /*
     * Joue une commande d'action d'un autre plugin, en lui passant la valeur
     * sous la forme que son sous-type attend : un curseur pour une consigne,
     * une liste pour un mode Tuya, un message pour ce qui prend du texte.
     */
    public function execAction($_cmdId, $_value = null) {
        if ($_cmdId === null) {
            return false;
        }
        try {
            $cmd = cmd::byId($_cmdId);
            if (!is_object($cmd) || $cmd->getType() != 'action') {
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : '
                    . __('commande introuvable ou qui n\'est pas une action :', __FILE__) . ' #' . $_cmdId . '#');
                return false;
            }
            $options = array();
            if ($_value !== null && $_value !== '') {
                switch ($cmd->getSubType()) {
                    case 'slider':  $options['slider'] = $_value; break;
                    case 'select':  $options['select'] = $_value; break;
                    case 'message': $options['message'] = $_value; break;
                }
            }
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' → ' . $cmd->getHumanName()
                . (count($options) > 0 ? ' ' . json_encode($options) : ''));
            $cmd->execCmd($options);
            return true;
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
            return false;
        }
    }

    /* Coupe ce que le thermostat faisait tourner, sans décision du moteur. */
    public function shutdown($_why) {
        $state = thermostatbeEngine::cleanState($this->loadState());
        $device = thermostatbeEngine::device($state['target']);
        if ($device === null) {
            return;
        }
        $sent = array();
        if ($device == 'boiler') {
            $this->sendBoiler(false, $sent, time());
        } else {
            $this->sendAc(false, null, null, $sent, time());
        }
        $state['target'] = thermostatbeEngine::IDLE;
        $state[$device . '_off_at'] = time();
        $this->storeState($state, true);
        cache::delete($this->sentKey());
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . thermostatbeEngine::deviceName($device)
            . ' ' . __('coupée', __FILE__) . ' (' . $_why . ')');
    }

    /* Les réglages de marche tels que l'utilisateur les a choisis. */
    public function runtime() {
        $settings = $this->settings();
        return array(
            'mode'          => $settings['mode'],
            'source'        => $settings['source'],
            'heat_setpoint' => $settings['heat_setpoint'],
            'cool_setpoint' => $settings['cool_setpoint'],
            'preset'        => $this->getConfiguration('preset', 'manual'),
        );
    }

    /* La dernière décision, pour la page de l'équipement. */
    public function status() {
        $decision = cache::byKey($this->decisionKey())->getValue(null);
        if (!is_array($decision)) {
            return array('runtime' => $this->runtime());
        }
        return array(
            'runtime'     => $this->runtime(),
            'conditions'  => isset($decision['conditions']) ? $decision['conditions'] : array(),
            'at'          => date('H:i:s', $decision['at']),
            'target'      => thermostatbeEngine::targetLabel($decision['target']),
            'status'      => $decision['status'],
            'reason'      => $decision['reason'],
            'source_why'  => $decision['source_why'],
            'season'      => thermostatbeEngine::seasonLabel($decision['season']),
            'indoor'      => thermostatbeEngine::formatTemperature($decision['inputs']['indoor']),
            'outdoor'     => thermostatbeEngine::formatTemperature($decision['inputs']['outdoor']),
            'outdoor_avg' => thermostatbeEngine::formatTemperature($decision['outdoor_avg']),
            'setpoint'    => thermostatbeEngine::formatTemperature($decision['setpoint']),
            'ac_setpoint' => thermostatbeEngine::formatTemperature($decision['ac_setpoint']),
            'costs'       => $decision['costs'],
            'sensors'     => array_map(function ($_sensor) {
                return array(
                    'name'  => $_sensor['name'],
                    'value' => thermostatbeEngine::formatTemperature($_sensor['value']),
                    'stale' => $_sensor['stale'],
                    'age'   => ($_sensor['age'] === null) ? null : thermostatbeEngine::formatDuration($_sensor['age']),
                );
            }, $decision['sensors']),
        );
    }
}

/*
 * La classe de commande est obligatoire, même réduite à son execute() : sans
 * elle, le coeur refuse de créer un équipement du plugin.
 */
class thermostatbeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        switch ($this->getLogicalId()) {
            case 'set_heat_setpoint':
                $eqLogic->setRuntime('heat_setpoint', isset($_options['slider']) ? $_options['slider'] : null);
                return;
            case 'set_cool_setpoint':
                $eqLogic->setRuntime('cool_setpoint', isset($_options['slider']) ? $_options['slider'] : null);
                return;
            case 'set_mode':
                $eqLogic->setRuntime('mode', isset($_options['select']) ? $_options['select'] : '');
                return;
            case 'set_source':
                $eqLogic->setRuntime('source', isset($_options['select']) ? $_options['select'] : '');
                return;
            case 'set_preset':
                $eqLogic->setRuntime('preset', isset($_options['select']) ? $_options['select'] : '');
                return;
            case 'refresh':
                $eqLogic->evaluate('command');
                return;
        }
    }
}
