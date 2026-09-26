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

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait alors plus sa classe. */
require_once __DIR__ . '/../core/class/thermostatbe.class.php';

function thermostatbe_install() {
    foreach (array('sensor_max_age' => thermostatbe::DEFAULT_SENSOR_MAX_AGE,
                   'boiler_resend'  => thermostatbe::DEFAULT_BOILER_RESEND) as $key => $default) {
        if (config::byKey($key, 'thermostatbe', '') === '') {
            config::save($key, $default, 'thermostatbe');
        }
    }
}

function thermostatbe_update() {
    thermostatbe_install();

    /* Les thermostats déjà créés n'ont pas les commandes ajoutées depuis. Un
     * équipement à la fois, sous son propre try : un thermostat en défaut ne
     * doit pas priver les autres de leur mise à jour. */
    foreach (eqLogic::byType('thermostatbe') as $eqLogic) {
        try {
            $eqLogic->createCommands();
        } catch (Throwable $e) {
            log::add('thermostatbe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function thermostatbe_remove() {
    /* Désinstaller le plugin ne doit pas laisser la chaudière allumée : plus
     * personne ne viendrait l'éteindre. */
    foreach (eqLogic::byType('thermostatbe') as $eqLogic) {
        try {
            $eqLogic->shutdown(__('plugin désinstallé', __FILE__));
        } catch (Throwable $e) {
            log::add('thermostatbe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
    message::removeAll('thermostatbe');
}
