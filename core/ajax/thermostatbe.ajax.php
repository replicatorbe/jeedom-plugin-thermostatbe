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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    /* L'autoload du coeur ne connaît que la classe qui porte le nom du plugin :
     * le moteur se charge par elle. */
    require_once __DIR__ . '/../class/thermostatbe.class.php';

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* eqLogic::byId() charge n'importe quel équipement : sans ce contrôle, un
     * identifiant étranger ferait agir le plugin sur l'équipement d'un autre. */
    $getThermostat = function ($_id) {
        $eqLogic = thermostatbe::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'thermostatbe') {
            throw new Exception(__('Thermostat introuvable', __FILE__));
        }
        return $eqLogic;
    };

    /* La dernière décision, pour le panneau « En ce moment » de la page. Avec
     * refresh=1, le thermostat repasse d'abord : c'est le bouton « Évaluer
     * maintenant », utile juste après avoir enregistré un réglage. */
    if (init('action') == 'status') {
        $eqLogic = $getThermostat(init('id'));
        if (init('refresh') == 1) {
            $eqLogic->evaluate('page');
        }
        ajax::success($eqLogic->status());
    }

    /* Un réglage de marche changé depuis la page : appliqué tout de suite,
     * comme depuis le tableau de bord, et non à l'enregistrement du
     * formulaire. Un formulaire ouvert depuis une heure renverrait sinon le
     * mode d'il y a une heure, et effacerait celui qu'un scénario ou le
     * tableau de bord a posé entre-temps. */
    if (init('action') == 'setRuntime') {
        $eqLogic = $getThermostat(init('id'));
        $key = init('key');
        if (!in_array($key, array('mode', 'source', 'heat_setpoint', 'cool_setpoint', 'preset'), true)) {
            throw new Exception(__('Réglage inconnu :', __FILE__) . ' ' . $key);
        }
        $eqLogic->setRuntime($key, init('value'));
        ajax::success(thermostatbe::byId($eqLogic->getId())->status());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
